<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\TagManager;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Db;
use Quince\Insight\Core\Clock;
use Quince\Insight\Core\Schema as CoreSchema;

/**
 * Tabela `gtm_entity`: co modul utworzyl w kontenerze (identyfikatory GTM per nasz klucz).
 *
 * To jest podstawa idempotencji: wiersz zapisuje sie zaraz po kazdym udanym wywolaniu,
 * jeszcze przed zapisem stanu zadania — wznowione zadanie nie tworzy encji drugi raz.
 *
 * Testy jednostkowe: `useMemory()` (bez bazy).
 */
final class EntityStore
{
    /** @var array<string,array<string,array<string,string>>>|null pamiec testow: "shop|container" => key => wiersz */
    private static ?array $memory = null;

    public static function useMemory(): void
    {
        self::$memory = [];
    }

    public static function useDatabase(): void
    {
        self::$memory = null;
    }

    /**
     * @return array<string,array{kind:string,key:string,gtm_id:string,name:string,fingerprint:string,gtm_fingerprint:string}> klucz encji => wiersz
     */
    public function all(int $idShop, string $container): array
    {
        if (self::$memory !== null) {
            return self::$memory[$idShop . '|' . $container] ?? [];
        }

        $rows = Db::getInstance()->executeS(
            'SELECT `kind`, `entity_key`, `gtm_id`, `name`, `fingerprint`, `gtm_fingerprint`
             FROM `' . CoreSchema::table(Schema::GTM_ENTITY) . '`
             WHERE `id_shop` = ' . $idShop . ' AND `container` = "' . pSQL($container) . '"'
        ) ?: [];

        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row['entity_key']] = [
                'kind' => (string) $row['kind'],
                'key' => (string) $row['entity_key'],
                'gtm_id' => (string) $row['gtm_id'],
                'name' => (string) $row['name'],
                'fingerprint' => (string) $row['fingerprint'],
                'gtm_fingerprint' => (string) $row['gtm_fingerprint'],
            ];
        }

        return $out;
    }

    public function save(int $idShop, string $container, string $kind, string $key, string $gtmId, string $name, string $fingerprint, string $gtmFingerprint): void
    {
        $row = [
            'kind' => $kind,
            'key' => $key,
            'gtm_id' => $gtmId,
            'name' => $name,
            'fingerprint' => $fingerprint,
            'gtm_fingerprint' => $gtmFingerprint,
        ];

        if (self::$memory !== null) {
            self::$memory[$idShop . '|' . $container][$key] = $row;

            return;
        }

        $sql = 'INSERT INTO `' . CoreSchema::table(Schema::GTM_ENTITY) . '`
            (`id_shop`, `container`, `kind`, `entity_key`, `gtm_id`, `name`, `fingerprint`, `gtm_fingerprint`, `date_upd`)
            VALUES (' . $idShop . ', "' . pSQL($container) . '", "' . pSQL($kind) . '", "' . pSQL($key) . '", "' . pSQL($gtmId) . '",
                    "' . pSQL(mb_substr($name, 0, 160)) . '", "' . pSQL($fingerprint) . '", "' . pSQL(mb_substr($gtmFingerprint, 0, 40)) . '", "' . pSQL(Clock::now()) . '")
            ON DUPLICATE KEY UPDATE `gtm_id` = VALUES(`gtm_id`), `name` = VALUES(`name`), `fingerprint` = VALUES(`fingerprint`),
                `gtm_fingerprint` = VALUES(`gtm_fingerprint`), `date_upd` = VALUES(`date_upd`)';

        Db::getInstance()->execute($sql);
    }

    public function remove(int $idShop, string $container, string $kind, string $key): void
    {
        if (self::$memory !== null) {
            unset(self::$memory[$idShop . '|' . $container][$key]);

            return;
        }

        Db::getInstance()->delete(
            CoreSchema::name(Schema::GTM_ENTITY),
            '`id_shop` = ' . $idShop . ' AND `container` = "' . pSQL($container) . '" AND `kind` = "' . pSQL($kind) . '" AND `entity_key` = "' . pSQL($key) . '"'
        );
    }

    /** Liczba encji modulu w kontenerze (karta kontenera, bez wywolania Google). */
    public function count(int $idShop, string $container): int
    {
        return count($this->all($idShop, $container));
    }
}
