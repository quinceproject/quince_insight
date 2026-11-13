<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\SearchConsole\Task;

use Quince\Insight\Core\Dto\JobStatus;
use Quince\Insight\Core\Google\GoogleApiException;
use Quince\Insight\Core\Google\NotConnectedException;
use Quince\Insight\Core\JobRunner;
use Quince\Insight\Service\SearchConsole\Job\SitemapRebuildJob;
use Quince\Insight\Service\SearchConsole\SearchConsoleService;

/**
 * Zadanie `searchconsole.sitemap` (grupa `sitemap`, raz na dobe w nocy).
 *
 * Decyzja #21: przebudowa przy KAZDYM uruchomieniu grupy (rekomendacja: raz na dobe) i zawsze
 * zgloszenie do Search Console po przebudowie, gdy usluga jest wybrana (SPEC §5.1
 * „automatyczne zgloszenie po regeneracji”); potem odczyt stanu mapy w Google. Zadanie, ktore
 * nie skonczylo sie w budzecie, dokancza kolejne uruchomienie (nowe nie startuje obok).
 * Nieudana przebudowa = wyjatek, wiec `Scheduler` zapisuje status `error` (fala 2, raport I).
 */
final class SitemapTask
{
    /** Ile sekund zadanie moze pracowac w jednym wywolaniu crona (budzet grupy: 50 s). */
    private const BUDGET = 40.0;

    public function __construct(
        private readonly SearchConsoleService $service,
        private readonly JobRunner $jobs,
    ) {
    }

    public function run(int $idShop): string
    {
        $parts = [];

        if ($this->service->mode($idShop) === 'module') {
            $active = $this->jobs->active($idShop, SitemapRebuildJob::TYPE);

            if ($active === null) {
                $this->jobs->start($idShop, SitemapRebuildJob::TYPE, ['submit' => $this->service->site($idShop) !== '']);
                $active = $this->jobs->active($idShop, SitemapRebuildJob::TYPE);
            }

            if ($active !== null) {
                $deadline = microtime(true) + self::BUDGET;
                $status = $active;

                while ($status->isActive() && $status->waitSeconds === 0 && microtime(true) < $deadline) {
                    $status = $this->jobs->tick($active->id);
                }

                if (!$status->isActive() && $status->status !== JobStatus::DONE) {
                    throw new \RuntimeException('Sitemap rebuild failed: ' . ($status->error !== '' ? $status->error : $status->status));
                }

                $parts[] = $status->isActive()
                    ? 'Sitemap rebuild continues at the next run (' . $status->done . '/' . $status->total . ').'
                    : $status->message . '.';
            }
        }

        if ($this->service->site($idShop) !== '') {
            try {
                $this->service->refreshGoogle($idShop);
                $parts[] = 'Sitemap status read from Search Console.';
            } catch (NotConnectedException|GoogleApiException $e) {
                $parts[] = 'Search Console status not read: ' . $e->getMessage();
            }
        }

        return implode(' ', $parts) ?: 'Nothing to do.';
    }
}
