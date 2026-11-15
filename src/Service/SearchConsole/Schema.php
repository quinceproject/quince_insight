<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\SearchConsole;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Quince\Insight\Core\Dto\TableDefinition;

/**
 * Tabele uslugi (ARCHITEKTURA §7.8). Zaklada je i usuwa `Core\Schema`.
 * Fala 3 (decyzja #20): bez tabeli `sitemap_exclude` — recznych wykluczen nie ma.
 *
 * Nazwy krotkie: pelna nazwa to `Core\Schema::table(Schema::X)` (surowy SQL) albo
 * `Core\Schema::name(Schema::X)` (Db::insert / update / delete). Od fali 1 plik nalezy
 * do agenta G. Kolumne dokladana po pierwszym wydaniu wpisac w definicje ORAZ
 * w czwarty argument `TableDefinition` (`added`).
 */
final class Schema
{
    public const SITEMAP_FILE = 'sitemap_file';
    public const GSC_DAILY = 'gsc_daily';

    /**
     * @return TableDefinition[]
     */
    public static function tables(): array
    {
        return [
            new TableDefinition(self::SITEMAP_FILE, '
                `id_sitemap_file` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT(11) UNSIGNED NOT NULL,
                `filename` VARCHAR(120) NOT NULL,
                `kind` VARCHAR(12) NOT NULL DEFAULT "" /* index | products | categories | cms | brands | suppliers | blog */,
                `id_lang` INT(11) UNSIGNED NOT NULL DEFAULT 0 /* 0 dla indeksu */,
                `url_count` INT(11) UNSIGNED NOT NULL DEFAULT 0,
                `bytes` INT(11) UNSIGNED NOT NULL DEFAULT 0,
                `generated_at` DATETIME NULL,
                `submitted_at` DATETIME NULL,
                `google_state` VARCHAR(12) NOT NULL DEFAULT "",
                `google_errors` INT(11) UNSIGNED NOT NULL DEFAULT 0,
                `google_warnings` INT(11) UNSIGNED NOT NULL DEFAULT 0,
                `google_checked_at` DATETIME NULL,
                PRIMARY KEY (`id_sitemap_file`),
                UNIQUE KEY `uq_shop_file` (`id_shop`, `filename`)
            '),

            new TableDefinition(self::GSC_DAILY, '
                `id_shop` INT(11) UNSIGNED NOT NULL,
                `day` DATE NOT NULL,
                `clicks` INT(11) UNSIGNED NOT NULL DEFAULT 0,
                `impressions` INT(11) UNSIGNED NOT NULL DEFAULT 0,
                `position_sum` DOUBLE NOT NULL DEFAULT 0 /* suma pozycja x wyswietlenia */,
                PRIMARY KEY (`id_shop`, `day`)
            '),
        ];
    }
}
