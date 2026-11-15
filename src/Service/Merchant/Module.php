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

if (!defined('_PS_VERSION_')) {
    exit;
}

use Db;
use Quince\Insight\Core\Dto\AttentionItem;
use Quince\Insight\Core\Dto\CronTask;
use Quince\Insight\Core\Dto\ServiceHealth;
use Quince\Insight\Core\Options;
use Quince\Insight\Core\Scheduler;
use Quince\Insight\Core\Schema as CoreSchema;
use Quince\Insight\Core\ServiceModule;
use Quince\Insight\Core\Services;
use Quince\Insight\Core\ShopContext;
use Quince\Insight\Service\Merchant\Dto\Feed;

/**
 * Usluga `merchant` — Merchant Center: konto, feedy, kolejka i synchronizacja produktow.
 *
 * Hooki produktu TYLKO oznaczaja zmiane (`product_dirty`) — zadnych wywolan Google
 * (ARCHITEKTURA §8). Oznaczenie trafia do kazdego sklepu z wlaczona usluga, w ktorym
 * produkt istnieje; reszte robi cron `products`.
 */
final class Module implements ServiceModule
{
    public function __construct(
        private readonly FeedRepository $feeds,
        private readonly FeedQueue $queue,
        private readonly FeedSync $sync,
        private readonly MerchantAccount $account,
        private readonly Services $services,
    ) {
    }

    public function key(): string
    {
        return 'merchant';
    }

    public function requires(): ?string
    {
        return 'gmc';
    }

    public function hooks(): array
    {
        return [
            'actionProductSave' => 'onProductSave',
            'actionProductUpdate' => 'onProductSave',
            'actionProductDelete' => 'onProductDelete',
            'actionUpdateQuantity' => 'onQuantity',
            'actionObjectSpecificPriceAddAfter' => 'onSpecificPrice',
            'actionObjectSpecificPriceUpdateAfter' => 'onSpecificPrice',
            'actionObjectSpecificPriceDeleteAfter' => 'onSpecificPrice',
            'actionAttributeCombinationSave' => 'onCombination',
            'actionAttributeCombinationDelete' => 'onCombination',
            'actionValidateOrder' => 'onOrder',
        ];
    }

    public function cronTasks(): array
    {
        return [
            new CronTask('merchant.queue', Scheduler::GROUP_PRODUCTS, Task\QueueTask::class, 5, 40),
            new CronTask('merchant.statuses', Scheduler::GROUP_STATUSES, Task\StatusTask::class, 60, 45),
            new CronTask('merchant.registration', Scheduler::GROUP_MAINTENANCE, Task\RegistrationTask::class, 720, 10),
        ];
    }

    public function jobs(): array
    {
        return [
            SyncJob::TYPE => SyncJob::class,
            FeedDeleteJob::TYPE => FeedDeleteJob::class,
            FeedDraftJob::TYPE => FeedDraftJob::class,
        ];
    }

    public function tables(): array
    {
        return Schema::tables();
    }

    /* ---------------- hooki ---------------- */

    /** @param array<string,mixed> $params */
    public function onProductSave(array $params): void
    {
        $this->mark((int) ($params['id_product'] ?? ($params['product']->id ?? 0)), 'save');
    }

    /** @param array<string,mixed> $params */
    public function onProductDelete(array $params): void
    {
        $this->mark((int) ($params['id_product'] ?? ($params['product']->id ?? 0)), 'delete', true);
    }

    /** @param array<string,mixed> $params */
    public function onQuantity(array $params): void
    {
        $idShop = (int) ($params['id_shop'] ?? 0);
        $this->mark((int) ($params['id_product'] ?? 0), 'stock', false, $idShop > 0 ? $idShop : null);
    }

    /** @param array<string,mixed> $params */
    public function onSpecificPrice(array $params): void
    {
        $object = $params['object'] ?? null;

        if (!is_object($object)) {
            return;
        }

        $idShop = (int) ($object->id_shop ?? 0);
        $idProduct = (int) ($object->id_product ?? 0);

        // Cena specyficzna dla wszystkich produktow (id_product = 0) to regula katalogowa —
        // oznaczamy produkty, ktore feedy juz znaja (pelna ocena i tak przyjdzie z crona).
        if ($idProduct === 0) {
            foreach ($this->knownProducts($idShop > 0 ? $idShop : null) as [$shop, $product]) {
                $this->queue->markDirty($shop, $product, 'price');
            }

            return;
        }

        $this->mark($idProduct, 'price', false, $idShop > 0 ? $idShop : null);
    }

