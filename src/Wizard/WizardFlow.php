<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Wizard;

/**
 * Kolejnosc i widocznosc krokow kreatora — czysta logika, bez bazy i bez PrestaShop.
 *
 * Kroki i ich nazwy 1:1 z prototypu (`STEPS`, `SERVICES`, `SCOPES`, `CLOUD_STEPS`).
 * Krok Shops pojawia sie tylko przy kilku sklepach; kolejny sklep (inny jest juz
 * skonfigurowany) nie ma powitania, a przy istniejacym polaczeniu takze logowania.
 */
final class WizardFlow
{
    /** Klucz kroku => nazwa w pasku krokow. Kolejnosc = kolejnosc kreatora. */
    public const STEPS = [
        'welcome' => 'Welcome',
        'shops' => 'Shops',
        'services' => 'Services',
        'cloud' => 'Google Cloud project',
        'connect' => 'Google account',
        'ga4' => 'Analytics',
        'ads' => 'Google Ads',
        'gtm' => 'Tag Manager',
        'verify' => 'Site verification',
        'gmc' => 'Merchant Center',
        'gcr' => 'Customer Reviews',
        'gsc' => 'Search Console',
        'summary' => 'Summary',
    ];

    /** Kroki z przyciskiem Skip. */
    public const OPTIONAL = ['ads', 'gcr'];

    /** Kroki dostarczane przez uslugi (`WizardStep`). */
    public const SERVICE_STEPS = ['ga4', 'ads', 'gtm', 'verify', 'gmc', 'gcr', 'gsc'];

    /** Kroki z przelacznikiem sklepu nad formularzem (kilka sklepow naraz). */
    public const SWITCHER_STEPS = ['ga4', 'ads', 'gtm', 'verify', 'gmc', 'gcr', 'gsc', 'summary'];

    /** Kroki bez "Use the same for all shops" — wynikaja z adresu sklepu. */
    public const NO_SAME = ['verify', 'gsc', 'summary'];

    /** Kroki, w ktorych brak uprawnienia Google blokuje przejscie dalej (wspolny alert). */
    public const GRANT_STEPS = ['gsc', 'ga4', 'gtm', 'gmc'];

    /** Grupy kart w kroku Services. */
    public const GROUPS = [
        'measure' => 'Measurement and ads',
        'shopping' => 'Shopping',
        'search' => 'Search',
    ];

    /** Uslugi w kolejnosci kart (prototyp `SERVICES`). */
    public const SERVICES = [
        'ga4' => ['group' => 'measure', 'icon' => 'eye', 'name' => 'Analytics', 'desc' => 'GA4 property and data stream, sales reports on the dashboard.', 'uses' => '', 'apis' => ['Google Analytics Admin API', 'Google Analytics Data API']],
        'ads' => ['group' => 'measure', 'icon' => 'checkCircle', 'name' => 'Google Ads', 'desc' => 'Purchase conversions and remarketing.', 'uses' => 'Sent through Tag Manager', 'apis' => []],
        'gtm' => ['group' => 'measure', 'icon' => 'link', 'name' => 'Tag Manager', 'desc' => 'Sends shop events to Analytics and Google Ads, with consent respected.', 'uses' => '', 'apis' => ['Tag Manager API']],
        'gmc' => ['group' => 'shopping', 'icon' => 'box', 'name' => 'Merchant Center', 'desc' => 'Product feed, product statuses, promotions and shipping.', 'uses' => '', 'apis' => ['Merchant API']],
        'gcr' => ['group' => 'shopping', 'icon' => 'user', 'name' => 'Customer Reviews', 'desc' => 'Google survey offered after an order.', 'uses' => 'Uses Merchant Center', 'apis' => []],
        'gsc' => ['group' => 'search', 'icon' => 'search', 'name' => 'Search Console', 'desc' => 'Search results summary and the sitemap.', 'uses' => '', 'apis' => ['Google Search Console API']],
        'psi' => ['group' => 'search', 'icon' => 'refresh', 'name' => 'Page speed', 'desc' => 'Core Web Vitals from real users and PageSpeed Insights for key pages.', 'uses' => '', 'apis' => ['PageSpeed Insights API', 'Chrome UX Report API']],
    ];

    /** Uprawnienia pokazywane w kroku Google account (prototyp `SCOPES`). */
    public const SCOPES = [
        'email' => ['label' => 'See your primary Google account e-mail address', 'svc' => 'Connection'],
        'verify' => ['label' => 'Manage the list of sites and domains you control', 'svc' => 'Site verification'],
        'gsc' => ['label' => 'View and manage Search Console data for your verified sites', 'svc' => 'Search Console'],
        'ga4' => ['label' => 'Edit Google Analytics management entities and see your Analytics data', 'svc' => 'Analytics'],
        'gtm' => ['label' => 'Manage, edit and publish your Tag Manager containers', 'svc' => 'Tag Manager'],
        'gmc' => ['label' => 'Manage your product listings and accounts for Google Shopping', 'svc' => 'Merchant Center'],
    ];

