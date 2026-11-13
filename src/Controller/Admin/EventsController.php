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
use Quince\Insight\Core\Clock;
use Quince\Insight\Core\Options;
use Quince\Insight\Core\Services;
use Quince\Insight\Service\Analytics\AnalyticsOptions;
use Quince\Insight\Service\Analytics\Ga4Settings;
use Quince\Insight\Service\Events\ConsentReader;
use Quince\Insight\Service\Events\CustomerReviews;
use Quince\Insight\Service\Events\EventLog;
use Quince\Insight\Service\Events\EventsOptions;
use Quince\Insight\Service\Events\EventsSettings;
use Quince\Insight\Service\Events\Json;
use Quince\Insight\Service\Events\MissingOrders;
use Quince\Insight\Service\Events\Module as EventsModule;
use Quince\Insight\Service\Events\MpPayload;
use Quince\Insight\Service\Events\MpSender;
use Quince\Insight\Service\Events\OrderLog;
use Quince\Insight\Service\Events\SendRules;
use Quince\Insight\Service\Events\ServerEvents;
use Quince\Insight\Service\TagManager\ContainerCard;
use Quince\Insight\Service\TagManager\GtmSettings;
use Quince\Insight\Ui\PageTabs;
use Quince\Insight\Ui\Pagination;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ekran Events — 1:1 z prototypu (`pg==='tracking'`, 7 zakladek z `TR_TABS`).
 *
 * Ustawienia zapisuja sie od razu po zmianie (prototyp nie ma przycisku Save):
 * `events_save` (jedno pole), `events_event_toggle`, `events_key_toggle`.
 * Karta kontenera na Overview pochodzi od agenta D (`events/_container.html.twig`).
 */
final class EventsController extends PanelController
{
    private const TABS = [
        'overview' => 'Overview',
        'events' => 'Event list',
        'consent' => 'Consent',
        'server' => 'Server-side',
        'orders' => 'Order log',
        'log' => 'Event log',
        'missing' => 'Missing orders',
    ];

