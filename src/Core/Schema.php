<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Core;

use Db;
use Quince\Insight\Core\Dto\TableDefinition;

/**
 * Tabele modulu: zakladanie, uzupelnianie kolumn, usuwanie.
 *
 * Definicje tabel rdzenia sa tutaj; kazda usluga trzyma swoje we wlasnym
 * `Service\<Nazwa>\Schema` i oddaje je przez `ServiceModule::tables()`.
 * Wszystkie tabele: prefiks `{_DB_PREFIX_}quince_insight_`, bez kluczy obcych,
 * daty z PHP (`Clock::now()`), JSON w `LONGTEXT`.
 */
final class Schema
{
    public const PREFIX = 'quince_insight_';

    public const CONNECTION = 'connection';
    public const SHOP = 'shop';
    public const SETTING = 'setting';
    public const NOTICE = 'notice';
    public const OAUTH_STATE = 'oauth_state';
    public const JOB = 'job';
    public const CRON_GROUP = 'cron_group';
    public const TASK = 'task';
    public const CACHE = 'cache';
    public const API_LOG = 'api_log';

    public function __construct(private readonly ServiceRegistry $registry)
    {
    }

    /** Nazwa bez prefiksu bazy — do `Db::insert()`, `update()`, `delete()` i `DbQuery::from()`. */
    public static function name(string $short): string
    {
        return self::PREFIX . $short;
    }

    /** Pelna nazwa z prefiksem bazy — do surowego SQL (w odwrotnych apostrofach). */
    public static function table(string $short): string
    {
        return _DB_PREFIX_ . self::PREFIX . $short;
    }

