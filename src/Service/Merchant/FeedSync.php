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
use Quince\Insight\Core\Google\GoogleApiException;
use Quince\Insight\Core\Google\GoogleAuthException;
use Quince\Insight\Core\Google\NotConnectedException;
use Quince\Insight\Core\Options;
use Quince\Insight\Core\Schema as CoreSchema;
use Quince\Insight\Service\Merchant\Dto\Feed;

/**
 * Ocena i wysylka ofert jednego feedu.
 *
 * `evaluate()` to jedyne miejsce, ktore decyduje o ofercie — wola je kolejka (tryb
 * roznicowy), pelna synchronizacja (`SyncJob`) i akcje recznie z listy Products:
 *   zbudowanie → filtry feedu (ten sam warunek co licznik) → reguly → kontrola lokalna →
 *   `SyncPlan::decide()` → insert / patch / delete / nic.
 * Wersja robocza i feed wstrzymany NIE wysylaja nic — ocena aktualizuje wtedy tylko stan
 * lokalny (licznik "Ready / With issues" i liste Products).
 */
final class FeedSync
{
    /** Wynik oceny: wyslano / usunieto / bez zmian / tylko lokalnie / blad. */
    public const SENT = 'sent';
    public const PATCHED = 'patched';
    public const DELETED = 'deleted';
    public const UNCHANGED = 'unchanged';
    public const LOCAL = 'local';
    public const FAILED = 'failed';

    /** @var array<string,array<int,bool>> */
    private array $inFilters = [];

    public function __construct(
        private readonly FeedRepository $feeds,
        private readonly FeedCatalog $catalog,
        private readonly ProductBuilder $builder,
        private readonly MerchantApi $api,
        private readonly FeedQueue $queue,
    ) {
    }

    /**
     * Ocenia oferte w feedzie i — gdy feed jest aktywny — wysyla zmiane do Google.
     * Limity Google i bledy logowania ida wyjatkiem w gore (zatrzymuja partie);
     * blad danych jednej oferty jest zapisywany przy ofercie.
     */
    public function evaluate(Feed $feed, int $idProduct, int $idProductAttribute, bool $send = true): string
    {
        $previous = $this->row($feed->id, $idProduct, $idProductAttribute);
        $offer = $this->builder->build($feed, $idProduct, $idProductAttribute);
        $inFilters = $offer !== null && ($this->filterState($feed, $idProduct)[$idProductAttribute] ?? false);
        $blocked = $offer !== null && $feed->onlyReady && LocalCheck::blocking($offer->issues);
        $wanted = $inFilters && !$offer->excluded && !$blocked;
        $sends = $send && SyncPlan::feedSends($feed->status);
        $now = Clock::now();

        $local = [
            'id_shop' => $feed->idShop,
            'id_feed' => $feed->id,
            'id_product' => $idProduct,
            'id_product_attribute' => $idProductAttribute,
            'checked_at' => $now,
        ];

        if ($offer !== null) {
            $local['title'] = mb_substr($offer->title(), 0, 255);
            $local['local_issues'] = json_encode($offer->issues, JSON_UNESCAPED_UNICODE);
        }

        if (!$sends) {
            // Bez wysylki: tylko stan lokalny. Oferta spoza filtrow, ktorej Google nie zna, znika.
            if (!$inFilters && !self::inGoogle($previous)) {
                $this->forget($previous);

                return self::LOCAL;
            }

            if ($offer !== null) {
                $local['offer_id'] = $offer->offerId();
                $local['sync_state'] = $offer->excluded ? 'excluded' : (self::inGoogle($previous) ? (string) $previous['sync_state'] : 'pending');
                $local['sync_error'] = $offer->excluded ? $offer->reason : (string) ($previous['sync_error'] ?? '');
            }

            $this->save($previous, $local);

            return self::LOCAL;
        }

        try {
            // Zmieniony identyfikator oferty (prefiks, zrodlo ID) = w Google to inny produkt:
            // stary trzeba usunac, inaczej zostalby sierota w koncie.
            if (self::inGoogle($previous) && $offer !== null && (string) $previous['offer_id'] !== $offer->offerId()) {
                $this->api->delete($feed, (string) $previous['offer_id']);
                $previous['sync_state'] = 'removed';
                $previous['payload_hash'] = '';
            }

            $action = SyncPlan::decide($previous, $wanted, $offer?->hash ?? '', $offer?->coreHash ?? '');

            switch ($action) {
                case SyncPlan::ACTION_INSERT:
                    $this->api->insert($feed, $offer->input);
                    $this->save($previous, $local + [
                        'offer_id' => $offer->offerId(),
                        'payload_hash' => $offer->hash,
                        'core_hash' => $offer->coreHash,
                        'sync_state' => 'synced',
                        'sync_error' => '',
                        'synced_at' => $now,
                        'google_status' => self::inGoogle($previous) ? (string) ($previous['google_status'] ?? '') : 'pending',
                    ]);

                    return self::SENT;

                case SyncPlan::ACTION_PATCH:
                    $this->api->patch($feed, $offer->input);
                    $this->save($previous, $local + [
                        'offer_id' => $offer->offerId(),
                        'payload_hash' => $offer->hash,
                        'sync_state' => 'synced',
                        'sync_error' => '',
                        'synced_at' => $now,
                    ]);

                    return self::PATCHED;

                case SyncPlan::ACTION_DELETE:
                    $this->api->delete($feed, (string) $previous['offer_id']);

                    if (!$inFilters) {
                        $this->forget($previous);
                    } else {
                        $this->save($previous, $local + [
                            'offer_id' => $offer->offerId(),
                            'payload_hash' => '',
                            'core_hash' => '',
                            'sync_state' => $offer->excluded ? 'excluded' : 'pending',
                            'sync_error' => $offer->excluded ? $offer->reason : '',
                            'google_status' => '',
                            'google_issues' => '',
                        ]);
                    }

                    return self::DELETED;

                case SyncPlan::ACTION_FORGET:
                    if (!$inFilters) {
                        $this->forget($previous);
                    } else {
                        $this->save($previous, $local + [
                            'offer_id' => $offer->offerId(),
                            'sync_state' => $offer->excluded ? 'excluded' : 'pending',
                            'sync_error' => $offer->excluded ? $offer->reason : '',
                        ]);
                    }

                    return self::LOCAL;

                default:
                    $this->save($previous, $local);

                    return self::UNCHANGED;
            }
        } catch (GoogleAuthException|NotConnectedException $e) {
            throw $e;
        } catch (GoogleApiException $e) {
            if ($e->isQuota() || MerchantAccount::reasonOf($e) === 'GCP_NOT_REGISTERED') {
                throw $e;
            }

            $this->save($previous, $local + [
                'offer_id' => $offer?->offerId() ?? (string) ($previous['offer_id'] ?? ''),
                'sync_state' => 'error',
                'sync_error' => mb_substr($e->getMessage(), 0, 500),
            ]);

            return self::FAILED;
        }
    }

