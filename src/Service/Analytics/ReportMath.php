<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\Analytics;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Przeliczenia odpowiedzi GA4 Data API na wiersze ekranu Analytics. Czysta logika, bez Google.
 *
 * Formaty jak w prototypie (`js/analytics.js`, `gmFmt`): tysiace oddzielone spacja,
 * kwoty zaokraglone do calosci z kodem waluty, zmiana procentowa "+6%", zmiana
 * konwersji w punktach procentowych "−0.1 pp" (znak minus U+2212).
 */
final class ReportMath
{
    public const MINUS = "\u{2212}";

    /** Etykiety krokow lejka (angielskie zrodla tlumaczen, 1:1 z prototypu). */
    public const FUNNEL_LABELS = [
        'view_item' => 'Viewed a product',
        'add_to_cart' => 'Added to cart',
        'begin_checkout' => 'Started checkout',
        'add_shipping_info' => 'Chose shipping',
        'add_payment_info' => 'Chose payment',
        'purchase' => 'Purchased',
    ];

    /**
     * Wiersze odpowiedzi jako tablice nazwa => wartosc.
     *
     * @param array<string,mixed> $response
     *
     * @return array<int,array{dims:array<string,string>,metrics:array<string,float>}>
     */
    public static function rows(array $response): array
    {
        $dimNames = array_map(static fn ($h): string => (string) ($h['name'] ?? ''), (array) ($response['dimensionHeaders'] ?? []));
        $metricNames = array_map(static fn ($h): string => (string) ($h['name'] ?? ''), (array) ($response['metricHeaders'] ?? []));
        $out = [];

        foreach ((array) ($response['rows'] ?? []) as $row) {
            $dims = [];
            $metrics = [];

            foreach ((array) ($row['dimensionValues'] ?? []) as $i => $value) {
                $dims[$dimNames[$i] ?? (string) $i] = (string) ($value['value'] ?? '');
            }

            foreach ((array) ($row['metricValues'] ?? []) as $i => $value) {
                $metrics[$metricNames[$i] ?? (string) $i] = (float) ($value['value'] ?? 0);
            }

            $out[] = ['dims' => $dims, 'metrics' => $metrics];
        }

        return $out;
    }

    /**
     * Sumy metryk podsumowania dla zakresu o nazwie `$range` (wymiar `dateRange`).
     * Przy jednym zakresie Google nie dodaje wymiaru — wtedy bierze pierwszy wiersz.
     *
     * @param array<string,mixed> $response
     *
     * @return array<string,float>
     */
    public static function rangeTotals(array $response, string $range): array
    {
        $rows = self::rows($response);

        foreach ($rows as $row) {
            if (($row['dims']['dateRange'] ?? '') === $range) {
                return $row['metrics'];
            }
        }

        if ($rows !== [] && !isset($rows[0]['dims']['dateRange']) && $range === 'current') {
            return $rows[0]['metrics'];
        }

        return [];
    }

    /** Konwersja zakupowa jako ulamek (0.019 = 1.9%). */
    public static function conversion(array $metrics, bool $purchaseIsKey): float
    {
        if ($purchaseIsKey && isset($metrics[ReportQueries::PURCHASE_RATE])) {
            return (float) $metrics[ReportQueries::PURCHASE_RATE];
        }

        $sessions = (float) ($metrics['sessions'] ?? 0);

        return $sessions > 0 ? (float) ($metrics['ecommercePurchases'] ?? 0) / $sessions : 0.0;
    }

    /**
     * Zmiana procentowa wzgledem poprzedniego okresu. Brak poprzedniej wartosci = brak porownania.
     *
     * @return array{text:string,up:?bool}
     */
    public static function percentChange(float $current, float $previous): array
    {
        if ($previous <= 0.0) {
            return ['text' => $current > 0 ? '—' : '0%', 'up' => $current > 0 ? null : true];
        }

        $pct = (int) round(($current - $previous) / $previous * 100);

        if ($pct === 0) {
            return ['text' => '0%', 'up' => true];
        }

        return ['text' => ($pct > 0 ? '+' : self::MINUS) . abs($pct) . '%', 'up' => $pct > 0];
    }

