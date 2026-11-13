<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Wizard;

/**
 * Klucze ustawien kreatora (per sklep). Odczyt i zapis przez `Core\Options`.
 */
final class WizardOptions
{
    /**
     * Biezace przejscie kreatora (JSON): sklepy, tryb, wybrane polaczenie, sklep-zrodlo kopii.
     * Zapisane w kazdym sklepie przejscia — kreator wznawia sie z kontekstu dowolnego z nich.
     */
    public const RUN = 'QUINCE_INSIGHT_WIZARD_RUN';

    /**
     * Sklep, z ktorego user kazal skopiowac ustawienia ("Copy settings from another shop").
     * Ustawienia zdarzen, zgod i mapowania kopiuje kreator; filtry pierwszego feedu
     * moze stad przejac krok Merchant Center.
     */
    public const COPY_FROM = 'QUINCE_INSIGHT_WIZARD_COPY_FROM';
}
