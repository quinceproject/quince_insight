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

use Quince\Insight\Core\Google\GoogleApiException;
use Quince\Insight\Core\Google\NotConnectedException;
use Quince\Insight\Core\JobRunner;
use Quince\Insight\Core\Options;
use Quince\Insight\Core\WizardStep;
use Quince\Insight\Service\Merchant\Dto\Feed;

/**
 * Krok kreatora `gmc` (prototyp `cur==='gmc'`): konto, rejestracja dewelopera, przejecie
 * adresu, pierwszy feed jako wersja robocza. ZADEN produkt nie jest wysylany — krok tworzy
 * tylko zrodlo danych i zapisuje feed jako `draft` (SPEC §3 "Feed po kreatorze").
 *
 * Szablon: `views/templates/admin/wizard/steps/gmc.html.twig` (zmienna `gmc`). Pola
 * formularza: account (id albo `manual`), manual_id, country, id_lang, id_currency,
 * id_source, offer_prefix, with_tax. Rejestracja i przejecie ida trasami JSON
 * `merchant_register` / `merchant_claim` (z panelu kroku).
 */
final class GmcStep implements WizardStep
{
    public function __construct(
        private readonly MerchantAccount $account,
        private readonly FeedRepository $feeds,
        private readonly FeedForm $form,
        private readonly JobRunner $jobs,
    ) {
    }

    public function key(): string
    {
        return 'gmc';
    }

    public function data(array $shopIds): array
    {
        $idShop = (int) ($shopIds[0] ?? 0);
        $idLang = (int) \Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);
        $accounts = [];
        $error = '';

        try {
            $accounts = $this->account->listAccounts($idShop);
        } catch (NotConnectedException $e) {
            $error = 'This shop is not connected to Google yet. Connect a Google account in Settings.';
        } catch (GoogleApiException $e) {
            $error = MerchantAccount::reasonOf($e) === 'GCP_NOT_REGISTERED'
                ? ''   // Bez rejestracji lista kont moze nie dzialac — Merchant ID wpisuje sie recznie.
                : $e->getMessage();
        }

        $current = $this->account->accountId($idShop);
        $listed = in_array($current, array_column($accounts, 'id'), true);
        $feed = $this->feeds->forShop($idShop)[0] ?? null;
        $defaults = $feed !== null ? [
            'country' => $feed->country,
            'id_lang' => $feed->idLang,
            'id_currency' => $feed->idCurrency,
            'with_tax' => $feed->withTax,
            'offer_prefix' => $feed->offerPrefix,
            'id_source' => $feed->idSource,
        ] : $this->form->defaults($idShop);

        $suggest = [];
        $countries = $this->form->countries($idShop, $idLang);

        foreach (array_keys($countries) as $iso) {
            $suggest[$iso] = $this->form->suggest($idShop, $iso);
        }

