<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Core;

use Quince\Insight\Core\Dto\Job;
use Quince\Insight\Core\Dto\JobProgress;

/**
 * Wykonawca zadania w tle. Rejestruje go usluga w `ServiceModule::jobs()`.
 *
 * Krok musi dac sie powtorzyc: przerwane zadanie (zamknieta karta, limit Google)
 * wznawia sie od stanu zwroconego przez ostatni udany krok.
 */
interface JobHandler
{
    /** Wykonuje jeden maly krok (<= ~8 s). Zwraca nowy stan. */
    public function step(Job $job): JobProgress;
}
