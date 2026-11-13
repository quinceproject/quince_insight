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

use Quince\Insight\Core\Dto\TableDefinition;

/**
 * Tabele uslugi (ARCHITEKTURA §7.6). Zaklada je i usuwa `Core\Schema`.
 *
 * Nazwy krotkie: pelna nazwa to `Core\Schema::table(Schema::X)` (surowy SQL) albo
 * `Core\Schema::name(Schema::X)` (Db::insert / update / delete). Od fali 1 plik nalezy
 * do agenta E. Kolumne dokladana po pierwszym wydaniu wpisac w definicje ORAZ
 * w czwarty argument `TableDefinition` (`added`).
 */
final class Schema
{
    public const FEED = 'feed';
    public const FEED_PRODUCT = 'feed_product';
    public const PRODUCT_DIRTY = 'product_dirty';
    public const FEED_QUEUE = 'feed_queue';
    public const FEED_LOG = 'feed_log';

    /**
     * @return TableDefinition[]
     */
    public static function tables(): array
    {
        return [
            new TableDefinition(self::FEED, '
                `id_feed` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT(11) UNSIGNED NOT NULL,
                `name` VARCHAR(120) NOT NULL DEFAULT "",
                `country` CHAR(2) NOT NULL DEFAULT "",
                `id_lang` INT(11) UNSIGNED NOT NULL DEFAULT 0,
                `content_language` VARCHAR(5) NOT NULL DEFAULT "",
                `feed_label` VARCHAR(20) NOT NULL DEFAULT "" /* domyslnie kod kraju */,
                `id_currency` INT(11) UNSIGNED NOT NULL DEFAULT 0,
                `with_tax` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
                `title_template` VARCHAR(255) NOT NULL DEFAULT "",
                `description_source` VARCHAR(20) NOT NULL DEFAULT "description_short" /* description_short | description | both (wartosci z prototypu) */,
                `offer_prefix` VARCHAR(20) NOT NULL DEFAULT "",
                `id_source` VARCHAR(12) NOT NULL DEFAULT "id" /* id | reference | ean */,
                `data_source` VARCHAR(120) NOT NULL DEFAULT "" /* accounts/x/dataSources/y */,
                `status` VARCHAR(8) NOT NULL DEFAULT "draft" /* draft | active | paused */,
                `only_ready` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 /* wysylaj tylko gotowe */,
                `filters` LONGTEXT NULL /* JSON — wszystkie grupy filtrow z zakladki What is sent */,
                `last_full_sync` DATETIME NULL,
                `last_status_sync` DATETIME NULL,
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_feed`),
                UNIQUE KEY `uq_shop_country_lang` (`id_shop`, `country`, `id_lang`)
            '),

            new TableDefinition(self::FEED_PRODUCT, '
                `id_feed_product` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT(11) UNSIGNED NOT NULL,
                `id_feed` INT(11) UNSIGNED NOT NULL,
                `id_product` INT(11) UNSIGNED NOT NULL,
                `id_product_attribute` INT(11) UNSIGNED NOT NULL DEFAULT 0 /* 0 = bez kombinacji */,
                `offer_id` VARCHAR(64) NOT NULL DEFAULT "",
                `title` VARCHAR(255) NOT NULL DEFAULT "" /* do list i wyszukiwania */,
                `payload_hash` CHAR(40) NOT NULL DEFAULT "",
                `core_hash` CHAR(40) NOT NULL DEFAULT "" /* hash bez ceny i dostepnosci — insert albo patch */,
                `sync_state` VARCHAR(10) NOT NULL DEFAULT "pending" /* pending | synced | error | excluded | removed */,
                `sync_error` VARCHAR(500) NOT NULL DEFAULT "",
                `local_issues` LONGTEXT NULL /* JSON problemow wykrytych przed wysylka */,
                `google_status` VARCHAR(12) NOT NULL DEFAULT "" /* approved | limited | disapproved | pending | puste */,
                `google_issues` LONGTEXT NULL,
                `manual_exclude` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                `synced_at` DATETIME NULL,
                `status_at` DATETIME NULL,
                `checked_at` DATETIME NULL /* ostatnia ocena oferty (budowa + filtry + reguly) */,
                PRIMARY KEY (`id_feed_product`),
                UNIQUE KEY `uq_feed_product` (`id_feed`, `id_product`, `id_product_attribute`),
                KEY `idx_shop_product` (`id_shop`, `id_product`),
                KEY `idx_feed_sync` (`id_feed`, `sync_state`),
                KEY `idx_feed_google` (`id_feed`, `google_status`)
            '),

            new TableDefinition(self::PRODUCT_DIRTY, '
                `id_shop` INT(11) UNSIGNED NOT NULL,
                `id_product` INT(11) UNSIGNED NOT NULL,
                `reason` VARCHAR(12) NOT NULL DEFAULT "save" /* save | stock | price | delete */,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_shop`, `id_product`)
            '),

            new TableDefinition(self::FEED_QUEUE, '
                `id_queue` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT(11) UNSIGNED NOT NULL,
                `id_feed` INT(11) UNSIGNED NOT NULL,
                `id_product` INT(11) UNSIGNED NOT NULL,
                `id_product_attribute` INT(11) UNSIGNED NOT NULL DEFAULT 0,
                `action` VARCHAR(8) NOT NULL DEFAULT "upsert" /* upsert | delete */,
                `offer_id` VARCHAR(64) NOT NULL DEFAULT "" /* dla delete */,
                `priority` TINYINT(3) UNSIGNED NOT NULL DEFAULT 5 /* 1 = najwyzszy */,
                `attempts` TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
                `available_at` DATETIME NOT NULL,
                `last_error` VARCHAR(500) NOT NULL DEFAULT "",
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_queue`),
                UNIQUE KEY `uq_queue` (`id_feed`, `id_product`, `id_product_attribute`, `action`),
                KEY `idx_shop_feed_order` (`id_shop`, `id_feed`, `priority`, `id_queue`)
            '),

            new TableDefinition(self::FEED_LOG, '
                `id_feed_log` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT(11) UNSIGNED NOT NULL,
                `id_feed` INT(11) UNSIGNED NOT NULL DEFAULT 0,
                `level` VARCHAR(10) NOT NULL DEFAULT "info",
                `message` VARCHAR(500) NOT NULL DEFAULT "",
                `context` LONGTEXT NULL,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_feed_log`),
                KEY `idx_shop_feed_date` (`id_shop`, `id_feed`, `date_add`)
            '),
        ];
    }
}