        return ['gmc' => [
            'accounts' => $accounts,
            'account' => $current !== '' ? ($listed ? $current : 'manual') : '',
            'savedAccount' => $current,
            'manualId' => $listed ? '' : $current,
            'error' => $error,
            'registered' => $this->account->isRegistered($idShop),
            'claim' => match (Options::str(MerchantOptions::CLAIM_STATE, 'unclaimed', $idShop)) {
                'claimed' => 'claimed',
                'other' => 'conflict',
                default => 'unclaimed',
            },
            'siteUrl' => \Quince\Insight\Core\ShopContext::baseUrl($idShop),
            'accountIssues' => $this->account->issues($idShop),
            'idShop' => $idShop,
            // R-09: postep "Create draft feed" (wspolny `job_progress`) — ostatnie zadanie sklepu.
            'draftJob' => $this->jobs->last($idShop, FeedDraftJob::TYPE)?->toArray(),
            'feedExists' => $feed !== null,
            'feedLocked' => $feed !== null,
            'form' => $defaults,
            'countries' => $countries,
            'languages' => array_map(static fn (array $l): array => ['id' => $l['id'], 'iso' => $l['iso'], 'name' => trim((string) preg_replace('/\s*\(.*\)$/', '', $l['name'])), 'active' => $l['active']], $this->form->languages($idShop)),
            'currencies' => $this->form->currencies($idShop),
            'suggest' => $suggest,
        ]];
    }

    public function save(array $shopIds, array $input): array
    {
        $errors = [];

        foreach ($shopIds as $idShop) {
            $idShop = (int) $idShop;
            $account = (string) ($input['account'] ?? '');
            $id = $account === 'manual' ? trim((string) ($input['manual_id'] ?? '')) : trim($account);

            if ($id === '') {
                return ['account' => 'Choose a Merchant Center account.'];
            }

            try {
                if ($id !== $this->account->accountId($idShop)) {
                    $this->account->select($idShop, $id);
                }
            } catch (\InvalidArgumentException $e) {
                return ['manual_id' => $e->getMessage()];
            } catch (GoogleApiException|NotConnectedException $e) {
                return ['account' => $e->getMessage()];
            }

            $feed = $this->feeds->forShop($idShop)[0] ?? null;

            if ($feed !== null) {
                // Kraj i jezyk istniejacego feedu sa niezmienne (zrodlo danych w Google) —
                // reszte ustawien mozna poprawic.
                $this->feeds->updateSettings($feed->id, [
                    'id_currency' => (int) ($input['id_currency'] ?? $feed->idCurrency),
                    'with_tax' => $input['with_tax'] ?? $feed->withTax,
                    'offer_prefix' => (string) ($input['offer_prefix'] ?? $feed->offerPrefix),
                    'id_source' => (string) ($input['id_source'] ?? $feed->idSource),
                ]);

                continue;
            }

            [$data, $fieldErrors] = $this->form->validate($idShop, $input + ['title_template' => '{name}', 'description_source' => 'description_short']);

            if ($fieldErrors) {
                return $fieldErrors;
            }

            try {
                $created = $this->form->createDraft($idShop, $data);
            } catch (GoogleApiException|NotConnectedException|\InvalidArgumentException $e) {
                return ['country' => $e->getMessage()];
            }

            $this->afterDraft($idShop, $created);
            $errors = [];
        }

        return $errors;
    }

    /**
     * Po utworzeniu pierwszego feedu (tu albo w zadaniu `merchant.draft`): filtry z kreatora
     * "Copy settings from…" i kontrola lokalna wersji roboczej (bez Google) — Overview od razu
     * pokazuje liczby.
     */
    public function afterDraft(int $idShop, Feed $created): void
    {
        $this->copyFiltersFromSourceShop($idShop, $created);
        SyncJob::request($created->id);
        $this->jobs->start($idShop, SyncJob::TYPE);
    }

    /**
     * Konto z pol kroku: zapis, gdy inne niz zapisane. Zwraca bledy pol (puste = ok).
     *
     * @param array<string,mixed> $input
     *
     * @return array<string,string>
     */
    public function selectAccount(int $idShop, array $input): array
    {
        $account = (string) ($input['account'] ?? '');
        $id = $account === 'manual' ? trim((string) ($input['manual_id'] ?? '')) : trim($account);

        if ($id === '') {
            return ['account' => 'Choose a Merchant Center account.'];
        }

        try {
            if ($id !== $this->account->accountId($idShop)) {
                $this->account->select($idShop, $id);
            }
        } catch (\InvalidArgumentException $e) {
            return ['manual_id' => $e->getMessage()];
        } catch (GoogleApiException|NotConnectedException $e) {
            return ['account' => $e->getMessage()];
        }

        return [];
    }

    /**
     * R-09 (prototyp `primaryDisabled()` dla `gmc`): "Create draft feed" dopiero po rejestracji
     * dewelopera i przejeciu adresu witryny.
     */
    public function checksPassed(int $idShop): bool
    {
        return $this->account->isRegistered($idShop) && Options::str(MerchantOptions::CLAIM_STATE, '', $idShop) === 'claimed';
    }

    /**
     * "Copy settings from another shop" (krok Services, ARCHITEKTURA §6): filtry feedu nie
     * mialy dokad trafic, dopoki sklep nie mial feedu — kreator zapamietal sklep-zrodlo w
     * `WizardOptions::COPY_FROM`. Pierwszy feed dostaje filtry feedu zrodla o tym samym kraju
     * i jezyku, a gdy takiego nie ma — pierwszego feedu zrodla. Reguly konkretnego feedu
     * zostaja w sklepie-zrodle (wspolne `id_feed = 0` kopiuje juz kreator).
     */
    private function copyFiltersFromSourceShop(int $idShop, Feed $created): void
    {
        $from = Options::int(\Quince\Insight\Wizard\WizardOptions::COPY_FROM, 0, $idShop);

        if ($from <= 0 || $from === $idShop) {
            return;
        }

        $sources = $this->feeds->forShop($from);
        $source = null;

        foreach ($sources as $candidate) {
            if ($candidate->country === $created->country && $candidate->idLang === $created->idLang) {
                $source = $candidate;
                break;
            }
        }

        $source ??= $sources[0] ?? null;

        if ($source !== null) {
            $this->form->copyFrom($source, $created, true, false);
            $this->feeds->log($idShop, $created->id, 'info', 'Feed filters copied from another shop.', ['id_shop' => $from, 'id_feed' => $source->id]);
        }
    }

    public function status(int $idShop): string
    {
        if ($this->account->accountId($idShop) === '') {
            return 'todo';
        }

        return $this->feeds->forShop($idShop) !== [] ? 'done' : 'todo';
    }

    public function summary(int $idShop): array
    {
        // Jeden wiersz jak w prototypie (`summaryRows`): Merchant ID · stan feedu.
        if ($this->status($idShop) !== 'done') {
            return [['label' => 'Merchant Center', 'value' => 'Not finished', 'state' => 'todo']];
        }

        $active = array_filter($this->feeds->forShop($idShop), static fn (Feed $f): bool => $f->isActive());

        return [[
            'label' => 'Merchant Center',
            'value' => $active === [] ? 'Merchant ID %id% · feed saved as a draft, nothing sent yet' : 'Merchant ID %id% · sending is on',
            'params' => ['%id%' => $this->account->accountId($idShop)],
            'state' => 'done',
        ]];
    }
}
