<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Controller\Admin;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShopBundle\Security\Attribute\AdminSecurity;
use Quince\Insight\Core\Clock;
use Quince\Insight\Core\JobRunner;
use Quince\Insight\Core\Options;
use Quince\Insight\Core\ShopContext;
use Quince\Insight\Service\SearchConsole\Job\SitemapRebuildJob;
use Quince\Insight\Service\SearchConsole\PropertySetup;
use Quince\Insight\Service\SearchConsole\SearchConsoleOptions;
use Quince\Insight\Service\SearchConsole\SearchConsoleRepository;
use Quince\Insight\Service\SearchConsole\SearchConsoleService;
use Quince\Insight\Service\SearchConsole\SiteMatcher;
use Quince\Insight\Service\SearchConsole\Sitemap\SitemapFiles;
use Quince\Insight\Service\SearchConsole\Sitemap\SitemapSource;
use Quince\Insight\Service\SearchConsole\Sitemap\SkippedReasons;
use Quince\Insight\Service\Verification\VerificationOptions;
use Quince\Insight\Ui\PageTabs;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ekran Search Console (prototyp `pg==='gsc'`): zakladki Overview i Sitemap oraz trasy JSON
 * bloku G (ARCHITEKTURA §9).
 */
final class SearchConsoleController extends PanelController
{
    /** Stan mapy w Google czytany ponownie przy wejsciu na ekran, gdy starszy niz tyle sekund. */
    private const GOOGLE_STALE = 21600;

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", message: 'You do not have permission to view this.')]
    public function indexAction(Request $request, PageTabs $pageTabs, SearchConsoleService $service, SearchConsoleRepository $repository, JobRunner $jobs): Response
    {
        $idShop = $this->idShop();
        $tabs = $pageTabs->resolve($request, ['overview' => 'Overview', 'sitemap' => 'Sitemap']);

        $data = [
            'tabs' => $tabs,
            'gsc' => $this->overviewData($idShop, $service, $repository, $jobs),
        ];

        return $this->renderPanel('gsc/index.html.twig', 'gsc', $data);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function sitesAction(Request $request, PropertySetup $setup): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_gsc_sites');

        try {
            return $this->ok(['properties' => $setup->properties($this->idShop())]);
        } catch (\Throwable $e) {
            return $this->fail($this->googleError($e), 502);
        }
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function siteAddAction(Request $request, PropertySetup $setup): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_gsc_site_add');

        try {
            $errors = $setup->save($this->idShop(), ['property' => 'prefix'] + $this->payload($request));

            return $errors === [] ? $this->ok(['site' => Options::str(SearchConsoleOptions::GSC_SITE, '', $this->idShop())])
                : $this->fail($this->t((string) reset($errors)), 400, ['errors' => $errors]);
        } catch (\Throwable $e) {
            return $this->fail($this->googleError($e), 502);
        }
    }

    /** Zapis kroku `gsc` z kreatora bez przeladowania + start zadania („Generate and submit”). */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function saveAction(Request $request, PropertySetup $setup, JobRunner $jobs): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_gsc_save');
        $input = $this->payload($request);
        // Sklep formularza kreatora — tylko z kontekstu pracownika (jak `merchant_wizard_draft`).
        $requested = (int) ($input['id_shop'] ?? 0);
        $idShop = $requested > 0 && in_array($requested, $this->contextShopIds(), true) ? $requested : $this->idShop();

