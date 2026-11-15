<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\MerchantTools;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Db;
use Quince\Insight\Core\Schema as CoreSchema;

/**
 * Reguly feedu w tabeli `rule` (ARCHITEKTURA §7.7).
 *
 * `id_feed = 0` — regula dla wszystkich feedow sklepu. Kolejnosc: `position` malejaco
 * ("Rules with higher priority run first"), przy rownym priorytecie starsza pierwsza.
 */
final class RuleRepository
{
    /** @var array<string,array<int,array<string,mixed>>> */
    private array $memo = [];

    /**
     * Reguly obowiazujace w feedzie (wlasne feedu + wspolne), opcjonalnie jednego typu bazy.
     *
     * @return array<int,array<string,mixed>>
     */
    public function forFeed(int $idShop, int $idFeed, ?string $dbType = null, bool $onlyActive = false): array
    {
        $key = $idShop . ':' . $idFeed;

        if (!isset($this->memo[$key])) {
            $this->memo[$key] = Db::getInstance()->executeS(
                'SELECT * FROM `' . CoreSchema::table(Schema::RULE) . '`
                 WHERE `id_shop` = ' . $idShop . ' AND `id_feed` IN (0, ' . $idFeed . ')
                 ORDER BY `position` DESC, `id_rule` ASC'
            ) ?: [];
        }

        $rows = $this->memo[$key];

        return array_values(array_filter($rows, static fn (array $r): bool => ($dbType === null || $r['type'] === $dbType)
            && (!$onlyActive || (int) $r['active'] === 1)));
    }

    /** @return array<string,mixed>|null */
    public function find(int $idShop, int $idRule): ?array
    {
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . CoreSchema::table(Schema::RULE) . '`
             WHERE `id_shop` = ' . $idShop . ' AND `id_rule` = ' . $idRule
        );

        return is_array($row) && $row ? $row : null;
    }

    /**
     * Zapis reguly po `RuleDefinitions::normalize()`. Zwraca id.
     *
     * @param array<string,mixed> $rule
     */
    public function save(int $idShop, int $idRule, int $idFeed, array $rule): int
    {
        $db = Db::getInstance();
        $data = [
            'type' => pSQL((string) $rule['type']),
            'name' => pSQL((string) $rule['name']),
            'conditions' => pSQL((string) json_encode($rule['conditions'], JSON_UNESCAPED_UNICODE), true),
            'actions' => pSQL((string) json_encode((object) $rule['actions'], JSON_UNESCAPED_UNICODE), true),
            'position' => (int) $rule['position'],
            'active' => $rule['active'] ? 1 : 0,
        ];

        $this->memo = [];

        if ($idRule > 0 && $this->find($idShop, $idRule)) {
            // Zasieg ("All feeds" / konkretny feed) zmienia sie z edytora (decyzja #14).
            $db->update(CoreSchema::name(Schema::RULE), $data + ['id_feed' => max(0, $idFeed)], '`id_shop` = ' . $idShop . ' AND `id_rule` = ' . $idRule);

            return $idRule;
        }

        $db->insert(CoreSchema::name(Schema::RULE), $data + ['id_shop' => $idShop, 'id_feed' => $idFeed]);

        return (int) $db->Insert_ID();
    }

    public function delete(int $idShop, int $idRule): bool
    {
        $this->memo = [];

        return (bool) Db::getInstance()->delete(CoreSchema::name(Schema::RULE), '`id_shop` = ' . $idShop . ' AND `id_rule` = ' . $idRule);
    }

    /**
     * Nowa kolejnosc: pierwsza na liscie dostaje najwyzszy priorytet.
     *
     * @param int[] $ids
     */
    public function sort(int $idShop, array $ids): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $count = count($ids);

        foreach ($ids as $i => $id) {
            Db::getInstance()->update(
                CoreSchema::name(Schema::RULE),
                ['position' => max(0, min(100, $count - $i))],
                '`id_shop` = ' . $idShop . ' AND `id_rule` = ' . $id
            );
        }

        $this->memo = [];
    }

    /** Kopia regul feedu do innego feedu (dla "Copy from another feed" agenta E). */
    public function copyFeed(int $idShop, int $fromFeed, int $toFeed): int
    {
        $n = 0;

        foreach ($this->forFeed($idShop, $fromFeed) as $row) {
            if ((int) $row['id_feed'] !== $fromFeed) {
                continue; // wspolne reguly (id_feed = 0) i tak obowiazuja w nowym feedzie
            }

            Db::getInstance()->insert(CoreSchema::name(Schema::RULE), [
                'id_shop' => $idShop,
                'id_feed' => $toFeed,
                'type' => pSQL((string) $row['type']),
                'name' => pSQL((string) $row['name']),
                'conditions' => pSQL((string) $row['conditions'], true),
                'actions' => pSQL((string) $row['actions'], true),
                'position' => (int) $row['position'],
                'active' => (int) $row['active'],
            ]);
            ++$n;
        }

        $this->memo = [];

        return $n;
    }

    /** Reguly tylko usunietego feedu (zasieg = ten feed, decyzja #14); wspolne zostaja. */
    public function deleteFeed(int $idShop, int $idFeed): void
    {
        if ($idFeed > 0) {
            Db::getInstance()->delete(CoreSchema::name(Schema::RULE), '`id_shop` = ' . $idShop . ' AND `id_feed` = ' . $idFeed);
        }

        $this->memo = [];
    }

    public function flush(): void
    {
        $this->memo = [];
    }
}
