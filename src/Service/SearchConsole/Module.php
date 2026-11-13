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

use Quince\Insight\Core\Container;
use Quince\Insight\Core\Dto\AttentionItem;
use Quince\Insight\Core\Dto\CronTask;
use Quince\Insight\Core\Dto\ServiceHealth;
use Quince\Insight\Core\Options;
use Quince\Insight\Core\Scheduler;
use Quince\Insight\Core\ServiceModule;
use Quince\Insight\Core\Services;
use Quince\Insight\Core\ShopRepository;
use Quince\Insight\Core\ShopContext;
use Quince\Insight\Service\SearchConsole\Job\GscImportJob;
use Quince\Insight\Service\SearchConsole\Job\SitemapRebuildJob;
use Quince\Insight\Service\SearchConsole\Sitemap\RobotsLine;
use Quince\Insight\Service\SearchConsole\Sitemap\SitemapFiles;
use Quince\Insight\Service\SearchConsole\Sitemap\SkippedReasons;
use Quince\Insight\Service\SearchConsole\Task\ImportTask;
use Quince\Insight\Service\SearchConsole\Task\SitemapTask;

/**
 * Usluga `searchconsole` — Search Console i mapa witryny (agent G).
 *
 * Bez konstruktora z zaleznosciami: rejestr tworzy uslugi takze w testach i przy budowie
 * schematu — klasy logiki biore przez `Container::get()` dopiero w metodach.
 */
final class Module implements ServiceModule
{
    public function key(): string
    {
        return 'searchconsole';
    }

    public function requires(): ?string
    {
        return 'gsc';
    }

    public function hooks(): array
    {
        return [
            'moduleRoutes' => 'onModuleRoutes',
            // Zmiany katalogu nie sa juz sledzone: mapa przebudowuje sie przy kazdym cronie `sitemap` (decyzja #21).
            'actionAdminMetaAfterWriteRobotsFile' => 'onWriteRobots',
        ];
    }

    public function cronTasks(): array
    {
        return [
            new CronTask('searchconsole.sitemap', Scheduler::GROUP_SITEMAP, SitemapTask::class, 0, 45),
            new CronTask('searchconsole.import', Scheduler::GROUP_MAINTENANCE, ImportTask::class, 720, 20),
        ];
    }

    public function jobs(): array
    {
        return [
            SitemapRebuildJob::TYPE => SitemapRebuildJob::class,
            GscImportJob::TYPE => GscImportJob::class,
        ];
    }

    public function tables(): array
    {
        return Schema::tables();
    }

    public function health(int $idShop): ?ServiceHealth
    {
        $service = Container::get(SearchConsoleService::class);
        $site = $service->site($idShop);

        if ($site === '') {
            return new ServiceHealth('attention', 'No Search Console property chosen yet.');
        }

        $google = Options::json(SearchConsoleOptions::SM_GOOGLE, [], $idShop)['state'] ?? null;
        $files = Container::get(SearchConsoleRepository::class)->files($idShop);
        $urls = (int) ($files[0]['url_count'] ?? 0);

        if (is_array($google) && ($google['state'] ?? '') === 'error') {
            return new ServiceHealth('error', 'Google reports errors in the sitemap.', $site);
        }

        if ($service->mode($idShop) === 'module' && $files === []) {
            return new ServiceHealth('attention', 'The sitemap has not been generated yet.', $site);
        }

        if (Options::str(SearchConsoleOptions::SM_SUBMITTED_URL, '', $idShop) === '') {
            return new ServiceHealth('attention', 'The sitemap is not submitted to Search Console.', $site);
        }

        return $service->mode($idShop) === 'module'
            ? new ServiceHealth('ok', '%count% addresses in the sitemap.', $site, ['%count%' => SearchMetrics::number($urls)])
            : new ServiceHealth('ok', 'Using the existing sitemap.', $site);
    }

