<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\TagManager\Dto;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Wszystko, od czego zalezy plan kontenera — i nic poza tym. Plan jest czysta funkcja
 * tego obiektu (`ContainerPlan::build`), wiec te same ustawienia daja ten sam hash.
 */
final class PlanInput
{
    /**
     * @param string $measurementId `G-...` ('' = brak, plan bez tagow GA4)
     * @param string[] $events wlaczone zdarzenia (kolejnosc bez znaczenia — plan ja ustala)
     * @param bool $ads usluga Google Ads wlaczona w sklepie
     * @param string $adsConversionId `AW-...` albo same cyfry
     */
    public function __construct(
        public string $measurementId,
        public array $events,
        public bool $ads = false,
        public string $adsConversionId = '',
        public string $adsConversionLabel = '',
        public bool $adsRemarketing = false,
        public bool $adsEnhanced = false,
    ) {
    }
}
