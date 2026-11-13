<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\Merchant\Task;

use Quince\Insight\Core\Google\GoogleApiException;
use Quince\Insight\Core\Google\NotConnectedException;
use Quince\Insight\Service\Merchant\MerchantAccount;
use Quince\Insight\Service\Merchant\StatusRefresher;

/**
 * Zadanie `merchant.statuses` (grupa `statuses`, co 3 godziny): statusy produktow,
 * problemy konta, stan przejecia adresu.
 */
final class StatusTask
{
    private const SECONDS = 40;

    public function __construct(private readonly StatusRefresher $statuses)
    {
    }

    public function run(int $idShop): string
    {
        try {
            $result = $this->statuses->run($idShop, microtime(true) + self::SECONDS);
        } catch (NotConnectedException $e) {
            return 'Not connected to Google.';
        } catch (GoogleApiException $e) {
            return 'Stopped: ' . (MerchantAccount::reasonOf($e) ?: $e->getMessage());
        }

        return sprintf('%d product statuses updated from %d pages%s.', $result['updated'], $result['pages'], $result['complete'] ? '' : ', continues next run');
    }
}
