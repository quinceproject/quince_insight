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

use Carrier;
use Cart;
use Context;
use Customer;
use Module as PsModule;
use Order;
use Quince\Insight\Service\Analytics\Ga4Settings;
use Quince\Insight\Service\TagManager\GtmSettings;
use Tools;
use Validate;

/**
 * Zdarzenia strony sklepu — dane z PHP, nie z HTML motywu (SPEC §5.3 [F]).
 *
 * Zbiera to, co wiadomo przy renderowaniu strony (page_view, view_item, listy, koszyk,
 * kasa, zakup, logowanie), oraz dane dla skryptu `views/js/front/events.js`, ktory
 * dokłada zdarzenia z przegladarki (koszyk, klikniecie w liste, platnosc).
 *
 * Wywolywane z `displayBeforeBodyClosingTag` — wtedy kontroler przypisal juz liste
 * produktow i produkt do Smarty (w `displayHeader` jeszcze nie).
 */
final class Storefront
{
    /** Zamowienie strony potwierdzenia, zapamietane w `displayOrderConfirmation`. */
    private ?Order $confirmation = null;

    public function __construct(
        private readonly EventsSettings $settings,
        private readonly ShopData $shop,
        private readonly ConsentReader $consent,
        private readonly OrderLog $orders,
        private readonly Ga4Settings $ga4,
        private readonly CheckoutContext $checkout,
        private readonly GtmSettings $gtm,
    ) {
    }

    public function rememberConfirmation(Order $order): void
    {
        $this->confirmation = $order;
    }

    public function confirmation(): ?Order
    {
        return $this->confirmation;
    }

    public static function controllerName(): string
    {
        $controller = Context::getContext()->controller ?? null;
        $name = is_object($controller) && isset($controller->php_self) ? (string) $controller->php_self : '';

        return strtolower($name !== '' ? $name : (string) Tools::getValue('controller', ''));
    }