    /** @param array<string,mixed> $params */
    public function onCombination(array $params): void
    {
        $idAttribute = (int) ($params['id_product_attribute'] ?? 0);

        if ($idAttribute <= 0) {
            return;
        }

        $idProduct = (int) Db::getInstance()->getValue('SELECT `id_product` FROM `' . _DB_PREFIX_ . 'product_attribute` WHERE `id_product_attribute` = ' . $idAttribute);

        if ($idProduct > 0) {
            $this->mark($idProduct, 'save');

            return;
        }

        // Kombinacja juz usunieta z bazy (hook po usunieciu) — produkt znamy z wlasnej tabeli.
        $rows = Db::getInstance()->executeS('SELECT DISTINCT `id_shop`, `id_product` FROM `' . CoreSchema::table(Schema::FEED_PRODUCT) . '` WHERE `id_product_attribute` = ' . $idAttribute);

        foreach (is_array($rows) ? $rows : [] as $row) {
            $this->queue->markDirty((int) $row['id_shop'], (int) $row['id_product'], 'delete');
        }
    }

    /** @param array<string,mixed> $params */
    public function onOrder(array $params): void
    {
        $order = $params['order'] ?? null;

        if (!is_object($order) || !method_exists($order, 'getProducts')) {
            return;
        }

        $idShop = (int) ($order->id_shop ?? 0);

        foreach ((array) $order->getProducts() as $line) {
            $this->mark((int) ($line['product_id'] ?? 0), 'stock', false, $idShop > 0 ? $idShop : null);
        }
    }

    /* ---------------- stan ---------------- */

    public function health(int $idShop): ?ServiceHealth
    {
        if (!$this->services->enabled($idShop, 'gmc')) {
            return new ServiceHealth(ServiceHealth::OFF, 'Merchant Center is not connected.');
        }

        $account = $this->account->accountId($idShop);

        if ($account === '') {
            return new ServiceHealth(ServiceHealth::ATTENTION, 'Choose a Merchant Center account.');
        }

        $feeds = $this->feeds->forShop($idShop);

        if ($feeds === []) {
            return new ServiceHealth(ServiceHealth::ATTENTION, 'No feed yet.', 'Merchant ID %id%', ['%id%' => $account]);
        }

        $active = array_values(array_filter($feeds, static fn (Feed $f): bool => $f->isActive()));

        if ($active === []) {
            $paused = array_filter($feeds, static fn (Feed $f): bool => $f->isPaused());

            return new ServiceHealth(ServiceHealth::ATTENTION, $paused ? 'Sending is paused.' : 'Products are not sent to Google yet', 'Merchant ID %id%', ['%id%' => $account]);
        }

        $approved = $disapproved = $inFeed = 0;

        foreach ($active as $feed) {
            $counts = $this->sync->counts($feed);
            $approved += $counts['approved'] + $counts['limited'];
            $disapproved += $counts['disapproved'];
            $inFeed += $counts['synced'] + $counts['failed'];
        }

        return new ServiceHealth(
            $disapproved > 0 ? ServiceHealth::ATTENTION : ServiceHealth::OK,
            '%approved% of %total% products approved',
            '%disapproved% disapproved',
            ['%approved%' => $approved, '%total%' => $inFeed, '%disapproved%' => $disapproved]
        );
    }

    public function attention(int $idShop): array
    {
        if (!$this->services->enabled($idShop, 'gmc')) {
            return [];
        }

        $items = [];
        $route = 'quince_insight_merchant';
        $regState = Options::str(MerchantOptions::REG_STATE, '', $idShop);

        if ($regState === 'not_registered') {
            $items[] = new AttentionItem(AttentionItem::ERROR, 'Your Google Cloud project is no longer registered with Merchant API. Product updates are blocked until you register again.', $route);
        } elseif ($regState === 'no_contact') {
            $items[] = new AttentionItem(AttentionItem::ERROR, 'The Merchant Center account used for developer registration (%id%) has no user with the API developer role. After 30 days Google blocks the API for every shop.', $route, [], ['%id%' => Options::str(MerchantOptions::REG_ACCOUNT, '', $idShop)]);
        }

        foreach ($this->account->issues($idShop) as $issue) {
            if (in_array($issue['severity'] ?? '', ['critical', 'error'], true)) {
                $items[] = new AttentionItem(AttentionItem::ERROR, 'Merchant Center account issue: %title%', $route, [], ['%title%' => (string) $issue['title']]);
            }
        }

        foreach ($this->feeds->forShop($idShop) as $feed) {
            $params = ['%feed%' => $feed->name];

            if ($feed->isDraft()) {
                $items[] = new AttentionItem(AttentionItem::WARNING, 'Products are not sent to Google yet — feed %feed% is a draft.', $route, ['feed' => $feed->id], $params);

                continue;
            }

            if ($feed->isPaused()) {
                $items[] = new AttentionItem(AttentionItem::WARNING, 'Sending is paused for feed %feed%.', $route, ['feed' => $feed->id], $params);
            }

            $counts = $this->sync->counts($feed);

            if ($counts['disapproved'] > 0) {
                $items[] = new AttentionItem(AttentionItem::ERROR, '%count% products were rejected by Google in feed %feed%.', $route, ['feed' => $feed->id, 'tab' => 'products', 'status' => 'disapproved'], $params + ['%count%' => $counts['disapproved']]);
            }

            if ($counts['failed'] > 0) {
                $items[] = new AttentionItem(AttentionItem::WARNING, '%count% products could not be sent in feed %feed%.', $route, ['feed' => $feed->id, 'tab' => 'products'], $params + ['%count%' => $counts['failed']]);
            }
        }

        return $items;
    }

