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

if (!defined('_PS_VERSION_')) {
    exit;
}

use Quince\Insight\Core\Google\GoogleClient;

/**
 * Wywolania Search Console API (research/05 §H). Bez stanu i bez bazy — dostaje klienta.
 *
 * Czesc map witryny przeniesiona z Rankateera (`SitemapApiService`: list / submit / delete /
 * status) z wymiana warstwy HTTP na `GoogleClient` — bledy ida wyjatkiem `GoogleApiException`,
 * nie cichym `false`, zeby ekran mogl powiedziec, co sie stalo.
 */
final class SearchConsoleApi
{
    public const SERVICE = 'searchconsole';
    public const BASE = 'https://www.googleapis.com/webmasters/v3';

    /** Najwiekszy `rowLimit` (research/05 §H.1). */
    public const ROW_LIMIT = 25000;

    /** Ochrona przed petla bez konca przy blednej odpowiedzi. */
    private const MAX_PAGES = 200;

    public function client(int $idShop): GoogleClient
    {
        return GoogleClient::forShop($idShop, self::SERVICE);
    }

    /** @return array<int,array{siteUrl:string,permissionLevel:string}> */
    public function sites(GoogleClient $client): array
    {
        $data = $client->get(self::BASE . '/sites');
        $out = [];

        foreach ((array) ($data['siteEntry'] ?? []) as $entry) {
            if (is_array($entry) && !empty($entry['siteUrl'])) {
                $out[] = ['siteUrl' => (string) $entry['siteUrl'], 'permissionLevel' => (string) ($entry['permissionLevel'] ?? '')];
            }
        }

        return $out;
    }

    /** @return array{siteUrl:string,permissionLevel:string} */
    public function site(GoogleClient $client, string $site): array
    {
        $data = $client->get(self::siteUrl($site));

        return ['siteUrl' => (string) ($data['siteUrl'] ?? $site), 'permissionLevel' => (string) ($data['permissionLevel'] ?? '')];
    }

    /** `sites.add` — dodaje usluge (nie weryfikuje jej; research/05 §H). */
    public function addSite(GoogleClient $client, string $site): void
    {
        $client->put(self::siteUrl($site));
    }

    /**
     * Wszystkie wiersze zapytania `searchAnalytics.query` — stronicowanie po `startRow`
     * co 25 000 az do odpowiedzi bez wierszy (research/05 §H.1, poprawka [F] SPEC §5.1).
     *
     * @param array<string,mixed> $body tresc zapytania bez `rowLimit` i `startRow`
     *
     * @return array<int,array<string,mixed>>
     */
    public function queryAll(GoogleClient $client, string $site, array $body): array
    {
        $rows = [];
        $startRow = 0;

        for ($page = 0; $page < self::MAX_PAGES; ++$page) {
            $data = $client->post(self::siteUrl($site) . '/searchAnalytics/query', $body + [
                'rowLimit' => self::ROW_LIMIT,
                'startRow' => $startRow,
            ]);

            $chunk = array_values(array_filter((array) ($data['rows'] ?? []), 'is_array'));

            if ($chunk === []) {
                break;
            }

            foreach ($chunk as $row) {
                $rows[] = $row;
            }

            $startRow += self::ROW_LIMIT;
        }

        return $rows;
    }

    /**
     * Wyniki dzienne (wymiar `date`) z okresu — do tabeli `gsc_daily`.
     *
     * @return array<int,array{day:string,clicks:int,impressions:int,position_sum:float}>
     */
    public function daily(GoogleClient $client, string $site, string $startDate, string $endDate): array
    {
        $rows = $this->queryAll($client, $site, [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'dimensions' => ['date'],
            'type' => 'web',
            'dataState' => 'final',
        ]);

        $out = [];

        foreach ($rows as $row) {
            $daily = SearchMetrics::dailyRow($row);

            if ($daily !== null) {
                $out[] = $daily;
            }
        }

        return $out;
    }

    /** @return array<int,array<string,mixed>> `WmxSitemap[]` */
    public function sitemaps(GoogleClient $client, string $site, string $sitemapIndex = ''): array
    {
        $data = $client->get(self::siteUrl($site) . '/sitemaps', $sitemapIndex !== '' ? ['sitemapIndex' => $sitemapIndex] : []);

        return array_values(array_filter((array) ($data['sitemap'] ?? []), 'is_array'));
    }

    /** @return array<string,mixed> `WmxSitemap` */
    public function sitemap(GoogleClient $client, string $site, string $feedpath): array
    {
        return $client->get(self::sitemapUrl($site, $feedpath));
    }

    public function submitSitemap(GoogleClient $client, string $site, string $feedpath): void
    {
        $client->put(self::sitemapUrl($site, $feedpath));
    }

    public function deleteSitemap(GoogleClient $client, string $site, string $feedpath): void
    {
        $client->delete(self::sitemapUrl($site, $feedpath));
    }

    /** `siteUrl` w sciezce jest zakodowany (`https%3A%2F%2Fyourshop.com%2F`, `sc-domain%3Ayourshop.com`). */
    public static function siteUrl(string $site): string
    {
        return self::BASE . '/sites/' . rawurlencode($site);
    }

    public static function sitemapUrl(string $site, string $feedpath): string
    {
        return self::siteUrl($site) . '/sitemaps/' . rawurlencode($feedpath);
    }

    /**
     * Stan mapy w Google bez wycofanego pola `indexed` (ARCHITEKTURA §12 pkt 12).
     *
     * @param array<string,mixed> $wmx
     *
     * @return array{path:string,state:string,errors:int,warnings:int,pending:bool,isIndex:bool,lastSubmitted:string,lastDownloaded:string,submitted:int}
     */
    public static function sitemapState(array $wmx): array
    {
        $errors = (int) ($wmx['errors'] ?? 0);
        $warnings = (int) ($wmx['warnings'] ?? 0);
        $pending = (bool) ($wmx['isPending'] ?? false);
        $submitted = 0;

        foreach ((array) ($wmx['contents'] ?? []) as $content) {
            if (is_array($content) && ($content['type'] ?? '') === 'web') {
                $submitted += (int) ($content['submitted'] ?? 0);
            }
        }

        $state = $errors > 0 ? 'error' : ($pending || empty($wmx['lastDownloaded']) ? 'pending' : ($warnings > 0 ? 'warning' : 'success'));

        return [
            'path' => (string) ($wmx['path'] ?? ''),
            'state' => $state,
            'errors' => $errors,
            'warnings' => $warnings,
            'pending' => $pending,
            'isIndex' => (bool) ($wmx['isSitemapsIndex'] ?? false),
            'lastSubmitted' => (string) ($wmx['lastSubmitted'] ?? ''),
            'lastDownloaded' => (string) ($wmx['lastDownloaded'] ?? ''),
            'submitted' => $submitted,
        ];
    }
}
