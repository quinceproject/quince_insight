<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Core\Dto;

/**
 * Definicja jednej tabeli modulu.
 *
 * Usluga oddaje liste takich obiektow w `ServiceModule::tables()`, a `Core\Schema`
 * zaklada je przy instalacji, uzupelnia kolumny przy aktualizacji i usuwa przy
 * odinstalowaniu. Nikt poza `Schema` nie pisze `CREATE TABLE`.
 */
final class TableDefinition
{
    /**
     * @param string $name nazwa krotka, bez prefiksu bazy i bez `quince_insight_` (np. `order_log`)
     * @param string $body kolumny i klucze — wnetrze nawiasu `CREATE TABLE (...)`
     * @param bool $perShop tabela ma kolumne `id_shop`; jej wiersze znikaja po usunieciu sklepu
     * @param array<string,string> $added kolumny dolozone po pierwszym wydaniu: nazwa => definicja
     *                                    (wpisac takze w $body; `CREATE TABLE IF NOT EXISTS` nie zmienia istniejacej tabeli)
     */
    public function __construct(
        public string $name,
        public string $body,
        public bool $perShop = true,
        public array $added = [],
    ) {
    }
}