    /**
     * Jedno uruchomienie kolejki (cron `products`, przycisk "Sync now").
     *
     * @return array{done:int,sent:int,deleted:int,failed:int,waiting:bool}
     */
    public function processQueue(int $idShop, float $deadline, int $limit = 0): array
    {
        $limit = $limit > 0 ? $limit : Options::int(MerchantOptions::BATCH, MerchantOptions::DEFAULT_BATCH, $idShop);
        $stats = ['done' => 0, 'sent' => 0, 'deleted' => 0, 'failed' => 0, 'waiting' => false];
        $feeds = [];

        foreach ($this->queue->due($idShop, $limit) as $item) {
            if (microtime(true) > $deadline) {
                $stats['waiting'] = true;

                break;
            }

            $idFeed = (int) $item['id_feed'];
            $feeds[$idFeed] ??= $this->feeds->find($idFeed);
            $feed = $feeds[$idFeed];

            if ($feed === null) {
                $this->queue->done((int) $item['id_queue']);

                continue;
            }

            try {
                $result = $this->evaluate($feed, (int) $item['id_product'], (int) $item['id_product_attribute']);
            } catch (GoogleApiException $e) {
                if ($e->isQuota()) {
                    // Limit Google: nie liczymy proby, cala reszta czeka do kolejnego crona.
                    $this->queue->postpone((int) $item['id_queue'], $e->retryAfterSeconds() ?? 300);
                    $stats['waiting'] = true;

                    break;
                }

                throw $e;
            }

            ++$stats['done'];

            if ($result === self::FAILED) {
                ++$stats['failed'];
                $this->queue->fail((int) $item['id_queue'], (int) $item['attempts'] + 1, (string) ($this->row($feed->id, (int) $item['id_product'], (int) $item['id_product_attribute'])['sync_error'] ?? ''));

                continue;
            }

            if ($result === self::SENT || $result === self::PATCHED) {
                ++$stats['sent'];
            } elseif ($result === self::DELETED) {
                ++$stats['deleted'];
            }

            $this->queue->done((int) $item['id_queue']);
        }

        foreach ($feeds as $feed) {
            if ($feed !== null && $feed->isActive() && $stats['done'] > 0) {
                $this->feeds->log($feed->idShop, $feed->id, $stats['failed'] ? 'warning' : 'info', 'Sync: delta', [
                    'mode' => 'delta', 'sent' => $stats['sent'], 'deleted' => $stats['deleted'], 'errors' => $stats['failed'],
                ]);
            }
        }

        return $stats;
    }

