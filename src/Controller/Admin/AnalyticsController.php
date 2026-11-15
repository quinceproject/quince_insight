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
use Quince\Insight\Core\Clock;
use Quince\Insight\Core\JobRunner;
use Quince\Insight\Core\Options;
use Quince\Insight\Core\ShopContext;
use Quince\Insight\Service\Analytics\AccountBrowser;
use Quince\Insight\Service\Analytics\AckRequiredException;
use Quince\Insight\Service\Analytics\AnalyticsOptions;
use Quince\Insight\Service\Analytics\Ga4Api;
use Quince\Insight\Service\Analytics\Ga4Settings;
use Quince\Insight\Service\Analytics\Ga4Step;
use Quince\Insight\Service\Analytics\PropertyInfo;
use Quince\Insight\Service\Analytics\PropertySetup;
use Quince\Insight\Service\Analytics\ReportQueries;
use Quince\Insight\Service\Analytics\Reports;
use Quince\Insight\Service\Analytics\SetupJob;
use Quince\Insight\Service\Analytics\ShopDefaults;
use Quince\Insight\Service\Events\EventsOptions;
use Quince\Insight\Ui\PageTabs;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ekran Analytics (prototyp `pg==='ga4'`): zakladki Overview, Traffic, Funnel, Property
 * oraz akcje JSON kroku kreatora `ga4` i zakladki Property.
 *
 * Trasy: `analytics`, `analytics_accounts`, `analytics_property_create`, `analytics_stream_create`,
 * `analytics_secret_create`, `analytics_ack`, `analytics_refresh`, `analytics_save`.
 */
final class AnalyticsController extends PanelController
{
    /** 15 zdarzen modulu (prototyp `EVENTS`). */
    private const MODULE_EVENTS = ['page_view', 'view_item', 'view_item_list', 'select_item', 'add_to_cart', 'remove_from_cart', 'view_cart',
        'begin_checkout', 'add_shipping_info', 'add_payment_info', 'purchase', 'refund', 'login', 'sign_up', 'search'];

    private const TABS = ['overview' => 'Overview', 'traffic' => 'Traffic', 'funnel' => 'Funnel', 'property' => 'Property'];

