<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\Events;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Quince\Insight\Core\Dto\TableDefinition;

/**
 * Tabele uslugi (ARCHITEKTURA §7.4). Zaklada je i usuwa `Core\Schema`.
 *
 * Nazwy krotkie: pelna nazwa to `Core\Schema::table(Schema::X)` (surowy SQL) albo
 * `Core\Schema::name(Schema::X)` (Db::insert / update / delete). Od fali 1 plik nalezy
 * do agenta C. Kolumne dokladana po pierwszym wydaniu wpisac w definicje ORAZ
 * w czwarty argument `TableDefinition` (`added`).
 */
final class Schema
{
    public const ORDER_LOG = 'order_log';
    public const REFUND_LOG = 'refund_log';
    public const EVENT_LOG = 'event_log';

    /**
     * @return TableDefinition[]
     */
    public static function tables(): array
    {
        return [
            new TableDefinition(self::ORDER_LOG, '
                `id_order_log` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT(11) UNSIGNED NOT NULL,
                `id_order` INT(11) UNSIGNED NOT NULL,
                `reference` VARCHAR(32) NOT NULL DEFAULT "" /* = transaction_id zawsze */,
                `total` DECIMAL(20,6) NOT NULL DEFAULT 0,
                `currency` CHAR(3) NOT NULL DEFAULT "",
                `ga_client_id` VARCHAR(64) NOT NULL DEFAULT "" /* pelna wartosc z ciasteczka _ga; pusta gdy brak */,
                `ga_session_id` VARCHAR(64) NOT NULL DEFAULT "" /* z ciasteczka _ga_<ID> */,
                `consent` VARCHAR(255) NOT NULL DEFAULT "" /* JSON 4 sygnalow w chwili zamowienia */,
                `source` VARCHAR(500) NOT NULL DEFAULT "" /* JSON: utm, gclid, referrer */,
                `browser_at` DATETIME NULL /* kiedy strona potwierdzenia wypisala purchase */,
                `server_status` VARCHAR(10) NOT NULL DEFAULT "none" /* none | sent | failed | skipped */,
                `server_reason` VARCHAR(40) NOT NULL DEFAULT "" /* np. no_consent, no_session, no_secret, http_4xx */,
                `server_at` DATETIME NULL,
                `attempts` TINYINT(3) UNSIGNED NOT NULL DEFAULT 0 /* proby wysylki z serwera (cron ponawia bledy sieci) */,
                `payload` LONGTEXT NULL /* co wyslano do GA4 */,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_order_log`),
                UNIQUE KEY `uq_shop_order` (`id_shop`, `id_order`),
                KEY `idx_shop_date` (`id_shop`, `date_add`)
            '),

            new TableDefinition(self::REFUND_LOG, '
                `id_refund_log` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT(11) UNSIGNED NOT NULL,
                `id_order` INT(11) UNSIGNED NOT NULL,
                `id_order_slip` INT(11) UNSIGNED NOT NULL,
                `amount` DECIMAL(20,6) NOT NULL DEFAULT 0,
                `currency` CHAR(3) NOT NULL DEFAULT "",
                `items` LONGTEXT NULL,
                `status` VARCHAR(10) NOT NULL DEFAULT "none",
                `reason` VARCHAR(40) NOT NULL DEFAULT "",
                `sent_at` DATETIME NULL,
                `attempts` TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
                `payload` LONGTEXT NULL,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_refund_log`),
                UNIQUE KEY `uq_shop_slip` (`id_shop`, `id_order`, `id_order_slip`) /* id_order_slip = 0: zwrot calosci po statusie */,
                KEY `idx_shop_order` (`id_shop`, `id_order`)
            '),

            new TableDefinition(self::EVENT_LOG, '
                `id_event_log` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT(11) UNSIGNED NOT NULL,
                `event` VARCHAR(40) NOT NULL DEFAULT "",
                `source` VARCHAR(8) NOT NULL DEFAULT "" /* browser | server */,
                `id_order` INT(11) UNSIGNED NULL,
                `page` VARCHAR(255) NOT NULL DEFAULT "",
                `payload` LONGTEXT NULL,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_event_log`),
                KEY `idx_shop_date` (`id_shop`, `date_add`),
                KEY `idx_shop_event` (`id_shop`, `event`)
            '),
        ];
    }
}
