<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\PageSpeed;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Core Web Vitals: progi, oceny i odczyt odpowiedzi CrUX / PageSpeed Insights.
 *
 * Czysta logika bez PrestaShop i bez bazy — testowana jednostkowo.
 *
 * Ocena idzie z p75 porownanego z progami Google (research/05 §J.3), a nie z
 * najwiekszego przedzialu histogramu jak w Rankateerze (SPEC §5.6 [F]).
 */
final class WebVitals
{
    public const GOOD = 'good';
    public const NEEDS_IMPROVEMENT = 'ni';
    public const POOR = 'poor';

    /** Klucz metryki CrUX => [skrot, nazwa, jednostka wyswietlana, prog dobry, prog slaby] (p75; ms albo bez jednostki). */
    public const METRICS = [
        'largest_contentful_paint' => ['LCP', 'Largest Contentful Paint', 's', 2500, 4000],
        'interaction_to_next_paint' => ['INP', 'Interaction to Next Paint', 'ms', 200, 500],
        'cumulative_layout_shift' => ['CLS', 'Cumulative Layout Shift', '', 0.1, 0.25],
    ];

    /** Ile podpowiedzi z testu laboratoryjnego trzymamy. */
    private const TIPS = 3;

    /** Tolerancja porownania (CLS przychodzi jako napis z dwoma miejscami). */
    private const EPSILON = 1e-9;

    /**
     * Ocena wartosci p75: `good` gdy <= prog dobry, `ni` gdy <= prog slaby, inaczej `poor`.
     */
    public static function rate(string $metric, float $p75): string
    {
        $def = self::METRICS[$metric] ?? null;

        if ($def === null) {
            throw new \InvalidArgumentException('Unknown metric ' . $metric . '.');
        }

        if ($p75 <= $def[3] + self::EPSILON) {
            return self::GOOD;
        }

        return $p75 <= $def[4] + self::EPSILON ? self::NEEDS_IMPROVEMENT : self::POOR;
    }

    /**
     * Rozklad good / needs improvement / poor w procentach z histogramu CrUX
     * (trzy przedzialy z `density` 0–1). Zaokraglenie tak, zeby suma byla 100.
     *
     * @param array<int,array<string,mixed>> $histogram
     *
     * @return int[] [good, ni, poor]
     */
    public static function distribution(array $histogram): array
    {
        $densities = [];

        for ($n = 0; $n < 3; ++$n) {
            $densities[] = max(0.0, (float) ($histogram[$n]['density'] ?? 0));
        }

        $sum = array_sum($densities);

        if ($sum <= 0) {
            return [0, 0, 0];
        }

        $raw = array_map(static fn (float $d): float => $d / $sum * 100, $densities);
        $out = array_map(static fn (float $v): int => (int) floor($v), $raw);
        $rest = 100 - array_sum($out);

        // Brakujace punkty procentowe trafiaja do przedzialow z najwieksza reszta.
        $fractions = [];

        foreach ($raw as $n => $value) {
            $fractions[$n] = $value - floor($value);
        }

        arsort($fractions);

        foreach (array_keys($fractions) as $n) {
            if ($rest <= 0) {
                break;
            }

            ++$out[$n];
            --$rest;
        }

        return $out;
    }

    /**
     * Czy strona zalicza Core Web Vitals: wszystkie zmierzone metryki dobre.
     * Bez LCP albo CLS nie ma czego zaliczyc; brak samego INP (za malo interakcji)
     * nie przekresla wyniku — ocenia sie to, co jest zmierzone.
     *
     * @param array<string,array<string,mixed>> $metrics klucz CrUX => ['p75' => …]
     */
    public static function passed(array $metrics): bool
    {
        if (!isset($metrics['largest_contentful_paint'], $metrics['cumulative_layout_shift'])) {
            return false;
        }

        foreach ($metrics as $key => $metric) {
            if (isset(self::METRICS[$key]) && self::rate($key, (float) $metric['p75']) !== self::GOOD) {
                return false;
            }
        }

        return true;
    }

    /**
     * Odczyt odpowiedzi `records:queryRecord`: p75 i rozklad kazdej metryki.
     *
     * @param array<mixed> $response
     *
     * @return array{metrics: array<string,array{p75: float, dist: int[]}>, period: array{first: string, last: string}}
     */
    public static function parseCrux(array $response): array
    {
        $metrics = [];

        foreach (self::METRICS as $key => $def) {
            $metric = $response['record']['metrics'][$key] ?? null;

            if (!is_array($metric) || !isset($metric['percentiles']['p75'])) {
                continue;
            }

            // CLS przychodzi jako napis ("doubles encoded as a string") — rzutujemy.
            $p75 = (float) $metric['percentiles']['p75'];

            $metrics[$key] = [
                'p75' => $p75,
                'dist' => self::distribution(is_array($metric['histogram'] ?? null) ? $metric['histogram'] : []),
            ];
        }

        return [
            'metrics' => $metrics,
            'period' => [
                'first' => self::date($response['record']['collectionPeriod']['firstDate'] ?? null),
                'last' => self::date($response['record']['collectionPeriod']['lastDate'] ?? null),
            ],
        ];
    }

