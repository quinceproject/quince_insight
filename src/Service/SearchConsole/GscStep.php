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

use Quince\Insight\Core\Google\GoogleApiException;
use Quince\Insight\Core\Google\NotConnectedException;
use Quince\Insight\Core\JobRunner;
use Quince\Insight\Core\Options;
use Quince\Insight\Core\ShopContext;
use Quince\Insight\Core\WizardStep;
use Quince\Insight\Service\SearchConsole\Job\SitemapRebuildJob;
use Quince\Insight\Service\SearchConsole\Sitemap\SitemapFiles;

/**
 * Krok kreatora `gsc` (prototyp `cur==='gsc'`): usluga i zrodlo mapy. Zgloszenie do Search
 * Console jest stale (decyzja #21) — bez przelacznika „Submit to Search Console”.
 *
 * Szablon: `views/templates/admin/wizard/steps/gsc.html.twig`. To krok z zadaniem (prototyp
 * `taskSteps`): `save()` zapisuje wybor i uruchamia zadanie `sitemap.rebuild` (przebudowa
 * albo istniejaca mapa → zgloszenie → import w tle); `data()['job']` to jego stan do paska
 * postepu, `status()` = `done` dopiero po udanym zadaniu.
 */
final class GscStep implements WizardStep
{
    public function __construct(
        private readonly PropertySetup $setup,
        private readonly SearchConsoleService $service,
        private readonly SearchConsoleRepository $repository,
        private readonly JobRunner $jobs,
    ) {
    }

    public function key(): string
    {
        return 'gsc';
    }

    public function data(array $shopIds): array
    {
        $idShop = (int) ($shopIds[0] ?? ShopContext::currentOrDefault());
        $error = '';
        $properties = null;
        $needsGrant = $this->setup->needsGrant($idShop);

        if (!$needsGrant) {
            try {
                $properties = $this->setup->properties($idShop);
            } catch (NotConnectedException|GoogleApiException $e) {
                $error = $e->getMessage();
            }
        }

        $site = $this->service->site($idShop);
        $choice = 'prefix';

        if ($site !== '' && $properties !== null) {
            $choice = $site === $properties['domain']['site'] && $properties['domain']['ok'] ? 'sc-domain'
                : ($site === $properties['prefix']['site'] ? 'prefix' : $site);
        }

        $job = $this->jobs->last($idShop, SitemapRebuildJob::TYPE);
        $existing = $this->service->existingSitemap($idShop);

        return [
            'idShop' => $idShop,
            'needsGrant' => $needsGrant,
            'error' => $error,
            'shopUrl' => ShopContext::baseUrl($idShop),
            'properties' => $properties,
            'choice' => $choice,
            'sitemap' => Options::has(SearchConsoleOptions::SM_MODE, $idShop) ? $this->service->mode($idShop) : 'module',
            'existing' => $existing,
            'job' => $job?->toArray(),
            // Stan zadania kroku dla przycisku glownego: '' | running | done | error.
            'task' => $job === null ? '' : ($job->isActive() ? 'running' : ($job->status === 'done' && $this->status($idShop) === 'done' ? 'done' : ($job->status === 'failed' ? 'error' : ''))),
        ];
    }

    public function save(array $shopIds, array $input): array
    {
        $errors = [];

        foreach ($shopIds as $idShop) {
            // "Continue" po przycisku "Generate and submit" / "Save and submit": wybor jest zapisany,
            // a zadanie tego sklepu juz przeszlo (`task_job` = jego id) — drugi raz nic nie robimy.
            $last = $this->jobs->last((int) $idShop, SitemapRebuildJob::TYPE);

            if ($last !== null && $last->id === (int) ($input['task_job'] ?? 0) && $last->status === 'done') {
                continue;
            }

            try {
                $errors = $this->setup->save((int) $idShop, $input);
            } catch (NotConnectedException|GoogleApiException $e) {
                $errors = ['property' => $e->getMessage()];
            }

            if ($errors !== []) {
                return $errors;
            }

            $this->setup->startSetupJob((int) $idShop);
        }

        return [];
    }

    public function status(int $idShop): string
    {
        if ($this->service->site($idShop) === '') {
            return 'todo';
        }

        $job = $this->jobs->last($idShop, SitemapRebuildJob::TYPE);

        if ($job !== null && $job->status === 'failed') {
            return 'error';
        }

        if ($job === null || $job->isActive()) {
            return 'todo';
        }

        if ($this->service->mode($idShop) === 'module' && !$this->repository->hasFile($idShop, SitemapFiles::INDEX)) {
            return 'todo';
        }

        return 'done';
    }

    public function summary(int $idShop): array
    {
        $site = $this->service->site($idShop);

        if ($site === '') {
            return [['label' => 'Search Console', 'value' => 'Not finished', 'state' => 'todo']];
        }

        $submitted = Options::str(SearchConsoleOptions::SM_SUBMITTED_URL, '', $idShop) !== '';

        // Tekst jak w prototypie (`summaryRows`): adres uslugi · sitemap submitted.
        return [[
            'label' => 'Search Console',
            'value' => $site . ($submitted ? ' · sitemap submitted' : ''),
            'state' => $this->status($idShop),
        ]];
    }
}
