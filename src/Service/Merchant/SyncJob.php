<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\Merchant;

use Db;
use Quince\Insight\Core\Clock;
use Quince\Insight\Core\Dto\Job;
use Quince\Insight\Core\Dto\JobProgress;
use Quince\Insight\Core\JobHandler;
use Quince\Insight\Core\Schema as CoreSchema;
use Quince\Insight\Service\Merchant\Dto\Feed;

/**
 * Zadanie `merchant.sync` — pelna ocena feedu jako `Job` (pierwsza wysylka po "Send now",
 * kontrola wersji roboczej po zmianie filtrow, "Sync now").
 *
 * Feedy do przejscia = feedy sklepu z `last_full_sync IS NULL` (oznaczane przez
 * `SyncJob::request()`), wiec drugi feed zgloszony w trakcie zadania dolacza do niego —
 * `JobRunner` pozwala na jedno aktywne zadanie danego typu w sklepie.
 *
 * Etapy na feed: A — oferty pasujace do filtrow (strona po stronie), B — oferty, ktore feed
 * znal, a ktorych etap A nie ocenil (wypadly z filtrow / zniknely ze sklepu → usuniecie z
 * Google tylko w tym feedzie). Wersja robocza konczy na stanie lokalnym, bez wywolan Google.
 */
final class SyncJob implements JobHandler
{
    public const TYPE = 'merchant.sync';

    private const STEP_SECONDS = 6.0;
    private const PAGE = 50;

    public function __construct(
        private readonly FeedRepository $feeds,
        private readonly FeedCatalog $catalog,
        private readonly FeedSync $sync,
    ) {
    }

    /** Zglasza feed do pelnej oceny (zadanie podejmie go przy najblizszym kroku). */
    public static function request(int $idFeed): void
    {
        Db::getInstance()->update(CoreSchema::name(Schema::FEED), ['last_full_sync' => null], '`id_feed` = ' . $idFeed, 0, true);
    }

    public function step(Job $job): JobProgress
    {
        $state = $job->state + ['feed' => 0, 'phase' => 'a', 'offset' => 0, 'since' => '', 'stats' => [], 'done' => 0, 'total' => 0, 'base' => 0, 'feeds' => 0];
        $deadline = microtime(true) + self::STEP_SECONDS;
        $feed = $state['feed'] ? $this->feeds->find((int) $state['feed']) : null;

        while (microtime(true) < $deadline) {
            if ($feed === null) {
                $feed = $this->next($job->idShop);

                if ($feed === null) {
                    return new JobProgress($state['done'], max($state['total'], $state['done']), 'Done', true, 0, ['items' => $state['items'] ?? []], [
                        'stats' => $state['stats'],
                    ]);
                }

                $state = ['feed' => $feed->id, 'phase' => 'a', 'offset' => 0, 'since' => Clock::now(), 'stats' => [],
                    'done' => $state['done'], 'base' => $state['done'], 'feeds' => $state['feeds'] + 1,
                    'total' => $state['done'] + $this->catalog->countCandidates($feed), 'items' => $state['items'] ?? []];
                $this->sync->resetCache();
                $this->sync->startRun($feed);
            }

            if ($feed->isPaused()) {
                // Wstrzymany w trakcie zadania: nic nie wysylamy, ocena wroci po wznowieniu.
                $this->feeds->touchFullSync($feed->id);
                $feed = null;
                $state['feed'] = 0;

                continue;
            }

            if ($state['phase'] === 'a') {
                $keys = $this->catalog->matching($feed, self::PAGE, (int) $state['offset']);

                if ($keys === []) {
                    $state['phase'] = 'b';
                    $state['total'] += $this->sync->staleCount($feed, $state['since']);

                    continue;
                }

                foreach ($keys as $key) {
                    $state = $this->one($feed, $key, $state);
                    ++$state['offset'];

                    if (microtime(true) >= $deadline) {
                        break;
                    }
                }

                continue;
            }

            $rows = $this->sync->staleRows($feed, $state['since'], self::PAGE);

            if ($rows === []) {
                $this->finishFeed($feed, $state);
                $feed = null;
                $state['feed'] = 0;

                continue;
            }

            foreach ($rows as $key) {
                $state = $this->one($feed, $key, $state);

                if (microtime(true) >= $deadline) {
                    break;
                }
            }
        }

        $message = $feed !== null && $feed->isActive() ? 'Sending products' : 'Checking products';
        $state['items'] = [['text' => $message, 'st' => 'run', 'note' => '']];

        return new JobProgress($state['done'], max($state['total'], $state['done'] + 1), $message, false, 0, $state);
    }

    /**
     * @param array{id_product:int,id_product_attribute:int} $key
     * @param array<string,mixed> $state
     *
     * @return array<string,mixed>
     */
    private function one(Feed $feed, array $key, array $state): array
    {
        // Limit Google idzie wyjatkiem do JobRunner, ktory odklada krok; oferta zostanie
        // oceniona ponownie przy nastepnym kroku (ocena jest idempotentna).
        $result = $this->sync->evaluate($feed, $key['id_product'], $key['id_product_attribute']);

        $state['stats'][$result] = (int) ($state['stats'][$result] ?? 0) + 1;
        ++$state['done'];

        return $state;
    }

    /** @param array<string,mixed> $state */
    private function finishFeed(Feed $feed, array $state): void
    {
        $this->feeds->touchFullSync($feed->id);

        if ($feed->isActive()) {
            $stats = $state['stats'];
            $this->feeds->log($feed->idShop, $feed->id, empty($stats[FeedSync::FAILED]) ? 'info' : 'warning', 'Sync: full', [
                'mode' => 'full',
                'sent' => (int) ($stats[FeedSync::SENT] ?? 0) + (int) ($stats[FeedSync::PATCHED] ?? 0),
                'deleted' => (int) ($stats[FeedSync::DELETED] ?? 0),
                'unchanged' => (int) ($stats[FeedSync::UNCHANGED] ?? 0),
                'errors' => (int) ($stats[FeedSync::FAILED] ?? 0),
            ]);
        }
    }

    private function next(int $idShop): ?Feed
    {
        foreach ($this->feeds->forShop($idShop) as $feed) {
            if ($feed->lastFullSync === null && !$feed->isPaused()) {
                return $feed;
            }
        }

        return null;
    }
}
