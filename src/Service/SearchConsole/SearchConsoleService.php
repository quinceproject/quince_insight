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

use Configuration;
use Quince\Insight\Core\Clock;
use Quince\Insight\Core\Log;
use Quince\Insight\Core\Options;
use Quince\Insight\Core\ShopContext;
use Quince\Insight\Service\SearchConsole\Sitemap\SitemapFiles;

/**
 * Logika uslugi wspolna dla panelu, kreatora i crona: ustawienia, zgloszenie mapy,
 * stan w Google, import i podsumowanie wynikow.
 */
final class SearchConsoleService
{
    /** Historia w Search Console: 16 miesiecy (research/05 §H.1 — [U], zachowawczo 486 dni). */
    public const HISTORY_DAYS = 486;

    /** Codzienny import: ostatnie dni (dane sa zwykle po 2–3 dniach, research/05 §H.1). */
    public const DAILY_WINDOW = 10;

    public function __construct(
        private readonly SearchConsoleApi $api,
        private readonly SearchConsoleRepository $repository,
    ) {
    }

    /** @return array<string,bool> */
    public function include(int $idShop): array
    {
        $saved = Options::json(SearchConsoleOptions::SM_INCLUDE, [], $idShop);
        $out = [];

        foreach (SearchConsoleOptions::INCLUDE_DEFAULTS as $key => $default) {
            $out[$key] = array_key_exists($key, $saved) ? (bool) $saved[$key] : $default;
        }

        // Zdjecia sa czescia wpisow produktow — bez produktow nie ma gdzie ich wpisac.
        if (!$out['products']) {
            $out['images'] = false;
        }

        return $out;
    }

    public function site(int $idShop): string
    {
        return Options::str(SearchConsoleOptions::GSC_SITE, '', $idShop);
    }

    public function mode(int $idShop): string
    {
        return Options::str(SearchConsoleOptions::SM_MODE, 'module', $idShop) === 'existing' ? 'existing' : 'module';
    }

    /** Przyjazne adresy w sklepie (`PS_REWRITING_SETTINGS`) — wtedy zglaszany jest adres `{sklep}qi-sitemap.xml`. */
    public function friendly(int $idShop): bool
    {
        return (bool) Configuration::get('PS_REWRITING_SETTINGS', null, null, $idShop);
    }

    public function fileUrl(int $idShop, string $filename): string
    {
        return SitemapFiles::publicUrl(ShopContext::baseUrl($idShop), $filename, $this->friendly($idShop));
    }

    /** Adres mapy zglaszanej do Google: indeks modulu albo istniejaca mapa (tryb `existing`). */
    public function submitUrl(int $idShop): string
    {
        if ($this->mode($idShop) === 'existing') {
            return Options::str(SearchConsoleOptions::SM_EXISTING_URL, '', $idShop);
        }

        return $this->fileUrl($idShop, SitemapFiles::INDEX);
    }

    /**
     * Istniejaca mapa w katalogu sklepu — modul gsitemap pisze `{id_shop}_index_sitemap.xml`
     * (ten sam plik, ktory rdzen wpisuje do robots.txt — `Tools::generateRobotsFile()`).
     *
     * @return array{url:string,source:string}|null
     */
    public function existingSitemap(int $idShop): ?array
    {
        $file = $idShop . '_index_sitemap.xml';

        if (!defined('_PS_ROOT_DIR_') || !is_file(rtrim(_PS_ROOT_DIR_, '/\\') . '/' . $file)) {
            return null;
        }

        $base = (string) parse_url(ShopContext::baseUrl($idShop), PHP_URL_SCHEME) . '://' . (string) parse_url(ShopContext::baseUrl($idShop), PHP_URL_HOST);
        $port = parse_url(ShopContext::baseUrl($idShop), PHP_URL_PORT);

        // Plik lezy w katalogu instalacji, wiec adres to domena + `__PS_BASE_URI__` (jak w rdzeniu).
        $uri = defined('__PS_BASE_URI__') ? (string) __PS_BASE_URI__ : '/';

        return ['url' => $base . ($port ? ':' . $port : '') . $uri . $file, 'source' => 'gsitemap'];
    }