    /**
     * Zmiana konwersji w punktach procentowych (ulamki na wejsciu).
     *
     * @return array{text:string,up:?bool}
     */
    public static function pointChange(float $current, float $previous): array
    {
        $pp = round(($current - $previous) * 100, 1);

        if (abs($pp) < 0.05) {
            return ['text' => '0.0 pp', 'up' => true];
        }

        return ['text' => ($pp > 0 ? '+' : self::MINUS) . number_format(abs($pp), 1, '.', '') . ' pp', 'up' => $pp > 0];
    }

    public static function int(float $value): string
    {
        $n = (int) round($value);

        return ($n < 0 ? self::MINUS : '') . number_format(abs($n), 0, '.', ' ');
    }

    public static function money(float $value, string $currency): string
    {
        return trim(self::int($value) . ' ' . $currency);
    }

    /** Ulamek -> "1.9%". */
    public static function percent(float $fraction): string
    {
        return number_format($fraction * 100, 1, '.', '') . '%';
    }

    /**
     * Szesc liczb Overview w kolejnosci prototypu (`AN_METRICS`).
     *
     * @param array<string,mixed> $response odpowiedz zapytania `ReportQueries::summary()`
     *
     * @return array<int,array{key:string,label:string,value:string,delta:string,up:?bool}>
     */
    public static function summary(array $response, bool $purchaseIsKey, string $currency): array
    {
        $cur = self::rangeTotals($response, 'current');
        $prev = self::rangeTotals($response, 'previous');
        $m = static fn (array $set, string $k): float => (float) ($set[$k] ?? 0);

        $out = [];

        foreach ([
            ['users', 'Users', 'totalUsers', false],
            ['sessions', 'Sessions', 'sessions', false],
            ['revenue', 'Revenue', 'purchaseRevenue', true],
            ['purchases', 'Purchases', 'ecommercePurchases', false],
        ] as [$key, $label, $metric, $isMoney]) {
            $change = self::percentChange($m($cur, $metric), $m($prev, $metric));
            $out[] = [
                'key' => $key,
                'label' => $label,
                'value' => $isMoney ? self::money($m($cur, $metric), $currency) : self::int($m($cur, $metric)),
                'delta' => $change['text'],
                'up' => $change['up'],
            ];
        }

        if ($purchaseIsKey) {
            $convCur = self::conversion($cur, true);
            $convPrev = self::conversion($prev, true);
            $change = $prev === [] ? ['text' => '—', 'up' => null] : self::pointChange($convCur, $convPrev);
            $out[] = ['key' => 'conversion', 'label' => 'Conversion rate', 'value' => self::percent($convCur), 'delta' => $change['text'], 'up' => $change['up'], 'noData' => false];
        } else {
            // Decyzja #5: `purchase` nie jest kluczowe w GA4 -> konwersja zakupowa = „No data”, nie blad
            // i nie liczba zastepcza (modul nie usuwa ani nie wymusza zdarzen kluczowych).
            $out[] = ['key' => 'conversion', 'label' => 'Conversion rate', 'value' => 'No data', 'delta' => '—', 'up' => null, 'noData' => true];
        }

        $change = self::percentChange($m($cur, 'averagePurchaseRevenue'), $m($prev, 'averagePurchaseRevenue'));
        $out[] = ['key' => 'aov', 'label' => 'Average order', 'value' => self::money($m($cur, 'averagePurchaseRevenue'), $currency), 'delta' => $change['text'], 'up' => $change['up']];

        return $out;
    }

