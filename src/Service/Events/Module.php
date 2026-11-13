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

use Context;
use Order;
use Quince\Insight\Core\Clock;
use Quince\Insight\Core\Container;
use Quince\Insight\Core\Dto\AttentionItem;
use Quince\Insight\Core\Dto\CronTask;
use Quince\Insight\Core\Dto\ServiceHealth;
use Quince\Insight\Core\Options;
use Quince\Insight\Core\Scheduler;
use Quince\Insight\Core\ServiceModule;
use Quince\Insight\Core\Services;
use Quince\Insight\Service\Events\Task\OrdersTask;
use Quince\Insight\Service\Events\Task\RetentionTask;
use Quince\Insight\Service\TagManager\GtmSettings;

/**
 * Usluga `events` — zdarzenia sklepu: dataLayer, zgody, wysylka z serwera, dzienniki, Customer Reviews.
 *
 * `requires()` = null (decyzja agenta C): usluga sklada sie z trzech czesci zaleznych od
 * roznych uslug kreatora, wiec kazda sprawdza swoja sama —
 *  - snippet GTM, zgody i dataLayer: `gtm` + identyfikator kontenera,
 *  - zakupy i zwroty z serwera: `ga4` + identyfikator pomiaru + sekret MP,
 *  - Customer Reviews: `gcr` + potwierdzenie usera + konto Merchant Center.
 * Bez zadnej z nich modul nie wypisuje na stronie sklepu nic.
 */
final class Module implements ServiceModule
{
    /** Zapis „snippet widziany” najwyzej co tyle sekund (kontrola na Overview, bez zapisu przy kazdej stronie). */
    private const SNIPPET_SEEN_EVERY = 300;

    public function key(): string
    {
        return 'events';
    }

    public function requires(): ?string
    {
        return null;
    }

    public function hooks(): array
    {
        return [
            'displayHeader' => 'onHeader',
            'displayAfterBodyOpeningTag' => 'onBodyOpen',
            'displayBeforeBodyClosingTag' => 'onBodyClose',
            'actionFrontControllerSetMedia' => 'onSetMedia',
            'displayOrderConfirmation' => 'onOrderConfirmation',
            'actionValidateOrder' => 'onValidateOrder',
            'actionOrderStatusPostUpdate' => 'onOrderStatus',
            'actionOrderSlipAdd' => 'onOrderSlip',
            'actionAuthentication' => 'onLogin',
            'actionCustomerAccountAdd' => 'onSignUp',
        ];
    }

    public function cronTasks(): array
    {
        return [
            new CronTask('events.orders', Scheduler::GROUP_ORDERS, OrdersTask::class, 0, 20),
            new CronTask('events.retention', Scheduler::GROUP_MAINTENANCE, RetentionTask::class, 0, 10),
        ];
    }

    public function jobs(): array
    {
        return [];
    }

    public function tables(): array
    {
        return Schema::tables();
    }

    /* ---------------- front ---------------- */

    /** Identyfikator kontenera, gdy snippet i dataLayer maja byc na stronie; '' = nic nie wstawiamy. */
    public static function frontContainer(int $idShop): string
    {
        if (!Container::get(Services::class)->enabled($idShop, 'gtm')) {
            return '';
        }

        $id = Container::get(GtmSettings::class)->publicId($idShop);

        return FrontHtml::validGtmId($id) ? $id : '';
    }

    /** @param array<string,mixed> $params */
    public function onHeader(array $params): string
    {
        $idShop = $this->shopId();
        $gtm = self::frontContainer($idShop);

        if ($gtm === '') {
            return '';
        }

        $settings = Container::get(EventsSettings::class);
        $reader = Container::get(ConsentReader::class);
        $source = $reader->effectiveSource($idShop);
        $html = '';

        if ($source !== 'qcmp') {
            $html .= FrontHtml::consent($source, $settings->consentDefaults($idShop), $settings->consentWait($idShop), $settings->consentCookie($idShop), $settings->consentMap($idShop));
        }

        $seen = Clock::secondsSince(Options::str(EventsOptions::EV_SNIPPET_SEEN, '', $idShop));

        if ($seen === null || $seen > self::SNIPPET_SEEN_EVERY) {
            Options::set(EventsOptions::EV_SNIPPET_SEEN, Clock::now(), $idShop);
        }

        return $html . FrontHtml::gtmHead($gtm);
    }

