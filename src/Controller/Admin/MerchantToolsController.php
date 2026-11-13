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

use PrestaShopBundle\Security\Attribute\AdminSecurity;
use Quince\Insight\Core\Dev;
use Quince\Insight\Core\Google\GoogleApiException;
use Quince\Insight\Core\Google\NotConnectedException;
use Quince\Insight\Service\Merchant\Dto\Feed;
use Quince\Insight\Service\Merchant\FeedRepository;
use Quince\Insight\Core\JobRunner;
use Quince\Insight\Service\Merchant\SyncJob;
use Quince\Insight\Service\MerchantTools\ConditionMap;
use Quince\Insight\Service\MerchantTools\Mapping;
use Quince\Insight\Service\MerchantTools\MerchantApi;
use Quince\Insight\Service\MerchantTools\PromotionCountries;
use Quince\Insight\Service\MerchantTools\PromotionService;
use Quince\Insight\Service\MerchantTools\ReportService;
use Quince\Insight\Service\MerchantTools\RuleDefinitions;
use Quince\Insight\Service\MerchantTools\RuleRepository;
use Quince\Insight\Service\MerchantTools\ShippingService;
use Quince\Insight\Service\MerchantTools\TabData;
use Quince\Insight\Service\MerchantTools\Taxonomy;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Akcje JSON zakladek Mapping, Rules, Promotions, Shipping, Reports ekranu Merchant Center
 * (ARCHITEKTURA §9 blok M). Ekran renderuje agent E (`MerchantController`); tu tylko akcje
 * i — w trybie deweloperskim — strona podgladu samych zakladek M (`merchant_tools_preview`).
 */
final class MerchantToolsController extends PanelController
{
    public function __construct(
        private readonly FeedRepository $feeds,
        private readonly Mapping $mapping,
        private readonly Taxonomy $taxonomy,
        private readonly RuleRepository $rules,
        private readonly PromotionService $promotions,
        private readonly ShippingService $shipping,
        private readonly ReportService $reports,
        private readonly MerchantApi $api,
        private readonly TabData $tabData,
        private readonly ConditionMap $conditions,
        private readonly JobRunner $jobs,
    ) {
    }