    public function __construct(
        private readonly PageTabs $pageTabs,
        private readonly Pagination $pagination,
        private readonly EventsSettings $settings,
        private readonly OrderLog $orders,
        private readonly EventLog $events,
        private readonly ServerEvents $server,
        private readonly MissingOrders $missing,
        private readonly ConsentReader $consent,
        private readonly MpSender $sender,
        private readonly CustomerReviews $reviews,
        private readonly Ga4Settings $ga4,
        private readonly GtmSettings $gtm,
        private readonly ContainerCard $card,
        private readonly Services $services,
    ) {
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", message: 'You do not have permission to view this.')]
    public function indexAction(Request $request): Response
    {
        $idShop = $this->idShop();
        $tabs = $this->pageTabs->resolve($request, self::TABS);
        $tab = $tabs['current'];
        $data = [
            'tabs' => $tabs,
            'tab' => $tab,
            'groups' => $this->groups(),
            'allEvents' => EventsSettings::allEvents(),
        ];

        $data += match ($tab) {
            'events' => $this->eventListTab($idShop),
            'consent' => $this->consentTab($idShop),
            'server' => $this->serverTab($idShop),
            'orders' => $this->ordersTab($request, $idShop),
            'log' => $this->logTab($request, $idShop),
            'missing' => $this->missingTab($idShop),
            default => $this->overviewTab($idShop),
        };

        return $this->renderPanel('events/index.html.twig', 'events', $data);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function saveAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_events_save');
        $input = $this->payload($request);
        $idShop = $this->idShop();
        $key = (string) ($input['key'] ?? '');
        $error = $this->settings->save($idShop, $key, $input['value'] ?? null);

        if ($error !== '') {
            return $this->fail($this->t($error));
        }

        return $this->ok(['message' => $this->t('Saved')]);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function eventToggleAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_events_event_toggle');
        $input = $this->payload($request);
        $event = (string) ($input['event'] ?? '');

        if (!in_array($event, EventsSettings::allEvents(), true)) {
            return $this->fail($this->t('Unknown event.'));
        }

        // Wspolny kontener: zdarzenia ustawia sklep zarzadzajacy kontenerem.
        $this->settings->setEvent($this->gtm->eventsShop($this->idShop()), $event, (bool) ($input['on'] ?? false));

        return $this->ok(['message' => $this->t('Saved')]);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function keyToggleAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_events_key_toggle');
        $input = $this->payload($request);
        $event = (string) ($input['event'] ?? '');

        if (!in_array($event, EventsSettings::allEvents(), true)) {
            return $this->fail($this->t('Unknown event.'));
        }

        try {
            $this->ga4->setKeyEvent($this->idShop(), $event, (bool) ($input['on'] ?? false));
        } catch (\DomainException $e) {
            return $this->fail($this->t($e->getMessage()));
        } catch (\Throwable $e) {
            return $this->fail($this->googleError($e));
        }

        return $this->ok(['message' => $this->t('Saved')]);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function testServerAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_events_test_server');
        $idShop = $this->idShop();

        if ($this->ga4->measurementId($idShop) === '') {
            return $this->fail($this->t('Choose the Analytics property and data stream first.'));
        }

        if ($this->ga4->mpSecret($idShop) === '') {
            return $this->fail($this->t('The Measurement Protocol secret is missing. Create it in Analytics → Property.'));
        }

        $result = $this->sender->send($idShop, MpPayload::test(Clock::time()), true);

        if (!$result['ok']) {
            $detail = $result['messages'][0]['description'] ?? $result['reason'];

            return $this->fail($this->t('GA4 did not accept the test event: %detail%', ['%detail%' => (string) $detail]));
        }

        return $this->ok(['message' => $this->t('GA4 accepted the test event. Nothing was recorded in reports — the test uses Google\'s validation endpoint.')]);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function orderResendAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_events_order_resend');
        $idShop = $this->idShop();
        $idOrder = (int) ($this->payload($request)['id'] ?? 0);
        $row = $this->orders->find($idShop, $idOrder);

        if ($row === null) {
            return $this->fail($this->t('This order is not in the log.'), 404);
        }

        $result = $this->server->sendPurchase($idShop, $idOrder, 'resend');

        if ($result['status'] === 'sent') {
            return $this->ok(['message' => $this->t('Order %ref% sent to GA4', ['%ref%' => (string) $row['reference']])]);
        }

        return $this->fail($this->t($this->reasonText($result['reason'])));
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function orderPayloadAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_events_order_payload');
        $idShop = $this->idShop();
        $idOrder = (int) ($this->payload($request)['id'] ?? 0);
        $row = $this->orders->find($idShop, $idOrder);

        if ($row === null) {
            return $this->fail($this->t('This order is not in the log.'), 404);
        }

        $payload = json_decode((string) $row['payload'], true);
        $stored = is_array($payload);

        if (!$stored) {
            $order = new \Order($idOrder);

            if (!\Validate::isLoadedObject($order)) {
                return $this->fail($this->t('The order no longer exists in the shop.'), 404);
            }

            $payload = $this->server->purchasePayload($order, $row, false);
        }

        $refunds = [];

        foreach ($this->orders->refunds($idShop, $idOrder) as $refund) {
            $refunds[] = [
                'slip' => (int) $refund['id_order_slip'],
                'amount' => (float) $refund['amount'],
                'status' => (string) $refund['status'],
                'reason' => $refund['reason'] !== '' ? $this->t($this->reasonText((string) $refund['reason'])) : '',
                'date' => substr((string) $refund['date_add'], 0, 16),
            ];
        }

        return $this->ok([
            'payload' => Json::encode($payload, JSON_PRETTY_PRINT | Json::FLAGS),
            'stored' => $stored,
            'refunds' => $refunds,
        ]);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function missingScanAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_events_missing_scan');
        $rows = $this->missing->scan($this->idShop());

        foreach ($rows as &$row) {
            $row['total'] = $this->price((float) $row['total'], (string) $row['currency']);
            $row['reason'] = $this->t((string) $row['reason']);
            $row['blocked'] = $row['blocked'] === '' ? '' : $this->t((string) $row['blocked']);
        }

        unset($row);

        return $this->ok(['orders' => $rows]);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function missingSendAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_events_missing_send');
        $input = $this->payload($request);
        $ids = array_map('intval', (array) ($input['ids'] ?? []));
        $dry = (bool) ($input['dry'] ?? true);
        $idShop = $this->idShop();

        if ($ids === []) {
            return $this->fail($this->t('Select at least one order.'));
        }

        if (!$this->server->canSend($idShop)) {
            return $this->fail($this->t('The Measurement Protocol secret is missing. Create it in Analytics → Property.'));
        }

        $result = $this->missing->send($idShop, $ids, $dry);

        if ($result['failed'] > 0) {
            return $this->fail($this->t('%count% orders were not accepted: %errors%', [
                '%count%' => $result['failed'],
                '%errors%' => implode('; ', array_slice($result['errors'], 0, 3)),
            ]), 400, ['result' => $result]);
        }

        $count = $dry ? $result['valid'] : $result['sent'];

        return $this->ok([
            'result' => $result,
            'message' => $dry
                ? $this->t('Test run finished — nothing was sent')
                : ($count === 1 ? $this->t('1 order sent to GA4') : $this->t('%count% orders sent to GA4', ['%count%' => $count])),
        ]);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function logClearAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_events_log_clear');
        $this->events->clear($this->idShop());

        return $this->ok(['message' => $this->t('Event log cleared.')]);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function reviewsSaveAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_reviews_save');
        $errors = $this->reviews->save($this->idShop(), $this->payload($request));

        if ($errors !== []) {
            return $this->fail($this->t((string) reset($errors)), 400, ['errors' => $errors]);
        }

        return $this->ok(['message' => $this->t('Saved')]);
    }

    /* ---------------- zakladki ---------------- */

    /** @return array<string,mixed> */
    private function overviewTab(int $idShop): array
    {
        $gtm = EventsModule::frontContainer($idShop);
        $source = $this->settings->consentSource($idShop);
        $effective = $this->consent->effectiveSource($idShop);
        $seen = Options::str(EventsOptions::EV_SNIPPET_SEEN, '', $idShop);
        $last = $this->settings->logOn($idShop) ? $this->events->lastBrowserEvent($idShop) : null;
        $other = Options::json(EventsOptions::EV_OTHER_GTM, [], $idShop);
        $sourceName = ['qcmp' => 'Quince CMP', 'other' => 'your consent tool', 'none' => 'module defaults'][$source];
        $hasCreds = $this->sender->hasCredentials($idShop);

        $checks = [
            ['on' => $gtm !== '', 'text' => $this->t('GTM snippet on every page'), 'note' => $gtm === '' ? $this->t('not on the pages') : ($seen !== '' ? $this->t('checked %ago%', ['%ago%' => $this->ago($seen)]) : $this->t('not seen yet'))],
            ['on' => $last !== null && (Clock::secondsSince($last) ?? PHP_INT_MAX) < 86400, 'text' => $this->t('Events reach the dataLayer'), 'note' => !$this->settings->logOn($idShop) ? $this->t('event log is off') : ($last !== null ? $this->t('last: %ago%', ['%ago%' => $this->ago($last)]) : $this->t('none yet'))],
            ['on' => $effective === $source, 'text' => $this->t('Consent from %source%', ['%source%' => $this->t($sourceName)]), 'note' => $this->t('Consent Mode v2')],
            ['on' => $hasCreds, 'text' => $this->t('Measurement Protocol secret'), 'note' => $hasCreds ? $this->t('saved') : $this->t('missing')],
            ['on' => false, 'text' => $this->t('Another GTM snippet found in the theme'), 'note' => !empty($other['ids']) ? implode(', ', (array) $other['ids']) : $this->t('none')],
        ];

        $stats = $this->orders->stats($idShop, 7) + ['all' => 0, 'both' => 0, 'server_only' => 0, 'browser_only' => 0, 'none' => 0, 'no_consent' => 0, 'no_session' => 0];
        $all = max(0, $stats['all']);
        $pct = static fn (int $n): int => $all > 0 ? (int) round($n / $all * 100) : 0;
        $pBoth = $pct($stats['both']);
        $pServer = $pct($stats['server_only']);
        $pBrowser = $pct($stats['browser_only']);

        return [
            'containerCard' => $this->card->data($idShop),
            'checks' => $checks,
            'stats' => [
                'all' => $all,
                'reached' => $all - $stats['none'],
                'none' => $stats['none'],
                'noConsent' => $stats['no_consent'],
                'noSession' => $stats['no_session'],
                'pBoth' => $pBoth,
                'pServer' => $pServer,
                'pBrowser' => $pBrowser,
                'pNone' => $all > 0 ? max(0, 100 - $pBoth - $pServer - $pBrowser) : 0,
            ],
            'on' => $this->settings->events($this->gtm->eventsShop($idShop)),
            'counts' => $this->events->counts($idShop, 24),
        ];
    }

    /** @return array<string,mixed> */
    private function eventListTab(int $idShop): array
    {
        $eventsShop = $this->gtm->eventsShop($idShop);
        $keyEvents = [];
        $keyError = '';

        if ($this->services->enabled($idShop, 'ga4') && $this->ga4->property($idShop) !== '') {
            try {
                $keyEvents = $this->ga4->keyEvents($idShop);
            } catch (\Throwable $e) {
                $keyError = $this->googleError($e);
            }
        } else {
            $keyError = $this->t('Key events need the Analytics property of this shop.');
        }

        return [
            'on' => $this->settings->events($eventsShop),
            'keyEvents' => $keyEvents,
            'keyError' => $keyError,
            'sharedFrom' => $eventsShop !== $idShop ? \Quince\Insight\Core\ShopContext::name($eventsShop) : '',
            'productId' => $this->settings->productId($idShop),
            'priceTax' => $this->settings->priceTax($idShop),
            'categoryPath' => $this->settings->categoryPath($idShop),
            'userData' => $this->settings->userData($idShop),
        ];
    }

    /** @return array<string,mixed> */
    private function consentTab(int $idShop): array
    {
        return [
            'consentSource' => $this->settings->consentSource($idShop),
            'consentCookie' => $this->settings->consentCookie($idShop),
            'consentDefaults' => $this->settings->consentDefaults($idShop),
            'consentWait' => $this->settings->consentWait($idShop),
            'consentMap' => $this->settings->consentMap($idShop),
            'qcmpDetected' => ConsentReader::qcmpAvailable(),
        ];
    }

    /** @return array<string,mixed> */
    private function serverTab(int $idShop): array
    {
        $measurementId = $this->ga4->measurementId($idShop);
        $hasSecret = $this->ga4->mpSecret($idShop) !== '';
        $byModule = Options::str(AnalyticsOptions::MP_SECRET_NAME, '', $idShop) !== '';

        if ($measurementId === '') {
            $line = $this->t('No measurement ID yet — choose the data stream in Analytics');
        } elseif (!$hasSecret) {
            $line = $this->t('Measurement ID %id% · no secret yet', ['%id%' => $measurementId]);
        } else {
            $line = $byModule
                ? $this->t('Measurement ID %id% · secret created by the module', ['%id%' => $measurementId])
                : $this->t('Measurement ID %id% · secret saved', ['%id%' => $measurementId]);
        }

        return [
            'serverPurchase' => $this->settings->serverPurchase($idShop),
            'serverRefund' => $this->settings->serverRefund($idShop),
            'serverUserData' => $this->settings->serverUserData($idShop),
            'connectionLine' => $line,
            'canTest' => $measurementId !== '' && $hasSecret,
        ];
    }

    /** @return array<string,mixed> */
    private function ordersTab(Request $request, int $idShop): array
    {
        $filter = (string) $request->query->get('filter', 'all');
        $filter = in_array($filter, OrderLog::FILTERS, true) ? $filter : 'all';
        $pagination = $this->pagination->resolve($request, $this->orders->count($idShop, $filter));
        $page = $this->orders->page($idShop, $filter, $pagination['per'], $pagination['offset']);
        $canSend = $this->server->canSend($idShop);
        $now = Clock::time();
        $rows = [];

        foreach ($page['rows'] as $row) {
            $status = (string) $row['server_status'];
            $reason = (string) $row['server_reason'];
            $refunded = (float) $row['refunded'];
            $rows[] = [
                'id' => (int) $row['id_order'],
                'ref' => (string) $row['reference'],
                'date' => substr((string) $row['date_add'], 0, 16),
                'total' => $this->price((float) $row['total'], (string) $row['currency']),
                'payment' => (string) ($row['payment'] ?? ''),
                'browser' => !empty($row['browser_at']),
                'server' => ['t' => $this->t($this->serverLabel($status, $reason)['t']), 'c' => $this->serverLabel($status, $reason)['c']],
                'status' => $status,
                'reason' => $reason,
                'statusText' => $this->t($this->statusText($status, $reason)),
                'refund' => (int) $row['refunds'] > 0 ? ($refunded + 0.005 >= (float) $row['total'] ? 'full' : 'partial') : '',
                'canResend' => $status !== 'sent' && SendRules::purchaseReason($row, true, $canSend, $now) === '',
            ];
        }

        return [
            'filter' => $filter,
            'rows' => $rows,
            'pagination' => $pagination,
        ];
    }

    /** @return array<string,mixed> */
    private function logTab(Request $request, int $idShop): array
    {
        $event = (string) $request->query->get('event', 'all');
        $event = in_array($event, EventsSettings::allEvents(), true) ? $event : 'all';
        $pagination = $this->pagination->resolve($request, $this->events->count($idShop, $event));
        $page = $this->events->page($idShop, $event, $pagination['per'], $pagination['offset']);
        $rows = [];

        foreach ($page['rows'] as $row) {
            $payload = json_decode((string) $row['payload'], true);
            $rows[] = [
                'id' => (int) $row['id_event_log'],
                'time' => (string) $row['date_add'],
                'event' => (string) $row['event'],
                'page' => (string) $row['page'],
                'payload' => Json::encode(is_array($payload) ? $payload : [], JSON_PRETTY_PRINT | Json::FLAGS),
            ];
        }

        return [
            'logEvent' => $event,
            'rows' => $rows,
            'pagination' => $pagination,
            'logOn' => $this->settings->logOn($idShop),
            'keepDays' => $this->settings->logKeepDays($idShop),
        ];
    }

    /** @return array<string,mixed> */
    private function missingTab(int $idShop): array
    {
        return [
            'autoMissing' => $this->settings->autoMissing($idShop),
            'autoHours' => intdiv($this->settings->autoMissingAfter($idShop), 60),
            'canSend' => $this->server->canSend($idShop),
        ];
    }

    /* ---------------- pomocnicze ---------------- */

    /** @return array<int,array{label:string, events:array<int,array{key:string, where:string, source:string}>}> */
    private function groups(): array
    {
        $out = [];

        foreach (EventsSettings::GROUPS as $label => $events) {
            $list = [];

            foreach ($events as $key => [$where, $source]) {
                $list[] = ['key' => $key, 'where' => $where, 'source' => $source];
            }

            $out[] = ['label' => $label, 'events' => $list];
        }

        return $out;
    }

    /** @return array{t:string, c:string} */
    private function serverLabel(string $status, string $reason): array
    {
        if ($status === 'sent') {
            return ['t' => 'Sent', 'c' => 'qc-badge-success'];
        }

        if ($status === 'none') {
            return ['t' => 'Waiting', 'c' => ''];
        }

        return match ($reason) {
            'no_consent' => ['t' => 'No analytics consent', 'c' => ''],
            'no_session' => ['t' => 'No GA session', 'c' => 'qc-badge-warning'],
            'disabled' => ['t' => 'Sending switched off', 'c' => ''],
            'no_secret' => ['t' => 'No secret', 'c' => 'qc-badge-warning'],
            'too_old' => ['t' => 'Older than 72 h', 'c' => ''],
            default => ['t' => 'Failed', 'c' => 'qc-badge-danger'],
        };
    }

    private function statusText(string $status, string $reason): string
    {
        if ($status === 'sent') {
            return match ($reason) {
                'resend' => 'Sent from the order log.',
                'missing' => 'Sent from Missing orders.',
                'auto' => 'Sent by the Orders cron — the browser event never came.',
                default => 'Sent from the server when the order was placed.',
            };
        }

        if ($status === 'none') {
            return 'Not sent yet — the Orders cron sends it if the browser event does not come.';
        }

        return $this->reasonText($reason);
    }

    private function reasonText(string $reason): string
    {
        return match ($reason) {
            'no_consent' => 'Not sent — the customer refused analytics. The module does not send it again.',
            'no_session' => 'Not sent — no GA session at the time of the order. The browser had no _ga cookie, and the module never makes up a visitor ID, so it cannot be sent from the server.',
            'disabled' => 'Not sent — sending purchases from the server is switched off. Only the browser event from the confirmation page counts.',
            'no_secret' => 'Not sent — the Measurement Protocol secret is missing.',
            'too_old' => 'Not sent — the order is older than 72 hours. GA4 does not accept older purchases.',
            'no_purchase' => 'Not sent — the purchase never reached GA4, so there is nothing to refund.',
            'covered' => 'Not sent — the cancellation already refunded the rest of this order in GA4, including this credit slip.',
            'timeout' => 'Google did not answer in time. The Orders cron tries again.',
            'invalid' => 'GA4 did not accept the data.',
            'not_found' => 'This order is not in the log.',
            default => str_starts_with($reason, 'http_') ? 'Google answered with an HTTP error. The Orders cron tries again when it makes sense.' : 'Not sent.',
        };
    }

    private function price(float $amount, string $iso): string
    {
        try {
            return \Context::getContext()->getCurrentLocale()->formatPrice($amount, $iso !== '' ? $iso : 'EUR');
        } catch (\Throwable $e) {
            return number_format($amount, 2, ',', ' ') . ' ' . $iso;
        }
    }

    private function ago(string $datetime): string
    {
        $seconds = Clock::secondsSince($datetime) ?? 0;

        if ($seconds < 60) {
            return $this->t('%count% s ago', ['%count%' => max(0, $seconds)]);
        }

        if ($seconds < 3600) {
            return $this->t('%count% min ago', ['%count%' => intdiv($seconds, 60)]);
        }

        if ($seconds < 86400) {
            return $this->t('%count% h ago', ['%count%' => intdiv($seconds, 3600)]);
        }

        return substr($datetime, 0, 16);
    }
}