    /** @param array<string,mixed> $params */
    public function onBodyOpen(array $params): string
    {
        $gtm = self::frontContainer($this->shopId());

        return $gtm === '' ? '' : FrontHtml::gtmNoscript($gtm);
    }

    /** @param array<string,mixed> $params */
    public function onSetMedia(array $params): void
    {
        $idShop = $this->shopId();

        if (self::frontContainer($idShop) === '') {
            return;
        }

        $controller = Context::getContext()->controller;

        if (is_object($controller) && method_exists($controller, 'registerJavascript')) {
            $controller->registerJavascript('quince-insight-events', 'modules/quince_insight/views/js/front/events.js', ['position' => 'bottom', 'priority' => 200]);
        }
    }

    /** @param array<string,mixed> $params */
    public function onOrderConfirmation(array $params): string
    {
        $order = $params['order'] ?? null;

        if ($order instanceof Order && (int) $order->id > 0) {
            Container::get(Storefront::class)->rememberConfirmation($order);
        }

        // Nic tu nie wypisujemy: motywy obudowuja tresc tego hooka sekcja na stronie.
        return '';
    }

    /** @param array<string,mixed> $params */
    public function onBodyClose(array $params): string
    {
        $idShop = $this->shopId();
        $storefront = Container::get(Storefront::class);
        $html = '';
        $gtm = self::frontContainer($idShop);

        if ($gtm !== '') {
            $built = $storefront->build($idShop);
            $settings = Container::get(EventsSettings::class);
            $config = $built['config'] + [
                'gtm' => $gtm,
                'log' => [
                    'on' => $settings->logOn($idShop),
                    'url' => Context::getContext()->link->getModuleLink('quince_insight', 'event', [], null, null, $idShop),
                    'token' => Beacon::token($idShop),
                ],
            ];
            $html .= FrontHtml::events($built['events'], $config);

            $logged = [];
            $idOrder = null;

            foreach ($built['events'] as $event) {
                $name = (string) ($event['data']['event'] ?? '');

                if ($name !== '') {
                    $logged[] = ['event' => $name, 'payload' => $event['data']];
                    $idOrder = $event['id_order'] ?? $idOrder;
                }
            }

            $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
            Container::get(EventLog::class)->write($idShop, 'browser', $uri, $logged, $idOrder);
        }

        $order = $storefront->confirmation();
        $reviews = Container::get(CustomerReviews::class);

        if ($order !== null && $reviews->active($idShop)
            && ConsentMapper::granted(Container::get(ConsentReader::class)->current($idShop), 'ad_user_data')) {
            $optIn = $reviews->optIn($order);

            if ($optIn !== null) {
                $html .= FrontHtml::customerReviews($optIn, (string) (Context::getContext()->language->iso_code ?? 'en'));
            }
        }

        return $html;
    }

    /* ---------------- zamowienia ---------------- */

    /** @param array<string,mixed> $params */
    public function onValidateOrder(array $params): void
    {
        $order = $params['order'] ?? null;

        if ($order instanceof Order && (int) $order->id > 0) {
            Container::get(ServerEvents::class)->onOrderValidated($order);
        }
    }

    /** @param array<string,mixed> $params */
    public function onOrderStatus(array $params): void
    {
        $state = $params['newOrderStatus'] ?? null;
        $idOrder = (int) ($params['id_order'] ?? 0);

        if ($idOrder > 0 && is_object($state) && (int) $state->id > 0) {
            Container::get(ServerEvents::class)->onStatusChanged($idOrder, (int) $state->id);
        }
    }

    /** @param array<string,mixed> $params */
    public function onOrderSlip(array $params): void
    {
        $order = $params['order'] ?? null;

        if ($order instanceof Order && (int) $order->id > 0) {
            Container::get(ServerEvents::class)->onSlipAdded($order);
        }
    }

    /* ---------------- konto ---------------- */

    /** @param array<string,mixed> $params */
    public function onLogin(array $params): void
    {
        $this->flag('qi_ev_login');
    }

    /** @param array<string,mixed> $params */
    public function onSignUp(array $params): void
    {
        $this->flag('qi_ev_signup');
    }

    /* ---------------- pulpit ---------------- */

