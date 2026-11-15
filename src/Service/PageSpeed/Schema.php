<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\PageSpeed;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Quince\Insight\Core\Dto\TableDefinition;

/**
 * Tabele uslugi (ARCHITEKTURA §7.9). Zaklada je i usuwa `Core\Schema`.
 *
 * Nazwy krotkie: pelna nazwa to `Core\Schema::table(Schema::X)` (surowy SQL) albo
 * `Core\Schema::name(Schema::X)` (Db::insert / update / delete). Od fali 1 plik nalezy
 * do agenta H. Kolumne dokladana po pierwszym wydaniu wpisac w definicje ORAZ
 * w czwarty argument `TableDefinition` (`added`).
 */
final class Schema
{
    public const PSI_PAGE = 'psi_page';
    public const PSI_RESULT = 'psi_result';

    /**
     * @return TableDefinition[]
     */
    public static function tables(): array
    {
        return [
            new TableDefinition(self::PSI_PAGE, '
                `id_psi_page` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT(11) UNSIGNED NOT NULL,
                `label` VARCHAR(60) NOT NULL DEFAULT "",
                `url` VARCHAR(500) NOT NULL DEFAULT "",
                `position` INT(11) UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`id_psi_page`),
                KEY `idx_shop_position` (`id_shop`, `position`)
            '),

            new TableDefinition(self::PSI_RESULT, '
                `id_psi_result` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT(11) UNSIGNED NOT NULL,
                `id_psi_page` INT(11) UNSIGNED NOT NULL DEFAULT 0 /* 0 = cala domena, CrUX */,
                `kind` VARCHAR(5) NOT NULL DEFAULT "" /* field | lab */,
                `strategy` VARCHAR(8) NOT NULL DEFAULT "" /* mobile | desktop */,
                `slot` VARCHAR(8) NOT NULL DEFAULT "" /* current | previous */,
                `score` SMALLINT(5) NULL,
                `metrics` LONGTEXT NULL /* JSON: p75, rozklad good/ni/poor, podpowiedzi */,
                `checked_at` DATETIME NULL,
                PRIMARY KEY (`id_psi_result`),
                UNIQUE KEY `uq_result` (`id_shop`, `id_psi_page`, `kind`, `strategy`, `slot`)
            '),
        ];
    }
}
