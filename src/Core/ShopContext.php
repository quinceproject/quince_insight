<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Core;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Configuration;
use Context;
use Db;
use Shop;

/**
 * Sklep, ktorego dotyczy biezace zadanie.
 *
 * Jedyne miejsce w module, ktore czyta kontekst PrestaShop. Kod uslug nie siega po
 * `Context::getContext()->shop` — dostaje `$idShop` jako argument, dzieki czemu ten
 * sam kod dziala w panelu, na froncie i w cronie obchodzacym kilka sklepow.
 */
final class ShopContext
{
    /** @var array<int,array{id_shop:int,name:string,domain:string,base_url:string,active:bool}>|null */
    private static ?array $shops = null;

    /** @var array<int,array{id_shop:int,name:string,domain:string,base_url:string,active:bool}>|null podmiana w testach */
    private static ?array $fake = null;

    private static ?int $fakeCurrent = null;

    /** Sklep biezacego kontekstu panelu; null = "wszystkie sklepy" albo grupa. */
    public static function current(): ?int
    {
        if (self::$fake !== null) {
            return self::$fakeCurrent;
        }

        // Bez wielosklepowosci kontekst zawsze wskazuje jedyny sklep.
        if (!Shop::isFeatureActive()) {
            return (int) (Context::getContext()->shop->id ?? Configuration::get('PS_SHOP_DEFAULT'));
        }

        if (Shop::getContext() !== Shop::CONTEXT_SHOP) {
            return null;
        }

        $id = (int) Shop::getContextShopID();

        return $id > 0 ? $id : null;
    }

    /** Jak current(), ale w kontekscie "wszystkie sklepy" zwraca sklep domyslny. */
    public static function currentOrDefault(): int
    {
        return self::current() ?? self::defaultShop();
    }

    public static function defaultShop(): int
    {
        if (self::$fake !== null) {
            return (int) (array_key_first(self::$fake) ?? 1);
        }

        return (int) Configuration::get('PS_SHOP_DEFAULT');
    }

    public static function isMultistore(): bool
    {
        if (self::$fake !== null) {
            return count(self::$fake) > 1;
        }

        return (bool) Shop::isFeatureActive();
    }

    /**
     * Wszystkie nieusuniete sklepy instalacji, takze wylaczone.
     *
     * @return array<int, array{id_shop:int, name:string, domain:string, base_url:string, active:bool}>
     */
    public static function shops(): array
    {
        if (self::$fake !== null) {
            return self::$fake;
        }

        if (self::$shops !== null) {
            return self::$shops;
        }

        // Jedno zapytanie zamiast obiektu Shop na sklep: adres glowny (`main = 1`)
        // razem z nazwa i stanem. Sklep bez adresu glownego dostaje pusty adres.
        $rows = Db::getInstance()->executeS(
            'SELECT s.`id_shop`, s.`name`, s.`active`, u.`domain`, u.`domain_ssl`, u.`physical_uri`, u.`virtual_uri`
             FROM `' . _DB_PREFIX_ . 'shop` s
             LEFT JOIN `' . _DB_PREFIX_ . 'shop_url` u ON u.`id_shop` = s.`id_shop` AND u.`main` = 1 AND u.`active` = 1
             WHERE s.`deleted` = 0
             ORDER BY s.`id_shop` ASC'
        ) ?: [];

        $out = [];

        foreach ($rows as $row) {
            $idShop = (int) $row['id_shop'];
            $ssl = (bool) Configuration::get('PS_SSL_ENABLED', null, null, $idShop);
            $domain = (string) ($ssl ? ($row['domain_ssl'] ?: $row['domain']) : $row['domain']);
            $path = '/' . trim((string) $row['physical_uri'] . (string) $row['virtual_uri'], '/');

            $out[$idShop] = [
                'id_shop' => $idShop,
                'name' => (string) $row['name'],
                'domain' => $domain,
                'base_url' => $domain === ''
                    ? ''
                    : ($ssl ? 'https://' : 'http://') . $domain . ($path === '/' ? '/' : $path . '/'),
                'active' => (bool) $row['active'],
            ];
        }

        return self::$shops = $out;
    }