    /**
     * Zdarzenia strony i dane dla skryptu.
     *
     * @return array{events:array<int,array<string,mixed>>, config:array<string,mixed>}
     */
    public function build(int $idShop): array
    {
        $context = Context::getContext();
        $idLang = (int) $context->language->id;
        // Wspolny kontener: obowiazuje zestaw zdarzen sklepu zarzadzajacego (ARCHITEKTURA §6).
        $on = $this->settings->events($this->gtm->eventsShop($idShop));
        $controller = self::controllerName();
        $ctrl = $context->controller;
        $smarty = $context->smarty;
        $currency = (string) ($context->currency->iso_code ?? '');
        $idField = $this->settings->productId($idShop);
        $tax = $this->settings->priceTax($idShop);
        $path = $this->settings->categoryPath($idShop);
        $signals = $this->consent->current($idShop);
        $events = [];
        $templates = [];
        $lists = [];
        $categories = [];
        $cookie = $context->cookie;

        $item = function (array $line, ?int $index = null) use ($idField, $tax, $path, $idLang, &$categories): array {
            $idCategory = (int) ($line['id_category_default'] ?? 0);
            $line['categories'] = $this->shop->categoryPath($idCategory, $idLang);
            $categories[$idCategory] = Ecommerce::categoryFields($line['categories'], $path);

            return Ecommerce::item($line, $idField, $tax, $path, $index);
        };

        if ($on['page_view']) {
            $events[] = ['data' => ['event' => 'page_view', 'pageCategory' => self::pageCategory($controller)]];
        }

        // Zdarzenia odlozone z hookow logowania / rejestracji (flaga w ciasteczku sklepu).
        foreach (['login' => 'qi_ev_login', 'sign_up' => 'qi_ev_signup'] as $event => $flag) {
            if (isset($cookie->{$flag})) {
                unset($cookie->{$flag});

                if ($on[$event]) {
                    $events[] = ['data' => ['event' => $event, 'method' => 'website']];
                }
            }
        }

        if ($controller === 'product' && is_object($ctrl) && method_exists($ctrl, 'getProduct')) {
            $presented = $smarty->getTemplateVars('product');

            if ($presented !== null && (int) ($presented['id_product'] ?? 0) > 0) {
                $line = $this->presentedLine($presented);
                $built = $item($line, 0);
                $templates[$this->key($line)] = $templates['p' . (int) $line['id_product']] = $built;

                if ($on['view_item']) {
                    $events[] = ['ecommerce' => true, 'data' => ['event' => 'view_item', 'ecommerce' => [
                        'currency' => $currency,
                        'value' => Ecommerce::itemsValue([$built]),
                        'items' => [$built],
                    ]]];
                }
            }
        }

        $listing = in_array($controller, ['category', 'search'], true) ? $smarty->getTemplateVars('listing') : null;

        if ($controller === 'search') {
            $term = trim((string) Tools::getValue('s', Tools::getValue('search_query', '')));

            if ($term !== '' && $on['search']) {
                $events[] = ['data' => ['event' => 'search', 'search_term' => mb_substr($term, 0, 100)]];
            }
        }

        if (is_array($listing) || $listing instanceof \ArrayAccess) {
            $listId = 'search_results';
            $listName = 'Search Results';

            if ($controller === 'category' && is_object($ctrl) && method_exists($ctrl, 'getCategory')) {
                $category = $ctrl->getCategory();

                if (is_object($category) && !empty($category->id)) {
                    $listId = 'category_' . (int) $category->id;
                    $listName = is_array($category->name) ? (string) reset($category->name) : (string) $category->name;
                }
            }

            $items = [];

            foreach (($listing['products'] ?? []) as $i => $product) {
                $line = $this->presentedLine($product);
                $built = $item($line, (int) $i) + ['item_list_id' => $listId, 'item_list_name' => $listName];
                $items[] = $built;
                $templates['p' . (int) $line['id_product']] ??= $built;
                $url = (string) ($product['url'] ?? '');

                if ($url !== '') {
                    $lists[] = ['url' => $url, 'item' => $built];
                }
            }

            if ($items !== [] && $on['view_item_list']) {
                $events[] = ['ecommerce' => true, 'data' => ['event' => 'view_item_list', 'ecommerce' => [
                    'currency' => $currency,
                    'item_list_id' => $listId,
                    'item_list_name' => $listName,
                    'items' => $items,
                ]]];
            }
        }

        $cart = $context->cart;
        $cartItems = [];
        $snapshot = [];

        if ($cart instanceof Cart && (int) $cart->id > 0) {
            foreach ($cart->getProducts() as $i => $product) {
                $line = $this->cartLine($product);
                $built = $item($line, (int) $i);
                $cartItems[] = $built;
                $templates[$this->key($line)] = $built;
                $templates['p' . (int) $line['id_product']] ??= $built;
                $snapshot[$this->key($line)] = (int) $line['quantity'];
            }
        }

        if ($controller === 'cart' && $cartItems !== [] && $on['view_cart']) {
            $events[] = ['ecommerce' => true, 'data' => ['event' => 'view_cart', 'ecommerce' => [
                'currency' => $currency, 'value' => Ecommerce::itemsValue($cartItems), 'items' => $cartItems,
            ]]];
        }

        $carriers = [];
        $payments = [];

        if (in_array($controller, ['order', 'orderopc', 'checkout'], true) && $cartItems !== [] && $cart instanceof Cart) {
            $ecommerce = ['currency' => $currency, 'value' => Ecommerce::itemsValue($cartItems), 'items' => $cartItems];

            // Raz na koszyk: znacznik w ciasteczku sklepu (dziennik) i w sessionStorage (karta).
            $once = (array) json_decode((string) ($cookie->qi_ev_once ?? ''), true);
            $idCart = (int) $cart->id;

            if ($on['begin_checkout'] && ($once['bc'] ?? 0) !== $idCart) {
                $events[] = ['ecommerce' => true, 'once' => 'bc_' . $idCart, 'data' => ['event' => 'begin_checkout', 'ecommerce' => $ecommerce]];
                $once['bc'] = $idCart;
            }

            foreach (Carrier::getCarriers($idLang, true, false, false, null, Carrier::ALL_CARRIERS) as $carrier) {
                // Przewoznik o nazwie "0" PrestaShop pokazuje jako nazwe sklepu.
                $carriers[(int) $carrier['id_carrier']] = (string) $carrier['name'] === '0' ? (string) \Configuration::get('PS_SHOP_NAME', null, null, $idShop) : (string) $carrier['name'];
            }

            // Stan kasy z bazy: obiekt koszyka w kontekscie jest wczytany przed zapisem kroku w tym zadaniu.
            $fresh = \Db::getInstance()->getRow('SELECT `id_carrier`, `checkout_session_data` FROM `' . _DB_PREFIX_ . 'cart` WHERE `id_cart` = ' . $idCart, false);
            $session = json_decode((string) ($fresh['checkout_session_data'] ?? ''), true);
            $idCarrier = (int) ($fresh['id_carrier'] ?? 0);
            $shipKey = $idCart . '_' . $idCarrier;

            if ($on['add_shipping_info'] && $idCarrier > 0 && !empty($session['checkout-delivery-step']['step_is_complete'])
                && ($once['ship'] ?? '') !== $shipKey) {
                $events[] = ['ecommerce' => true, 'once' => 'ship_' . $shipKey, 'data' => ['event' => 'add_shipping_info', 'ecommerce' => $ecommerce + [
                    'shipping_tier' => $carriers[$idCarrier] ?? (string) $idCarrier,
                ]]];
                $once['ship'] = $shipKey;
            }

            $cookie->qi_ev_once = (string) json_encode($once);

            foreach (PsModule::getPaymentModules() as $row) {
                $module = PsModule::getInstanceByName((string) $row['name']);
                $payments[(string) $row['name']] = is_object($module) ? (string) $module->displayName : (string) $row['name'];
            }

            // Kasa to ostatnie miejsce z ciasteczkami klienta przed platnoscia — zapis dla
            // zamowien potwierdzanych potem powiadomieniem operatora platnosci.
            $ids = GaCookies::fromCookies($_COOKIE, $this->ga4->measurementId($idShop));

            if ($ids['client_id'] !== '') {
                $this->checkout->remember($idShop, (int) $cart->id, $ids['client_id'], $ids['session_id'], $signals);
            }
        }

        $order = $this->confirmation;

        if ($order !== null && $on['purchase'] && $this->orders->claimBrowser($idShop, (int) $order->id)) {
            $data = $this->shop->order($order, $idLang);
            $ecommerce = Ecommerce::purchase($data, $idField, $tax, $path);
            $push = ['event' => 'purchase', 'ecommerce' => $ecommerce];

            if ($this->settings->userData($idShop) !== 'never' && ConsentMapper::granted($signals, 'ad_user_data')) {
                $user = UserData::forDataLayer($this->shop->orderCustomer($order));

                if ($user !== []) {
                    $push['user_data'] = $user;
                }
            }

            $events[] = ['ecommerce' => true, 'once' => 'purchase_' . (int) $order->id, 'data' => $push, 'id_order' => (int) $order->id];

            // Klient wrocil z platnosci z ciasteczkiem, ktorego nie bylo przy zamowieniu
            // (zamowienie z powiadomienia operatora) — uzupelniamy prawdziwa wartoscia.
            $row = $this->orders->find($idShop, (int) $order->id);
            $ids = GaCookies::fromCookies($_COOKIE, $this->ga4->measurementId($idShop));

            if ($row !== null && (string) $row['ga_client_id'] === '' && $ids['client_id'] !== '') {
                $this->orders->update($idShop, (int) $order->id, ['ga_client_id' => $ids['client_id'], 'ga_session_id' => $ids['session_id'], 'consent' => $signals]);
            }
        }

        // Dane klienta na kazdej stronie dla zalogowanych („always”), tylko przy zgodzie.
        $customer = $context->customer;

        if ($order === null && $this->settings->userData($idShop) === 'always' && $customer instanceof Customer
            && $customer->isLogged() && ConsentMapper::granted($signals, 'ad_user_data')) {
            $user = UserData::forDataLayer($this->shop->customerRaw($customer, $this->shop->customerAddress($customer, $idLang)));

            if ($user !== []) {
                $events[] = ['data' => ['user_id' => (string) $customer->id, 'user_data' => $user]];
            }
        }

        return [
            'events' => $events,
            'config' => [
                'on' => array_intersect_key($on, array_flip(EventsSettings::JS_EVENTS)),
                'currency' => $currency,
                'idField' => $idField,
                'tax' => $tax,
                'templates' => (object) $templates,
                'categories' => (object) array_filter($categories),
                'lists' => $lists,
                'cart' => (object) $snapshot,
                'carriers' => (object) $carriers,
                'payments' => (object) $payments,
            ],
        ];
    }