    /** Podkroki instrukcji Google Cloud (prototyp `CLOUD_STEPS`). */
    public const CLOUD_STEPS = [
        1 => ['title' => 'Create a project', 'url' => 'https://console.cloud.google.com/projectcreate'],
        2 => ['title' => 'Enable the APIs', 'url' => 'https://console.cloud.google.com/apis/library'],
        3 => ['title' => 'Configure the OAuth consent screen', 'url' => 'https://console.cloud.google.com/apis/credentials/consent'],
        4 => ['title' => 'Create an OAuth client', 'url' => 'https://console.cloud.google.com/apis/credentials'],
        5 => ['title' => 'Create an API key', 'url' => 'https://console.cloud.google.com/apis/credentials'],
    ];

    /**
     * Czy krok wystepuje w tym przejsciu.
     *
     * @param string[] $services uslugi wybrane dla sklepow przejscia
     * @param bool $adding kolejny sklep (inny sklep jest juz skonfigurowany)
     * @param bool $existingConnection kolejny sklep korzysta z istniejacego polaczenia
     */
    public static function isActive(string $step, array $services, bool $multistore, bool $adding, bool $existingConnection): bool
    {
        $on = static fn (string $key): bool => in_array($key, $services, true);

        return match ($step) {
            'welcome' => !$adding,
            'shops' => $multistore && !$adding,
            'connect' => !($adding && $existingConnection),
            'ga4', 'ads', 'gtm', 'gmc', 'gcr', 'gsc' => $on($step),
            'verify' => $on('gsc') || $on('gmc'),
            'services', 'cloud', 'summary' => true,
            default => false,
        };
    }

    /**
     * @param string[] $services
     *
     * @return string[] klucze aktywnych krokow w kolejnosci
     */
    public static function activeSteps(array $services, bool $multistore, bool $adding, bool $existingConnection): array
    {
        return array_values(array_filter(
            array_keys(self::STEPS),
            static fn (string $step): bool => self::isActive($step, $services, $multistore, $adding, $existingConnection)
        ));
    }

    /** @param string[] $active */
    public static function next(string $step, array $active): ?string
    {
        $i = array_search($step, $active, true);

        if ($i === false) {
            // Krok spoza listy (np. wylaczony w tym przejsciu): pierwszy aktywny za nim.
            $order = array_keys(self::STEPS);
            $pos = (int) array_search($step, $order, true);

            foreach ($active as $candidate) {
                if ((int) array_search($candidate, $order, true) > $pos) {
                    return $candidate;
                }
            }

            return null;
        }

        return $active[$i + 1] ?? null;
    }

    /** @param string[] $active */
    public static function prev(string $step, array $active): ?string
    {
        $i = array_search($step, $active, true);

        return $i === false || $i === 0 ? null : $active[$i - 1];
    }

    /** Numer kroku (od 1) na liscie aktywnych; 0 = krok nieaktywny. @param string[] $active */
    public static function number(string $step, array $active): int
    {
        $i = array_search($step, $active, true);

        return $i === false ? 0 : $i + 1;
    }

    /**
     * Krok kreatora, ktory konfiguruje usluge. Page speed nie ma wlasnego kroku —
     * klucz API podaje sie w kroku Google Cloud project.
     */
    public static function stepFor(string $service): string
    {
        return $service === 'psi' ? 'cloud' : $service;
    }

    /** Usluga konfigurowana krokiem (odwrotnosc stepFor); '' dla krokow rdzenia i weryfikacji. */
    public static function serviceOf(string $step): string
    {
        if ($step === 'cloud') {
            return 'psi';
        }

        return isset(self::SERVICES[$step]) ? $step : '';
    }

    /**
     * API do wlaczenia w Google Cloud — lista z wybranych uslug, jak w prototypie (`apisNeeded`).
     *
     * @param string[] $services
     *
     * @return string[]
     */
    public static function apisNeeded(array $services): array
    {
        $list = [];

        if (in_array('gsc', $services, true) || in_array('gmc', $services, true)) {
            $list[] = 'Site Verification API';
        }

        foreach (self::SERVICES as $key => $service) {
            if (in_array($key, $services, true)) {
                foreach ($service['apis'] as $api) {
                    $list[] = $api;
                }
            }
        }

        return $list;
    }

