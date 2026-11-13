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
use Quince\Insight\Service\Merchant\FeedQueue;
use Quince\Insight\Service\Merchant\FeedRepository;
use Quince\Insight\Service\Merchant\FeedSync;
use Quince\Insight\Service\Merchant\MerchantAccount;

/**
 * Zadanie `merchant.queue` (grupa `products`, co 15 minut): oznaczenia z hookow → kolejka
 * ofert kazdego feedu → wysylka zmian (tryb roznicowy). Wersje robocze aktualizuja tylko
 * kontrole lokalna, feedy wstrzymane czekaja w kolejce.
 */
final class QueueTask
{
    private const SECONDS = 35;

    public function __construct(
        private readonly FeedRepository $feeds,
        private readonly FeedQueue $queue,
        private readonly FeedSync $sync,
    ) {
    }

    public function run(int $idShop): string
    {
        $deadline = microtime(true) + self::SECONDS;
        $feeds = $this->feeds->forShop($idShop);

        if ($feeds === []) {
            return 'No feeds.';
        }

        $expanded = $this->queue->expandDirty($idShop, $feeds);

        try {
            $stats = $this->sync->processQueue($idShop, $deadline);
        } catch (NotConnectedException $e) {
            return 'Not connected to Google.';
        } catch (GoogleApiException $e) {
            return 'Stopped: ' . (MerchantAccount::reasonOf($e) ?: $e->getMessage());
        }

        return sprintf('%d changed products, %d offers processed, %d sent, %d removed, %d failed%s.',
            $expanded, $stats['done'], $stats['sent'], $stats['deleted'], $stats['failed'], $stats['waiting'] ? ', more waiting' : '');
    }
}