    /** Czy sklep istnieje (nie zostal usuniety). */
    public static function exists(int $idShop): bool
    {
        return isset(self::shops()[$idShop]);
    }

    /**
     * Czy zalogowany pracownik moze zarzadzac sklepem (przypisanie w "Employees" albo superadmin).
     * Bez pracownika (cron, front, testy) nie ma kogo ograniczac.
     */
    public static function employeeMayManage(int $idShop): bool
    {
        $employee = self::$fake !== null ? null : (Context::getContext()->employee ?? null);

        if ($employee === null || !$employee->id) {
            return true;
        }

        return (bool) $employee->hasAuthOnShop($idShop);
    }

    /** Czy sklep istnieje i jest wlaczony. Sklep wylaczony ma wstrzymane crony i wysylke. */
    public static function isActive(int $idShop): bool
    {
        return (bool) (self::shops()[$idShop]['active'] ?? false);
    }

    public static function name(int $idShop): string
    {
        return (string) (self::shops()[$idShop]['name'] ?? '');
    }

    /**
     * Adres bazowy sklepu ze sciezka wirtualna, zakonczony "/".
     *
     * Protokol wynika z ustawienia SSL sklepu: `https://` gdy wlaczone, inaczej `http://`
     * (instalacje lokalne). Google przyjmuje adres zwrotny tylko po HTTPS — sklep bez SSL
     * nie polaczy sie wcale i kreator mowi to wprost.
     */
    public static function baseUrl(int $idShop): string
    {
        return (string) (self::shops()[$idShop]['base_url'] ?? '');
    }

    /** Czy sklep dziala po HTTPS (warunek polaczenia z Google poza localhost). */
    public static function isHttps(int $idShop): bool
    {
        return strncmp(self::baseUrl($idShop), 'https://', 8) === 0;
    }

    /**
     * Wykonuje $fn z kontekstem PrestaShop przelaczonym na sklep (cron); potem przywraca.
     *
     * Potrzebne wszedzie tam, gdzie rdzen liczy cos "dla biezacego sklepu": ceny,
     * odnosniki, stany magazynowe.
     */
    public static function run(int $idShop, callable $fn): mixed
    {
        if (self::$fake !== null) {
            $before = self::$fakeCurrent;
            self::$fakeCurrent = $idShop;

            try {
                return $fn($idShop);
            } finally {
                self::$fakeCurrent = $before;
            }
        }

        $context = Context::getContext();
        $shopBefore = $context->shop;
        $typeBefore = Shop::getContext();
        $idBefore = $typeBefore === Shop::CONTEXT_SHOP
            ? Shop::getContextShopID()
            : ($typeBefore === Shop::CONTEXT_GROUP ? Shop::getContextShopGroupID() : null);

        $same = $typeBefore === Shop::CONTEXT_SHOP && (int) $idBefore === $idShop
            && (int) ($shopBefore->id ?? 0) === $idShop;

        if ($same) {
            return $fn($idShop);
        }

        try {
            Shop::setContext(Shop::CONTEXT_SHOP, $idShop);
            $context->shop = new Shop($idShop);

            return $fn($idShop);
        } finally {
            $context->shop = $shopBefore;
            Shop::setContext($typeBefore, $idBefore);
        }
    }

    /** Zapomina liste sklepow (po dodaniu albo usunieciu sklepu w tym samym zadaniu). */
    public static function flush(): void
    {
        self::$shops = null;
    }

    /**
     * Tylko testy: podmienia liste sklepow i biezacy sklep. `null` przywraca PrestaShop.
     *
     * @param array<int,array{id_shop:int,name:string,domain:string,base_url:string,active:bool}>|null $shops
     */
    public static function fake(?array $shops, ?int $current = null): void
    {
        self::$fake = $shops;
        self::$fakeCurrent = $current;
        self::$shops = null;
    }
}