    /**
     * Linia z produktu przygotowanego przez prezenter PrestaShop (strona produktu, listy).
     *
     * @param array<string,mixed>|\ArrayAccess<string,mixed> $p
     *
     * @return array<string,mixed>
     */
    private function presentedLine(array|\ArrayAccess $p): array
    {
        $variant = [];

        foreach ((array) ($p['attributes'] ?? []) as $attribute) {
            if (is_array($attribute) && isset($attribute['name'])) {
                $variant[] = (string) $attribute['name'];
            }
        }

        $incl = $p['price_amount_tax_included'] ?? ($p['price_amount'] ?? 0);
        $excl = $p['price_amount_tax_excluded'] ?? ($p['price_tax_exc'] ?? $incl);

        return [
            'id_product' => (int) ($p['id_product'] ?? 0),
            'id_product_attribute' => (int) ($p['id_product_attribute'] ?? 0),
            'reference' => (string) ($p['reference'] ?? ''),
            'ean13' => (string) ($p['ean13'] ?? ''),
            'name' => (string) ($p['name'] ?? ''),
            'variant' => implode(', ', $variant),
            'brand' => $this->shop->brand((int) ($p['id_manufacturer'] ?? 0)),
            'id_category_default' => (int) ($p['id_category_default'] ?? 0),
            'price_incl' => is_numeric($incl) ? (float) $incl : 0.0,
            'price_excl' => is_numeric($excl) ? (float) $excl : 0.0,
            'quantity' => 1,
        ];
    }

