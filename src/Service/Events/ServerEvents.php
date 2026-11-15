<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\Events;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Configuration;
use Context;
use Order;
use Quince\Insight\Core\Clock;
use Quince\Insight\Core\Log;
use Quince\Insight\Core\Services;
use Quince\Insight\Core\ShopContext;
use Quince\Insight\Core\ShopRepository;
use Quince\Insight\Service\Analytics\Ga4Settings;
use Validate;

/**
 * Zakupy i zwroty z serwera (Measurement Protocol) oraz zapis `order_log`.
 *
 * Poprawki wzgledem TagPilota (research/02 §3.4–§3.5), wszystkie obowiazkowe:
 *  - status `sent` tylko z faktycznego wyniku wywolania, nie z ustawienia;
 *  - zwrot, ponowna wysylka i brakujace zamowienia uzywaja `ga_client_id` zapisanego przy
 *    zamowieniu — nigdy identyfikatora wyliczonego z id klienta;
 *  - kazdy zwrot (dokument korekty) to osobny wiersz z wlasnymi pozycjami i kwota;
 *  - anulowanie / status zwrotu wysyla tylko to, czego jeszcze nie zwrocono.
 */
final class ServerEvents
{
    public function __construct(
        private readonly EventsSettings $settings,
        private readonly OrderLog $log,
        private readonly EventLog $events,
        private readonly ShopData $shop,
        private readonly MpSender $sender,
        private readonly ConsentReader $consent,
        private readonly CheckoutContext $checkout,
        private readonly Ga4Settings $ga4,
        private readonly Services $services,
        private readonly ShopRepository $shops,
    ) {
    }

    /**
     * Czy zdarzenia (dzienniki, zakupy z serwera) maja w sklepie sens: kreator sklepu
     * ukonczony (R-02 — sklep w polowie kreatora nic nie zapisuje ani nie wysyla)
     * oraz Tag Manager albo Analytics wlaczone.
     */
    public function active(int $idShop): bool
    {
        return $this->shops->isLive($idShop)
            && ($this->services->enabled($idShop, 'gtm') || $this->services->enabled($idShop, 'ga4'));
    }

    /** Czy serwer moze w ogole wysylac w tym sklepie (Analytics wlaczone + identyfikator + sekret). */
    public function canSend(int $idShop): bool
    {
        return $this->services->enabled($idShop, 'ga4') && $this->sender->hasCredentials($idShop);
    }

    /**
     * `actionValidateOrder`: zapis wiersza i proba wysylki zakupu.
     */
    public function onOrderValidated(Order $order): void
    {
        $idShop = (int) $order->id_shop;

        if (!$this->active($idShop) || $this->log->find($idShop, (int) $order->id) !== null) {
            return;
        }

        $ids = ['client_id' => '', 'session_id' => ''];
        $consent = null;

        // W panelu ciasteczka naleza do pracownika, nie do klienta — nie wolno ich uzyc.
        if (!$this->inBackOffice() && isset($_COOKIE['_ga'])) {
            $ids = GaCookies::fromCookies($_COOKIE, $this->ga4->measurementId($idShop));
            $consent = $this->consent->current($idShop);
        }

        $saved = $this->checkout->find($idShop, (int) $order->id_cart);

        if ($ids['client_id'] === '' && $saved !== null) {
            $ids = ['client_id' => $saved['client_id'], 'session_id' => $saved['session_id']];
            $consent = $saved['consent'];
        }

        if ($consent === null) {
            $consent = $saved['consent'] ?? ($this->inBackOffice() ? ConsentMapper::normalize([]) : $this->consent->current($idShop));
        }

        $host = (string) parse_url(ShopContext::baseUrl($idShop), PHP_URL_HOST);

        $this->log->create($idShop, (int) $order->id, [
            'reference' => (string) $order->reference,
            'total' => (float) $order->total_paid_tax_incl,
            'currency' => $this->shop->currencyIso((int) $order->id_currency),
            'ga_client_id' => $ids['client_id'],
            'ga_session_id' => $ids['session_id'],
            'consent' => $consent,
            'source' => $this->shop->orderSource($order, $host),
            'date_add' => (string) $order->date_add ?: Clock::now(),
        ]);

        $this->sendPurchase($idShop, (int) $order->id, 'order');
    }