    /**
     * Podglad zakladek M bez ekranu agenta E — tylko z `config/dev.php` (bez pliku: 404).
     */
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", message: 'You do not have permission to view this.')]
    public function previewAction(Request $request): Response
    {
        if (!Dev::enabled()) {
            throw new NotFoundHttpException();
        }

        $idShop = $this->idShop();
        $feeds = $this->feeds->forShop($idShop);
        $feed = $this->feed($idShop, (int) $request->query->get('feed', 0)) ?? ($feeds[0] ?? null);
        $tab = in_array((string) $request->query->get('tab'), TabData::TABS, true) ? (string) $request->query->get('tab') : 'mapping';

        return $this->renderPanel('merchant/tools_preview.html.twig', 'merchant', [
            'tab' => $tab,
            'feed' => $feed,
            'feeds' => $feeds,
            'tabNames' => ['mapping' => 'Mapping', 'rules' => 'Rules', 'promotions' => 'Promotions', 'shipping' => 'Shipping', 'reports' => 'Reports'],
            'tabData' => $this->tabData->for($tab, $idShop, $feed, $request),
        ]);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function mappingSaveAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_merchant_mapping_save');
        $idShop = $this->idShop();
        $p = $this->payload($request);

        switch ((string) ($p['type'] ?? '')) {
            case 'identifier':
                if (!$this->mapping->saveIdentifier($idShop, (string) ($p['key'] ?? ''), (string) ($p['value'] ?? ''))) {
                    return $this->fail($this->t('Choose a value from the list.'));
                }

                return $this->ok(['message' => $this->t('Saved')]);

            case 'attribute':
                if (!$this->mapping->saveAttribute($idShop, (string) ($p['target'] ?? ''), (string) ($p['source'] ?? ''))) {
                    return $this->fail($this->t('Choose a value from the list.'));
                }

                return $this->ok(['message' => $this->t('Saved')]);

            case 'condition':
                // Decyzja #12: stan 9.2 -> stan Google albo "Do not send".
                if (!$this->conditions->save($idShop, (string) ($p['state'] ?? ''), (string) ($p['value'] ?? ''))) {
                    return $this->fail($this->t('Choose a value from the list.'));
                }

                // Zmiana zbioru ofert: ponowna ocena feedow sklepu (aktywny feed usuwa z Google
                // produkty z "Do not send" i wysyla nowy stan; wersja robocza tylko lokalnie).
                $feeds = $this->feeds->forShop($idShop);

                foreach ($feeds as $f) {
                    SyncJob::request($f->id);
                }

                if ($feeds) {
                    $this->jobs->start($idShop, SyncJob::TYPE);
                }

                return $this->ok(['message' => $this->t('Saved')]);

            case 'category':
                $idCategory = (int) ($p['id_category'] ?? 0);
                $googleId = (int) ($p['google_id'] ?? 0);

                if ($idCategory <= 0 || ($googleId > 0 && !$this->taxonomy->exists($googleId))) {
                    return $this->fail($this->t('Choose a Google category from the list.'));
                }

                $this->mapping->saveCategory($idShop, $idCategory, $googleId);
                $locale = Taxonomy::locale((string) ($p['locale'] ?? 'en'));
                $paths = $this->taxonomy->paths([$googleId], $locale);
                $mapped = count(array_filter($this->mapping->categoryMap($idShop)));

                return $this->ok(['path' => $paths[$googleId] ?? '', 'mappedCount' => $mapped, 'message' => $this->t('Saved')]);
        }

        return $this->fail($this->t('Unknown setting.'));
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function taxonomySearchAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_merchant_taxonomy_search');
        $p = $this->payload($request);
        $locale = (string) ($p['locale'] ?? '');
        $locale = in_array($locale, Taxonomy::LOCALES, true) ? $locale : Taxonomy::FALLBACK;

        return $this->ok(['hits' => $this->taxonomy->search(mb_substr((string) ($p['q'] ?? ''), 0, 100), $locale, 8)]);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function ruleSaveAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_merchant_rule_save');
        $idShop = $this->idShop();
        $p = $this->payload($request);
        [$rule, $error] = RuleDefinitions::normalize((array) ($p['rule'] ?? []));

        if ($error !== '') {
            return $this->fail($this->t($error));
        }

        $idRule = (int) ($p['rule']['id'] ?? 0);
        $existing = $idRule > 0 ? $this->rules->find($idShop, $idRule) : null;
        // Decyzja #14: zasieg z edytora — 0 = "All feeds", inaczej feed tego sklepu.
        $scope = (int) ($p['rule']['feed'] ?? ($existing['id_feed'] ?? 0));

        if ($scope > 0 && $this->feed($idShop, $scope) === null) {
            return $this->fail($this->t('This feed no longer exists.'));
        }

        $this->rules->save($idShop, $existing ? $idRule : 0, max(0, $scope), $rule);

        return $this->ok(['rules' => $this->ruleList($idShop, (int) ($p['feed'] ?? 0)), 'message' => $this->t('Rule saved')]);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function ruleDeleteAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_merchant_rule_delete');
        $idShop = $this->idShop();
        $p = $this->payload($request);
        $this->rules->delete($idShop, (int) ($p['id'] ?? 0));

        return $this->ok(['rules' => $this->ruleList($idShop, (int) ($p['feed'] ?? 0)), 'message' => $this->t('Rule deleted')]);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function ruleSortAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_merchant_rule_sort');
        $idShop = $this->idShop();
        $p = $this->payload($request);
        $this->rules->sort($idShop, (array) ($p['ids'] ?? []));

        return $this->ok(['rules' => $this->ruleList($idShop, (int) ($p['feed'] ?? 0))]);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function promoSendAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_merchant_promo_send');
        $idShop = $this->idShop();
        $p = $this->payload($request);
        $feed = $this->feed($idShop, (int) ($p['feed'] ?? 0));

        if ($feed === null) {
            return $this->fail($this->t('Choose a feed first.'));
        }

        if (!PromotionCountries::supports($feed->country)) {
            return $this->fail($this->t('Google does not accept promotions through the API for this country.'));
        }

        try {
            [$ok, $error] = $this->promotions->add($idShop, $feed, (int) ($p['id_cart_rule'] ?? 0));
        } catch (NotConnectedException | GoogleApiException $e) {
            return $this->fail($this->googleError($e), 400, ['promos' => $this->promotions->listForFeed($idShop, $feed)]);
        }

        if (!$ok) {
            return $this->fail($this->t($error), 400, ['promos' => $this->promotions->listForFeed($idShop, $feed)]);
        }

        return $this->ok(['promos' => $this->promotions->listForFeed($idShop, $feed), 'message' => $this->t('Promotion sent to Google')]);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function promoScanAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_merchant_promo_scan');
        $idShop = $this->idShop();
        $feed = $this->feed($idShop, (int) ($this->payload($request)['feed'] ?? 0));

        if ($feed === null) {
            return $this->fail($this->t('Choose a feed first.'));
        }

        $scan = $this->promotions->scan($idShop, $feed);

        foreach ($scan['skipped'] as &$s) {
            $s['reason'] = $this->t($s['reason']);
        }
        unset($s);

        return $this->ok($scan);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function shippingPreviewAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_merchant_shipping_preview');
        $idShop = $this->idShop();

        if ($this->api->accountId($idShop) === '') {
            return $this->fail($this->t('Choose a Merchant Center account first.'));
        }

        if (!$this->shipping->hasFeeds($idShop)) {
            return $this->fail($this->t('Add a feed first.'));
        }

        try {
            return $this->ok($this->shippingOut($this->shipping->preview($idShop)));
        } catch (NotConnectedException | GoogleApiException $e) {
            return $this->fail($this->googleError($e));
        }
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function shippingSendAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_merchant_shipping_send');
        $idShop = $this->idShop();
        $p = $this->payload($request);

        // Nadpisuje cale ustawienia wysylki konta — bez wyraznego potwierdzenia nic nie idzie.
        if (($p['confirm'] ?? false) !== true) {
            return $this->fail($this->t('Confirm that the shipping settings in Merchant Center can be replaced.'));
        }

        if ($this->api->accountId($idShop) === '') {
            return $this->fail($this->t('Choose a Merchant Center account first.'));
        }

        if (!$this->shipping->hasFeeds($idShop)) {
            return $this->fail($this->t('Add a feed first.'));
        }

        try {
            [$ok, $error, $preview] = $this->shipping->send($idShop, (string) ($p['hash'] ?? ''));
        } catch (NotConnectedException | GoogleApiException $e) {
            return $this->fail($this->googleError($e));
        }

        if (!$ok) {
            return $this->fail($this->t($error), 409, $preview ? ['preview' => $this->shippingOut($preview)] : []);
        }

        return $this->ok(['preview' => $this->shippingOut($preview), 'message' => $this->t('Shipping sent to Merchant Center')]);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function reportAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_merchant_report');
        $idShop = $this->idShop();
        $p = $this->payload($request);

        if ($this->api->accountId($idShop) === '') {
            return $this->fail($this->t('Choose a Merchant Center account first.'));
        }

        $country = preg_match('/^[A-Za-z]{2}$/', (string) ($p['country'] ?? '')) ? strtoupper((string) $p['country']) : 'all';
        $market = preg_match('/^[A-Za-z]{2}$/', (string) ($p['market'] ?? '')) ? strtoupper((string) $p['market']) : '';

        try {
            $report = $this->reports->performance($idShop, (int) ($p['period'] ?? 30), $country, !empty($p['refresh']));
        } catch (NotConnectedException | GoogleApiException $e) {
            // Konta zaawansowane nie maja raportow (research/04 §10) — informacja, nie blad.
            if ($e instanceof GoogleApiException && in_array($e->httpCode(), [400, 403], true)) {
                return $this->ok(['unavailable' => true]);
            }

            return $this->fail($this->googleError($e));
        }

        try {
            $report['price'] = $this->reports->priceCompetitiveness($idShop, $market);
            $report['bestSellers'] = $market !== '' ? $this->reports->bestSellers($idShop, $market) : ['available' => false, 'rows' => []];
        } catch (\Throwable $e) {
            $report['price'] = ['available' => false, 'rows' => []];
            $report['bestSellers'] = ['available' => false, 'rows' => []];
        }

        $report['countryNames'] = [];

        foreach (array_merge($report['countries'], array_column($report['byCountry'], 'country')) as $iso) {
            $id = (int) \Country::getByIso((string) $iso);
            $report['countryNames'][$iso] = $id > 0 ? (string) \Country::getNameById((int) \Language::getIdByIso('en') ?: (int) \Configuration::get('PS_LANG_DEFAULT', null, null, $idShop), $id) : ($iso === 'ZZ' ? $this->t('Unknown') : (string) $iso);
        }

        return $this->ok($report);
    }

    /** Feed sklepu albo null (cudzy sklep i nieistniejacy feed = null). */
    private function feed(int $idShop, int $idFeed): ?Feed
    {
        if ($idFeed <= 0) {
            return null;
        }

        $feed = $this->feeds->find($idFeed);

        return $feed !== null && $feed->idShop === $idShop ? $feed : null;
    }

    /** @return array<int,array<string,mixed>> */
    private function ruleList(int $idShop, int $idFeed): array
    {
        $this->rules->flush();

        return array_map([RuleDefinitions::class, 'toEditor'], $this->rules->forFeed($idShop, $idFeed));
    }

    /**
     * Podglad wysylki do JSON z przetlumaczonymi powodami pominiecia.
     *
     * @param array<string,mixed> $preview
     *
     * @return array<string,mixed>
     */
    private function shippingOut(array $preview): array
    {
        foreach ($preview['skipped'] as &$s) {
            $s['reason'] = $this->t($s['reason']);
        }
        unset($s);

        return $preview;
    }
}
