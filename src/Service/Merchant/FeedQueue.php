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
use Quince\Insight\Core\Schema as CoreSchema;
use Quince\Insight\Service\Merchant\Dto\Feed;

/**
 * Oznaczenia zmian (`product_dirty`) i kolejka ofert (`feed_queue`).
 *
 * Hook tylko oznacza produkt (jeden zapis w panelu wola hooki kilka razy — research/03 §6).
 * Cron `products` rozwija oznaczenia na oferty kazdego feedu: kombinacje, nie "rodzica"
 * (SPEC §5.4 [F]). Deduplikacja przez UNIQUE `(id_feed, id_product, id_product_attribute,
 * action)` — kolejne zmiany tej samej oferty laczą sie w jedna pozycje z najwyzszym
 * priorytetem (Google: produkt aktualizowany najwyzej ~2 razy na dobe, research/04 §8).
 */
final class FeedQueue
{
    public const ACTION_UPSERT = 'upsert';

    /** Kolejnosc waznosci powodow: silniejszy nie jest nadpisywany przez slabszy. */
    private const REASON_RANK = ['delete' => 4, 'stock' => 3, 'price' => 2, 'save' => 1];

    public function __construct(private readonly FeedCatalog $catalog)
    {
    }

    /** Oznacza produkt do ponownej oceny w sklepie. `reason`: save | stock | price | delete. */
    public function markDirty(int $idShop, int $idProduct, string $reason): void
    {
        if ($idShop <= 0 || $idProduct <= 0) {
            return;
        }

        $reason = isset(self::REASON_RANK[$reason]) ? $reason : 'save';
        $table = CoreSchema::table(Schema::PRODUCT_DIRTY);
        $current = (string) Db::getInstance()->getValue('SELECT `reason` FROM `' . $table . '` WHERE `id_shop` = ' . $idShop . ' AND `id_product` = ' . $idProduct);

        if ($current !== '' && (self::REASON_RANK[$current] ?? 0) >= self::REASON_RANK[$reason]) {
            return;
        }

        Db::getInstance()->execute('INSERT INTO `' . $table . '` (`id_shop`, `id_product`, `reason`, `date_add`)
            VALUES (' . $idShop . ', ' . $idProduct . ', "' . pSQL($reason) . '", "' . pSQL(Clock::now()) . '")
            ON DUPLICATE KEY UPDATE `reason` = VALUES(`reason`)');
    }

    /**
     * Rozwija oznaczenia sklepu na pozycje kolejki we wszystkich feedach (bez wywolan Google).
     *
     * @param Feed[] $feeds feedy sklepu (wersje robocze tez — utrzymujemy ich lokalna kontrole)
     *
     * @return int ile produktow rozwinieto
     */
    public function expandDirty(int $idShop, array $feeds, int $limit = 200): int
    {
        $db = Db::getInstance();
        $rows = $db->executeS('SELECT `id_product`, `reason` FROM `' . CoreSchema::table(Schema::PRODUCT_DIRTY) . '`
            WHERE `id_shop` = ' . $idShop . ' ORDER BY `date_add` ASC LIMIT ' . $limit);
        $rows = is_array($rows) ? $rows : [];

        foreach ($rows as $row) {
            $idProduct = (int) $row['id_product'];
            $priority = SyncPlan::priority((string) $row['reason']);

            foreach ($feeds as $feed) {
                foreach ($this->offerKeys($feed, $idProduct) as $idAttribute) {
                    $this->enqueue($feed, $idProduct, $idAttribute, $priority);
                }
            }

            $db->delete(CoreSchema::name(Schema::PRODUCT_DIRTY), '`id_shop` = ' . $idShop . ' AND `id_product` = ' . $idProduct);
        }

        return count($rows);
    }

    /**
     * Oferty produktu do oceny w feedzie: obecne kombinacje (albo 0 dla produktu prostego)
     * oraz te, ktore feed juz zna — zeby usunieta kombinacja zniknela z Google.
     *
     * @return int[]
     */
    public function offerKeys(Feed $feed, int $idProduct): array
    {
        $keys = array_keys($this->catalog->offersOfProduct($feed, $idProduct));
        $known = Db::getInstance()->executeS('SELECT `id_product_attribute` FROM `' . CoreSchema::table(Schema::FEED_PRODUCT) . '`
            WHERE `id_feed` = ' . $feed->id . ' AND `id_product` = ' . $idProduct);

        foreach (is_array($known) ? $known : [] as $row) {
            $keys[] = (int) $row['id_product_attribute'];
        }

        return array_values(array_unique(array_map('intval', $keys)));
    }

    public function enqueue(Feed $feed, int $idProduct, int $idProductAttribute, int $priority = 5): void
    {
        $now = pSQL(Clock::now());

        Db::getInstance()->execute('INSERT INTO `' . CoreSchema::table(Schema::FEED_QUEUE) . '`
            (`id_shop`, `id_feed`, `id_product`, `id_product_attribute`, `action`, `offer_id`, `priority`, `attempts`, `available_at`, `last_error`, `date_add`)
            VALUES (' . $feed->idShop . ', ' . $feed->id . ', ' . $idProduct . ', ' . $idProductAttribute . ', "' . self::ACTION_UPSERT . '", "", '
            . max(1, min(9, $priority)) . ', 0, "' . $now . '", "", "' . $now . '")
            ON DUPLICATE KEY UPDATE `priority` = LEAST(`priority`, VALUES(`priority`)), `attempts` = 0,
                `available_at` = LEAST(`available_at`, VALUES(`available_at`)), `last_error` = ""');
    }

    /**
     * Pozycje do wykonania: tylko feedy, ktore cos z nimi robia (aktywne wysylaja, robocze
     * aktualizuja kontrole lokalna). Wstrzymane czekaja w kolejce do wznowienia.
     *
     * @return array<int,array<string,mixed>>
     */
    public function due(int $idShop, int $limit): array
    {
        $rows = Db::getInstance()->executeS('SELECT q.* FROM `' . CoreSchema::table(Schema::FEED_QUEUE) . '` q
            INNER JOIN `' . CoreSchema::table(Schema::FEED) . '` f ON f.`id_feed` = q.`id_feed` AND f.`status` IN ("active", "draft")
            WHERE q.`id_shop` = ' . $idShop . ' AND q.`available_at` <= "' . pSQL(Clock::now()) . '"
            ORDER BY q.`priority` ASC, q.`id_queue` ASC LIMIT ' . max(1, $limit));

        return is_array($rows) ? $rows : [];
    }

    public function done(int $idQueue): void
    {
        Db::getInstance()->delete(CoreSchema::name(Schema::FEED_QUEUE), '`id_queue` = ' . $idQueue);
    }

    /** Blad pozycji: ponowienie z rosnacym odstepem albo — po limicie prob — usuniecie z kolejki. */
    public function fail(int $idQueue, int $attempts, string $error): bool
    {
        $delay = SyncPlan::retryDelay($attempts);

        if ($delay === null) {
            $this->done($idQueue);

            return false;
        }

        Db::getInstance()->update(CoreSchema::name(Schema::FEED_QUEUE), [
            'attempts' => $attempts,
            'available_at' => pSQL(Clock::at($delay)),
            'last_error' => pSQL(mb_substr($error, 0, 500), true),
        ], '`id_queue` = ' . $idQueue);

        return true;
    }

    /** Odlozenie pozycji bez liczenia proby (limit Google). */
    public function postpone(int $idQueue, int $seconds): void
    {
        Db::getInstance()->update(CoreSchema::name(Schema::FEED_QUEUE), ['available_at' => pSQL(Clock::at(max(1, $seconds)))], '`id_queue` = ' . $idQueue);
    }

    /** Ile pozycji czeka w feedzie (Activity → Waiting to send). */
    public function waiting(int $idFeed): int
    {
        return (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . CoreSchema::table(Schema::FEED_QUEUE) . '` WHERE `id_feed` = ' . $idFeed);
    }

    public function dirtyCount(int $idShop): int
    {
        return (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . CoreSchema::table(Schema::PRODUCT_DIRTY) . '` WHERE `id_shop` = ' . $idShop);
    }
}