    /**
     * Zglasza mape do Search Console. Wymaga wybranej uslugi z prawem zapisu.
     */
    public function submit(int $idShop): string
    {
        $site = $this->site($idShop);
        $url = $this->submitUrl($idShop);

        if ($site === '' || $url === '') {
            throw new \RuntimeException('Choose the Search Console property first.');
        }

        if ($this->mode($idShop) === 'module' && !$this->repository->hasFile($idShop, SitemapFiles::INDEX)) {
            throw new \RuntimeException('Rebuild the sitemap before submitting it.');
        }

        if (!SiteMatcher::covers($site, $url)) {
            throw new \RuntimeException('The sitemap address is outside the chosen Search Console property.');
        }

        $client = $this->api->client($idShop);
        $this->api->submitSitemap($client, $site, $url);

        Options::set(SearchConsoleOptions::SM_SUBMITTED_AT, Clock::now(), $idShop);
        Options::set(SearchConsoleOptions::SM_SUBMITTED_URL, $url, $idShop);

        if ($this->mode($idShop) === 'module') {
            $this->repository->markSubmitted($idShop, SitemapFiles::INDEX);
        }

        Log::write($idShop, SearchConsoleApi::SERVICE, Log::INFO, 'Sitemap submitted to Search Console.', ['sitemap' => $url]);

        return $url;
    }

    /**
     * Odczyt map uslugi z Google: stan naszej mapy (indeks i pliki z niego) oraz inne mapy.
     *
     * @return array{ours:?array<string,mixed>,others:array<int,array{path:string,lastDownloaded:string,errors:int,warnings:int}>}
     */
    public function refreshGoogle(int $idShop): array
    {
        $site = $this->site($idShop);

        if ($site === '') {
            return ['ours' => null, 'others' => []];
        }

        $client = $this->api->client($idShop);
        $ours = $this->submitUrl($idShop);
        $submitted = Options::str(SearchConsoleOptions::SM_SUBMITTED_URL, '', $idShop);
        $ownPrefix = $this->fileUrl($idShop, 'qi-sitemap');
        $state = null;
        $others = [];

        foreach ($this->api->sitemaps($client, $site) as $wmx) {
            $path = (string) ($wmx['path'] ?? '');

            if ($path === '') {
                continue;
            }

            if ($path === $ours || $path === $submitted) {
                if ($path === $ours) {
                    $state = SearchConsoleApi::sitemapState($wmx);
                }

                continue;
            }

            // Stare pliki modulu (np. po zmianie przyjaznych adresow) tez sa „nasze” — do usuniecia z tej samej listy.
            $others[] = [
                'path' => $path,
                'lastDownloaded' => substr((string) ($wmx['lastDownloaded'] ?? ''), 0, 10),
                'errors' => (int) ($wmx['errors'] ?? 0),
                'warnings' => (int) ($wmx['warnings'] ?? 0),
                'own' => str_starts_with($path, $ownPrefix),
            ];
        }

        if ($state !== null && $this->mode($idShop) === 'module') {
            $this->repository->saveGoogleState($idShop, SitemapFiles::INDEX, $state);

            // Pliki z indeksu — stan kazdego (bledy i ostrzezenia per plik).
            if ($state['isIndex']) {
                foreach ($this->api->sitemaps($client, $site, $ours) as $child) {
                    $childState = SearchConsoleApi::sitemapState($child);
                    $name = basename((string) parse_url($childState['path'], PHP_URL_PATH));
                    parse_str((string) parse_url($childState['path'], PHP_URL_QUERY), $query);
                    $name = SitemapFiles::isValidName($name) ? $name : (string) ($query['file'] ?? '');

                    if (SitemapFiles::isValidName($name)) {
                        $this->repository->saveGoogleState($idShop, $name, $childState);
                    }
                }
            }
        }

        Options::set(SearchConsoleOptions::SM_GOOGLE, ['state' => $state, 'checked_at' => Clock::now()], $idShop);
        Options::set(SearchConsoleOptions::SM_OTHERS, $others, $idShop);

        return ['ours' => $state, 'others' => $others];
    }

