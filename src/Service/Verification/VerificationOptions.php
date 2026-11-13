<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\Verification;

/**
 * Klucze ustawien uslugi (per sklep) — weryfikacja witryny. Odczyt i zapis przez `Core\Options`.
 *
 * Lista z ARCHITEKTURA §7; od fali 1 plik nalezy do agenta A.
 */
final class VerificationOptions
{
    /** Tresc atrybutu `content` tagu meta weryfikacji. */
    public const META = 'QUINCE_INSIGHT_VERIFY_META';

    /** none | pending | verified | manual */
    public const STATE = 'QUINCE_INSIGHT_VERIFY_STATE';

    /** Kiedy zweryfikowano. */
    public const AT = 'QUINCE_INSIGHT_VERIFY_AT';

    /** Zweryfikowany adres. */
    public const SITE = 'QUINCE_INSIGHT_VERIFY_SITE';
}
