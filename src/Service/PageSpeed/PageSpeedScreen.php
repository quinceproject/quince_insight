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

use Quince\Insight\Core\Google\ConnectionRepository;
use Quince\Insight\Core\JobRunner;
use Quince\Insight\Core\Options;
use Quince\Insight\Core\ShopContext;

/**
 * Dane ekranu Page speed — wszystko gotowe do wyswietlenia (napisy angielskie, jak w prototypie),
 * dla obu urzadzen naraz: przelacznik Mobile / Desktop dziala w przegladarce bez zapytania.
 */
final class PageSpeedScreen
{
    public function __construct(
        private readonly PsiRepository $repository,
        private readonly ConnectionRepository $connections,
        private readonly JobRunner $jobs,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function data(int $idShop): array
    {
        $results = $this->repository->results($idShop);
        $connection = $this->connections->forShop($idShop);
        $base = ShopContext::baseUrl($idShop);
        $field = [];
        $lab = [];
        $checked = [];

        foreach (PsiRepository::STRATEGIES as $strategy) {
            $field[$strategy] = self::field($results[0][PsiRepository::FIELD][$strategy] ?? []);
        }

        foreach ($this->repository->pages($idShop) as $page) {
            $row = [
                'id' => $page['id'],
                'label' => $page['label'],
                'path' => self::path($page['url'], $base),
                'url' => $page['url'],
                'report' => 'https://pagespeed.web.dev/analysis?url=' . rawurlencode($page['url']),
            ];

            foreach (PsiRepository::STRATEGIES as $strategy) {
                $current = $results[$page['id']][PsiRepository::LAB][$strategy][PsiRepository::CURRENT] ?? null;
                $row[$strategy] = self::lab($current);

                if ($current !== null) {
                    $checked[$strategy][] = $current['checked_at'];
                }
            }

            $lab[] = $row;
        }

        $last = [];

        foreach (PsiRepository::STRATEGIES as $strategy) {
            $last[$strategy] = !empty($checked[$strategy]) ? substr((string) max($checked[$strategy]), 0, 16) : '';
        }

        $job = $this->jobs->active($idShop, CheckJob::TYPE);

        return [
            'domain' => (string) (parse_url($base, PHP_URL_HOST) ?: $base),
            'hasKey' => $connection !== null && $connection->hasApiKey,
            'field' => $field,
            'pages' => $lab,
            'last' => $last,
            'schedule' => Options::str(PageSpeedOptions::SCHEDULE, PageSpeedOptions::DEFAULT_SCHEDULE, $idShop),
            'maxPages' => PsiRepository::MAX_PAGES,
            'job' => $job?->toArray(),
        ];
    }

    /**
     * Dane CrUX jednego urzadzenia: metryki z ocena, rozkladem i poprzednia wartoscia.
     *
     * @param array<string,array{score:?int,metrics:array<mixed>,checked_at:string}> $slots
     *
     * @return array<string,mixed>
     */
    public static function field(array $slots): array
    {
        $current = $slots[PsiRepository::CURRENT] ?? null;

        if ($current === null) {
            return ['state' => 'none', 'metrics' => [], 'passed' => false];
        }

        if (!empty($current['metrics']['nodata'])) {
            return ['state' => 'nodata', 'metrics' => [], 'passed' => false, 'checked' => $current['checked_at']];
        }

        $metrics = (array) ($current['metrics']['metrics'] ?? []);
        $previous = (array) ($slots[PsiRepository::PREVIOUS]['metrics']['metrics'] ?? []);
        $out = [];

        foreach (WebVitals::METRICS as $key => $def) {
            if (!isset($metrics[$key]['p75'])) {
                continue;
            }

            $p75 = (float) $metrics[$key]['p75'];
            $dist = array_map('intval', (array) ($metrics[$key]['dist'] ?? [0, 0, 0]));
            $prev = isset($previous[$key]['p75']) ? WebVitals::format($key, (float) $previous[$key]['p75']) : '';

            $out[] = [
                'key' => $def[0],
                'name' => $def[1],
                'value' => WebVitals::format($key, $p75),
                'rating' => WebVitals::rate($key, $p75),
                'dist' => [$dist[0] ?? 0, $dist[1] ?? 0, $dist[2] ?? 0],
                'threshold' => WebVitals::thresholdText($key),
                'prev' => $prev,
            ];
        }

        return [
            'state' => $out ? 'ok' : 'nodata',
            'metrics' => $out,
            'passed' => WebVitals::passed($metrics),
            'checked' => $current['checked_at'],
        ];
    }

    /**
     * @param array{score:?int,metrics:array<mixed>,checked_at:string}|null $result
     *
     * @return array<string,mixed>
     */
    public static function lab(?array $result): array
    {
        if ($result === null) {
            return ['pending' => true];
        }

        $m = $result['metrics'];

        if (($m['error'] ?? '') !== '' || $result['score'] === null) {
            return ['pending' => false, 'error' => (string) ($m['error'] ?? '') ?: 'The test did not finish.', 'tips' => []];
        }

        $tips = [];

        foreach ((array) ($m['tips'] ?? []) as $tip) {
            $tips[] = ['title' => (string) $tip['title'], 'saves' => 'saves ' . WebVitals::savings((int) $tip['ms'])];
        }

        return [
            'pending' => false,
            'error' => '',
            'score' => (int) $result['score'],
            'scoreClass' => WebVitals::scoreClass((int) $result['score']),
            'metrics' => 'LCP ' . number_format(((float) ($m['lcp'] ?? 0)) / 1000, 1, '.', '') . ' s · TBT '
                . (int) round((float) ($m['tbt'] ?? 0)) . ' ms · CLS ' . number_format((float) ($m['cls'] ?? 0), 2, '.', ''),
            'tips' => $tips,
        ];
    }

    /**
     * Adres wpisany przez usera -> pelny adres strony sklepu; null gdy to nie jest strona
     * tego sklepu (test PageSpeed liczy sie z limitu projektu Google usera).
     * Przyjmuje pelny adres w domenie sklepu albo sama sciezke (`/3-fasteners`).
     */
    public static function normalizeUrl(string $input, string $base): ?string
    {
        $input = trim($input);

        if ($input === '' || $base === '' || preg_match('/[\s<>"]/', $input)) {
            return null;
        }

        if ($input[0] === '/') {
            return rtrim($base, '/') . $input;
        }

        if (!preg_match('#^https?://#i', $input)) {
            $input = 'https://' . $input;
        }

        $host = (string) parse_url($input, PHP_URL_HOST);
        $baseParts = parse_url($base);
        $baseHost = (string) ($baseParts['host'] ?? '');

        if ($host === '' || strcasecmp($host, $baseHost) !== 0) {
            return null;
        }

        // Schemat i port sklepu, nie wpisane (http zamiast https da przekierowanie i gorszy wynik).
        $prefix = ($baseParts['scheme'] ?? 'https') . '://' . $baseHost . (isset($baseParts['port']) ? ':' . $baseParts['port'] : '');
        $path = (string) (parse_url($input, PHP_URL_PATH) ?: '/');
        $query = parse_url($input, PHP_URL_QUERY);

        return $prefix . $path . ($query ? '?' . $query : '');
    }

    /** Sciezka wzgledem adresu sklepu (jak w prototypie: `/3-fasteners`). */
    public static function path(string $url, string $base): string
    {
        $parts = parse_url($url);

        if (!is_array($parts)) {
            return $url;
        }

        $baseHost = (string) parse_url($base, PHP_URL_HOST);

        if (isset($parts['host']) && strcasecmp($parts['host'], $baseHost) !== 0) {
            return $url;
        }

        return ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
}
