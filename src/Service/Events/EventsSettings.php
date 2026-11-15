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

use Quince\Insight\Core\Options;

/**
 * Ustawienia zdarzen sklepu z wartosciami domyslnymi i walidacja zapisu.
 *
 * Jedyne miejsce, ktore zna domyslne wartosci (odczyt, nie instalacja — ARCHITEKTURA §3.1).
 * Czytaja z niej tez inni: agent D (plan kontenera — `enabledEvents()`), kreator (krok gtm).
 */
final class EventsSettings
{
    /** 15 zdarzen z prototypu, pogrupowane jak na ekranie Event list. Klucz grupy = etykieta. */
    public const GROUPS = [
        'Pages and search' => [
            'page_view' => ['Every page', 'Browser'],
            'search' => ['Search results', 'Browser'],
        ],
        'Product lists and product page' => [
            'view_item_list' => ['Category and search pages', 'Browser'],
            'select_item' => ['Click on a product in a list', 'Browser'],
            'view_item' => ['Product page', 'Browser'],
        ],
        'Cart' => [
            'add_to_cart' => ['Add to cart', 'Browser'],
            'remove_from_cart' => ['Remove from cart', 'Browser'],
            'view_cart' => ['Cart page', 'Browser'],
        ],
        'Checkout' => [
            'begin_checkout' => ['Checkout opened — once per cart', 'Browser'],
            'add_shipping_info' => ['Carrier chosen', 'Browser'],
            'add_payment_info' => ['Payment chosen', 'Browser'],
        ],
        'Order' => [
            'purchase' => ['Order confirmation — once per order', 'Browser + server'],
            'refund' => ['Credit slip, cancellation, refund status', 'Server'],
        ],
        'Account' => [
            'login' => ['After signing in', 'Browser'],
            'sign_up' => ['After creating an account', 'Browser'],
        ],
    ];

    /** Zdarzenia wysylane z przegladarki przez skrypt frontu (nie z PHP przy renderowaniu). */
    public const JS_EVENTS = ['select_item', 'add_to_cart', 'remove_from_cart', 'add_shipping_info', 'add_payment_info'];

    public const SIGNALS = ['analytics_storage', 'ad_storage', 'ad_user_data', 'ad_personalization'];

    public const CATEGORIES = ['analytics', 'marketing', 'preferences'];

    public const DEFAULT_MAP = [
        'analytics_storage' => 'analytics',
        'ad_storage' => 'marketing',
        'ad_user_data' => 'marketing',
        'ad_personalization' => 'marketing',
    ];

