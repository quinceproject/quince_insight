<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\SearchConsole\Task;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Quince\Insight\Core\Clock;
use Quince\Insight\Service\SearchConsole\SearchConsoleService;

/**
 * Zadanie `searchconsole.import` (grupa `maintenance`, raz na dobe): ostatnie 10 dni wynikow.
 *
 * Okno 10 dni, bo Google daje dane po 2–3 dniach, a dni juz zapisane po prostu sie nadpisuja
 * (research/05 §H.1). Brak uslugi = nic do zrobienia, nie blad.
 */
final class ImportTask
{
    public function __construct(private readonly SearchConsoleService $service)
    {
    }

    public function run(int $idShop): string
    {
        if ($this->service->site($idShop) === '') {
            return 'No Search Console property chosen.';
        }

        $to = gmdate('Y-m-d', Clock::time() - 86400);
        $from = gmdate('Y-m-d', Clock::time() - SearchConsoleService::DAILY_WINDOW * 86400);
        $days = $this->service->import($idShop, $from, $to);

        return 'Search data imported: ' . $days . ' days (' . $from . ' – ' . $to . ').';
    }
}
