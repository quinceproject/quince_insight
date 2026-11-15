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

if (!defined('_PS_VERSION_')) {
    exit;
}

use Quince\Insight\Service\Events\ServerEvents;

/**
 * Zadanie `events.orders` (grupa `orders`, co 10 minut): zakupy i zwroty, ktore nie
 * zmiescily sie w 3 s przy zamowieniu, oraz automatyczne dosylanie brakujacych zamowien
 * (ARCHITEKTURA §12 pkt 10).
 */
final class OrdersTask
{
    public function __construct(private readonly ServerEvents $server)
    {
    }

    public function run(int $idShop): string
    {
        return $this->server->runCron($idShop);
    }
}