        try {
            $errors = $setup->save($idShop, $input);

            if ($errors !== []) {
                return $this->fail($this->t((string) reset($errors)), 400, ['errors' => $errors]);
            }

            $idJob = $setup->startSetupJob($idShop);

            return $this->ok(['job' => $jobs->status($idJob)->toArray()]);
        } catch (\Throwable $e) {
            return $this->fail($this->googleError($e), 502);
        }
    }

    /** Stan mapy w Google i ostatnie dni wynikow — wolane po cichu przez ekran, gdy dane sa stare. */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function refreshAction(Request $request, SearchConsoleService $service): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_gsc_refresh');
        $idShop = $this->idShop();

        try {
            $service->refreshGoogle($idShop);
            $service->import($idShop, gmdate('Y-m-d', Clock::time() - SearchConsoleService::DAILY_WINDOW * 86400), gmdate('Y-m-d', Clock::time() - 86400));

            return $this->ok();
        } catch (\Throwable $e) {
            return $this->fail($this->googleError($e), 502);
        }
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function sitemapRebuildAction(Request $request, JobRunner $jobs, SearchConsoleService $service): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_sitemap_rebuild');
        $idShop = $this->idShop();

        if ($service->mode($idShop) !== 'module') {
            return $this->fail($this->t('The shop uses an existing sitemap. Switch to the module sitemap in the Search Console step of the wizard.'));
        }

        $idJob = $jobs->start($idShop, SitemapRebuildJob::TYPE, []);

        return $this->ok(['job' => $jobs->status($idJob)->toArray()]);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function sitemapSubmitAction(Request $request, SearchConsoleService $service): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_sitemap_submit');
        $idShop = $this->idShop();

        try {
            $service->submit($idShop);
        } catch (\RuntimeException $e) {
            if ($e instanceof \Quince\Insight\Core\Google\GoogleApiException || $e instanceof \Quince\Insight\Core\Google\NotConnectedException) {
                return $this->fail($this->googleError($e), 502);
            }

            return $this->fail($this->t($e->getMessage()));
        }

        // Stan po zgloszeniu — Google zwykle pokazuje mape jako oczekujaca na pobranie.
        try {
            $service->refreshGoogle($idShop);
        } catch (\Throwable $e) {
            // Zgloszenie sie udalo; odczyt stanu powtorzy cron.
        }

        return $this->ok(['submitted' => Options::str(SearchConsoleOptions::SM_SUBMITTED_AT, '', $idShop)]);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function sitemapRemoveAction(Request $request, SearchConsoleService $service): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_sitemap_remove');

        try {
            $service->removeSitemap($this->idShop(), (string) ($this->payload($request)['path'] ?? ''));
        } catch (\RuntimeException $e) {
            if ($e instanceof \Quince\Insight\Core\Google\GoogleApiException || $e instanceof \Quince\Insight\Core\Google\NotConnectedException) {
                return $this->fail($this->googleError($e), 502);
            }

            return $this->fail($this->t($e->getMessage()));
        }

        return $this->ok();
    }

    /**
     * Zakres mapy (`include`) i zgoda na wpis `Sitemap:` w robots.txt (decyzja #19).
     * Trybu przebudowy i recznych wykluczen nie ma (decyzje #20, #21).
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function sitemapSaveAction(Request $request, SearchConsoleService $service): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_sitemap_save');
        $idShop = $this->idShop();
        $input = $this->payload($request);

        if (isset($input['include']) && is_array($input['include'])) {
            $include = $service->include($idShop);

            foreach (SearchConsoleOptions::INCLUDE_DEFAULTS as $key => $default) {
                if (array_key_exists($key, $input['include'])) {
                    $include[$key] = filter_var($input['include'][$key], FILTER_VALIDATE_BOOLEAN);
                }
            }

            Options::set(SearchConsoleOptions::SM_INCLUDE, $include, $idShop);
        }

        // Tylko zapis zgody — modul NIE przepisuje robots.txt sam. Linia pojawia sie przy nastepnym
        // „Generate robots.txt” w PrestaShop (hook `actionAdminMetaAfterWriteRobotsFile`, decyzja #19).
        // Przy mapie z innego modulu przelacznika nie ma — linie pisze tamten modul albo rdzen.
        if (array_key_exists('robots_line', $input) && $service->mode($idShop) === 'module') {
            Options::set(SearchConsoleOptions::SM_ROBOTS_LINE, filter_var($input['robots_line'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0, $idShop);
        }

        return $this->ok([
            'include' => $service->include($idShop),
            'robotsLine' => Options::bool(SearchConsoleOptions::SM_ROBOTS_LINE, false, $idShop),
        ]);
    }

    /**
     * Dane obu zakladek (sa lekkie — liczby z bazy, bez wywolan Google).
     *
     * @return array<string,mixed>
     */
    private function overviewData(int $idShop, SearchConsoleService $service, SearchConsoleRepository $repository, JobRunner $jobs): array
    {
        $site = $service->site($idShop);
        $mode = $service->mode($idShop);
        $files = $repository->files($idShop);
        $index = null;
        $list = [];

        foreach ($files as $file) {
            if ($file['kind'] === 'index') {
                $index = $file;

                continue;
            }

            $list[] = [
                'file' => (string) $file['filename'],
                'url' => $service->fileUrl($idShop, (string) $file['filename']),
                'lang' => $this->iso((int) $file['id_lang'], $idShop),
                'urls' => (int) $file['url_count'],
                'state' => (string) $file['google_state'],
                'errors' => (int) $file['google_errors'],
                'warnings' => (int) $file['google_warnings'],
            ];
        }

        $google = Options::json(SearchConsoleOptions::SM_GOOGLE, [], $idShop);
        $summaries = [];

        foreach ([7, 28, 90] as $days) {
            $summaries[(string) $days] = $service->summary($idShop, $days);
        }

        $include = $service->include($idShop);
        $blog = SitemapSource::blogDetected($idShop);
        $verify = Options::str(VerificationOptions::STATE, 'none', $idShop);
        $permission = Options::str(SearchConsoleOptions::GSC_PERMISSION, '', $idShop);
        $checkedAt = (string) ($google['checked_at'] ?? '');

        return [
            'site' => $site,
            'mode' => $mode,
            'permission' => $permission !== '' ? SiteMatcher::permissionLabel($permission) : '',
            'verify' => $verify,
            'indexUrl' => $service->fileUrl($idShop, SitemapFiles::INDEX),
            'existingUrl' => Options::str(SearchConsoleOptions::SM_EXISTING_URL, '', $idShop),
            'hasIndex' => $index !== null,
            'urlTotal' => (int) ($index['url_count'] ?? 0),
            'files' => $list,
            'google' => is_array($google['state'] ?? null) ? $google['state'] : null,
            'googleStale' => $site !== '' && (Clock::secondsSince($checkedAt ?: null) ?? PHP_INT_MAX) > self::GOOGLE_STALE,
            'others' => array_values(array_filter(Options::json(SearchConsoleOptions::SM_OTHERS, [], $idShop), 'is_array')),
            'lastBuild' => $this->when(Options::str(SearchConsoleOptions::SM_LAST_BUILD, '', $idShop)),
            'submittedAt' => $this->when(Options::str(SearchConsoleOptions::SM_SUBMITTED_AT, '', $idShop)),
            'robotsLine' => Options::bool(SearchConsoleOptions::SM_ROBOTS_LINE, false, $idShop),
            'skipped' => SkippedReasons::summary(Options::json(SearchConsoleOptions::SM_SKIPPED, [], $idShop)),
            'include' => $include,
            'blog' => ['detected' => $blog, 'url' => $blog ? rtrim(ShopContext::baseUrl($idShop), '/') . '/' . SitemapSource::blogPrefix($idShop) . '/…' : ''],
            'summaries' => $summaries,
            'hasData' => $summaries['90']['hasData'],
            'job' => $jobs->active($idShop, SitemapRebuildJob::TYPE)?->toArray(),
        ];
    }

    private function iso(int $idLang, int $idShop): string
    {
        static $langs = [];
        $langs[$idShop] ??= SitemapSource::languages($idShop);

        return $langs[$idShop][$idLang]['iso'] ?? '';
    }

    /** Data do tekstow ekranu w postaci prototypu (`2026-11-10 03:00`). */
    private function when(string $datetime): string
    {
        return $datetime !== '' ? substr($datetime, 0, 16) : '';
    }
}