    public function health(int $idShop): ?ServiceHealth
    {
        $services = Container::get(Services::class);

        if (!$services->enabled($idShop, 'gtm') && !$services->enabled($idShop, 'ga4')) {
            return null;
        }

        $stats = Container::get(OrderLog::class)->stats($idShop, 7);
        $count = count(Container::get(EventsSettings::class)->enabledEvents($idShop));
        $attention = $this->attention($idShop);
        $state = $attention === [] ? ServiceHealth::OK : ServiceHealth::ATTENTION;

        return new ServiceHealth(
            $state,
            '%count% of 15 events are on.',
            '%reached% of %all% orders from the last 7 days reached GA4.',
            ['%count%' => $count, '%reached%' => ($stats['all'] ?? 0) - ($stats['none'] ?? 0), '%all%' => $stats['all'] ?? 0]
        );
    }

    public function attention(int $idShop): array
    {
        $services = Container::get(Services::class);
        $settings = Container::get(EventsSettings::class);
        $out = [];

        if ($services->enabled($idShop, 'gtm')) {
            if (self::frontContainer($idShop) === '') {
                $out[] = new AttentionItem(AttentionItem::WARNING, 'The Tag Manager snippet is not on the shop pages yet — choose a container.', 'quince_insight_events', ['tab' => 'overview']);
            }

            $source = $settings->consentSource($idShop);

            if ($source === 'qcmp' && !ConsentReader::qcmpAvailable()) {
                $out[] = new AttentionItem(AttentionItem::WARNING, 'Quince CMP is chosen as the consent source, but it is not enabled in this shop.', 'quince_insight_events', ['tab' => 'consent']);
            } elseif ($source === 'other' && $settings->consentCookie($idShop) === '') {
                $out[] = new AttentionItem(AttentionItem::WARNING, 'Another consent tool is chosen, but its cookie name is empty.', 'quince_insight_events', ['tab' => 'consent']);
            }

            $other = Options::json(EventsOptions::EV_OTHER_GTM, [], $idShop);
            $age = Clock::secondsSince((string) ($other['at'] ?? ''));

            if (!empty($other['ids']) && $age !== null && $age < 7 * 86400) {
                $out[] = new AttentionItem(AttentionItem::WARNING, 'Another Tag Manager container is on the shop pages: %ids%.', 'quince_insight_events', ['tab' => 'overview'], ['%ids%' => implode(', ', (array) $other['ids'])]);
            }
        }

        if ($services->enabled($idShop, 'ga4') && ($settings->serverPurchase($idShop) || $settings->serverRefund($idShop))
            && !Container::get(MpSender::class)->hasCredentials($idShop)) {
            $out[] = new AttentionItem(AttentionItem::WARNING, 'Purchases cannot be sent from the server: the Measurement Protocol secret is missing.', 'quince_insight_events', ['tab' => 'server']);
        }

        if ($services->enabled($idShop, 'gtm') || $services->enabled($idShop, 'ga4')) {
            $missing = 0;

            foreach (Container::get(OrderLog::class)->since($idShop, 72) as $row) {
                if (SendRules::isMissing($row)) {
                    ++$missing;
                }
            }

            if ($missing > 0) {
                $out[] = new AttentionItem(AttentionItem::WARNING, '%count% orders from the last 72 hours did not reach GA4.', 'quince_insight_events', ['tab' => 'missing'], ['%count%' => $missing]);
            }
        }

        return $out;
    }

    public function disable(int $idShop): void
    {
        // Wylaczenie uslugi w sklepie nie usuwa dziennikow ani ustawien — po ponownym
        // wlaczeniu wszystko dziala jak wczesniej. Na stronie sklepu i tak nic nie zostaje,
        // bo kazdy hook sprawdza wlaczenie uslug przy kazdym zadaniu.
    }

    public function purge(int $idShop): void
    {
        // Wiersze tabel `order_log`, `refund_log`, `event_log` usuwa rdzen po `id_shop`; plikow brak.
    }

    public function leftInGoogle(int $idShop): array
    {
        return [];
    }

    private function shopId(): int
    {
        return (int) (Context::getContext()->shop->id ?? 0);
    }

    private function flag(string $name): void
    {
        $cookie = Context::getContext()->cookie ?? null;

        if ($cookie !== null && self::frontContainer($this->shopId()) !== '') {
            $cookie->{$name} = '1';
        }
    }
}
