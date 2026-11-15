<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Controller\Admin;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShopBundle\Security\Attribute\AdminSecurity;
use Quince\Insight\Core\Google\GoogleApiException;
use Quince\Insight\Core\Google\NotConnectedException;
use Quince\Insight\Core\JobRunner;
use Quince\Insight\Service\Merchant\Dto\Feed;
use Quince\Insight\Core\Options;
use Quince\Insight\Service\Merchant\FeedCatalog;
use Quince\Insight\Service\Merchant\FeedDeleteJob;
use Quince\Insight\Service\Merchant\FeedDraftJob;
use Quince\Insight\Service\Merchant\GmcStep;
use Quince\Insight\Service\Merchant\MerchantOptions;
use Quince\Insight\Service\Merchant\FeedFilters;
use Quince\Insight\Service\Merchant\FeedForm;
use Quince\Insight\Service\Merchant\FeedQueue;
use Quince\Insight\Service\Merchant\FeedRepository;
use Quince\Insight\Service\Merchant\FeedSync;
use Quince\Insight\Service\Merchant\MerchantAccount;
use Quince\Insight\Service\Merchant\MerchantApi;
use Quince\Insight\Service\Merchant\MerchantScreen;
use Quince\Insight\Service\Merchant\ProductBuilder;
use Quince\Insight\Service\Merchant\StatusRefresher;
use Quince\Insight\Service\Merchant\SyncJob;
use Quince\Insight\Service\MerchantTools\TabData;
use Quince\Insight\Ui\PageTabs;
use Quince\Insight\Ui\Pagination;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ekran Merchant Center (agent E): naglowek z wyborem feedu i "Add feed", zakladki Overview,
 * Products, What is sent oraz widok Add feed; zakladki Mapping … Reports dostarcza agent M
 * (`MerchantTools\TabData` + `merchant/tabs/<klucz>.html.twig`).
 */
final class MerchantController extends PanelController
{
    /** Zakladki 1:1 z prototypu (`GMC_TABS`). */
    private const TABS = [
        'overview' => 'Overview',
        'products' => 'Products',
        'sent' => 'What is sent',
        'mapping' => 'Mapping',
        'rules' => 'Rules',
        'promotions' => 'Promotions',
        'shipping' => 'Shipping',
        'reports' => 'Reports',
    ];

    private const DRAFT_STATUSES = ['all', 'ready', 'issue', 'excluded'];
    private const LIVE_STATUSES = ['all', 'approved', 'pending', 'disapproved', 'excluded'];

