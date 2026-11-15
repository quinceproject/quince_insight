<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\MerchantTools\Task;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Quince\Insight\Service\MerchantTools\PromotionService;

/**
 * Zadanie `merchanttools.promotions` (grupa `statuses`): zmienione reguly koszyka wysylane ponownie,
 * usuniete / wylaczone / przeterminowane konczone data, stany promocji odswiezane z Google.
 */
final class PromotionsTask
{
    public function __construct(private readonly PromotionService $promotions)
    {
    }

    public function run(int $idShop): string
    {
        return $this->promotions->sync($idShop);
    }
}
