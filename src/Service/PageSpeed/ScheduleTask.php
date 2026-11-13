<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\PageSpeed;

use Quince\Insight\Core\Clock;
use Quince\Insight\Core\Google\ConnectionRepository;
use Quince\Insight\Core\JobRunner;
use Quince\Insight\Core\Options;

/**
 * Zadanie cron `pagespeed.schedule` (grupa `maintenance`): sprawdzenie wedlug harmonogramu
 * (co tydzien / codziennie / recznie).
 *
 * Test PageSpeed jednej strony trwa do kilkudziesieciu sekund, wiec cron nie robi calosci
 * naraz: zaczyna zadanie `pagespeed.check` i wykonuje tyle krokow, ile miesci sie w jego
 * budzecie. Reszte dokanczaja kolejne wywolania cronow (`orders`, `products`, `all`
 * popychaja niedokonczone zadania) albo otwarty ekran Page speed.
 */
final class ScheduleTask
{
    /** Budzet tego zadania w cronie (s) — CronTask::maxSeconds ma zapas na jeden test. */
    private const BUDGET = 25;

    public function __construct(
        private readonly JobRunner $jobs,
        private readonly ConnectionRepository $connections,
    ) {
    }

    public function run(int $idShop): string
    {
        $schedule = Options::str(PageSpeedOptions::SCHEDULE, PageSpeedOptions::DEFAULT_SCHEDULE, $idShop);

        if ($schedule === 'manual') {
            return 'Page speed runs only on demand.';
        }

        $connection = $this->connections->forShop($idShop);

        if ($connection === null || !$connection->hasApiKey) {
            return 'Page speed skipped: no Google API key.';
        }

        $active = $this->jobs->active($idShop, CheckJob::TYPE);

        if ($active === null && !self::isDue($schedule, Clock::secondsSince(Options::str(PageSpeedOptions::LAST_RUN, '', $idShop) ?: null))) {
            return 'Page speed is up to date.';
        }

        $idJob = $active !== null ? $active->id : $this->jobs->start($idShop, CheckJob::TYPE, ['source' => 'cron']);
        $deadline = microtime(true) + self::BUDGET;
        $status = $this->jobs->status($idJob);

        while ($status->isActive() && $status->waitSeconds === 0 && microtime(true) < $deadline) {
            $before = $status->done;
            $status = $this->jobs->tick($idJob);

            // Krok trzyma ktos inny (otwarty ekran) — nie kreci sie w miejscu.
            if ($status->isActive() && $status->done === $before) {
                break;
            }
        }

        return $status->isActive()
            ? 'Page speed check in progress: ' . $status->done . ' of ' . $status->total . '.'
            : 'Page speed check ' . $status->status . '.';
    }

    /**
     * Czy czas na sprawdzenie. Godzina zapasu, zeby cron puszczany codziennie o tej samej
     * porze nie przesuwal sprawdzenia o dobe przez kilka minut roznicy.
     */
    public static function isDue(string $schedule, ?int $secondsSinceLastRun): bool
    {
        if ($schedule === 'manual') {
            return false;
        }

        if ($secondsSinceLastRun === null) {
            return true;
        }

        $period = $schedule === 'daily' ? 86400 : 7 * 86400;

        return $secondsSinceLastRun >= $period - 3600;
    }
}
