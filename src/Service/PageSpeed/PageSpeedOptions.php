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

/**
 * Klucze ustawien uslugi (per sklep) — Page speed. Odczyt i zapis przez `Core\Options`.
 *
 * Lista z ARCHITEKTURA §7; od fali 1 plik nalezy do agenta H.
 */
final class PageSpeedOptions
{
    /** weekly | daily | manual */
    public const SCHEDULE = 'QUINCE_INSIGHT_PSI_SCHEDULE';

    public const LAST_RUN = 'QUINCE_INSIGHT_PSI_LAST_RUN';

    /** 1 = domyslna lista stron do testu jest juz w tabeli `psi_page` (od tej chwili rzadzi tabela). */
    public const PAGES_READY = 'QUINCE_INSIGHT_PSI_PAGES_READY';

    public const DEFAULT_SCHEDULE = 'weekly';

    public const SCHEDULES = ['weekly', 'daily', 'manual'];
}
