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
use Quince\Insight\Core\Dto\Job;
use Quince\Insight\Core\Dto\JobProgress;
use Quince\Insight\Core\Google\GoogleApiException;
use Quince\Insight\Core\JobHandler;
use Quince\Insight\Core\Log;
use Quince\Insight\Core\Options;
use Quince\Insight\Core\ShopContext;

/**
 * Zadanie `pagespeed.check`: CrUX dla domeny (telefon, komputer), potem test PageSpeed
 * Insights kazdej strony z listy na obu urzadzeniach. Jedno wywolanie Google na krok,
 * wynik zapisany od razu — przerwane zadanie wznawia sie od nastepnej pozycji.
 *
 * Blad pojedynczej strony (zly adres, blad Lighthouse) nie zatrzymuje reszty; limit Google
 * oddaje `JobRunner`owi (krok wraca po odczekaniu), brak klucza konczy zadanie bledem.
 */
final class CheckJob implements JobHandler
{
    public const TYPE = 'pagespeed.check';

    public function __construct(
        private readonly PsiRepository $repository,
        private readonly PageSpeedApi $api,
    ) {
    }

    public function step(Job $job): JobProgress
    {
        $idShop = $job->idShop;
        $state = $job->state;

        if (!isset($state['units'])) {
            $this->repository->materialize($idShop);
            $state['units'] = $this->units($idShop);
            $state['next'] = 0;
            $state['results'] = [];
        }

        $units = (array) $state['units'];
        $next = (int) $state['next'];
        $total = count($units);

        if ($next < $total) {
            $unit = $units[$next];
            $state['results'][$next] = $this->run($idShop, $unit);
            $state['next'] = ++$next;
        }

        $finished = $next >= $total;
        $state['items'] = $this->items($units, $state['results'], $next);

        if ($finished) {
            Options::set(PageSpeedOptions::LAST_RUN, Clock::now(), $idShop);
        }

        $pages = count(array_filter($units, static fn (array $u): bool => $u['kind'] === PsiRepository::LAB)) / 2;

        return new JobProgress(
            $next,
            $total,
            $finished ? 'Checked ' . (int) $pages . ' pages on mobile and desktop' : ($units[$next]['text'] ?? ''),
            $finished,
            0,
            $state,
            $finished ? ['pages' => (int) $pages] : [],
        );
    }

    /**
     * Lista krokow: CrUX dla obu urzadzen, potem kazda strona na telefonie i komputerze.
     *
     * @return array<int,array{kind:string,strategy:string,page:int,url:string,text:string}>
     */
    private function units(int $idShop): array
    {
        $units = [];

        foreach (PsiRepository::STRATEGIES as $strategy) {
            $units[] = [
                'kind' => PsiRepository::FIELD,
                'strategy' => $strategy,
                'page' => 0,
                'url' => PageSpeedApi::origin(ShopContext::baseUrl($idShop)),
                'text' => 'Real visitors · ' . $strategy,
            ];
        }

        foreach ($this->repository->pages($idShop) as $page) {
            foreach (PsiRepository::STRATEGIES as $strategy) {
                $units[] = [
                    'kind' => PsiRepository::LAB,
                    'strategy' => $strategy,
                    'page' => $page['id'],
                    'url' => $page['url'],
                    'text' => $page['label'] . ' · ' . $strategy,
                ];
            }
        }

        return $units;
    }

    /**
     * @param array{kind:string,strategy:string,page:int,url:string,text:string} $unit
     *
     * @return array{st:string,note:string}
     */
    private function run(int $idShop, array $unit): array
    {
        try {
            if ($unit['kind'] === PsiRepository::FIELD) {
                $data = $this->api->crux($idShop, $unit['url'], $unit['strategy']);
                $this->repository->saveResult($idShop, 0, PsiRepository::FIELD, $unit['strategy'], null, $data ?? ['nodata' => true]);

                return ['st' => 'ok', 'note' => $data === null ? 'Not enough visitors for Chrome UX Report data' : ''];
            }

            $lab = $this->api->lab($idShop, $unit['url'], $unit['strategy']);
            $this->repository->saveResult($idShop, (int) $unit['page'], PsiRepository::LAB, $unit['strategy'], $lab['score'], $lab);

            return ['st' => $lab['error'] === '' ? 'ok' : 'err', 'note' => $lab['error']];
        } catch (GoogleApiException $e) {
            // Limit obsluguje JobRunner: chwilowy — krok wraca po odczekaniu, dobowy — koniec zadania.
            if ($e->isQuota()) {
                throw $e;
            }

            Log::write($idShop, 'pagespeed', Log::WARNING, 'Page speed check failed: ' . $e->getMessage(), ['url' => $unit['url'], 'kind' => $unit['kind']]);

            // Wylaczone API (np. wlaczony tylko PageSpeed Insights, bez CrUX) nie zatrzymuje
            // reszty listy — user widzi przy pozycji, co wlaczyc.
            $note = $e->isApiDisabled()
                ? ($unit['kind'] === PsiRepository::FIELD ? 'Chrome UX Report API is not enabled in the Google Cloud project' : 'PageSpeed Insights API is not enabled in the Google Cloud project')
                : mb_substr($e->getMessage(), 0, 200);

            return ['st' => 'err', 'note' => $note];
        }
    }

    /**
     * @param array<int,array<string,mixed>> $units
     * @param array<int|string,array{st:string,note:string}> $results
     *
     * @return array<int,array{text:string,st:string,note:string}>
     */
    private function items(array $units, array $results, int $next): array
    {
        $out = [];

        foreach ($units as $n => $unit) {
            $result = $results[$n] ?? null;
            $out[] = [
                'text' => (string) $unit['text'],
                'st' => $result['st'] ?? ($n === $next ? 'run' : 'wait'),
                'note' => (string) ($result['note'] ?? ''),
            ];
        }

        return $out;
    }
}