    /**
     * Uprawnienia, o ktore zapyta Google (prototyp `scopesNeeded`), w kolejnosci ekranu.
     *
     * @param string[] $services
     *
     * @return string[] klucze z SCOPES
     */
    public static function scopeKeys(array $services): array
    {
        $on = static fn (string $key): bool => in_array($key, $services, true);
        $out = ['email'];

        if ($on('gsc') || $on('gmc')) {
            $out[] = 'verify';
        }

        foreach (['gsc', 'ga4', 'gtm', 'gmc'] as $key) {
            if ($on($key)) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /** Podkroki Google Cloud: piaty (klucz API) tylko przy Page speed. @param string[] $services @return int[] */
    public static function cloudSteps(array $services): array
    {
        return in_array('psi', $services, true) ? [1, 2, 3, 4, 5] : [1, 2, 3, 4];
    }

    /**
     * Stan kroku uslugi w sklepie: wynik wlasciciela kroku polaczony z tym, co zapisal kreator.
     *
     * Wlasciciel mowi "done" — gotowe. Kreator zapisal "skipped" — pominiete (Skip).
     * Kreator zapisal "done" (Continue przeszlo bez bledow), a wlasciciel jeszcze nie umie
     * tego stwierdzic (krok w budowie) — tez gotowe, zeby kreator dalo sie przejsc.
     */
    public static function mergeState(string $shell, string $owner): string
    {
        if ($owner === 'done' || $owner === 'error') {
            return $owner;
        }

        if ($shell === 'skipped' || $shell === 'done') {
            return $shell;
        }

        return $owner !== '' ? $owner : ($shell !== '' ? $shell : 'todo');
    }

    /**
     * Stan kroku dla calego przejscia: gotowy, gdy kazdy sklep ma zapisany wybor.
     *
     * @param string[] $perShop
     */
    public static function combine(array $perShop): string
    {
        if ($perShop === []) {
            return 'todo';
        }

        $unique = array_values(array_unique($perShop));

        if (count($unique) === 1) {
            return $unique[0];
        }

        if (in_array('error', $unique, true)) {
            return 'error';
        }

        // Mieszanka gotowych i pominietych to tez krok zamkniety.
        return array_diff($unique, ['done', 'skipped']) === [] ? 'done' : 'todo';
    }

    /**
     * Czy da sie wejsc w krok z paska krokow: aktywny i juz odwiedzony albo z zapisanym stanem.
     *
     * @param string[] $active
     * @param array<string,string> $states
     */
    public static function canGo(string $step, array $active, string $furthest, array $states): bool
    {
        if (!in_array($step, $active, true)) {
            return false;
        }

        $order = array_keys(self::STEPS);
        $pos = array_search($step, $order, true);
        $far = array_search($furthest, $order, true);

        if ($far !== false && $pos <= $far) {
            return true;
        }

        return in_array($states[$step] ?? 'todo', ['done', 'skipped', 'error'], true);
    }

    /**
     * Sklepy wybrane w kroku Shops otwartym w kontekscie "wszystkie sklepy" (decyzja #23):
     * nic nie jest zaznaczone z gory, "Only this shop" wymaga wskazania sklepu z listy.
     *
     * @param string $mode one | several
     * @param int $one sklep wskazany przy "Only this shop"
     * @param array<int|string,mixed> $pick sklep => zaznaczony (przy "Several shops at once")
     * @param int[] $candidates sklepy kontekstu pracownika, w kolejnosci listy
     *
     * @return int[] wybrane sklepy w kolejnosci listy; pusta = nic nie wybrano
     */
    public static function pickedShops(string $mode, int $one, array $pick, array $candidates): array
    {
        $candidates = array_values(array_map('intval', $candidates));

        if ($mode !== 'several') {
            return in_array($one, $candidates, true) ? [$one] : [];
        }

        return array_values(array_filter(
            $candidates,
            static fn (int $id): bool => !empty($pick[$id]) && (string) $pick[$id] !== '0'
        ));
    }

    /**
     * Czy sklepy moga isc jednym przejsciem: wszystkie bez polaczenia albo na tym samym
     * projekcie Google Cloud.
     *
     * @param int[] $connectionIds polaczenie kazdego sklepu (0 = brak)
     */
    public static function sameProject(array $connectionIds): bool
    {
        return count(array_unique(array_filter(array_map('intval', $connectionIds)))) <= 1;
    }

    /** Dalszy z dwoch krokow (wedlug kolejnosci kreatora). */
    public static function further(string $a, string $b): string
    {
        $order = array_keys(self::STEPS);
        $ia = array_search($a, $order, true);
        $ib = array_search($b, $order, true);

        if ($ia === false) {
            return $b;
        }

        if ($ib === false) {
            return $a;
        }

        return $ib > $ia ? $b : $a;
    }

    /** Sprawdzenie formularza Google Cloud (prototyp `cloudCheck`). Zwraca pole => blad. @return array<string,string> */
    public static function checkCredentials(string $clientId, string $secret, string $apiKey, bool $needsKey, bool $hasSecret, bool $hasKey): array
    {
        $errors = [];

        if (!str_ends_with(trim($clientId), '.apps.googleusercontent.com')) {
            $errors['client_id'] = 'The Client ID ends with .apps.googleusercontent.com.';
        }

        if (trim($secret) === '' && !$hasSecret) {
            $errors['client_secret'] = 'Paste the Client secret.';
        }

        if ($needsKey && trim($apiKey) === '' && !$hasKey) {
            $errors['api_key'] = 'Paste the API key — Page speed needs it.';
        }

        return $errors;
    }
}
