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
 * Feedy sklepu (kontrakt dla agenta M, ARCHITEKTURA §7.6) i dziennik feedu.
 *
 * Unikalnosc kraj + jezyk w sklepie pilnuje modul (indeks UNIQUE i `exists()` przed zapisem):
 * zrodla danych nie izoluja produktow — klucz `lang~label~offerId` jest w skali konta
 * (ARCHITEKTURA §12 pkt 4).
 */
final class FeedRepository
{
    public const STATUSES = [Feed::STATUS_DRAFT, Feed::STATUS_ACTIVE, Feed::STATUS_PAUSED];
    public const DESCRIPTION_SOURCES = ['description_short', 'description', 'both'];
    public const ID_SOURCES = ['id', 'reference', 'ean'];

    public function find(int $idFeed): ?Feed
    {
        if ($idFeed <= 0) {
            return null;
        }

        $row = Db::getInstance()->getRow('SELECT f.*, c.`iso_code` AS currency_iso FROM `' . CoreSchema::table(Schema::FEED) . '` f
            LEFT JOIN `' . _DB_PREFIX_ . 'currency` c ON c.`id_currency` = f.`id_currency`
            WHERE f.`id_feed` = ' . $idFeed);

        return is_array($row) && $row ? self::hydrate($row) : null;
    }

    /** Feed tylko wtedy, gdy nalezy do sklepu (id z adresu nie moze siegnac do cudzego sklepu). */
    public function findInShop(int $idShop, int $idFeed): ?Feed
    {
        $feed = $this->find($idFeed);

        return $feed !== null && $feed->idShop === $idShop ? $feed : null;
    }

    /** @return Feed[] */
    public function forShop(int $idShop): array
    {
        $rows = Db::getInstance()->executeS('SELECT f.*, c.`iso_code` AS currency_iso FROM `' . CoreSchema::table(Schema::FEED) . '` f
            LEFT JOIN `' . _DB_PREFIX_ . 'currency` c ON c.`id_currency` = f.`id_currency`
            WHERE f.`id_shop` = ' . $idShop . ' ORDER BY f.`id_feed` ASC');

        return array_map([self::class, 'hydrate'], is_array($rows) ? $rows : []);
    }

    /** Feedy, ktore wysylaja (stan `active`). @return Feed[] */
    public function activeForShop(int $idShop): array
    {
        return array_values(array_filter($this->forShop($idShop), static fn (Feed $f): bool => $f->isActive()));
    }

    public function exists(int $idShop, string $country, int $idLang, int $exceptId = 0): bool
    {
        return (bool) Db::getInstance()->getValue('SELECT 1 FROM `' . CoreSchema::table(Schema::FEED) . '`
            WHERE `id_shop` = ' . $idShop . ' AND `country` = "' . pSQL(strtoupper($country)) . '" AND `id_lang` = ' . $idLang
            . ($exceptId > 0 ? ' AND `id_feed` <> ' . $exceptId : ''));
    }

    /**
     * @param array<string,mixed> $data pola jak w `normalize()`
     */
    public function create(int $idShop, array $data): int
    {
        $now = pSQL(Clock::now());
        $row = self::normalize($data) + [
            'id_shop' => $idShop,
            'status' => Feed::STATUS_DRAFT,
            'data_source' => '',
            'only_ready' => 1,
            'filters' => pSQL((string) json_encode(FeedFilters::defaults(), JSON_UNESCAPED_UNICODE), true),
            'date_add' => $now,
            'date_upd' => $now,
        ];

        Db::getInstance()->insert(CoreSchema::name(Schema::FEED), $row);

        return (int) Db::getInstance()->Insert_ID();
    }

    /**
     * Zmiana ustawien feedu (zakladka What is sent → Feed settings). Kraj i jezyk sa staly —
     * zrodlo danych w Google ma niezmienne `feedLabel` i `contentLanguage`.
     *
     * @param array<string,mixed> $data
     */
    public function updateSettings(int $idFeed, array $data): void
    {
        $row = self::normalize($data);
        unset($row['country'], $row['id_lang'], $row['content_language'], $row['feed_label'], $row['name']);
        $row['date_upd'] = pSQL(Clock::now());

        Db::getInstance()->update(CoreSchema::name(Schema::FEED), $row, '`id_feed` = ' . $idFeed);
    }

    /** @param array<string,mixed> $filters */
    public function saveFilters(int $idFeed, array $filters): void
    {
        Db::getInstance()->update(CoreSchema::name(Schema::FEED), [
            'filters' => pSQL((string) json_encode(FeedFilters::normalize($filters), JSON_UNESCAPED_UNICODE), true),
            'date_upd' => pSQL(Clock::now()),
        ], '`id_feed` = ' . $idFeed);
    }

    public function setStatus(int $idFeed, string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Unknown feed status.');
        }

        Db::getInstance()->update(CoreSchema::name(Schema::FEED), ['status' => pSQL($status), 'date_upd' => pSQL(Clock::now())], '`id_feed` = ' . $idFeed);
    }

    public function setOnlyReady(int $idFeed, bool $onlyReady): void
    {
        Db::getInstance()->update(CoreSchema::name(Schema::FEED), ['only_ready' => $onlyReady ? 1 : 0, 'date_upd' => pSQL(Clock::now())], '`id_feed` = ' . $idFeed);
    }

    public function setDataSource(int $idFeed, string $name): void
    {
        Db::getInstance()->update(CoreSchema::name(Schema::FEED), ['data_source' => pSQL($name), 'date_upd' => pSQL(Clock::now())], '`id_feed` = ' . $idFeed);
    }

    public function touchFullSync(int $idFeed): void
    {
        Db::getInstance()->update(CoreSchema::name(Schema::FEED), ['last_full_sync' => pSQL(Clock::now())], '`id_feed` = ' . $idFeed);
    }

    public function touchStatusSync(int $idFeed): void
    {
        Db::getInstance()->update(CoreSchema::name(Schema::FEED), ['last_status_sync' => pSQL(Clock::now())], '`id_feed` = ' . $idFeed);
    }

    /** Usuwa feed lokalnie razem z jego stanem produktow, kolejka i dziennikiem. */
    public function delete(int $idFeed): void
    {
        $db = Db::getInstance();

        foreach ([Schema::FEED_PRODUCT, Schema::FEED_QUEUE, Schema::FEED_LOG, Schema::FEED] as $table) {
            $db->delete(CoreSchema::name($table), '`id_feed` = ' . $idFeed);
        }
    }

    /** @param array<string,mixed> $context */
    public function log(int $idShop, int $idFeed, string $level, string $message, array $context = []): void
    {
        Db::getInstance()->insert(CoreSchema::name(Schema::FEED_LOG), [
            'id_shop' => $idShop,
            'id_feed' => $idFeed,
            'level' => pSQL($level),
            'message' => pSQL(mb_substr($message, 0, 500), true),
            'context' => pSQL((string) json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true),
            'date_add' => pSQL(Clock::now()),
        ]);
    }

    /** Ostatni wpis dziennika feedu danego rodzaju (np. podsumowanie synchronizacji). @return array<string,mixed>|null */
    public function lastLog(int $idFeed, string $messageLike = ''): ?array
    {
        $row = Db::getInstance()->getRow('SELECT * FROM `' . CoreSchema::table(Schema::FEED_LOG) . '` WHERE `id_feed` = ' . $idFeed
            . ($messageLike !== '' ? ' AND `message` LIKE "' . pSQL($messageLike) . '%"' : '')
            . ' ORDER BY `id_feed_log` DESC');

        if (!is_array($row) || !$row) {
            return null;
        }

        $context = json_decode((string) $row['context'], true);
        $row['context'] = is_array($context) ? $context : [];

        return $row;
    }

    /**
     * Pola zapisu po walidacji (bez stanu i filtrow).
     *
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     */
    private static function normalize(array $data): array
    {
        $out = [];

        if (isset($data['country'])) {
            $out['country'] = pSQL(strtoupper(substr((string) $data['country'], 0, 2)));
            $out['feed_label'] = pSQL(strtoupper(substr((string) ($data['feed_label'] ?? $data['country']), 0, 20)));
        }

        if (isset($data['id_lang'])) {
            $out['id_lang'] = (int) $data['id_lang'];
            $out['content_language'] = pSQL(strtolower(substr((string) ($data['content_language'] ?? ''), 0, 5)));
        }

        if (isset($data['name'])) {
            $out['name'] = pSQL(mb_substr((string) $data['name'], 0, 120));
        }

        if (isset($data['id_currency'])) {
            $out['id_currency'] = (int) $data['id_currency'];
        }

        if (array_key_exists('with_tax', $data)) {
            $out['with_tax'] = filter_var($data['with_tax'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }

        if (isset($data['title_template'])) {
            $template = trim((string) $data['title_template']);
            $out['title_template'] = pSQL(mb_substr($template !== '' ? $template : '{name}', 0, 255));
        }

        if (isset($data['description_source'])) {
            $out['description_source'] = in_array($data['description_source'], self::DESCRIPTION_SOURCES, true) ? $data['description_source'] : 'description_short';
        }

        if (isset($data['offer_prefix'])) {
            $out['offer_prefix'] = pSQL(mb_substr(trim((string) $data['offer_prefix']), 0, 20));
        }

        if (isset($data['id_source'])) {
            $out['id_source'] = in_array($data['id_source'], self::ID_SOURCES, true) ? $data['id_source'] : 'id';
        }

        return $out;
    }

    /** @param array<string,mixed> $row */
    private static function hydrate(array $row): Feed
    {
        $filters = json_decode((string) ($row['filters'] ?? ''), true);

        return new Feed(
            (int) $row['id_feed'],
            (int) $row['id_shop'],
            (string) $row['name'],
            (string) $row['country'],
            (int) $row['id_lang'],
            (string) $row['content_language'],
            (string) $row['feed_label'],
            (int) $row['id_currency'],
            (bool) $row['with_tax'],
            (string) $row['title_template'],
            (string) $row['description_source'],
            (string) $row['offer_prefix'],
            (string) $row['id_source'],
            (string) $row['data_source'],
            (string) $row['status'],
            (bool) $row['only_ready'],
            FeedFilters::normalize(is_array($filters) ? $filters : []),
            $row['last_full_sync'] ? (string) $row['last_full_sync'] : null,
            $row['last_status_sync'] ? (string) $row['last_status_sync'] : null,
            (string) ($row['currency_iso'] ?? ''),
        );
    }
}
