<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\MerchantTools\Dto;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Wynik zastosowania regul do produktu. Zmienione pola sa w samym `ProductData`.
 */
final class RuleResult
{
    /**
     * @param bool $excluded produkt wykluczony z feedu przez regule
     * @param string $reason nazwa reguly, ktora wykluczyla ('' gdy nie wykluczono)
     */
    public function __construct(
        public bool $excluded = false,
        public string $reason = '',
    ) {
    }
}
