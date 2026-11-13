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

use Quince\Insight\Core\Dto\TableDefinition;

/**
 * Tabele uslugi (ARCHITEKTURA §7.7). Zaklada je i usuwa `Core\Schema`.
 *
 * Nazwy krotkie: pelna nazwa to `Core\Schema::table(Schema::X)` (surowy SQL) albo
 * `Core\Schema::name(Schema::X)` (Db::insert / update / delete). Od fali 1 plik nalezy
 * do agenta M. Kolumne dokladana po pierwszym wydaniu wpisac w definicje ORAZ
 * w czwarty argument `TableDefinition` (`added`).
 */
final class Schema
{
    public const CATEGORY_MAP = 'category_map';
    public const GOOGLE_TAXONOMY = 'google_taxonomy';
    public const ATTRIBUTE_MAP = 'attribute_map';
    public const RULE = 'rule';
    public const PROMOTION = 'promotion';

    /**
     * @return TableDefinition[]
     */
    public static function tables(): array
    {
        return [
            new TableDefinition(self::CATEGORY_MAP, '
                `id_shop` INT(11) UNSIGNED NOT NULL,
                `id_category` INT(11) UNSIGNED NOT NULL,
                `google_id` INT(11) UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`id_shop`, `id_category`)
            '),

            new TableDefinition(self::GOOGLE_TAXONOMY, '
                `google_id` INT(11) UNSIGNED NOT NULL,
                `lang` VARCHAR(5) NOT NULL,
                `path` VARCHAR(500) NOT NULL DEFAULT "",
                PRIMARY KEY (`google_id`, `lang`),
                KEY `idx_lang` (`lang`)
            ', false),

            new TableDefinition(self::ATTRIBUTE_MAP, '
                `id_shop` INT(11) UNSIGNED NOT NULL,
                `target` VARCHAR(30) NOT NULL /* color | size | material | pattern | gender | age_group | ... */,
                `source_type` VARCHAR(10) NOT NULL DEFAULT "" /* attribute | feature | fixed */,
                `source_id` INT(11) UNSIGNED NOT NULL DEFAULT 0,
                `fixed_value` VARCHAR(120) NOT NULL DEFAULT "",
                PRIMARY KEY (`id_shop`, `target`)
            '),

            new TableDefinition(self::RULE, '
                `id_rule` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT(11) UNSIGNED NOT NULL,
                `id_feed` INT(11) UNSIGNED NOT NULL DEFAULT 0 /* 0 = wszystkie feedy */,
                `type` VARCHAR(12) NOT NULL DEFAULT "" /* exclude | price | label | identifier */,
                `name` VARCHAR(120) NOT NULL DEFAULT "",
                `conditions` LONGTEXT NULL /* JSON: pola i operatory 1:1 z FeedForge */,
                `actions` LONGTEXT NULL,
                `position` INT(11) UNSIGNED NOT NULL DEFAULT 0,
                `active` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
                PRIMARY KEY (`id_rule`),
                KEY `idx_shop_feed_position` (`id_shop`, `id_feed`, `position`)
            '),

            new TableDefinition(self::PROMOTION, '
                `id_promotion` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT(11) UNSIGNED NOT NULL,
                `id_feed` INT(11) UNSIGNED NOT NULL,
                `id_cart_rule` INT(11) UNSIGNED NOT NULL,
                `promotion_id` VARCHAR(60) NOT NULL DEFAULT "",
                `status` VARCHAR(12) NOT NULL DEFAULT "draft" /* draft | sent | error | ended */,
                `payload` LONGTEXT NULL,
                `last_error` VARCHAR(500) NOT NULL DEFAULT "",
                `google_status` VARCHAR(12) NOT NULL DEFAULT "" /* active | pending | rejected | ended — stan z Google */,
                `payload_hash` CHAR(40) NOT NULL DEFAULT "" /* odcisk wyslanej tresci; "" = do ponownej wysylki */,
                `synced_at` DATETIME NULL,
                PRIMARY KEY (`id_promotion`),
                UNIQUE KEY `uq_feed_rule` (`id_feed`, `id_cart_rule`),
                KEY `idx_shop` (`id_shop`)
            '),
        ];
    }
}