    public function __construct(
        private readonly PageTabs $pageTabs,
        private readonly Reports $reports,
        private readonly PropertyInfo $info,
        private readonly Ga4Settings $settings,
        private readonly AccountBrowser $browser,
        private readonly PropertySetup $setup,
        private readonly ShopDefaults $defaults,
        private readonly Ga4Api $api,
    ) {
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", message: 'You do not have permission to view this.')]
    public function indexAction(Request $request): Response
    {
        $idShop = $this->idShop();
        $tabs = $this->pageTabs->resolve($request, self::TABS);
        $period = ReportQueries::period($request->query->get('period', 28));
        $configured = Options::str(AnalyticsOptions::PROPERTY, '', $idShop) !== '';
        $report = null;
        $property = null;
        $keyEvents = [];
        $keyEventsError = '';

        if ($configured) {
            $report = match ($tabs['current']) {
                'traffic' => $this->reports->traffic($idShop, $period),
                'funnel' => $this->reports->funnel($idShop, $period),
                'property' => null,
                default => $this->reports->overview($idShop, $period),
            };

            if ($report !== null) {
                $report = $this->viewReport($report);
            }

            if ($tabs['current'] === 'property') {
                try {
                    $property = $this->info->get($idShop);
                } catch (\Throwable $e) {
                    $property = ['error' => $this->googleError($e)];
                }

                try {
                    $keyEvents = $this->visibleKeyEvents($idShop, $this->settings->keyEvents($idShop));
                } catch (\Throwable $e) {
                    $keyEventsError = $this->googleError($e);
                }
            }
        }

        return $this->renderPanel('analytics/index.html.twig', 'analytics', [
            'tabs' => $tabs,
            'period' => $period,
            'periods' => ReportQueries::PERIODS,
            'configured' => $configured,
            'report' => $report,
            'property' => $property,
            'keyEvents' => $keyEvents,
            'keyEventsError' => $keyEventsError,
            'propertyName' => Options::str(AnalyticsOptions::PROPERTY_NAME, '', $idShop),
            'measurementId' => Options::str(AnalyticsOptions::MEASUREMENT_ID, '', $idShop),
            'mpOn' => Options::bool(AnalyticsOptions::MP_ON, true, $idShop),
            'ackDone' => Options::str(AnalyticsOptions::ACK_AT, '', $idShop) !== '',
            'ackNeeded' => Options::bool(AnalyticsOptions::ACK_NEEDED, false, $idShop),
        ]);
    }

    /**
     * Konta GA4 i uslugi wybranego konta (ze strumieniami) dla kroku kreatora.
     * Tresc: {account?: 'accounts/1', refresh?: bool, id_shop?: int}.
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function accountsAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_analytics_accounts');
        $data = $this->payload($request);
        $idShop = $this->shopFrom($data);

        try {
            $accounts = $this->browser->accounts($idShop, !empty($data['refresh']));
            $account = (string) ($data['account'] ?? '');

            if ($account === '' && $accounts !== []) {
                $account = $accounts[0]['name'];
            }

            $properties = $account !== '' ? $this->browser->properties($idShop, $account, $this->defaults->origin($idShop)) : [];

            return $this->ok(['accounts' => $accounts, 'account' => $account, 'properties' => $properties]);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($this->t('Choose an Analytics account.'));
        } catch (\Throwable $e) {
            return $this->fail($this->googleError($e), 502);
        }
    }

    /**
     * "Set up Analytics" w kroku kreatora `ga4`: zadanie `analytics.setup` z karta postepu
     * (prototyp `taskLines('ga4')`). Tresc: pola formularza kroku + id_shop.
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function wizardSetupAction(Request $request, JobRunner $jobs): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_analytics_wizard_setup');
        $data = $this->payload($request);
        $idShop = $this->shopFrom($data);

        $errors = Ga4Step::validate(
            trim((string) ($data['account'] ?? '')),
            trim((string) ($data['property'] ?? '')),
            (string) ($data['name'] ?? ''),
            (string) ($data['timezone'] ?? ''),
            (string) ($data['currency'] ?? '')
        );

        if ($errors !== []) {
            return $this->fail($this->t((string) reset($errors)), 400, ['errors' => array_map(fn (string $e): string => $this->t($e), $errors)]);
        }

        $payload = [];

        foreach (['account', 'property', 'name', 'timezone', 'currency', 'stream', 'mp', 'key_purchase', 'ack'] as $key) {
            $payload[$key] = is_scalar($data[$key] ?? null) ? (string) $data[$key] : '';
        }

        $job = $jobs->status($jobs->start($idShop, SetupJob::TYPE, $payload));

        return $this->ok(['job' => $job->toArray()]);
    }

    /** Nowa usluga GA4 (albo istniejaca o tej nazwie na koncie). Tresc: {account, name, timezone, currency}. */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function propertyCreateAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_analytics_property_create');
        $data = $this->payload($request);
        $idShop = $this->shopFrom($data);
        $timezone = (string) ($data['timezone'] ?? $this->defaults->timeZone($idShop));
        $currency = strtoupper((string) ($data['currency'] ?? $this->defaults->currency($idShop)));

        if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true) || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            return $this->fail($this->t('Choose a time zone and a currency.'));
        }

        try {
            $property = $this->setup->ensureProperty($idShop, (string) ($data['account'] ?? ''), 'new', (string) ($data['name'] ?? ''), $timezone, $currency);
            $this->browser->accounts($idShop, true);

            return $this->ok(['property' => [
                'name' => (string) ($property['name'] ?? ''),
                'displayName' => (string) ($property['displayName'] ?? ''),
            ]]);
        } catch (\DomainException|\InvalidArgumentException $e) {
            return $this->fail($this->t($e->getMessage()));
        } catch (\Throwable $e) {
            return $this->fail($this->googleError($e), 502);
        }
    }

    /** Strumien WWW sklepu w usludze (istniejacy dla tej domeny albo nowy). Tresc: {property}. */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function streamCreateAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_analytics_stream_create');
        $data = $this->payload($request);
        $idShop = $this->shopFrom($data);

        try {
            $property = Ga4Api::resource((string) ($data['property'] ?? ''), 'properties');
            $stream = $this->setup->ensureStream($idShop, $property, 'new', $this->defaults->origin($idShop));
            $this->browser->accounts($idShop, true);

            return $this->ok(['stream' => [
                'name' => (string) ($stream['name'] ?? ''),
                'defaultUri' => (string) ($stream['webStreamData']['defaultUri'] ?? ''),
                'measurementId' => (string) ($stream['webStreamData']['measurementId'] ?? ''),
            ]]);
        } catch (\DomainException|\InvalidArgumentException $e) {
            return $this->fail($this->t('Choose a property first.'));
        } catch (\Throwable $e) {
            return $this->fail($this->googleError($e), 502);
        }
    }

    /**
     * Sekret Measurement Protocol dla zapisanego strumienia sklepu. Tresc: {ack?: bool}.
     * Wartosc sekretu nigdy nie wraca w odpowiedzi.
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function secretCreateAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_analytics_secret_create');
        $data = $this->payload($request);
        $idShop = $this->shopFrom($data);
        $property = Options::str(AnalyticsOptions::PROPERTY, '', $idShop);
        $stream = Options::str(AnalyticsOptions::STREAM, '', $idShop);

        if ($property === '' || $stream === '') {
            return $this->fail($this->t('Choose a property and a web data stream first.'));
        }

        try {
            $this->setup->ensureSecret($idShop, $property, $stream, !empty($data['ack']));
            Options::set(AnalyticsOptions::MP_ON, 1, $idShop);
            Options::delete(AnalyticsOptions::SETUP_ERROR, $idShop);
            $this->info->forget($idShop);

            return $this->ok(['message' => $this->t('Measurement Protocol secret created.')]);
        } catch (AckRequiredException $e) {
            return $this->fail($this->t('Google requires the user data collection acknowledgement for this property before a Measurement Protocol secret can be created.'), 409, ['ack' => true]);
        } catch (\Throwable $e) {
            return $this->fail($this->googleError($e), 502);
        }
    }

    /** Potwierdzenie zbierania danych (`acknowledgeUserDataCollection`) — tylko na wyrazne zyczenie usera. */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function ackAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_analytics_ack');
        $data = $this->payload($request);
        $idShop = $this->shopFrom($data);
        $property = (string) ($data['property'] ?? Options::str(AnalyticsOptions::PROPERTY, '', $idShop));

        try {
            $property = Ga4Api::resource($property, 'properties');
        } catch (\InvalidArgumentException $e) {
            return $this->fail($this->t('Choose a property first.'));
        }

        try {
            $this->api->acknowledge($idShop, $property);

            if ($property === Options::str(AnalyticsOptions::PROPERTY, '', $idShop)) {
                Options::set(AnalyticsOptions::ACK_AT, Clock::now(), $idShop);
            }

            return $this->ok();
        } catch (\Throwable $e) {
            return $this->fail($this->googleError($e), 502);
        }
    }

    /** "Refresh": raporty i stan uslugi od nowa z Google. */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function refreshAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_analytics_refresh');
        $this->reports->refresh($this->idShop());

        return $this->ok();
    }

    /** Zapis z zakladki Property. Tresc: {field: 'retention', value: '2'|'14'}. */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function saveAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_analytics_save');
        $data = $this->payload($request);
        $idShop = $this->idShop();

        if (($data['field'] ?? '') !== 'retention') {
            return $this->fail($this->t('Unknown setting.'));
        }

        if (Options::str(AnalyticsOptions::PROPERTY, '', $idShop) === '') {
            return $this->fail($this->t('Choose a property first.'));
        }

        try {
            $this->info->setRetention($idShop, (string) ($data['value'] ?? ''));

            return $this->ok(['message' => $this->t('Saved')]);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($this->t('Unknown setting.'));
        } catch (\Throwable $e) {
            return $this->fail($this->googleError($e), 502);
        }
    }

    /**
     * Dane raportu do szablonu: wyjatek zamieniony na komunikat, data aktualizacji.
     *
     * @param array<string,mixed> $report
     *
     * @return array<string,mixed>
     */
    private function viewReport(array $report): array
    {
        $error = $report['error'] ?? null;
        $report['errorMessage'] = $error instanceof \Throwable ? $this->googleError($error) : '';
        $report['failed'] = $report['responses'] === null;
        unset($report['error'], $report['responses']);

        return $report;
    }

    /**
     * Zdarzenia kluczowe z GA4, ktore modul wysyla (wlaczone w Events -> Event list) — jak w prototypie.
     *
     * @param string[] $keyEvents
     *
     * @return string[]
     */
    private function visibleKeyEvents(int $idShop, array $keyEvents): array
    {
        $enabled = Options::json(EventsOptions::EV_ENABLED, [], $idShop);

        // Brak wpisu = zdarzenie wlaczone (domyslnie wszystkie 15).
        return array_values(array_filter($keyEvents, static fn (string $e): bool => in_array($e, self::MODULE_EVENTS, true)
            && (!array_key_exists($e, $enabled) || (bool) $enabled[$e])));
    }

    /**
     * Sklep z tresci zadania (kreator przy kilku sklepach) albo biezacy.
     *
     * @param array<string,mixed> $data
     */
    private function shopFrom(array $data): int
    {
        // R-06: tylko sklep z kontekstu pracownika (jak TagManagerController) — inaczej POST
        // z cudzym id_shop zmienilby wlasciwosc GA4 i sekret MP sklepu, do ktorego nie ma dostepu.
        $id = (int) ($data['id_shop'] ?? 0);

        if ($id > 0 && ShopContext::exists($id) && in_array($id, array_map('intval', \Shop::getContextListShopID()), true)) {
            return $id;
        }

        return $this->idShop();
    }
}
