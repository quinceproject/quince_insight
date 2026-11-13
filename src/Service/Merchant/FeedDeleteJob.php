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
use Quince\Insight\Core\Dto\Job;
use Quince\Insight\Core\Dto\JobProgress;
use Quince\Insight\Core\JobHandler;
use Quince\Insight\Core\Options;
use Quince\Insight\Core\Schema as CoreSchema;
use Quince\Insight\Service\Merchant\Dto\Feed;
use Quince\Insight\Service\MerchantTools\RuleRepository;

/**
 * Zadanie `merchant.feed_delete` — "Delete feed" (decyzja #10 w DECYZJE-PO-FALI-1).
 *
 * Dokumentacja Google nie mowi, czy `dataSources.delete` usuwa produkty zrodla (research/04
 * §6 [U]), wiec kolejnosc jest jawna:
 *   1. produkty feedu znane Google — `productInputs.delete` po jednym (z glownego zrodla
 *      usuwa produkt, research/04 §7.1), najwyzej `PER_STEP` wywolan na krok; limit
 *      minutowy (429) zatrzymuje krok, a `JobRunner` czeka i wznawia;
 *   2. zrodlo danych w Merchant Center (`dataSources.delete`, 404 = juz nie ma);
 *   3. feed w module (stan produktow, kolejka, dziennik) i reguly tylko tego feedu.
 * Kazdy krok da sie powtorzyc: usuniety produkt znika z `feed_product` od razu.
 * Feed jest wstrzymany od startu zadania — nic nie wysyla, a hooki tylko czekaja w kolejce.
 */
final class FeedDeleteJob implements JobHandler
{
    public const TYPE = 'merchant.feed_delete';

    /** Wywolan `productInputs.delete` na jeden krok (krok co ok. 1 s z karty panelu). */
    private const PER_STEP = 20;

    private const STEP_SECONDS = 6.0;

    public function __construct(
        private readonly FeedRepository $feeds,
        private readonly MerchantApi $api,
        private readonly RuleRepository $rules,
    ) {
    }

    /** Produkty feedu, ktore Google zna (wyslane albo z bledem po wyslaniu) — "N" w ostrzezeniu. */
    public static function inGoogle(int $idFeed): int
    {
        return (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . CoreSchema::table(Schema::FEED_PRODUCT) . '`
            WHERE `id_feed` = ' . $idFeed . ' AND `sync_state` IN ("synced", "error")');
    }

    public function step(Job $job): JobProgress
    {
        $idFeed = (int) ($job->payload['feed'] ?? 0);
        $feed = $this->feeds->find($idFeed);
        $state = $job->state + ['phase' => 'products', 'total' => -1, 'removed' => 0];

        if ($feed === null || $feed->idShop !== $job->idShop) {
            $this->clearFlag($job->idShop, $idFeed);

            return new JobProgress(1, 1, 'Done', true, 0, ['items' => []], ['deleted' => true]);
        }

        if ($state['total'] < 0) {
            $state['total'] = self::inGoogle($feed->id);

            if (!$feed->isPaused()) {
                $this->feeds->setStatus($feed->id, Feed::STATUS_PAUSED);
            }

            Options::set(MerchantOptions::DELETING_FEED, (string) $feed->id, $feed->idShop);
            $this->feeds->log($feed->idShop, $feed->id, 'warning', 'Feed deletion started', ['products' => $state['total']]);
        }

        $deadline = microtime(true) + self::STEP_SECONDS;

        if ($state['phase'] === 'products') {
            $rows = Db::getInstance()->executeS('SELECT `id_feed_product`, `offer_id` FROM `' . CoreSchema::table(Schema::FEED_PRODUCT) . '`
                WHERE `id_feed` = ' . $feed->id . ' AND `sync_state` IN ("synced", "error")
                ORDER BY `id_feed_product` ASC LIMIT ' . self::PER_STEP) ?: [];

            foreach ($rows as $row) {
                if (microtime(true) >= $deadline) {
                    break;
                }

                if ((string) $row['offer_id'] !== '' && $feed->dataSource !== '') {
                    // 429 leci wyjatkiem do `JobRunner`: zadanie czeka i wznawia od tego produktu.
                    $this->api->delete($feed, (string) $row['offer_id']);
                }

                Db::getInstance()->delete(CoreSchema::name(Schema::FEED_PRODUCT), '`id_feed_product` = ' . (int) $row['id_feed_product']);
                ++$state['removed'];
            }

            if (count($rows) < self::PER_STEP && self::inGoogle($feed->id) === 0) {
                $state['phase'] = 'source';
            }

            return $this->progress($state, false);
        }

        // Zrodlo danych dopiero po produktach, potem feed w module.
        $this->api->deleteDataSource($feed->idShop, $feed->dataSource);
        $this->feeds->log($feed->idShop, $feed->id, 'info', 'Feed deleted', ['products' => $state['removed']]);
        $this->feeds->delete($feed->id);
        $this->rules->deleteFeed($feed->idShop, $feed->id);
        $this->clearFlag($feed->idShop, $feed->id);
        $state['phase'] = 'done';

        return $this->progress($state, true);
    }

    /** @param array<string,mixed> $state */
    private function progress(array $state, bool $finished): JobProgress
    {
        $total = max(0, (int) $state['total']);
        $phase = (string) $state['phase'];
        // Teksty stale (tlumaczone w panelu); liczbe produktow pokazuje pasek postepu.
        $items = [
            ['text' => "Removing the feed's products from Google", 'st' => $phase === 'products' ? 'run' : 'ok', 'note' => ''],
            ['text' => 'Deleting the data source in Merchant Center', 'st' => $phase === 'products' ? 'wait' : ($phase === 'source' && !$finished ? 'run' : 'ok'), 'note' => ''],
            ['text' => 'Deleting the feed in the module', 'st' => $finished ? 'ok' : 'wait', 'note' => ''],
        ];
        $done = min($total, (int) $state['removed']) + ($phase === 'products' ? 0 : 1) + ($finished ? 1 : 0);
        $state['items'] = $items;

        return new JobProgress($done, $total + 2, $finished ? 'Feed deleted' : 'Deleting the feed', $finished, 0, $state, $finished ? ['deleted' => true, 'removed' => (int) $state['removed']] : []);
    }

    private function clearFlag(int $idShop, int $idFeed): void
    {
        if (Options::int(MerchantOptions::DELETING_FEED, 0, $idShop) === $idFeed) {
            Options::set(MerchantOptions::DELETING_FEED, '0', $idShop);
        }
    }
}