    /**
     * `actionOrderStatusPostUpdate`: zaplacone, a jeszcze nie wyslane -> wysylka;
     * anulowanie albo zwrot pieniedzy -> zwrot tego, czego jeszcze nie zwrocono.
     */
    public function onStatusChanged(int $idOrder, int $newStatus): void
    {
        $order = new Order($idOrder);

        if (!Validate::isLoadedObject($order)) {
            return;
        }

        $idShop = (int) $order->id_shop;
        $row = $this->log->find($idShop, $idOrder);

        if ($row === null || !$this->active($idShop)) {
            return;
        }

        $refundStates = [
            (int) Configuration::get('PS_OS_CANCELED', null, null, $idShop),
            (int) Configuration::get('PS_OS_REFUND', null, null, $idShop),
        ];

        if (in_array($newStatus, $refundStates, true)) {
            $this->refundRemaining($order);

            return;
        }

        $state = new \OrderState($newStatus);

        if (Validate::isLoadedObject($state) && $state->paid && in_array($row['server_status'], ['none', 'failed'], true)) {
            $this->sendPurchase($idShop, $idOrder, 'order');
        }
    }

    /**
     * Wysylka zakupu.
     *
     * @param string $mode order (przy zamowieniu / statusie) | resend (Order log) | missing (reczna) | auto (cron)
     *
     * @return array{status:string, reason:string, messages:array<int,mixed>, payload:array<string,mixed>}
     */
    public function sendPurchase(int $idShop, int $idOrder, string $mode, bool $dryRun = false): array
    {
        $row = $this->log->find($idShop, $idOrder);
        $result = ['status' => 'skipped', 'reason' => 'not_found', 'messages' => [], 'payload' => []];

        if ($row === null) {
            return $result;
        }

        $order = new Order($idOrder);

        if (!Validate::isLoadedObject($order)) {
            return $result;
        }

        $enabled = match ($mode) {
            'order' => $this->settings->serverPurchase($idShop) && $this->settings->eventOn($idShop, 'purchase'),
            'auto' => $this->settings->autoMissing($idShop),
            default => true,
        };
        $now = Clock::time();
        $reason = SendRules::purchaseReason($row, $enabled, $this->canSend($idShop), $now);

        if ($reason !== '') {
            // Reczna proba niczego nie zapisuje — zostaje powod z chwili zamowienia.
            if ($mode === 'order' && $row['server_status'] !== 'sent') {
                $this->log->update($idShop, $idOrder, ['server_status' => 'skipped', 'server_reason' => $reason]);
            }

            return ['status' => 'skipped', 'reason' => $reason, 'messages' => [], 'payload' => []];
        }

        if ($row['server_status'] === 'sent' && !$dryRun) {
            return ['status' => 'sent', 'reason' => '', 'messages' => [], 'payload' => (array) json_decode((string) $row['payload'], true)];
        }

        $payload = $this->purchasePayload($order, $row, $mode !== 'order');
        $outcome = $this->sender->send($idShop, $payload, $dryRun, $mode === 'order');

        if ($dryRun) {
            return ['status' => $outcome['ok'] ? 'valid' : 'invalid', 'reason' => $outcome['reason'], 'messages' => $outcome['messages'], 'payload' => $payload];
        }

        if ($outcome['ok']) {
            $this->log->update($idShop, $idOrder, [
                'server_status' => 'sent',
                'server_reason' => $mode === 'order' ? '' : $mode,
                'server_at' => Clock::now(),
                'payload' => $payload,
                'attempts' => (int) $row['attempts'] + 1,
            ]);
            $this->events->write($idShop, 'server', 'server:' . $mode, [['event' => 'purchase', 'payload' => $payload['events'][count($payload['events']) - 1]['params']]], $idOrder);

            return ['status' => 'sent', 'reason' => '', 'messages' => [], 'payload' => $payload];
        }

        $this->log->update($idShop, $idOrder, [
            'server_status' => 'failed',
            'server_reason' => substr($outcome['reason'], 0, 40),
            'payload' => $payload,
            'attempts' => (int) $row['attempts'] + 1,
        ]);

        return ['status' => 'failed', 'reason' => $outcome['reason'], 'messages' => $outcome['messages'], 'payload' => $payload];
    }

