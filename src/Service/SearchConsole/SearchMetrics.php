<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\SearchConsole;

/**
 * Liczby podsumowania Search Console z wierszy dziennych — czyste funkcje.
 *
 * Poprawka bledu Rankateera (research/02 §2.4): CTR = suma klikniec / suma wyswietlen,
 * pozycja = srednia wazona wyswietleniami (suma pozycja x wyswietlenia / suma wyswietlen),
 * nigdy srednia z wierszy.
 */
final class SearchMetrics
{
    /**
     * Wiersz `gsc_daily` z odpowiedzi Google: pozycja zamieniona na sume wazona.
     *
     * @param array{keys?:array<int,string>,clicks?:int|float,impressions?:int|float,position?:int|float} $row
     *
     * @return array{day:string,clicks:int,impressions:int,position_sum:float}|null
     */
    public static function dailyRow(array $row): ?array
    {
        $day = (string) ($row['keys'][0] ?? '');

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
            return null;
        }

        $impressions = (int) round((float) ($row['impressions'] ?? 0));

        return [
            'day' => $day,
            'clicks' => (int) round((float) ($row['clicks'] ?? 0)),
            'impressions' => $impressions,
            'position_sum' => (float) ($row['position'] ?? 0) * $impressions,
        ];
    }

    /**
     * Sumy okresu.
     *
     * @param iterable<array{clicks:int|string,impressions:int|string,position_sum:float|string}> $rows
     *
     * @return array{clicks:int,impressions:int,ctr:float,position:float,days:int}
     */
    public static function totals(iterable $rows): array
    {
        $clicks = 0;
        $impressions = 0;
        $positionSum = 0.0;
        $days = 0;

        foreach ($rows as $row) {
            $clicks += (int) $row['clicks'];
            $impressions += (int) $row['impressions'];
            $positionSum += (float) $row['position_sum'];
            ++$days;
        }

        return [
            'clicks' => $clicks,
            'impressions' => $impressions,
            'ctr' => $impressions > 0 ? $clicks / $impressions : 0.0,
            'position' => $impressions > 0 ? $positionSum / $impressions : 0.0,
            'days' => $days,
        ];
    }

    /**
     * Cztery kafelki z prototypu (`scSummary`) z porownaniem do poprzedniego okresu.
     * Pozycja: mniejsza = lepsza, wiec spadek liczby to wzrost (`up = true`).
     *
     * @param array{clicks:int,impressions:int,ctr:float,position:float,days:int} $now
     * @param array{clicks:int,impressions:int,ctr:float,position:float,days:int} $before
     *
     * @return array<int,array{label:string,value:string,delta:string,up:bool}>
     */
    public static function tiles(array $now, array $before): array
    {
        $hasBefore = $before['days'] > 0;

        return [
            [
                'label' => 'Clicks',
                'value' => self::number($now['clicks']),
                'delta' => $hasBefore ? self::percentDelta($now['clicks'], $before['clicks']) : '—',
                'up' => $now['clicks'] >= $before['clicks'],
            ],
            [
                'label' => 'Impressions',
                'value' => self::number($now['impressions']),
                'delta' => $hasBefore ? self::percentDelta($now['impressions'], $before['impressions']) : '—',
                'up' => $now['impressions'] >= $before['impressions'],
            ],
            [
                'label' => 'CTR',
                'value' => number_format($now['ctr'] * 100, 1, '.', '') . '%',
                'delta' => $hasBefore ? self::signed(($now['ctr'] - $before['ctr']) * 100, 1) . ' pp' : '—',
                'up' => $now['ctr'] >= $before['ctr'],
            ],
            [
                'label' => 'Average position',
                'value' => $now['impressions'] > 0 ? number_format($now['position'], 1, '.', '') : '—',
                'delta' => $hasBefore && $now['impressions'] > 0 && $before['impressions'] > 0
                    ? self::signed($now['position'] - $before['position'], 1)
                    : '—',
                'up' => $before['impressions'] === 0 || $now['position'] <= $before['position'],
            ],
        ];
    }

    /**
     * Wykres dzienny jak w prototypie (`scTrend`): kolumna na kazdy dzien okresu, wysokosc
     * w procentach dnia z najwieksza liczba klikniec. Dni bez danych = 0 (Google je pomija).
     *
     * @param array<string,int> $clicksByDay `YYYY-MM-DD` => klikniecia
     *
     * @return array<int,array{day:string,clicks:int,height:int}>
     */
    public static function trend(array $clicksByDay, string $firstDay, int $days): array
    {
        $max = max([1, ...array_values($clicksByDay)]);
        $start = strtotime($firstDay . ' 00:00:00 UTC');
        $out = [];

        for ($i = 0; $i < $days; ++$i) {
            $day = gmdate('Y-m-d', $start + $i * 86400);
            $clicks = (int) ($clicksByDay[$day] ?? 0);
            $out[] = ['day' => $day, 'clicks' => $clicks, 'height' => $clicks > 0 ? max(2, (int) round($clicks * 100 / $max)) : 0];
        }

        return $out;
    }

    public static function percentDelta(int $now, int $before): string
    {
        if ($before === 0) {
            return $now === 0 ? '0%' : '+100%';
        }

        $pct = ($now - $before) * 100 / $before;

        return self::signed($pct, 0) . '%';
    }

    private static function signed(float $value, int $decimals): string
    {
        $rounded = round($value, $decimals);

        if (abs($rounded) < 0.5 * (10 ** -$decimals)) {
            $rounded = 0.0;
        }

        // Znak minus typograficzny jak w prototypie (`−1.3`).
        return ($rounded > 0 ? '+' : ($rounded < 0 ? '−' : '')) . number_format(abs($rounded), $decimals, '.', '');
    }

    /** Odstep tysiecy jak `gmFmt` prototypu: spacja (`12 480`). */
    public static function number(int $value): string
    {
        return number_format($value, 0, '.', ' ');
    }
}
