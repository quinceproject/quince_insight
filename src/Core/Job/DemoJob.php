<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Core\Job;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Quince\Insight\Core\Dto\Job;
use Quince\Insight\Core\Dto\JobProgress;
use Quince\Insight\Core\JobHandler;

/**
 * Zadanie przykladowe `core.demo` — wzor dla uslug i sprawdzian silnika zadan.
 *
 * Nic nie robi poza odliczaniem krokow. Uruchamia sie wylacznie z ekranu deweloperskiego
 * (`quince_insight_dev`), ktory istnieje tylko przy pliku `config/dev.php`.
 *
 * Payload: `steps` (domyslnie 6), `wait` (sekundy przerwy po kazdym kroku, domyslnie 0),
 * `fail_at` (numer kroku, na ktorym zadanie ma sie wywrocic — do sprawdzenia obslugi bledu).
 */
final class DemoJob implements JobHandler
{
    public function step(Job $job): JobProgress
    {
        $total = max(1, min(50, (int) ($job->payload['steps'] ?? 6)));
        $wait = max(0, min(30, (int) ($job->payload['wait'] ?? 0)));
        $failAt = (int) ($job->payload['fail_at'] ?? 0);
        $done = (int) ($job->state['done'] ?? 0) + 1;

        if ($failAt > 0 && $done === $failAt) {
            throw new \RuntimeException('Demo job failed on purpose at step ' . $done . '.');
        }

        // Udawana praca — krok prawdziwego zadania to np. jedno wywolanie Google.
        usleep(250000);

        $finished = $done >= $total;

        // Lista krokow pod paskiem postepu (`state['items']` — jedyna czesc stanu widoczna w panelu).
        $items = [];

        for ($n = 1; $n <= $total; ++$n) {
            $items[] = [
                'text' => 'Demo step ' . $n,
                'st' => $n <= $done ? 'ok' : ($n === $done + 1 ? 'run' : 'wait'),
            ];
        }

        return new JobProgress(
            $done,
            $total,
            $finished ? 'Demo job finished' : 'Demo step ' . $done . ' of ' . $total,
            $finished,
            $finished ? 0 : $wait,
            ['done' => $done, 'items' => $items],
            $finished ? ['steps' => $done] : [],
        );
    }
}