    /**
     * Tresc zakupu dla zamowienia (takze podglad w Order log).
     *
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    public function purchasePayload(Order $order, array $row, bool $withCampaign): array
    {
        $idShop = (int) $order->id_shop;
        $data = $this->shop->order($order, (int) $order->id_lang);
        $ecommerce = Ecommerce::purchase($data, $this->settings->productId($idShop), $this->settings->priceTax($idShop), $this->settings->categoryPath($idShop));
        $consent = SendRules::consent($row);
        $userData = [];
        $userId = '';

        if ($this->settings->serverUserData($idShop) && ConsentMapper::granted($consent, 'ad_user_data')) {
            $userData = UserData::forMeasurementProtocol($this->shop->orderCustomer($order));

            if ($userData !== []) {
                $customer = new \Customer((int) $order->id_customer);
                $userId = Validate::isLoadedObject($customer) && !$customer->is_guest ? (string) $customer->id : '';
            }
        }

        $source = json_decode((string) $row['source'], true);
        $campaign = $withCampaign && is_array($source) ? Attribution::campaignParams($source) : [];

        if ((string) $row['ga_client_id'] === '') {
            // Podglad zamowienia bez identyfikatora: pokazujemy, czego brakuje, nic nie wymyslamy.
            return ['client_id' => '', 'events' => [['name' => 'purchase', 'params' => $ecommerce]]];
        }

        return MpPayload::purchase(
            (string) $row['ga_client_id'],
            (string) $row['ga_session_id'],
            strtotime((string) $row['date_add']) ?: Clock::time(),
            Clock::time(),
            $ecommerce,
            $consent,
            $userData,
            $userId,
            $campaign
        );
    }

    /**
     * `actionOrderSlipAdd`: nowy dokument korekty -> zwrot z jego pozycjami i kwota.
     */
    public function onSlipAdded(Order $order): void
    {
        $idShop = (int) $order->id_shop;

        if (!$this->active($idShop)) {
            return;
        }

        foreach ($this->shop->slips((int) $order->id) as $slip) {
            $idSlip = (int) $slip['id_order_slip'];

            if ($this->log->refund($idShop, (int) $order->id, $idSlip) !== null) {
                continue;
            }

            // R-04: anulowanie / status zwrotu juz zwrocil w GA4 cala reszte zamowienia —
            // pozniejsza korekta jest jej czescia, druga wysylka zawyzylaby zwroty.
            $covered = SendRules::slipCovered($this->log->refund($idShop, (int) $order->id, 0));

            $withTax = $this->settings->priceTax($idShop);
            $data = $this->shop->order($order, (int) $order->id_lang);
            $items = Ecommerce::refundItems($data['lines'], $this->shop->slipRows($idSlip), $this->settings->productId($idShop), $withTax, $this->settings->categoryPath($idShop));
            $value = (float) ($withTax ? $slip['total_products_tax_incl'] : $slip['total_products_tax_excl']);
            $shipping = (float) ($withTax ? $slip['total_shipping_tax_incl'] : $slip['total_shipping_tax_excl']);
            $refund = Ecommerce::refund((string) $order->reference, $data['currency'], $value, $shipping, $items);

            if (!$this->log->createRefund($idShop, (int) $order->id, $idSlip, ['amount' => $value + $shipping, 'currency' => $data['currency'], 'items' => $refund])) {
                continue;
            }

            if ($covered) {
                $this->log->updateRefund($idShop, (int) $order->id, $idSlip, ['status' => 'skipped', 'reason' => 'covered']);
            } else {
                $this->sendRefund($idShop, (int) $order->id, $idSlip);
            }
        }
    }

    /** Zwrot pozostalej czesci zamowienia (status anulowany / zwrot pieniedzy). */
    public function refundRemaining(Order $order): void
    {
        $idShop = (int) $order->id_shop;
        $idOrder = (int) $order->id;

        if ($this->log->refund($idShop, $idOrder, 0) !== null) {
            return;
        }

        $withTax = $this->settings->priceTax($idShop);
        $data = $this->shop->order($order, (int) $order->id_lang);
        $refundedQty = [];
        $refundedValue = 0.0;
        $refundedShipping = 0.0;

        foreach ($this->shop->slips($idOrder) as $slip) {
            foreach ($this->shop->slipRows((int) $slip['id_order_slip']) as $row) {
                $refundedQty[$row['id_order_detail']] = ($refundedQty[$row['id_order_detail']] ?? 0) + $row['quantity'];
            }

            $refundedValue += (float) ($withTax ? $slip['total_products_tax_incl'] : $slip['total_products_tax_excl']);
            $refundedShipping += (float) ($withTax ? $slip['total_shipping_tax_incl'] : $slip['total_shipping_tax_excl']);
        }

        $lines = Ecommerce::remainingLines($data['lines'], $refundedQty);
        $purchase = Ecommerce::purchase($data, $this->settings->productId($idShop), $withTax, $this->settings->categoryPath($idShop));
        $value = max(0.0, (float) $purchase['value'] - $refundedValue);
        $shipping = max(0.0, (float) $purchase['shipping'] - $refundedShipping);

        if ($lines === [] && $value <= 0 && $shipping <= 0) {
            return;
        }

        $items = [];

        foreach ($lines as $i => $line) {
            $items[] = Ecommerce::item($line, $this->settings->productId($idShop), $withTax, $this->settings->categoryPath($idShop), $i);
        }

        $refund = Ecommerce::refund((string) $order->reference, $data['currency'], $value, $shipping, $items);

        if ($this->log->createRefund($idShop, $idOrder, 0, ['amount' => $value + $shipping, 'currency' => $data['currency'], 'items' => $refund])) {
            $this->sendRefund($idShop, $idOrder, 0);
        }
    }