    /** @return string[] wszystkie 15 zdarzen w kolejnosci ekranu */
    public static function allEvents(): array
    {
        $out = [];

        foreach (self::GROUPS as $events) {
            foreach (array_keys($events) as $key) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /** @return array<string,bool> zdarzenie => wlaczone (domyslnie wszystkie, jak w prototypie) */
    public function events(int $idShop): array
    {
        $saved = Options::json(EventsOptions::EV_ENABLED, [], $idShop);
        $out = [];

        foreach (self::allEvents() as $key) {
            $out[$key] = !array_key_exists($key, $saved) || (bool) $saved[$key];
        }

        return $out;
    }

    /** @return string[] */
    public function enabledEvents(int $idShop): array
    {
        return array_keys(array_filter($this->events($idShop)));
    }

    public function eventOn(int $idShop, string $event): bool
    {
        return $this->events($idShop)[$event] ?? false;
    }

    public function setEvent(int $idShop, string $event, bool $on): void
    {
        if (!in_array($event, self::allEvents(), true)) {
            throw new \InvalidArgumentException('Unknown event.');
        }

        $all = array_map(static fn (bool $v): int => $v ? 1 : 0, $this->events($idShop));
        $all[$event] = $on ? 1 : 0;
        Options::set(EventsOptions::EV_ENABLED, $all, $idShop);
    }

    /** id | reference | ean */
    public function productId(int $idShop): string
    {
        $value = Options::str(EventsOptions::EV_PRODUCT_ID, 'id', $idShop);

        return in_array($value, ['id', 'reference', 'ean'], true) ? $value : 'id';
    }

    public function priceTax(int $idShop): bool
    {
        return Options::bool(EventsOptions::EV_PRICE_TAX, true, $idShop);
    }

    public function categoryPath(int $idShop): bool
    {
        return Options::bool(EventsOptions::EV_CATEGORY_PATH, true, $idShop);
    }

    /** never | purchase | always */
    public function userData(int $idShop): string
    {
        $value = Options::str(EventsOptions::EV_USER_DATA, 'purchase', $idShop);

        return in_array($value, ['never', 'purchase', 'always'], true) ? $value : 'purchase';
    }

    /** qcmp | other | none — zapisany wybor (bez sprawdzania, czy Quince CMP jest w sklepie). */
    public function consentSource(int $idShop): string
    {
        $value = Options::str(EventsOptions::EV_CONSENT_SOURCE, 'qcmp', $idShop);

        return in_array($value, ['qcmp', 'other', 'none'], true) ? $value : 'qcmp';
    }

    public function consentCookie(int $idShop): string
    {
        return Options::str(EventsOptions::EV_CONSENT_COOKIE, '', $idShop);
    }

    /** @return array<string,string> sygnal => kategoria narzedzia zgod */
    public function consentMap(int $idShop): array
    {
        $saved = Options::json(EventsOptions::EV_CONSENT_MAP, [], $idShop);
        $out = [];

        foreach (self::DEFAULT_MAP as $signal => $category) {
            $value = $saved[$signal] ?? $category;
            $out[$signal] = in_array($value, self::CATEGORIES, true) ? (string) $value : $category;
        }

        return $out;
    }

    /** @return array<string,string> sygnal => granted | denied (domyslnie wszystkie denied — UE) */
    public function consentDefaults(int $idShop): array
    {
        $saved = Options::json(EventsOptions::EV_CONSENT_DEFAULT, [], $idShop);
        $out = [];

        foreach (self::SIGNALS as $signal) {
            $out[$signal] = ($saved[$signal] ?? 'denied') === 'granted' ? 'granted' : 'denied';
        }

        return $out;
    }

    public function consentWait(int $idShop): int
    {
        return max(0, min(10000, Options::int(EventsOptions::EV_CONSENT_WAIT, 500, $idShop)));
    }

    public function serverPurchase(int $idShop): bool
    {
        return Options::bool(EventsOptions::EV_SERVER_PURCHASE, true, $idShop);
    }

    public function serverRefund(int $idShop): bool
    {
        return Options::bool(EventsOptions::EV_SERVER_REFUND, true, $idShop);
    }

    public function serverUserData(int $idShop): bool
    {
        return Options::bool(EventsOptions::EV_SERVER_USER_DATA, true, $idShop);
    }

    public function logOn(int $idShop): bool
    {
        return Options::bool(EventsOptions::EV_LOG_ON, true, $idShop);
    }

    public function logKeepDays(int $idShop): int
    {
        return max(1, min(365, Options::int(EventsOptions::EV_LOG_KEEP_DAYS, 14, $idShop)));
    }

    public function autoMissing(int $idShop): bool
    {
        return Options::bool(EventsOptions::EV_AUTO_MISSING, true, $idShop);
    }

    /** Minuty od zlozenia zamowienia (ekran pokazuje godziny 1–23). */
    public function autoMissingAfter(int $idShop): int
    {
        return max(60, min(23 * 60, Options::int(EventsOptions::EV_AUTO_MISSING_AFTER, 120, $idShop)));
    }

    /**
     * Zapis jednego ustawienia z ekranu (autozapis jak w prototypie — bez przycisku Save).
     * Zwraca komunikat bledu albo ''.
     */
    public function save(int $idShop, string $key, mixed $value): string
    {
        $bool = static fn (mixed $v): bool => $v === true || $v === 1 || $v === '1' || $v === 'true';

        switch ($key) {
            case 'productId':
                if (!in_array($value, ['id', 'reference', 'ean'], true)) {
                    return 'Choose one of the options.';
                }
                Options::set(EventsOptions::EV_PRODUCT_ID, (string) $value, $idShop);
                break;
            case 'priceTax':
                Options::set(EventsOptions::EV_PRICE_TAX, $bool($value), $idShop);
                break;
            case 'categoryPath':
                Options::set(EventsOptions::EV_CATEGORY_PATH, $bool($value), $idShop);
                break;
            case 'userData':
                if (!in_array($value, ['never', 'purchase', 'always'], true)) {
                    return 'Choose one of the options.';
                }
                Options::set(EventsOptions::EV_USER_DATA, (string) $value, $idShop);
                break;
            case 'consentSource':
                if (!in_array($value, ['qcmp', 'other', 'none'], true)) {
                    return 'Choose one of the options.';
                }
                Options::set(EventsOptions::EV_CONSENT_SOURCE, (string) $value, $idShop);
                break;
            case 'consentCookie':
                $name = trim((string) $value);
                // Nazwa ciasteczka wg RFC 6265 (token) — trafia do skryptu na stronie sklepu.
                if ($name !== '' && !preg_match('/^[A-Za-z0-9!#$%&\'*+\-.^_`|~]{1,128}$/', $name)) {
                    return 'This is not a valid cookie name.';
                }
                Options::set(EventsOptions::EV_CONSENT_COOKIE, $name, $idShop);
                break;
            case 'consentMap':
                if (!is_array($value)) {
                    return 'Choose one of the options.';
                }
                $map = $this->consentMap($idShop);
                foreach ($value as $signal => $category) {
                    if (!isset($map[$signal]) || !in_array($category, self::CATEGORIES, true)) {
                        return 'Choose one of the options.';
                    }
                    $map[$signal] = (string) $category;
                }
                Options::set(EventsOptions::EV_CONSENT_MAP, $map, $idShop);
                break;
            case 'consentDefaults':
                if (!is_array($value)) {
                    return 'Choose one of the options.';
                }
                $defaults = $this->consentDefaults($idShop);
                foreach ($value as $signal => $state) {
                    if (!isset($defaults[$signal]) || !in_array($state, ['granted', 'denied'], true)) {
                        return 'Choose one of the options.';
                    }
                    $defaults[$signal] = (string) $state;
                }
                Options::set(EventsOptions::EV_CONSENT_DEFAULT, $defaults, $idShop);
                break;
            case 'consentWait':
                if (!is_numeric($value) || (int) $value < 0 || (int) $value > 10000) {
                    return 'Enter a number from 0 to 10000.';
                }
                Options::set(EventsOptions::EV_CONSENT_WAIT, (int) $value, $idShop);
                break;
            case 'serverPurchase':
                Options::set(EventsOptions::EV_SERVER_PURCHASE, $bool($value), $idShop);
                break;
            case 'serverRefund':
                Options::set(EventsOptions::EV_SERVER_REFUND, $bool($value), $idShop);
                break;
            case 'serverUserData':
                Options::set(EventsOptions::EV_SERVER_USER_DATA, $bool($value), $idShop);
                break;
            case 'logOn':
                Options::set(EventsOptions::EV_LOG_ON, $bool($value), $idShop);
                break;
            case 'logKeepDays':
                if (!is_numeric($value) || (int) $value < 1 || (int) $value > 365) {
                    return 'Enter a number of days from 1 to 365.';
                }
                Options::set(EventsOptions::EV_LOG_KEEP_DAYS, (int) $value, $idShop);
                break;
            case 'autoMissing':
                Options::set(EventsOptions::EV_AUTO_MISSING, $bool($value), $idShop);
                break;
            case 'autoMissingHours':
                if (!is_numeric($value) || (int) $value < 1 || (int) $value > 23) {
                    return 'Enter a number of hours from 1 to 23.';
                }
                Options::set(EventsOptions::EV_AUTO_MISSING_AFTER, (int) $value * 60, $idShop);
                break;
            default:
                return 'Unknown setting.';
        }

        return '';
    }
}
