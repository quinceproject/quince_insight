<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\Events\Task;

use Quince\Insight\Service\Events\EventLog;
use Quince\Insight\Service\Events\EventsSettings;
use Quince\Insight\Service\Events\OrderLog;

/**
 * Zadanie `events.retention` (grupa `maintenance`): usuwa wpisy dziennika zdarzen starsze
 * niz „Keep for … days” i czysci tresc wysylek (`payload` z haszami danych klienta)
 * w dzienniku zamowien po tym samym czasie (ARCHITEKTURA §11). Same wiersze zamowien
 * zostaja — to stan wysylki, potrzebny do zwrotow.
 */
final class RetentionTask
{
    public function __construct(
        private readonly EventLog $log,
        private readonly OrderLog $orders,
        private readonly EventsSettings $settings,
    ) {
    }

    public function run(int $idShop): string
    {
        $events = $this->log->purgeOld($idShop);
        $payloads = $this->orders->clearPayloads($idShop, $this->settings->logKeepDays($idShop));

        return 'Removed ' . $events . ' event log entries, cleared ' . $payloads . ' order payloads.';
    }
}
