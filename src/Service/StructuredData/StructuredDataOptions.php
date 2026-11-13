<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\StructuredData;

/**
 * Klucze ustawien uslugi (per sklep) — dane strukturalne. Odczyt i zapis przez `Core\Options`.
 *
 * Lista z ARCHITEKTURA §7; od fali 1 plik nalezy do agenta H.
 */
final class StructuredDataOptions
{
    /** JSON: typ => 0/1 (organization, website, breadcrumb, product, itemlist, variants, shipping). */
    public const ON = 'QUINCE_INSIGHT_SD_ON';

    /** JSON typow wlaczonych mimo duplikatu. */
    public const FORCE = 'QUINCE_INSIGHT_SD_FORCE';

    public const SCANNED_AT = 'QUINCE_INSIGHT_SD_SCANNED_AT';
}