    /**
     * Usuwa mape z Search Console — tylko adres z listy map znanych Google (albo wlasny),
     * nigdy dowolny adres z formularza.
     */
    public function removeSitemap(int $idShop, string $path): void
    {
        $site = $this->site($idShop);
        $allowed = array_column(Options::json(SearchConsoleOptions::SM_OTHERS, [], $idShop), 'path');
        $own = [$this->submitUrl($idShop), Options::str(SearchConsoleOptions::SM_SUBMITTED_URL, '', $idShop)];

        if ($site === '' || $path === '' || (!in_array($path, $allowed, true) && !in_array($path, $own, true))) {
            throw new \RuntimeException('This sitemap is not listed in Search Console.');
        }

        $this->api->deleteSitemap($this->api->client($idShop), $site, $path);

        Options::set(SearchConsoleOptions::SM_OTHERS, array_values(array_filter(
            Options::json(SearchConsoleOptions::SM_OTHERS, [], $idShop),
            static fn ($row): bool => is_array($row) && ($row['path'] ?? '') !== $path
        )), $idShop);

        if (in_array($path, $own, true)) {
            Options::delete(SearchConsoleOptions::SM_SUBMITTED_AT, $idShop);
            Options::delete(SearchConsoleOptions::SM_SUBMITTED_URL, $idShop);
            Options::delete(SearchConsoleOptions::SM_GOOGLE, $idShop);
        }

        Log::write($idShop, SearchConsoleApi::SERVICE, Log::INFO, 'Sitemap removed from Search Console.', ['sitemap' => $path]);
    }

    /**
     * Import wynikow dziennych z okresu do `gsc_daily`.
     */
    public function import(int $idShop, string $from, string $to): int
    {
        $site = $this->site($idShop);

        if ($site === '') {
            return 0;
        }

        $rows = $this->api->daily($this->api->client($idShop), $site, $from, $to);
        $saved = $this->repository->saveDaily($idShop, $rows);
        Options::set(SearchConsoleOptions::GSC_IMPORTED_AT, Clock::now(), $idShop);

        return $saved;
    }

    /**
     * Podsumowanie okresu (7 / 28 / 90 dni) z porownaniem do poprzedniego okresu i wykresem.
     * Okres konczy sie na najswiezszym dniu w danych (Google daje dane z 2–3 dniowym opoznieniem).
     *
     * @return array{tiles:array<int,array<string,mixed>>,trend:array<int,array<string,mixed>>,from:string,to:string,hasData:bool}
     */
    public function summary(int $idShop, int $days): array
    {
        $days = in_array($days, [7, 28, 90], true) ? $days : 28;
        $last = $this->repository->lastDay($idShop);

        if ($last === null) {
            return ['tiles' => [], 'trend' => [], 'from' => '', 'to' => '', 'hasData' => false];
        }

        $end = strtotime($last . ' 00:00:00 UTC');
        $from = gmdate('Y-m-d', $end - ($days - 1) * 86400);
        $beforeTo = gmdate('Y-m-d', $end - $days * 86400);
        $beforeFrom = gmdate('Y-m-d', $end - (2 * $days - 1) * 86400);

        $now = $this->repository->daily($idShop, $from, $last);
        $before = $this->repository->daily($idShop, $beforeFrom, $beforeTo);
        $clicks = [];

        foreach ($now as $row) {
            $clicks[(string) $row['day']] = (int) $row['clicks'];
        }

        return [
            'tiles' => SearchMetrics::tiles(SearchMetrics::totals($now), SearchMetrics::totals($before)),
            'trend' => SearchMetrics::trend($clicks, $from, $days),
            'from' => $from,
            'to' => $last,
            'hasData' => $now !== [],
        ];
    }
}