    /**
     * Odczyt odpowiedzi PageSpeed Insights: wynik 0–100, LCP, TBT, CLS i najwazniejsze podpowiedzi.
     *
     * @param array<mixed> $response
     *
     * @return array{score: ?int, lcp: ?float, tbt: ?float, cls: ?float, tips: array<int,array{title:string,ms:int}>, error: string}
     */
    public static function parseLab(array $response): array
    {
        $lh = is_array($response['lighthouseResult'] ?? null) ? $response['lighthouseResult'] : [];
        $audits = is_array($lh['audits'] ?? null) ? $lh['audits'] : [];
        $error = (string) ($lh['runtimeError']['message'] ?? '');
        $score = $lh['categories']['performance']['score'] ?? null;

        $numeric = static function (string $id) use ($audits): ?float {
            $value = $audits[$id]['numericValue'] ?? null;

            return is_numeric($value) ? (float) $value : null;
        };

        return [
            'score' => is_numeric($score) ? (int) round((float) $score * 100) : null,
            'lcp' => $numeric('largest-contentful-paint'),
            'tbt' => $numeric('total-blocking-time'),
            'cls' => $numeric('cumulative-layout-shift'),
            'tips' => $error === '' ? self::tips($audits) : [],
            'error' => $error,
        ];
    }

    /**
     * Podpowiedzi: audyty z wynikiem ponizej 0,9 i szacowana oszczednoscia czasu,
     * od najwiekszej. Oszczednosc z `metricSavings` (LCP / FCP; TBT gdy nic wiecej)
     * albo z `details.overallSavingsMs` (starsze wersje Lighthouse).
     *
     * @param array<string,mixed> $audits
     *
     * @return array<int,array{title:string,ms:int}>
     */
    public static function tips(array $audits): array
    {
        $out = [];

        foreach ($audits as $audit) {
            if (!is_array($audit) || !isset($audit['title'])) {
                continue;
            }

            $score = $audit['score'] ?? null;

            if (!is_numeric($score) || (float) $score >= 0.9) {
                continue;
            }

            $savings = is_array($audit['metricSavings'] ?? null) ? $audit['metricSavings'] : [];
            $ms = max(
                (float) ($savings['LCP'] ?? 0),
                (float) ($savings['FCP'] ?? 0),
                (float) ($audit['details']['overallSavingsMs'] ?? 0)
            );

            if ($ms <= 0) {
                $ms = (float) ($savings['TBT'] ?? 0);
            }

            if ($ms < 50) {
                continue;
            }

            $out[] = ['title' => self::plain((string) $audit['title']), 'ms' => (int) round($ms)];
        }

        usort($out, static fn (array $a, array $b): int => $b['ms'] <=> $a['ms']);

        return array_slice($out, 0, self::TIPS);
    }

    /**
     * Wartosc do wyswietlenia: LCP w sekundach (1 miejsce), INP w ms, CLS z dwoma miejscami.
     */
    public static function format(string $metric, float $p75): string
    {
        $unit = self::METRICS[$metric][2] ?? '';

        if ($unit === 's') {
            return number_format($p75 / 1000, 1, '.', '') . ' s';
        }

        if ($unit === 'ms') {
            return (int) round($p75) . ' ms';
        }

        return number_format($p75, 2, '.', '');
    }

    /** "Good ≤ 2.5 s · poor > 4.0 s" — jak w prototypie. */
    public static function thresholdText(string $metric): string
    {
        $def = self::METRICS[$metric];

        return 'Good ≤ ' . self::format($metric, (float) $def[3]) . ' · poor > ' . self::format($metric, (float) $def[4]);
    }

    /** Klasa odznaki wyniku laboratoryjnego (0–100). */
    public static function scoreClass(int $score): string
    {
        return $score >= 90 ? 'qc-badge-success' : ($score >= 50 ? 'qc-badge-warning' : 'qc-badge-danger');
    }

    /** "about 1.2 s" — oszczednosc z podpowiedzi. */
    public static function savings(int $ms): string
    {
        return 'about ' . number_format(max(0.1, $ms / 1000), 1, '.', '') . ' s';
    }

    /** Tytul audytu bywa w Markdown (`code`, [link](…)) — zostawiamy sam tekst. */
    private static function plain(string $text): string
    {
        $text = (string) preg_replace('/\[([^\]]+)\]\([^)]*\)/', '$1', $text);

        return trim(str_replace('`', '', $text));
    }

    private static function date(mixed $date): string
    {
        if (!is_array($date) || !isset($date['year'], $date['month'], $date['day'])) {
            return '';
        }

        return sprintf('%04d-%02d-%02d', (int) $date['year'], (int) $date['month'], (int) $date['day']);
    }
}