    /**
     * Wykres dzienny: kazdy dzien okresu (takze bez danych), wysokosc slupka w % maksimum.
     *
     * @param array<string,mixed> $response odpowiedz `ReportQueries::daily()`
     * @param string $lastDay ostatni dzien okresu (wczoraj) jako Y-m-d
     *
     * @return array{days:string[],series:array<string,array<int,int>>,values:array<string,array<int,float>>}
     */
    public static function daily(array $response, int $days, string $lastDay): array
    {
        $map = ['sessions' => 'sessions', 'users' => 'totalUsers', 'revenue' => 'purchaseRevenue', 'purchases' => 'ecommercePurchases'];
        $byDate = [];

        foreach (self::rows($response) as $row) {
            $byDate[$row['dims']['date'] ?? ''] = $row['metrics'];
        }

        $end = new \DateTimeImmutable($lastDay);
        $dates = [];

        for ($i = $days - 1; $i >= 0; --$i) {
            $dates[] = $end->modify('-' . $i . ' days')->format('Ymd');
        }

        $values = [];
        $series = [];

        foreach ($map as $key => $metric) {
            $values[$key] = array_map(static fn (string $d): float => (float) ($byDate[$d][$metric] ?? 0), $dates);
            $max = max($values[$key] ?: [0]);
            $series[$key] = array_map(static fn (float $v): int => $max > 0 ? (int) round($v / $max * 100) : 0, $values[$key]);
        }

        return ['days' => $dates, 'series' => $series, 'values' => $values];
    }

    /**
     * Kanaly: udzial w sesjach, sesje, konwersja, przychod.
     *
     * @param array<string,mixed> $response odpowiedz `ReportQueries::channels()`
     *
     * @return array<int,array{name:string,sessions:string,share:int,width:int,conv:string,revenue:string}>
     */
    public static function channels(array $response, bool $purchaseIsKey, string $currency): array
    {
        $rows = self::rows($response);
        $total = array_sum(array_map(static fn (array $r): float => (float) ($r['metrics']['sessions'] ?? 0), $rows));
        $out = [];
        $maxShare = 0.0;

        foreach ($rows as $row) {
            $sessions = (float) ($row['metrics']['sessions'] ?? 0);
            $share = $total > 0 ? $sessions / $total : 0.0;
            $maxShare = max($maxShare, $share);
            $out[] = [
                'name' => (string) ($row['dims']['sessionDefaultChannelGroup'] ?? ''),
                'sessionsRaw' => $sessions,
                'shareRaw' => $share,
                'sessions' => self::int($sessions),
                'share' => (int) round($share * 100),
                // Decyzja #5: bez kluczowego `purchase` konwersji nie ma ("—").
                'conv' => $purchaseIsKey ? self::percent(self::conversion($row['metrics'], true)) : '—',
                'revenue' => self::money((float) ($row['metrics']['purchaseRevenue'] ?? 0), $currency),
            ];
        }

        foreach ($out as &$row) {
            // Najwiekszy kanal = pelny pasek; reszta proporcjonalnie (czytelniej niz sam udzial).
            $row['width'] = $maxShare > 0 ? (int) round($row['shareRaw'] / $maxShare * 100) : 0;
            unset($row['shareRaw'], $row['sessionsRaw']);
        }
        unset($row);

        return $out;
    }

    /**
     * Udzial w sesjach wg wymiaru (urzadzenia, kraje). Przy `$top` reszta trafia do "Other".
     *
     * @param array<string,mixed> $response
     *
     * @return array<int,array{name:string,share:int,sessions:string}>
     */
    public static function shares(array $response, string $dimension, ?int $top = null): array
    {
        $rows = self::rows($response);
        $total = array_sum(array_map(static fn (array $r): float => (float) ($r['metrics']['sessions'] ?? 0), $rows));

        if ($total <= 0) {
            return [];
        }

        $items = [];

        foreach ($rows as $row) {
            $items[] = [(string) ($row['dims'][$dimension] ?? ''), (float) ($row['metrics']['sessions'] ?? 0)];
        }

        if ($top !== null && count($items) > $top + 1) {
            $rest = array_sum(array_map(static fn (array $i): float => $i[1], array_slice($items, $top)));
            $items = array_slice($items, 0, $top);
            $items[] = ['Other', $rest];
        }

        return array_map(static fn (array $i): array => [
            'name' => $i[0] === '(not set)' ? 'Other' : self::deviceLabel($i[0]),
            'share' => (int) round($i[1] / $total * 100),
            'sessions' => self::int($i[1]),
        ], $items);
    }

