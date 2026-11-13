<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\SearchConsole\Job;

use Quince\Insight\Core\Dto\Job;
use Quince\Insight\Core\Dto\JobProgress;
use Quince\Insight\Core\JobHandler;
use Quince\Insight\Service\SearchConsole\SearchConsoleService;

/**
 * Zadanie `gsc.import`: wyniki dzienne z okresu (`from`, `to`) do `gsc_daily`.
 *
 * Okres dzielony na kawalki po 90 dni — jedno krotkie zapytanie na krok (limit obciazenia
 * Search Analytics liczy dlugie okresy drozej, research/05 §H.4). Wymiar tylko `date`,
 * wiec liczby sa takie jak sumy w Search Console (bez gubienia anonimowych zapytan).
 */
final class GscImportJob implements JobHandler
{
    public const TYPE = 'gsc.import';

    private const SLICE_DAYS = 90;

    public function __construct(private readonly SearchConsoleService $service)
    {
    }

    public function step(Job $job): JobProgress
    {
        $from = self::date((string) ($job->payload['from'] ?? ''), gmdate('Y-m-d', time() - SearchConsoleService::HISTORY_DAYS * 86400));
        $to = self::date((string) ($job->payload['to'] ?? ''), gmdate('Y-m-d', time() - 86400));
        $slices = self::slices($from, $to, self::SLICE_DAYS);
        $n = (int) ($job->state['slice'] ?? 0);
        $rows = (int) ($job->state['rows'] ?? 0);

        if (isset($slices[$n])) {
            $rows += $this->service->import($job->idShop, $slices[$n][0], $slices[$n][1]);
            ++$n;
        }

        $finished = $n >= count($slices);

        return new JobProgress(
            $n,
            max(1, count($slices)),
            $finished ? 'Search data imported — ' . $rows . ' days' : 'Importing search data',
            $finished,
            0,
            ['slice' => $n, 'rows' => $rows],
            $finished ? ['days' => $rows] : [],
        );
    }

    /**
     * Kawalki okresu, obie daty wlacznie (Search Console liczy daty wlacznie, research/05 §H.1).
     *
     * @return array<int,array{0:string,1:string}>
     */
    public static function slices(string $from, string $to, int $days): array
    {
        $start = strtotime($from . ' 00:00:00 UTC');
        $end = strtotime($to . ' 00:00:00 UTC');
        $out = [];

        while ($start !== false && $end !== false && $start <= $end) {
            $sliceEnd = min($end, $start + ($days - 1) * 86400);
            $out[] = [gmdate('Y-m-d', $start), gmdate('Y-m-d', $sliceEnd)];
            $start = $sliceEnd + 86400;
        }

        return $out;
    }

    private static function date(string $value, string $default): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : $default;
    }
}