    public function __construct(
        private readonly PageTabs $tabs,
        private readonly Pagination $pagination,
        private readonly FeedRepository $feeds,
        private readonly FeedCatalog $catalog,
        private readonly FeedForm $form,
        private readonly FeedSync $sync,
        private readonly FeedQueue $queue,
        private readonly MerchantAccount $account,
        private readonly MerchantApi $api,
        private readonly MerchantScreen $screen,
        private readonly ProductBuilder $builder,
        private readonly StatusRefresher $statuses,
        private readonly JobRunner $jobs,
        private readonly TabData $tabData,
        private readonly GmcStep $gmcStep,
    ) {
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", message: 'You do not have permission to view this.')]
    public function indexAction(Request $request): Response
    {
        $idShop = $this->idShop();
        // Jezyk panelu (nazwy krajow) — legacy Context, dostepny w 9.0–9.2 bez nowych serwisow.
        $idLang = (int) \Context::getContext()->language->id;
        $feeds = $this->feeds->forShop($idShop);
        $countries = $this->form->countries($idShop, $idLang);
        $languages = $this->form->languages($idShop);
        $feed = $this->feeds->findInShop($idShop, (int) $request->query->get('feed', 0)) ?? ($feeds[0] ?? null);
        $accountId = $this->account->accountId($idShop);
        $newView = $request->query->get('tab') === 'new' || ($feed === null && $accountId !== '');

        $data = [
            'title' => 'Merchant Center',
            'description' => 'Your products on Google — what is sent, how it looks and how Google sees it.',
            'accountId' => $accountId,
            'feed' => $feed,
            'feedList' => array_map(fn (Feed $f): array => ['id' => $f->id, 'label' => MerchantScreen::label($f, $countries, $languages), 'status' => $f->status], $feeds),
            'view' => $newView ? 'new' : '',
        ];

        if ($newView) {
            $data += $this->newFeedData($idShop, $countries, $languages, $feeds, $feed);

            return $this->renderPanel('merchant/index.html.twig', 'merchant', $data);
        }

        if ($feed === null) {
            return $this->renderPanel('merchant/index.html.twig', 'merchant', $data);
        }

        $tabs = $this->tabs->resolve($request, self::TABS);
        $tab = $tabs['current'];
        $data['tabs'] = $tabs;
        $data['tab'] = $tab;
        $data['feedDeleting'] = Options::int(MerchantOptions::DELETING_FEED, 0, $idShop) === $feed->id;

        if ($tab === 'overview') {
            $data['overview'] = $this->screen->overview($feed);
        } elseif ($tab === 'products') {
            $data += $this->productsData($request, $feed);
        } elseif ($tab === 'sent') {
            $data['options'] = $this->screen->filterOptions($feed);
            $data['counter'] = ['count' => $this->catalog->count($feed), 'total' => $this->catalog->total($feed)];
            $data['summary'] = $this->translatedSummary($feed->filters);
            // Decyzja #11a: przedzialy "Price" porownuja cene przed regulami Pricing — ostrzezenie przy sekcji.
            $data['pricingRules'] = $this->tabData->activePricingRules($idShop, $feed->id);
            // "Delete feed" (decyzja #10): liczba produktow w Google i trwajace/przerwane usuwanie.
            $data['inGoogle'] = FeedDeleteJob::inGoogle($feed->id);
            $data['deleting'] = Options::int(MerchantOptions::DELETING_FEED, 0, $idShop) === $feed->id;
            $deleteJob = $data['deleting'] ? $this->jobs->last($idShop, FeedDeleteJob::TYPE) : null;
            $data['deleteJob'] = $deleteJob?->toArray();
        } else {
            $data['tabData'] = $this->tabData->for($tab, $idShop, $feed, $request);
            $data['tabTemplate'] = is_file(_PS_MODULE_DIR_ . 'quince_insight/views/templates/admin/merchant/tabs/' . $tab . '.html.twig')
                ? '@Modules/quince_insight/views/templates/admin/merchant/tabs/' . $tab . '.html.twig'
                : '';
        }

        return $this->renderPanel('merchant/index.html.twig', 'merchant', $data);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function accountsAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_merchant_accounts');
        $input = $this->payload($request);
        $idShop = $this->shopFrom($input);

        try {
            if (isset($input['select'])) {
                $name = $this->account->select($idShop, (string) $input['select']);

                return $this->ok(['account' => $this->account->accountId($idShop), 'name' => $name, 'admin' => $this->account->isAdmin($idShop)]);
            }

            return $this->ok(['accounts' => $this->account->listAccounts($idShop), 'selected' => $this->account->accountId($idShop)]);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($this->t($e->getMessage()));
        } catch (GoogleApiException|NotConnectedException $e) {
            return $this->fail($this->googleError($e), 502);
        }
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function registerAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_merchant_register');
        $idShop = $this->shopFrom($this->payload($request));

        try {
            $result = $this->account->register($idShop);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($this->t($e->getMessage()));
        } catch (GoogleApiException|NotConnectedException $e) {
            return $this->fail($this->googleError($e), 502);
        }

        return $this->ok([
            'registered' => true,
            'account' => $result['account'],
            'pending' => $this->account->registrationPending($idShop),
        ]);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function claimAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_merchant_claim');
        $overwrite = filter_var($this->payload($request)['overwrite'] ?? false, FILTER_VALIDATE_BOOLEAN);

        try {
            $state = $this->account->claim($this->shopFrom($this->payload($request)), $overwrite);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($this->t($e->getMessage()));
        } catch (GoogleApiException|NotConnectedException $e) {
            return $this->fail($this->googleError($e), 502);
        }

        return $this->ok(['claim' => $state === 'other' ? 'conflict' : $state]);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function feedCreateAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_merchant_feed_create');
        $idShop = $this->idShop();
        $input = $this->payload($request);
        [$data, $errors] = $this->form->validate($idShop, $input);

        if ($errors) {
            return $this->fail($this->t((string) reset($errors)), 400, ['errors' => array_map(fn (string $e): string => $this->t($e), $errors)]);
        }

        try {
            $feed = $this->form->createDraft($idShop, $data);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($this->t($e->getMessage()));
        } catch (GoogleApiException|NotConnectedException $e) {
            return $this->fail($this->googleError($e), 502);
        }

        $source = $this->feeds->findInShop($idShop, (int) ($input['copy_from'] ?? 0));

        if ($source !== null) {
            $this->form->copyFrom($source, $feed, !empty($input['copy_filters']), !empty($input['copy_rules']));
        }

        // Kontrola lokalna wersji roboczej (bez wywolan Google) — liczniki Ready / With issues.
        SyncJob::request($feed->id);
        $this->jobs->start($idShop, SyncJob::TYPE);

        return $this->ok([
            'message' => $this->t('Feed created as a draft — nothing is sent yet'),
            'redirect' => $this->generateUrl('quince_insight_merchant', ['feed' => $feed->id, 'tab' => 'overview']),
        ]);
    }

    /**
     * Krok kreatora `gmc`, przycisk "Create draft feed" (R-09): zapis konta i zadanie
     * `merchant.draft` (zrodlo danych + wersja robocza) z paskiem postepu w kroku. Dopiero
     * po rejestracji dewelopera i przejeciu adresu — jak `primaryDisabled()` prototypu.
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function wizardDraftAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_merchant_wizard_draft');
        $input = $this->payload($request);
        $idShop = $this->shopFrom($input);

        $errors = $this->gmcStep->selectAccount($idShop, $input);

        if ($errors) {
            return $this->fail($this->t((string) reset($errors)), 400, ['errors' => array_map(fn (string $e): string => $this->t($e), $errors)]);
        }

        if (!$this->gmcStep->checksPassed($idShop)) {
            return $this->fail($this->t('Register the Cloud project and claim the website first.'));
        }

        if ($this->feeds->forShop($idShop) !== []) {
            return $this->ok(['done' => true]);
        }

        [$data, $errors] = $this->form->validate($idShop, $input + ['title_template' => '{name}', 'description_source' => 'description_short']);

        if ($errors) {
            return $this->fail($this->t((string) reset($errors)), 400, ['errors' => array_map(fn (string $e): string => $this->t($e), $errors)]);
        }

        $job = $this->jobs->status($this->jobs->start($idShop, FeedDraftJob::TYPE, ['data' => $data]));

        return $this->ok(['job' => $job->toArray()]);
    }

    /**
     * Sklep z `id_shop` kreatora (kilka sklepow naraz) — tylko sklep z kontekstu pracownika.
     *
     * @param array<string,mixed> $input
     */
    private function shopFrom(array $input): int
    {
        $requested = (int) ($input['id_shop'] ?? 0);

        return $requested > 0 && in_array($requested, $this->contextShopIds(), true) ? $requested : $this->idShop();
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function feedSaveAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_merchant_feed_save');
        $input = $this->payload($request);
        $feed = $this->feeds->findInShop($this->idShop(), (int) ($input['feed'] ?? 0));

        if ($feed === null) {
            return $this->fail($this->t('This feed no longer exists.'), 404);
        }

        $settings = (array) ($input['settings'] ?? []);
        $prefix = trim((string) ($settings['offer_prefix'] ?? $feed->offerPrefix));

        if ($prefix !== '' && !preg_match('/^[A-Za-z0-9_\-]{1,20}$/', $prefix)) {
            return $this->fail($this->t('Use up to 20 letters, digits, "-" or "_".'));
        }

        $this->feeds->updateSettings($feed->id, [
            'title_template' => (string) ($settings['title_template'] ?? $feed->titleTemplate),
            'description_source' => (string) ($settings['description_source'] ?? $feed->descriptionSource),
            'offer_prefix' => $prefix,
            'id_source' => (string) ($settings['id_source'] ?? $feed->idSource),
            'with_tax' => $settings['with_tax'] ?? $feed->withTax,
        ]);

        if (isset($input['filters']) && is_array($input['filters'])) {
            $this->feeds->saveFilters($feed->id, $input['filters']);
        }

        // Nowa ocena calego feedu: wersja robocza tylko lokalnie, aktywny — zmiany ida do Google
        // (oferty, ktore przestaly pasowac, sa usuwane tylko z tego feedu).
        SyncJob::request($feed->id);
        $job = $this->jobs->status($this->jobs->start($feed->idShop, SyncJob::TYPE));

        return $this->ok(['message' => $this->t('Feed saved'), 'job' => $job->toArray()]);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function feedDeleteAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_merchant_feed_delete');
        $feed = $this->feeds->findInShop($this->idShop(), (int) ($this->payload($request)['feed'] ?? 0));

        if ($feed === null) {
            return $this->fail($this->t('This feed no longer exists.'), 404);
        }

        // Decyzja #10: potwierdzenie z liczba produktow, ktore znikna z Google — serwer
        // sprawdza, ze user widzial aktualna liczbe (inaczej odmowa z nowa liczba).
        $inGoogle = FeedDeleteJob::inGoogle($feed->id);
        $input = $this->payload($request);

        if (($input['confirm'] ?? false) !== true || (int) ($input['products'] ?? -1) !== $inGoogle) {
            return $this->fail($this->t('The number of products on Google changed. Check it and confirm again.'), 409, ['products' => $inGoogle]);
        }

        $deleting = Options::int(MerchantOptions::DELETING_FEED, 0, $feed->idShop);
        $active = $this->jobs->active($feed->idShop, FeedDeleteJob::TYPE);

        if ($active !== null && $deleting !== $feed->id) {
            return $this->fail($this->t('Another feed is being deleted. Wait until it finishes.'));
        }

        // Kolejnosc w zadaniu: produkty z Google, zrodlo danych, feed w module.
        Options::set(MerchantOptions::DELETING_FEED, (string) $feed->id, $feed->idShop);
        $job = $this->jobs->status($this->jobs->start($feed->idShop, FeedDeleteJob::TYPE, ['feed' => $feed->id]));

        return $this->ok([
            'message' => $this->t('Deleting the feed'),
            'job' => $job->toArray(),
            'redirect' => $this->generateUrl('quince_insight_merchant'),
        ]);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function feedStatusAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_merchant_feed_status');
        $input = $this->payload($request);
        $feed = $this->feeds->findInShop($this->idShop(), (int) ($input['feed'] ?? 0));

        if ($feed === null) {
            return $this->fail($this->t('This feed no longer exists.'), 404);
        }

        $action = (string) ($input['action'] ?? '');

        if (Options::int(MerchantOptions::DELETING_FEED, 0, $feed->idShop) === $feed->id) {
            return $this->fail($this->t('This feed is being deleted.'));
        }

        if ($action === 'only_ready') {
            $this->feeds->setOnlyReady($feed->id, filter_var($input['value'] ?? true, FILTER_VALIDATE_BOOLEAN));

            return $this->ok();
        }

        if ($action === 'pause') {
            if (!$feed->isActive()) {
                return $this->fail($this->t('This feed is not sending.'));
            }

            $this->feeds->setStatus($feed->id, Feed::STATUS_PAUSED);

            return $this->ok(['message' => $this->t('Sending paused'), 'status' => Feed::STATUS_PAUSED]);
        }

        if ($action === 'start' || $action === 'resume') {
            if (($action === 'start' && !$feed->isDraft()) || ($action === 'resume' && !$feed->isPaused())) {
                return $this->fail($this->t('This feed is already sending.'));
            }

            if ($action === 'start') {
                $this->feeds->setOnlyReady($feed->id, filter_var($input['only_ready'] ?? true, FILTER_VALIDATE_BOOLEAN));
            }

            try {
                if ($feed->dataSource === '') {
                    $this->feeds->setDataSource($feed->id, $this->api->ensureDataSource($feed));
                }
            } catch (\InvalidArgumentException $e) {
                return $this->fail($this->t($e->getMessage()));
            } catch (GoogleApiException|NotConnectedException $e) {
                return $this->fail($this->googleError($e), 502);
            }

            $this->feeds->setStatus($feed->id, Feed::STATUS_ACTIVE);
            $this->feeds->log($feed->idShop, $feed->id, 'info', $action === 'start' ? 'Sending started' : 'Sending resumed');
            SyncJob::request($feed->id);
            $job = $this->jobs->status($this->jobs->start($feed->idShop, SyncJob::TYPE));

            return $this->ok([
                'message' => $this->t($action === 'start' ? 'Sending started' : 'Sending resumed'),
                'status' => Feed::STATUS_ACTIVE,
                'job' => $job->toArray(),
            ]);
        }

        return $this->fail($this->t('Unknown action.'));
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function countAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_merchant_count');
        $input = $this->payload($request);
        $feed = $this->feeds->findInShop($this->idShop(), (int) ($input['feed'] ?? 0));

        if ($feed === null) {
            return $this->fail($this->t('This feed no longer exists.'), 404);
        }

        // Licznik niezapisanych filtrow — ten sam warunek, ktory potem stosuje synchronizacja.
        $filters = FeedFilters::normalize((array) ($input['filters'] ?? []));

        // Filtr cen porownuje cene wysylana do Google — z niezapisanym ustawieniem podatku.
        if (isset($input['with_tax'])) {
            $feed = clone $feed;
            $feed->withTax = filter_var($input['with_tax'], FILTER_VALIDATE_BOOLEAN);
        }

        return $this->ok([
            'count' => $this->catalog->count($feed, $filters),
            'total' => $this->catalog->total($feed),
            'summary' => $this->translatedSummary($filters),
        ]);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function syncAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_merchant_sync');
        $feed = $this->feeds->findInShop($this->idShop(), (int) ($this->payload($request)['feed'] ?? 0));

        if ($feed === null || !$feed->isActive()) {
            return $this->fail($this->t('Sending is not on for this feed.'));
        }

        SyncJob::request($feed->id);
        $job = $this->jobs->status($this->jobs->start($feed->idShop, SyncJob::TYPE));

        return $this->ok(['message' => $this->t('Sync started'), 'job' => $job->toArray()]);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function productsActionAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_merchant_products_action');
        $input = $this->payload($request);
        $feed = $this->feeds->findInShop($this->idShop(), (int) ($input['feed'] ?? 0));

        if ($feed === null) {
            return $this->fail($this->t('This feed no longer exists.'), 404);
        }

        $keys = [];

        foreach ((array) ($input['keys'] ?? []) as $key) {
            if (preg_match('/^(\d+)-(\d+)$/', (string) $key, $m)) {
                $keys[] = [(int) $m[1], (int) $m[2]];
            }
        }

        $keys = array_slice($keys, 0, 500);

        if (!$keys) {
            return $this->fail($this->t('Select products first.'));
        }

        $action = (string) ($input['action'] ?? '');

        if ($action === 'exclude') {
            // Wykluczenie dotyczy produktu (z kombinacjami) — trafia do filtra "Excluded products".
            $filters = $feed->filters;
            $filters['excluded_products'] = array_merge($filters['excluded_products'], array_column($keys, 0));
            $this->feeds->saveFilters($feed->id, $filters);
            $feed = $this->feeds->find($feed->id) ?? $feed;
            $this->sync->resetCache();

            $products = array_values(array_unique(array_column($keys, 0)));

            foreach ($products as $idProduct) {
                foreach ($this->queue->offerKeys($feed, $idProduct) as $idAttribute) {
                    $this->queue->enqueue($feed, $idProduct, $idAttribute, 1);
                }
            }

            $this->processNow($feed);
            $n = count($products);

            return $this->ok(['message' => $this->t($n === 1 ? '%count% product excluded from the feed' : '%count% products excluded from the feed', ['%count%' => $n])]);
        }

        if ($action === 'send') {
            if (!$feed->isActive()) {
                return $this->fail($this->t('Sending is not on for this feed.'));
            }

            foreach ($keys as [$idProduct, $idAttribute]) {
                // "Send again": pelny insert niezaleznie od hasha.
                $row = $this->sync->row($feed->id, $idProduct, $idAttribute);

                if ($row !== null && $row['sync_state'] === 'synced') {
                    $this->sync->forceResend((int) $row['id_feed_product']);
                }

                $this->queue->enqueue($feed, $idProduct, $idAttribute, 1);
            }

            $this->processNow($feed);
            $n = count($keys);

            return $this->ok(['message' => $this->t($n === 1 ? '%count% product queued for sending' : '%count% products queued for sending', ['%count%' => $n])]);
        }

        return $this->fail($this->t('Unknown action.'));
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function productDetailAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_merchant_product_detail');
        $input = $this->payload($request);
        $feed = $this->feeds->findInShop($this->idShop(), (int) ($input['feed'] ?? 0));

        if ($feed === null) {
            return $this->fail($this->t('This feed no longer exists.'), 404);
        }

        $offer = $this->builder->build($feed, (int) ($input['id_product'] ?? 0), (int) ($input['id_product_attribute'] ?? 0));

        if ($offer === null) {
            return $this->fail($this->t('This product is not available in the shop.'), 404);
        }

        return $this->ok([
            'google' => array_map(fn (array $r): array => [$this->t($r[0]), $r[1]], MerchantScreen::googleData($offer)),
            'issues' => array_map(fn (array $i): string => $this->t($i['message']), $offer->issues),
            'excluded' => $offer->excluded,
            'reason' => $offer->reason,
            'payload' => $offer->input,
        ]);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function refreshStatusAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_merchant_refresh_status');

        try {
            $result = $this->statuses->run($this->idShop(), microtime(true) + 15);
        } catch (GoogleApiException|NotConnectedException $e) {
            return $this->fail($this->googleError($e), 502);
        }

        return $this->ok(['message' => $this->t('%count% product statuses updated', ['%count%' => $result['updated']]), 'complete' => $result['complete']]);
    }

    /* ---------------- pomocnicze ---------------- */

    /** Krotka wysylka zaraz po akcji z listy (reszte dokonczy cron `products`). */
    private function processNow(Feed $feed): void
    {
        try {
            $this->sync->processQueue($feed->idShop, microtime(true) + 8, 60);
        } catch (GoogleApiException|NotConnectedException $e) {
            // Pozycje zostaja w kolejce — cron sprobuje ponownie.
        }
    }

    /**
     * @param array<string,string> $countries
     * @param array<int,array<string,mixed>> $languages
     * @param Feed[] $feeds
     *
     * @return array<string,mixed>
     */
    private function newFeedData(int $idShop, array $countries, array $languages, array $feeds, ?Feed $current): array
    {
        $currencies = $this->form->currencies($idShop);
        $suggest = [];

        foreach (array_keys($countries) as $iso) {
            $suggest[$iso] = $this->form->suggest($idShop, $iso);
        }

        $defaults = $this->form->defaults($idShop);
        $defaults['copy_from'] = $current?->id ?? 0;

        return [
            'newFeed' => [
                'countries' => $countries,
                'languages' => array_map(static fn (array $l): array => ['id' => $l['id'], 'iso' => $l['iso'], 'name' => trim((string) preg_replace('/\s*\(.*\)$/', '', $l['name'])), 'active' => $l['active']], $languages),
                'currencies' => $currencies,
                'suggest' => $suggest,
                'taken' => array_map(static fn (Feed $f): string => $f->country . ':' . $f->idLang, $feeds),
                'defaults' => $defaults,
                'cancelUrl' => $current !== null ? $this->generateUrl('quince_insight_merchant', ['feed' => $current->id]) : '',
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function productsData(Request $request, Feed $feed): array
    {
        $statuses = $feed->isDraft() ? self::DRAFT_STATUSES : self::LIVE_STATUSES;
        $status = (string) $request->query->get('status', 'all');
        $status = in_array($status, $statuses, true) ? $status : 'all';
        $q = mb_substr(trim((string) $request->query->get('q', '')), 0, 100);

        $first = $this->catalog->page($feed, $q, $status, 1, 0);
        $pagination = $this->pagination->resolve($request, $first['total']);
        $page = $this->catalog->page($feed, $q, $status, $pagination['per'], $pagination['offset']);

        return [
            'statuses' => $statuses,
            'status' => $status,
            'q' => $q,
            'pagination' => $pagination,
            'rows' => $this->screen->rows($feed, $page['rows']),
        ];
    }

    /**
     * @param array<string,mixed> $filters
     *
     * @return string[]
     */
    private function translatedSummary(array $filters): array
    {
        return array_map(fn (array $item): string => $this->t($item[0], $item[1]), FeedFilters::summary($filters));
    }
}