    /**
     * Lejek po `eventName`: liczba, udzial wzgledem pierwszego kroku i odplyw wzgledem
     * poprzedniego kroku (w %). Krok bez danych = 0.
     *
     * Decyzja #4: jedna odpowiedz ma obie miary — `eventCount` (przelacznik „Events”, domyslny)
     * i `totalUsers` („Visitors”: osoby, ktore wywolaly zdarzenie kroku).
     *
     * @param array<string,mixed> $response odpowiedz `ReportQueries::funnel()`
     * @param string $metric eventCount | totalUsers
     *
     * @return array<int,array{event:string,label:string,n:int,count:string,pct:int,drop:int}>
     */
    public static function funnel(array $response, string $metric = 'eventCount'): array
    {
        $counts = array_fill_keys(ReportQueries::FUNNEL_EVENTS, 0);

        foreach (self::rows($response) as $row) {
            $event = $row['dims']['eventName'] ?? '';

            if (isset($counts[$event])) {
                $counts[$event] += (int) round($row['metrics'][$metric] ?? 0);
            }
        }

        $first = $counts[ReportQueries::FUNNEL_EVENTS[0]];
        $out = [];
        $prev = null;

        foreach (ReportQueries::FUNNEL_EVENTS as $event) {
            $n = $counts[$event];
            $out[] = [
                'event' => $event,
                'label' => self::FUNNEL_LABELS[$event],
                'n' => $n,
                'count' => self::int($n),
                'pct' => $first > 0 ? (int) min(100, round($n / $first * 100)) : 0,
                // Lejek otwarty (bez wymuszonej kolejnosci): krok moze miec wiecej zdarzen niz poprzedni —
                // wtedy odplyw 0, nie ujemny.
                'drop' => $prev !== null && $prev > 0 ? (int) max(0, round((1 - $n / $prev) * 100)) : 0,
            ];
            $prev = $n;
        }

        return $out;
    }

    /**
     * Indeks kroku (1..5), przed ktorym odpada najwiecej; null gdy lejek pusty.
     *
     * @param array<int,array{drop:int,n:int}> $steps
     */
    public static function biggestDrop(array $steps): ?int
    {
        if (($steps[0]['n'] ?? 0) <= 0) {
            return null;
        }

        $best = null;

        foreach ($steps as $i => $step) {
            if ($i > 0 && ($best === null || $step['drop'] > $steps[$best]['drop'])) {
                $best = $i;
            }
        }

        return $best;
    }

    /**
     * Flagi jakosci z `metadata` odpowiedzi (research/05 §C.4).
     *
     * @param array<int,array<string,mixed>> $responses
     *
     * @return array{thresholded:bool,sampled:?int,other:bool,restricted:bool}
     */
    public static function quality(array $responses): array
    {
        $flags = ['thresholded' => false, 'sampled' => null, 'other' => false, 'restricted' => false];

        foreach ($responses as $response) {
            $meta = (array) ($response['metadata'] ?? []);
            $flags['thresholded'] = $flags['thresholded'] || !empty($meta['subjectToThresholding']);
            $flags['other'] = $flags['other'] || !empty($meta['dataLossFromOtherRow']);
            $flags['restricted'] = $flags['restricted'] || !empty($meta['schemaRestrictionResponse']['activeMetricRestrictions']);

            foreach ((array) ($meta['samplingMetadatas'] ?? []) as $sample) {
                $space = (float) ($sample['samplingSpaceSize'] ?? 0);

                if ($space > 0) {
                    $pct = (int) max(1, round((float) ($sample['samplesReadCount'] ?? 0) / $space * 100));
                    $flags['sampled'] = $flags['sampled'] === null ? $pct : min($flags['sampled'], $pct);
                }
            }
        }

        return $flags;
    }

    /** @param array<int,array<string,mixed>> $responses */
    public static function hasRows(array $responses): bool
    {
        foreach ($responses as $response) {
            foreach (self::rows($response) as $row) {
                if (array_sum($row['metrics']) > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function deviceLabel(string $value): string
    {
        // deviceCategory przychodzi malymi literami (mobile, desktop, tablet, smart tv).
        return $value !== '' && strtolower($value) === $value ? ucfirst($value) : $value;
    }
}