    /** Usluga wylaczona w sklepie: feedy przestaja wysylac (wstrzymane), kolejka znika. Google bez zmian. */
    public function disable(int $idShop): void
    {
        foreach ($this->feeds->forShop($idShop) as $feed) {
            if ($feed->isActive()) {
                $this->feeds->setStatus($feed->id, Feed::STATUS_PAUSED);
            }
        }

        Db::getInstance()->delete(CoreSchema::name(Schema::FEED_QUEUE), '`id_shop` = ' . $idShop);
        Db::getInstance()->delete(CoreSchema::name(Schema::PRODUCT_DIRTY), '`id_shop` = ' . $idShop);
    }

    public function purge(int $idShop): void
    {
        // Wszystko lokalne jest w tabelach z `id_shop` (czysci je rdzen); plikow usluga nie ma.
    }

    public function leftInGoogle(int $idShop): array
    {
        $account = $this->account->accountId($idShop);

        if ($account === '') {
            return [];
        }

        $out = ['Merchant Center account ' . $account . ' — the module does not delete anything there.'];

        foreach ($this->feeds->forShop($idShop) as $feed) {
            if ($feed->dataSource !== '') {
                $out[] = 'Data source "' . MerchantApi::dataSourceName($feed) . '" (' . $feed->dataSource . ') — its products expire on their own after 30 days without updates.';
            }
        }

        return $out;
    }

    /* ---------------- pomocnicze ---------------- */

    /**
     * Oznacza produkt w sklepach z wlaczona usluga i co najmniej jednym feedem.
     * `$any` — takze tam, gdzie produktu juz nie ma (usuniecie).
     */
    private function mark(int $idProduct, string $reason, bool $any = false, ?int $onlyShop = null): void
    {
        if ($idProduct <= 0) {
            return;
        }

        foreach ($this->shopsWithFeeds() as $idShop) {
            if ($onlyShop !== null && $onlyShop !== $idShop && !$this->sharesStock($onlyShop, $idShop)) {
                continue;
            }

            if (!$any && !Db::getInstance()->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . 'product_shop` WHERE `id_product` = ' . $idProduct . ' AND `id_shop` = ' . $idShop)
                && !Db::getInstance()->getValue('SELECT 1 FROM `' . CoreSchema::table(Schema::FEED_PRODUCT) . '` WHERE `id_product` = ' . $idProduct . ' AND `id_shop` = ' . $idShop)) {
                continue;
            }

            $this->queue->markDirty($idShop, $idProduct, $reason);
        }
    }

    /** @return int[] */
    private function shopsWithFeeds(): array
    {
        $rows = Db::getInstance()->executeS('SELECT DISTINCT `id_shop` FROM `' . CoreSchema::table(Schema::FEED) . '`');
        $out = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if ($this->services->enabled((int) $row['id_shop'], 'gmc')) {
                $out[] = (int) $row['id_shop'];
            }
        }

        return $out;
    }

    /** Zmiana stanu w sklepie A dotyczy tez sklepu B, gdy grupa dzieli stan magazynu. */
    private function sharesStock(int $a, int $b): bool
    {
        $px = _DB_PREFIX_;

        return (bool) Db::getInstance()->getValue('SELECT 1 FROM `' . $px . 'shop` s1
            INNER JOIN `' . $px . 'shop` s2 ON s2.`id_shop_group` = s1.`id_shop_group`
            INNER JOIN `' . $px . 'shop_group` g ON g.`id_shop_group` = s1.`id_shop_group` AND g.`share_stock` = 1
            WHERE s1.`id_shop` = ' . $a . ' AND s2.`id_shop` = ' . $b);
    }

    /** @return array<int,array{0:int,1:int}> [sklep, produkt] znane feedom */
    private function knownProducts(?int $idShop): array
    {
        $rows = Db::getInstance()->executeS('SELECT DISTINCT `id_shop`, `id_product` FROM `' . CoreSchema::table(Schema::FEED_PRODUCT) . '`'
            . ($idShop !== null ? ' WHERE `id_shop` = ' . $idShop : '') . ' LIMIT 5000');

        return array_map(static fn (array $r): array => [(int) $r['id_shop'], (int) $r['id_product']], is_array($rows) ? $rows : []);
    }
}