    /**
     * Tabele rdzenia (ARCHITEKTURA §7.1).
     *
     * @return TableDefinition[]
     */
    public static function coreTables(): array
    {
        return [
            new TableDefinition(self::CONNECTION, '
                `id_connection` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `label` VARCHAR(120) NOT NULL DEFAULT "",
                `client_id` VARCHAR(255) NOT NULL DEFAULT "",
                `client_secret` TEXT NULL /* zaszyfrowane (Crypto) */,
                `api_key` TEXT NULL /* zaszyfrowane; puste gdy brak */,
                `production_confirmed` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                `refresh_token` TEXT NULL /* zaszyfrowane */,
                `access_token` TEXT NULL /* zaszyfrowane */,
                `access_expires_at` DATETIME NULL,
                `google_email` VARCHAR(255) NOT NULL DEFAULT "",
                `scopes` TEXT NULL /* przyznane, rozdzielone spacja */,
                `status` VARCHAR(20) NOT NULL DEFAULT "new" /* new | ok | needs_reauth | revoked */,
                `last_error` VARCHAR(500) NOT NULL DEFAULT "",
                `redirect_uri` VARCHAR(500) NOT NULL DEFAULT "" /* adres zwrotny z chwili polaczenia */,
                `connected_at` DATETIME NULL,
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_connection`)
            ', false),

            // Wiersz znika razem ze sklepem, ale usuwa go `ShopLifecycle` jawnie, nie petla po tabelach.
            new TableDefinition(self::SHOP, '
                `id_shop` INT(11) UNSIGNED NOT NULL,
                `id_connection` INT(11) UNSIGNED NOT NULL DEFAULT 0 /* 0 = brak */,
                `wizard_done` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                `wizard_step` VARCHAR(30) NOT NULL DEFAULT "" /* ostatni otwarty krok */,
                `wizard_state` LONGTEXT NULL /* JSON: krok => done|skipped|error|todo */,
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_shop`)
            '),

            new TableDefinition(self::SETTING, '
                `id_shop` INT(11) UNSIGNED NOT NULL,
                `name` VARCHAR(64) NOT NULL /* stala QUINCE_INSIGHT_* bez prefiksu */,
                `value` LONGTEXT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_shop`, `name`)
            '),

            // Informacje globalne na pulpicie; zostaja po usunieciu sklepu — o nim wlasnie mowia.
            new TableDefinition(self::NOTICE, '
                `id_notice` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `kind` VARCHAR(30) NOT NULL DEFAULT "",
                `title` VARCHAR(255) NOT NULL DEFAULT "",
                `body` LONGTEXT NULL /* JSON: lista pozycji */,
                `dismissed` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_notice`),
                KEY `idx_dismissed` (`dismissed`)
            ', false),

            new TableDefinition(self::OAUTH_STATE, '
                `state` CHAR(64) NOT NULL,
                `id_connection` INT(11) UNSIGNED NOT NULL DEFAULT 0,
                `id_shop` INT(11) UNSIGNED NOT NULL DEFAULT 0,
                `id_employee` INT(11) UNSIGNED NOT NULL DEFAULT 0,
                `scopes` TEXT NULL,
                `return_url` VARCHAR(1000) NOT NULL DEFAULT "",
                `expires_at` DATETIME NOT NULL,
                PRIMARY KEY (`state`),
                KEY `idx_shop` (`id_shop`)
            '),

            new TableDefinition(self::JOB, '
                `id_job` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT(11) UNSIGNED NOT NULL,
                `type` VARCHAR(60) NOT NULL,
                `status` VARCHAR(12) NOT NULL DEFAULT "pending" /* pending | running | done | failed | cancelled */,
                `done` INT(11) UNSIGNED NOT NULL DEFAULT 0,
                `total` INT(11) UNSIGNED NOT NULL DEFAULT 0,
                `message` VARCHAR(255) NOT NULL DEFAULT "",
                `payload` LONGTEXT NULL,
                `state` LONGTEXT NULL,
                `result` LONGTEXT NULL,
                `error` VARCHAR(1000) NOT NULL DEFAULT "",
                `not_before` DATETIME NULL,
                `locked_until` DATETIME NULL,
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_job`),
                KEY `idx_shop_type_status` (`id_shop`, `type`, `status`)
            '),

            new TableDefinition(self::CRON_GROUP, '
                `id_shop` INT(11) UNSIGNED NOT NULL,
                `group_key` VARCHAR(12) NOT NULL,
                `last_run` DATETIME NULL,
                `last_status` VARCHAR(10) NOT NULL DEFAULT "",
                `last_duration_ms` INT(11) UNSIGNED NOT NULL DEFAULT 0,
                `source` VARCHAR(8) NOT NULL DEFAULT "" /* cron | manual */,
                PRIMARY KEY (`id_shop`, `group_key`)
            '),

            new TableDefinition(self::TASK, '
                `id_shop` INT(11) UNSIGNED NOT NULL,
                `task_key` VARCHAR(60) NOT NULL,
                `last_run` DATETIME NULL,
                `last_status` VARCHAR(10) NOT NULL DEFAULT "" /* ok | error */,
                `last_message` VARCHAR(500) NOT NULL DEFAULT "",
                `last_duration_ms` INT(11) UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`id_shop`, `task_key`)
            '),

            new TableDefinition(self::CACHE, '
                `id_shop` INT(11) UNSIGNED NOT NULL,
                `cache_key` VARCHAR(120) NOT NULL,
                `payload` LONGTEXT NULL,
                `expires_at` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_shop`, `cache_key`)
            '),

            new TableDefinition(self::API_LOG, '
                `id_log` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT(11) UNSIGNED NOT NULL DEFAULT 0,
                `service` VARCHAR(30) NOT NULL DEFAULT "",
                `level` VARCHAR(10) NOT NULL DEFAULT "info" /* info | warning | error */,
                `message` VARCHAR(500) NOT NULL DEFAULT "",
                `http_method` VARCHAR(8) NOT NULL DEFAULT "",
                `url` VARCHAR(500) NOT NULL DEFAULT "",
                `http_code` SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
                `duration_ms` INT(11) UNSIGNED NOT NULL DEFAULT 0,
                `context` LONGTEXT NULL,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_log`),
                KEY `idx_shop_service_date` (`id_shop`, `service`, `date_add`)
            '),
        ];
    }

    /**
     * Wszystkie tabele modulu: rdzen, potem uslugi w kolejnosci rejestru.
     *
     * @return TableDefinition[]
     */
    public function all(): array
    {
        $out = self::coreTables();

        foreach ($this->registry->all() as $service) {
            foreach ($service->tables() as $definition) {
                $out[] = $definition;
            }
        }

        return $out;
    }

    /**
     * Nazwy krotkie tabel, z ktorych po usunieciu sklepu znikaja wiersze `id_shop = X`.
     *
     * @return string[]
     */
    public function perShopTables(): array
    {
        $out = [];

        foreach ($this->all() as $definition) {
            if ($definition->perShop) {
                $out[] = $definition->name;
            }
        }

        return $out;
    }

    /** Zaklada brakujace tabele i dokladane pozniej kolumny. Bezpieczne do powtarzania. */
    public function install(): bool
    {
        $db = Db::getInstance();

        foreach ($this->all() as $definition) {
            $sql = 'CREATE TABLE IF NOT EXISTS `' . self::table($definition->name) . '` ('
                . $definition->body
                . ') ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4';

            if (!$db->execute($sql)) {
                return false;
            }

            if ($definition->added && !$this->addMissingColumns($definition)) {
                return false;
            }
        }

        return true;
    }

    /** Usuwa wszystkie tabele modulu, w odwrotnej kolejnosci. */
    public function drop(): bool
    {
        $db = Db::getInstance();
        $ok = true;

        foreach (array_reverse($this->all()) as $definition) {
            $ok = $db->execute('DROP TABLE IF EXISTS `' . self::table($definition->name) . '`') && $ok;
        }

        return $ok;
    }

    /**
     * `CREATE TABLE IF NOT EXISTS` nie rusza istniejacej tabeli, wiec kolumny dolozone
     * po pierwszym wydaniu trzeba dopisac osobno — tu, przy instalacji i aktualizacji.
     */
    private function addMissingColumns(TableDefinition $definition): bool
    {
        $db = Db::getInstance();
        $existing = [];

        foreach ($db->executeS('SHOW COLUMNS FROM `' . self::table($definition->name) . '`') ?: [] as $row) {
            $existing[(string) $row['Field']] = true;
        }

        foreach ($definition->added as $column => $ddl) {
            if (isset($existing[$column])) {
                continue;
            }

            $sql = 'ALTER TABLE `' . self::table($definition->name) . '` ADD COLUMN `' . bqSQL($column) . '` ' . $ddl;

            if (!$db->execute($sql)) {
                return false;
            }
        }

        return true;
    }
}
