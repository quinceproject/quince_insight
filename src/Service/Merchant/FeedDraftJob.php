<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\Merchant;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Quince\Insight\Core\Dto\Job;
use Quince\Insight\Core\Dto\JobProgress;
use Quince\Insight\Core\JobHandler;

/**
 * Zadanie `merchant.draft` — "Create draft feed" w kroku kreatora `gmc` (R-09, prototyp
 * `taskLines('gmc')`): 1. zrodlo danych w Merchant Center, 2. zapis feedu jako wersji
 * roboczej (bez zadnego produktu). Pasek postepu = wspolny `job_progress`.
 *
 * Payload: wynik `FeedForm::validate()` (`data`). Krok 1 jest jedynym wywolaniem Google
 * (`FeedForm::createDraft` — blad Google = nic nie zostaje lokalnie, ponowienie bezpieczne,
 * bo zrodlo o tej nazwie jest najpierw szukane). Krok 2 to stan lokalny i kontrola lokalna.
 */
final class FeedDraftJob implements JobHandler
{
    public const TYPE = 'merchant.draft';

    public function __construct(
        private readonly FeedForm $form,
        private readonly FeedRepository $feeds,
        private readonly GmcStep $step,
    ) {
    }

    public function step(Job $job): JobProgress
    {
        $data = (array) ($job->payload['data'] ?? []);
        $state = $job->state + ['feed' => 0];
        // Tekst jak w prototypie; nazwa zrodla jako parametr tlumaczenia (`params`).
        $source = ['text' => 'Creating data source “%name%”', 'params' => ['%name%' => self::sourceName($data)], 'st' => 'ok', 'note' => ''];

        if ((int) $state['feed'] === 0) {
            $existing = $this->feeds->forShop($job->idShop)[0] ?? null;

            if ($existing === null) {
                // Wyjatek Google konczy zadanie bledem (pokazanym w karcie) — feed nie powstaje.
                $existing = $this->form->createDraft($job->idShop, $data);
            }

            $state['feed'] = $existing->id;
            $state['items'] = [
                $source,
                ['text' => 'Saving the feed as a draft — no products are sent yet', 'st' => 'run', 'note' => ''],
            ];

            return new JobProgress(1, 2, 'Saving the feed as a draft — no products are sent yet', false, 0, $state);
        }

        $feed = $this->feeds->find((int) $state['feed']);

        if ($feed !== null) {
            $this->step->afterDraft($job->idShop, $feed);
        }

        $state['items'] = [
            $source,
            ['text' => 'Saving the feed as a draft — no products are sent yet', 'st' => 'ok', 'note' => ''],
        ];

        return new JobProgress(2, 2, 'Done', true, 0, $state, ['feed' => (int) $state['feed']]);
    }

    /** "Quince Insight · PL · pl" — jak `MerchantApi::dataSourceName()`. @param array<string,mixed> $data */
    public static function sourceName(array $data): string
    {
        return 'Quince Insight · ' . strtoupper((string) ($data['country'] ?? '')) . ' · ' . strtolower((string) ($data['content_language'] ?? ''));
    }
}
