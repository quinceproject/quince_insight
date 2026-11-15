<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\StructuredData;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Typy danych strukturalnych, ktore modul moze dodac — nazwy, opisy i miejsca 1:1 z
 * prototypem (`prototypy/js/structured.js`, `SD_TYPES`), opisy zgodne z research/05 §K
 * (ItemList i pole wyszukiwania WebSite nie daja wyniku rozszerzonego — ARCHITEKTURA §12 pkt 14).
 */
final class Types
{
    public const ORGANIZATION = 'organization';
    public const WEBSITE = 'website';
    public const BREADCRUMB = 'breadcrumb';
    public const PRODUCT = 'product';
    public const ITEMLIST = 'itemlist';
    public const VARIANTS = 'variants';
    public const SHIPPING = 'shipping';

    /** Typy zablokowane bez „Add anyway” (decyzja #18). */
    public const NO_FORCE = [self::VARIANTS];

    public const KEYS = [self::ORGANIZATION, self::WEBSITE, self::BREADCRUMB, self::PRODUCT, self::ITEMLIST, self::VARIANTS, self::SHIPPING];

    /** Typy schema.org, ktore na stronie oznaczaja obecnosc danego typu modulu. */
    public const DETECT = [
        self::ORGANIZATION => ['Organization', 'OnlineStore', 'OnlineBusiness', 'Store', 'LocalBusiness', 'Corporation'],
        self::WEBSITE => ['WebSite'],
        self::BREADCRUMB => ['BreadcrumbList'],
        self::PRODUCT => ['Product', 'ProductGroup'],
        self::ITEMLIST => ['ItemList'],
        // Warianty: blokada tylko z Product / ProductGroup na stronie produktu (`ScanParser::present`).
        self::VARIANTS => ['Product', 'ProductGroup'],
    ];

    /**
     * @return array<int,array{key:string,name:string,where:string,desc:string}>
     */
    public static function definitions(): array
    {
        return [
            ['key' => self::ORGANIZATION, 'name' => 'Organization', 'where' => 'Every page', 'desc' => 'Shop name, logo, contact details.'],
            ['key' => self::WEBSITE, 'name' => 'WebSite + search box', 'where' => 'Home page', 'desc' => 'Google stopped showing the search box in results in November 2024. Harmless, but no longer useful.'],
            ['key' => self::BREADCRUMB, 'name' => 'BreadcrumbList', 'where' => 'Categories, products', 'desc' => 'The category path shown under the result.'],
            ['key' => self::PRODUCT, 'name' => 'Product', 'where' => 'Product pages', 'desc' => 'Price, availability, rating, GTIN, brand.'],
            ['key' => self::ITEMLIST, 'name' => 'ItemList', 'where' => 'Category pages', 'desc' => 'Products listed on the category page. Google shows no rich result for product lists — it only helps it read the page.'],
            ['key' => self::VARIANTS, 'name' => 'Product variants', 'where' => 'Products with combinations', 'desc' => 'ProductGroup with hasVariant — every size and colour with its own price and availability. Replaces the theme\'s Product markup, so turn that off in the theme first.'],
            ['key' => self::SHIPPING, 'name' => 'Shipping and returns', 'where' => 'Product pages and Organization', 'desc' => 'OfferShippingDetails and MerchantReturnPolicy — the same values as sent to Merchant Center.'],
        ];
    }

    public static function name(string $key): string
    {
        foreach (self::definitions() as $def) {
            if ($def['key'] === $key) {
                return $def['name'];
            }
        }

        return $key;
    }

    /**
     * Wlaczone typy po normalizacji: tylko znane klucze, 0/1.
     *
     * @param array<mixed> $on
     *
     * @return array<string,bool>
     */
    public static function normalize(array $on): array
    {
        $out = [];

        foreach (self::KEYS as $key) {
            $out[$key] = !empty($on[$key]);
        }

        return $out;
    }
}