    public function attention(int $idShop): array
    {
        $service = Container::get(SearchConsoleService::class);
        $items = [];

        if ($service->site($idShop) === '') {
            return [new AttentionItem(AttentionItem::WARNING, 'Choose the Search Console property for this shop.', 'quince_insight_wizard', ['step' => 'gsc'])];
        }

        $google = Options::json(SearchConsoleOptions::SM_GOOGLE, [], $idShop)['state'] ?? null;

        if (is_array($google) && (int) ($google['errors'] ?? 0) > 0) {
            $items[] = new AttentionItem(AttentionItem::ERROR, 'Google reports %count% errors in the sitemap.', 'quince_insight_gsc', ['tab' => 'sitemap'], ['%count%' => (int) $google['errors']]);
        }

        if (Options::str(SearchConsoleOptions::SM_SUBMITTED_URL, '', $idShop) === '') {
            $items[] = new AttentionItem(AttentionItem::WARNING, 'The sitemap is not submitted to Search Console.', 'quince_insight_gsc', ['tab' => 'sitemap']);
        }

        $notFound = (int) (Options::json(SearchConsoleOptions::SM_SKIPPED, [], $idShop)[SkippedReasons::NOT_FOUND] ?? 0);

        if ($service->mode($idShop) === 'module' && $notFound > 0) {
            $items[] = new AttentionItem(AttentionItem::WARNING, '%count% addresses left out of the sitemap because they return 404.', 'quince_insight_gsc', ['tab' => 'sitemap'], ['%count%' => $notFound]);
        }

        return $items;
    }

    /** Usluga wylaczona w sklepie: pliki mapy znikaja z serwera (w Google niczego nie ruszamy). */
    public function disable(int $idShop): void
    {
        SitemapFiles::purge($idShop);
        Container::get(SearchConsoleRepository::class)->clearFiles($idShop);
    }

    public function purge(int $idShop): void
    {
        SitemapFiles::purge($idShop);
    }

    public function leftInGoogle(int $idShop): array
    {
        $url = Options::str(SearchConsoleOptions::SM_SUBMITTED_URL, '', $idShop);
        $site = Options::str(SearchConsoleOptions::GSC_SITE, '', $idShop);

        return $url !== '' ? ['Search Console - sitemap ' . $url . ' submitted to ' . $site] : [];
    }

    /**
     * Przyjazny adres plikow mapy: `{sklep}qi-sitemap[...].xml` → kontroler `sitemap`.
     * Tylko adresy modulu (prefiks `qi-sitemap`), tylko przy mapie generowanej przez modul
     * i tylko w sklepie na zywo (R-14: po „Restart wizard” stare pliki nie sa serwowane).
     *
     * @param array<string,mixed> $params
     *
     * @return array<string,array<string,mixed>>
     */
    public function onModuleRoutes(array $params): array
    {
        $idShop = (int) ($params['id_shop'] ?? 0) ?: ShopContext::currentOrDefault();

        if (!Container::get(ShopRepository::class)->isLive($idShop)
            || !Container::get(Services::class)->enabled($idShop, 'gsc')
            || Container::get(SearchConsoleService::class)->mode($idShop) !== 'module') {
            return [];
        }

        return [
            'module-quince_insight-sitemap' => [
                'controller' => 'sitemap',
                'rule' => 'qi-sitemap{name}.xml',
                'keywords' => ['name' => ['regexp' => '[a-z0-9-]*', 'param' => 'name']],
                'params' => ['fc' => 'module', 'module' => 'quince_insight', 'controller' => 'sitemap'],
            ],
        ];
    }

    /**
     * Linia `Sitemap:` w robots.txt dla sklepow, ktore sie na to zgodzily (rdzen daje uchwyt pliku
     * przy „Generate robots.txt file” — decyzja #19). Tylko sklepy na zywo z wlaczona usluga,
     * mapa modulu (przy „Use existing” przelacznika nie ma) i gotowy plik indeksu.
     *
     * @param array<string,mixed> $params
     */
    public function onWriteRobots(array $params): void
    {
        $handle = $params['write_fd'] ?? null;

        if (!is_resource($handle)) {
            return;
        }

        $service = Container::get(SearchConsoleService::class);
        $services = Container::get(Services::class);
        $shops = Container::get(ShopRepository::class);
        $rows = [];

        foreach (array_keys(ShopContext::shops()) as $idShop) {
            $consent = $services->enabled($idShop, 'gsc') && Options::bool(SearchConsoleOptions::SM_ROBOTS_LINE, false, $idShop);

            $rows[] = [
                'consent' => $consent,
                'live' => $consent && $shops->isLive($idShop),
                'mode' => $service->mode($idShop),
                'url' => $consent ? $service->submitUrl($idShop) : '',
                'ready' => $consent && SitemapFiles::path($idShop, SitemapFiles::INDEX) !== null,
            ];
        }

        RobotsLine::append($handle, RobotsLine::urlsFor($rows));
    }
}