    /**
     * Oferty feedu, ktore nie zostaly ocenione od `$since` (pelna synchronizacja, etap
     * "sprzatania": produkty, ktore wypadly z filtrow albo zniknely ze sklepu).
     *
     * @return array<int,array{id_product:int,id_product_attribute:int}>
     */
    public function staleRows(Feed $feed, string $since, int $limit): array
    {
        $rows = Db::getInstance()->executeS('SELECT `id_product`, `id_product_attribute` FROM `' . CoreSchema::table(Schema::FEED_PRODUCT) . '`
            WHERE `id_feed` = ' . $feed->id . ' AND `checked_at` IS NULL
            ORDER BY `id_feed_product` ASC LIMIT ' . max(1, $limit));

        return array_map(static fn (array $r): array => ['id_product' => (int) $r['id_product'], 'id_product_attribute' => (int) $r['id_product_attribute']], is_array($rows) ? $rows : []);
    }

    /**
     * Poczatek pelnej oceny feedu: wszystkie oferty "nieocenione w tym przebiegu" (NULL).
     * Etap B bierze to, co po etapie A dalej ma NULL — bez porownywania czasu z dokladnoscia
     * do sekundy (dwa przebiegi w tej samej sekundzie gubily oferty do usuniecia).
     */
    public function startRun(Feed $feed): void
    {
        Db::getInstance()->update(CoreSchema::name(Schema::FEED_PRODUCT), ['checked_at' => null], '`id_feed` = ' . $feed->id, 0, true);
    }

    public function staleCount(Feed $feed, string $since): int
    {
        return (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . CoreSchema::table(Schema::FEED_PRODUCT) . '`
            WHERE `id_feed` = ' . $feed->id . ' AND `checked_at` IS NULL');
    }

    /**
     * Liczniki feedu do Overview — z ostatniej oceny ofert (`feed_product`).
     *
     * @return array<string,int>
     */
    public function counts(Feed $feed): array
    {
        $row = Db::getInstance()->getRow('SELECT
                SUM(`sync_state` <> "excluded") AS matched,
                SUM(`sync_state` <> "excluded" AND IFNULL(`local_issues`, "") NOT LIKE \'%"level":"error"%\') AS ready,
                SUM(`sync_state` <> "excluded" AND IFNULL(`local_issues`, "") LIKE \'%"level":"error"%\') AS issues,
                SUM(`sync_state` <> "excluded" AND IFNULL(`local_issues`, "") NOT IN ("", "[]")) AS flagged,
                SUM(`sync_state` = "excluded") AS excluded,
                SUM(`sync_state` = "synced") AS synced,
                SUM(`sync_state` = "error") AS failed,
                SUM(`sync_state` IN ("synced", "error") AND `google_status` = "approved") AS approved,
                SUM(`sync_state` IN ("synced", "error") AND `google_status` = "limited") AS limited,
                SUM(`sync_state` IN ("synced", "error") AND `google_status` = "disapproved") AS disapproved,
                SUM(`sync_state` IN ("synced", "error") AND `google_status` IN ("pending", "")) AS pending,
                SUM(`sync_state` = "pending" AND IFNULL(`local_issues`, "") LIKE \'%"level":"error"%\') AS waiting_issues
            FROM `' . CoreSchema::table(Schema::FEED_PRODUCT) . '` WHERE `id_feed` = ' . $feed->id);

        $out = [];

        foreach (['matched', 'ready', 'issues', 'flagged', 'excluded', 'synced', 'failed', 'approved', 'limited', 'disapproved', 'pending', 'waiting_issues'] as $key) {
            $out[$key] = (int) ($row[$key] ?? 0);
        }

        return $out;
    }

    /**
     * Najczestsze problemy: z Google (po wysylce) albo z kontroli lokalnej (wersja robocza).
     *
     * @return array<int,array{level:string,message:string,count:int}>
     */
    public function topIssues(Feed $feed, bool $google, int $limit = 5): array
    {
        $column = $google ? 'google_issues' : 'local_issues';
        $rows = Db::getInstance()->executeS('SELECT `' . $column . '` AS issues FROM `' . CoreSchema::table(Schema::FEED_PRODUCT) . '`
            WHERE `id_feed` = ' . $feed->id . ' AND `sync_state` <> "excluded" AND `' . $column . '` IS NOT NULL AND `' . $column . '` NOT IN ("", "[]")');
        $counts = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $issues = json_decode((string) $row['issues'], true);

            foreach (is_array($issues) ? $issues : [] as $issue) {
                $level = (string) ($issue['level'] ?? ($issue['severity'] ?? 'warning'));
                $message = (string) ($issue['message'] ?? '');

                if ($message === '' || $level === 'suggestion') {
                    continue;
                }

                $key = $level . '|' . $message;
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        arsort($counts);
        $out = [];

        foreach (array_slice($counts, 0, $limit, true) as $key => $count) {
            [$level, $message] = explode('|', $key, 2);
            $out[] = ['level' => in_array($level, ['critical', 'error'], true) ? 'critical' : 'warning', 'message' => $message, 'count' => $count];
        }

        return $out;
    }

    /**
     * "Send again": nastepna ocena zrobi pelny `insert` niezaleznie od hasha. Oferta zostaje
     * oznaczona jako znana Google (stan `error` z pustym hashem = "wyslij ponownie").
     */
    public function forceResend(int $idFeedProduct): void
    {
        Db::getInstance()->update(CoreSchema::name(Schema::FEED_PRODUCT), ['payload_hash' => '', 'core_hash' => '', 'sync_state' => 'synced', 'sync_error' => ''], '`id_feed_product` = ' . $idFeedProduct);
    }

    /** Pamiec flag "w filtrach" — czyszczona przy zmianie filtrow i miedzy partiami. */
    public function resetCache(): void
    {
        $this->inFilters = [];
        $this->builder->reset();
    }

    /** @return array<string,mixed>|null */
    public function row(int $idFeed, int $idProduct, int $idProductAttribute): ?array
    {
        $row = Db::getInstance()->getRow('SELECT * FROM `' . CoreSchema::table(Schema::FEED_PRODUCT) . '`
            WHERE `id_feed` = ' . $idFeed . ' AND `id_product` = ' . $idProduct . ' AND `id_product_attribute` = ' . $idProductAttribute);

        return is_array($row) && $row ? $row : null;
    }

    /** @return array<int,bool> */
    private function filterState(Feed $feed, int $idProduct): array
    {
        $key = $feed->id . '.' . $idProduct;

        if (count($this->inFilters) > 2000) {
            $this->inFilters = [];
        }

        return $this->inFilters[$key] ??= $this->catalog->offersOfProduct($feed, $idProduct);
    }

    /** @param array<string,mixed>|null $row */
    private static function inGoogle(?array $row): bool
    {
        return $row !== null && in_array((string) $row['sync_state'], ['synced', 'error'], true);
    }

    /**
     * @param array<string,mixed>|null $previous
     * @param array<string,mixed> $fields
     */
    private function save(?array $previous, array $fields): void
    {
        $data = [];

        foreach ($fields as $key => $value) {
            $data[$key] = is_int($value) ? $value : pSQL((string) $value, true);
        }

        if ($previous !== null) {
            Db::getInstance()->update(CoreSchema::name(Schema::FEED_PRODUCT), $data, '`id_feed_product` = ' . (int) $previous['id_feed_product']);

            return;
        }

        $data += ['offer_id' => '', 'title' => '', 'sync_state' => 'pending'];
        Db::getInstance()->insert(CoreSchema::name(Schema::FEED_PRODUCT), $data, false, true, Db::INSERT_IGNORE);
    }

    /** @param array<string,mixed>|null $previous */
    private function forget(?array $previous): void
    {
        if ($previous !== null) {
            Db::getInstance()->delete(CoreSchema::name(Schema::FEED_PRODUCT), '`id_feed_product` = ' . (int) $previous['id_feed_product']);
        }
    }
}