    /** @return array{status:string, reason:string} */
    public function sendRefund(int $idShop, int $idOrder, int $idSlip): array
    {
        $refund = $this->log->refund($idShop, $idOrder, $idSlip);
        $row = $this->log->find($idShop, $idOrder);

        if ($refund === null || $row === null) {
            return ['status' => 'skipped', 'reason' => 'not_found'];
        }

        $enabled = $this->settings->serverRefund($idShop) && $this->settings->eventOn($idShop, 'refund');
        $reason = SendRules::refundReason($row, $enabled, $this->canSend($idShop));

        if ($reason !== '') {
            $this->log->updateRefund($idShop, $idOrder, $idSlip, ['status' => 'skipped', 'reason' => $reason]);

            return ['status' => 'skipped', 'reason' => $reason];
        }

        $params = (array) json_decode((string) $refund['items'], true);
        $payload = MpPayload::refund((string) $row['ga_client_id'], Clock::time(), $params, SendRules::consent($row));
        $outcome = $this->sender->send($idShop, $payload, false, !$this->inBackOffice() && PHP_SAPI !== 'cli');

        $this->log->updateRefund($idShop, $idOrder, $idSlip, [
            'status' => $outcome['ok'] ? 'sent' : 'failed',
            'reason' => $outcome['ok'] ? '' : substr($outcome['reason'], 0, 40),
            'sent_at' => $outcome['ok'] ? Clock::now() : null,
            'payload' => $payload,
            'attempts' => (int) $refund['attempts'] + 1,
        ]);

        if ($outcome['ok']) {
            $this->events->write($idShop, 'server', 'server:refund', [['event' => 'refund', 'payload' => $params]], $idOrder);
        } else {
            Log::write($idShop, 'events', Log::WARNING, 'Refund was not sent to GA4.', ['id_order' => $idOrder, 'reason' => $outcome['reason']]);
        }

        return ['status' => $outcome['ok'] ? 'sent' : 'failed', 'reason' => $outcome['reason']];
    }

    /**
     * Cron `orders`: ponowienia bledow sieci, brakujace zamowienia (automat), zwroty.
     */
    public function runCron(int $idShop): string
    {
        if (!$this->shops->isLive($idShop)) {
            return 'Nothing to send: the setup wizard is not finished in this shop.';
        }

        if (!$this->active($idShop) || !$this->canSend($idShop)) {
            return 'Nothing to send: Analytics is not set up for server-side events.';
        }

        $now = Clock::time();
        $sent = 0;
        $failed = 0;
        $after = $this->settings->autoMissingAfter($idShop);
        $auto = $this->settings->autoMissing($idShop);
        $deadline = microtime(true) + 15;

        foreach ($this->log->pending($idShop) as $row) {
            if (microtime(true) > $deadline) {
                break;
            }

            $mode = null;

            if (SendRules::retryable($row)) {
                $mode = 'order';
            } elseif ($auto && SendRules::autoSendable($row, $after, $now)) {
                $mode = 'auto';
            }

            if ($mode === null) {
                continue;
            }

            $result = $this->sendPurchase($idShop, (int) $row['id_order'], $mode);
            $result['status'] === 'sent' ? ++$sent : ($result['status'] === 'failed' ? ++$failed : null);
        }

        foreach ($this->log->pendingRefunds($idShop) as $refund) {
            if (microtime(true) > $deadline) {
                break;
            }

            $result = $this->sendRefund($idShop, (int) $refund['id_order'], (int) $refund['id_order_slip']);
            $result['status'] === 'sent' ? ++$sent : ($result['status'] === 'failed' ? ++$failed : null);
        }

        return $sent || $failed ? sprintf('%d sent to GA4, %d failed.', $sent, $failed) : 'Nothing to send.';
    }

    private function inBackOffice(): bool
    {
        $controller = Context::getContext()->controller ?? null;

        return defined('_PS_ADMIN_DIR_') || $controller instanceof \AdminController
            || (is_object($controller) && isset($controller->controller_type) && $controller->controller_type === 'admin');
    }
}
