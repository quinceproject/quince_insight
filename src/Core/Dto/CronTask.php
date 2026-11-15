<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Core\Dto;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Zadanie cykliczne uslugi — wpis w harmonogramie (`Core\Scheduler`).
 */
final class CronTask
{
    /**
     * @param string $key unikalny klucz, np. `merchant.queue`
     * @param string $group orders | products | statuses | sitemap | maintenance
     * @param string $handlerClass klasa z metoda `run(int $idShop): string` (komunikat do dziennika)
     * @param int $minMinutes najmniejszy odstep miedzy uruchomieniami (ochrona przed zbyt czestym cronem)
     * @param int $maxSeconds ile zadanie moze zajac; harmonogram nie zaczyna go, gdy zostalo mniej budzetu
     */
    public function __construct(
        public string $key,
        public string $group,
        public string $handlerClass,
        public int $minMinutes = 0,
        public int $maxSeconds = 20,
    ) {
    }
}