    /**
     * Linia z `Cart::getProducts()` (surowe pola, nie sformatowane przez prezenter).
     *
     * @param array<string,mixed> $p
     *
     * @return array<string,mixed>
     */
    private function cartLine(array $p): array
    {
        return [
            'id_product' => (int) $p['id_product'],
            'id_product_attribute' => (int) ($p['id_product_attribute'] ?? 0),
            'reference' => (string) ($p['reference'] ?? ''),
            'ean13' => (string) ($p['ean13'] ?? ''),
            'name' => (string) ($p['name'] ?? ''),
            'variant' => (string) ($p['attributes_small'] ?? ''),
            'brand' => (string) ($p['manufacturer_name'] ?? ''),
            'id_category_default' => (int) ($p['id_category_default'] ?? 0),
            'price_incl' => (float) ($p['price_with_reduction'] ?? ($p['price_wt'] ?? 0)),
            'price_excl' => (float) ($p['price_with_reduction_without_tax'] ?? ($p['price'] ?? 0)),
            'quantity' => (int) ($p['cart_quantity'] ?? ($p['quantity'] ?? 1)),
        ];
    }

    /** @param array<string,mixed> $line */
    private function key(array $line): string
    {
        return 'p' . (int) $line['id_product'] . '-' . (int) $line['id_product_attribute'];
    }

    public static function pageCategory(string $controller): string
    {
        return [
            'index' => 'home', 'product' => 'product', 'category' => 'category', 'search' => 'search',
            'cart' => 'cart', 'order' => 'checkout', 'orderopc' => 'checkout', 'checkout' => 'checkout',
            'order-confirmation' => 'purchase', 'orderconfirmation' => 'purchase', 'cms' => 'content',
            'contact' => 'contact', 'my-account' => 'account', 'myaccount' => 'account', 'identity' => 'account',
            'history' => 'account', 'addresses' => 'account', 'address' => 'account', 'order-detail' => 'account',
        ][$controller] ?? 'other';
    }

    /** Czy to zalogowany klient (dla hookow logowania). */
    public static function validCustomer(mixed $customer): bool
    {
        return $customer instanceof Customer && Validate::isLoadedObject($customer);
    }
}
