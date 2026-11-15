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

use Quince\Insight\Core\Dto\TableDefinition;

/**
 * Tabele uslugi (ARCHITEKTURA §7.5). Zaklada je i usuwa `Core\Schema`.
 *
 * Nazwy krotkie: pelna nazwa to `Core\Schema::table(Schema::X)` (surowy SQL) albo
 * `Core\Schema::name(Schema::X)` (Db::insert / update / delete). Od fali 1 plik nalezy
 * do agenta D. Kolumne dokladana po pierwszym wydaniu wpisac w definicje ORAZ
 * w czwarty argument `TableDefinition` (`added`).
 */
final class Schema
{
    public const GTM_ENTITY = 'gtm_entity';

    /**
     * @return TableDefinition[]
     */
    public static function tables(): array
    {
        return [
            new TableDefinition(self::GTM_ENTITY, '
                `id_gtm_entity` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT(11) UNSIGNED NOT NULL,
                `container` VARCHAR(64) NOT NULL DEFAULT "" /* sciezka accounts/x/containers/y */,
                `kind` VARCHAR(16) NOT NULL DEFAULT "" /* tag | trigger | variable | builtin | folder */,
                `entity_key` VARCHAR(80) NOT NULL DEFAULT "" /* nasz staly klucz, np. tag.ga4.purchase */,
                `gtm_id` VARCHAR(20) NOT NULL DEFAULT "",
                `name` VARCHAR(160) NOT NULL DEFAULT "",
                `fingerprint` CHAR(40) NOT NULL DEFAULT "" /* hash oczekiwanej definicji */,
                `gtm_fingerprint` VARCHAR(40) NOT NULL DEFAULT "" /* fingerprint encji w GTM po naszym zapisie — wykrywa reczne zmiany */,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_gtm_entity`),
                UNIQUE KEY `uq_entity` (`id_shop`, `container`, `kind`, `entity_key`)
            '),
        ];
    }
}
