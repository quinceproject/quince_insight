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

use Category;
use Configuration;
use Context;
use Country;
use Db;
use Image;
use ImageType;
use Manufacturer;
use Module as PsModule;
use Product;
use Shop;
use State;
use StockAvailable;
use Validate;

/**
 * Dane sklepu dla JSON-LD, zebrane z PrestaShop w postaci, ktora przyjmuje `JsonLdBuilder`.
 *
 * Dziala bez kontenera Symfony — na froncie (displayHeader) i w panelu (podglad, w kontekscie
 * sklepu z `ShopContext::run`). Dane tozsamosci (nazwa, logo, kontakt, adres) wylacznie
 * z ustawien sklepu; puste pole zostaje puste.
 *
 * Ceny i waluta: z kontekstu (na froncie — waluta i grupa odwiedzajacego), z promocja
 * i bez niej; podatek wedlug sposobu wyswietlania cen grupy klienta.
 */
final class ShopData
{
    /**
     * @return array<string,mixed> name, url, logo, email, telephone, address
     */
    public function organization(int $idShop, int $idLang): array
    {
        $context = Context::getContext();
        $logo = (string) Configuration::get('PS_LOGO', null, null, $idShop);
        $address = [];
        $shop = new Shop($idShop);
        $shopAddress = Validate::isLoadedObject($shop) ? $shop->getAddress() : null;

        if ($shopAddress !== null) {
            $address = [
                'street' => trim(implode(', ', array_filter([(string) $shopAddress->address1, (string) $shopAddress->address2]))),
                'city' => (string) $shopAddress->city,
                'postcode' => (string) $shopAddress->postcode,
                'region' => !empty($shopAddress->id_state) ? (string) (new State((int) $shopAddress->id_state))->name : '',
                'country' => !empty($shopAddress->id_country) ? (string) Country::getIsoById((int) $shopAddress->id_country) : '',
            ];
        }

        return [
            'name' => (string) Configuration::get('PS_SHOP_NAME', null, null, $idShop),
            'url' => $this->homeUrl($idShop, $idLang),
            'logo' => $logo !== '' && is_file(_PS_IMG_DIR_ . $logo) ? (string) $context->link->getMediaLink(_PS_IMG_ . $logo) : '',
            'email' => (string) Configuration::get('PS_SHOP_EMAIL', null, null, $idShop),
            'telephone' => (string) Configuration::get('PS_SHOP_PHONE', null, null, $idShop),
            'address' => $address,
        ];
    }

    /**
     * @return array<string,mixed> name, url, search
     */
    public function website(int $idShop, int $idLang): array
    {
        $search = (string) Context::getContext()->link->getPageLink('search', null, $idLang, null, false, $idShop);

        return [
            'name' => (string) Configuration::get('PS_SHOP_NAME', null, null, $idShop),
            'url' => $this->homeUrl($idShop, $idLang),
            'search' => $search . (str_contains($search, '?') ? '&' : '?') . 's={search_term_string}',
        ];
    }

    /**
     * Okruszki kategorii: strona glowna, kategorie nadrzedne (bez korzenia), kategoria.
     *
     * @return array<int,array{name:string,url:string}>
     */
    public function categoryPath(int $idCategory, int $idShop, int $idLang): array
    {
        $category = new Category($idCategory, $idLang, $idShop);

        if (!Validate::isLoadedObject($category)) {
            return [];
        }

        $home = (int) Configuration::get('PS_HOME_CATEGORY', null, null, $idShop);
        $link = Context::getContext()->link;
        $links = [['name' => $this->homeName($idShop, $idLang), 'url' => $this->homeUrl($idShop, $idLang)]];

        foreach (array_reverse($category->getParentsCategories($idLang) ?: []) as $parent) {
            $id = (int) $parent['id_category'];

            if ($id === $home || !empty($parent['is_root_category'])) {
                continue;
            }

            $links[] = ['name' => (string) $parent['name'], 'url' => (string) $link->getCategoryLink($id, null, $idLang, null, $idShop)];
        }

        return $links;
    }

    /**
     * Okruszki produktu: sciezka kategorii domyslnej + nazwa produktu.
     *
     * @return array<int,array{name:string,url:string}>
     */
    public function productPath(int $idProduct, int $idShop, int $idLang): array
    {
        $product = new Product($idProduct, false, $idLang, $idShop);

        if (!Validate::isLoadedObject($product)) {
            return [];
        }

        $links = $this->categoryPath((int) $product->id_category_default, $idShop, $idLang);

        if ($links === []) {
            $links = [['name' => $this->homeName($idShop, $idLang), 'url' => $this->homeUrl($idShop, $idLang)]];
        }

        $links[] = ['name' => (string) $product->name, 'url' => (string) Context::getContext()->link->getProductLink($product, null, null, null, $idLang, $idShop)];

        return $links;
    }

    /**
     * Produkty strony kategorii (domyslna kolejnosc sklepu, numer strony z adresu).
     *
     * @return array<int,array{name:string,url:string}>
     */
    public function categoryItems(int $idCategory, int $idShop, int $idLang, int $page = 1): array
    {
        $category = new Category($idCategory, $idLang, $idShop);

        if (!Validate::isLoadedObject($category)) {
            return [];
        }

        $perPage = max(1, (int) Configuration::get('PS_PRODUCTS_PER_PAGE', null, null, $idShop) ?: 12);
        $orderBy = ['name', 'price', 'date_add', 'date_upd', 'position', 'manufacturer_name', 'quantity', 'reference'][(int) Configuration::get('PS_PRODUCTS_ORDER_BY', null, null, $idShop)] ?? 'position';
        $orderWay = (int) Configuration::get('PS_PRODUCTS_ORDER_WAY', null, null, $idShop) === 1 ? 'DESC' : 'ASC';
        $products = $category->getProducts($idLang, max(1, $page), $perPage, $orderBy, $orderWay) ?: [];
        $link = Context::getContext()->link;
        $out = [];

        foreach ($products as $row) {
            $out[] = [
                'name' => (string) $row['name'],
                'url' => (string) $link->getProductLink((int) $row['id_product'], (string) $row['link_rewrite'], null, null, $idLang, $idShop),
            ];
        }

        return $out;
    }

    /**
     * Produkt z oferta i (gdy ma kombinacje) wariantami. Null — produktu nie da sie kupic
     * (nieaktywny, ukryty, bez ceny, tryb katalogu).
     *
     * @return array<string,mixed>|null
     */
    public function product(int $idProduct, int $idShop, int $idLang): ?array
    {
        $context = Context::getContext();
        $product = new Product($idProduct, true, $idLang, $idShop);

        if (!Validate::isLoadedObject($product) || !$product->active || !$product->show_price
            || !$product->available_for_order || Configuration::get('PS_CATALOG_MODE', null, null, $idShop)) {
            return null;
        }

        $currency = (string) ($context->currency->iso_code ?? '');
        $link = $context->link;
        $url = (string) $link->getProductLink($product, null, null, null, $idLang, $idShop);
        $rewrite = is_array($product->link_rewrite) ? (string) reset($product->link_rewrite) : (string) $product->link_rewrite;
        $imageType = ImageType::getFormattedName('large');
        $images = [];

        foreach (Image::getImages($idLang, $idProduct, null, $idShop) ?: [] as $image) {
            $src = (string) $link->getImageLink($rewrite, $idProduct . '-' . (int) $image['id_image'], $imageType);
            if (empty($image['cover'])) {
                $images[] = $src;
            } else {
                array_unshift($images, $src);
            }
        }

        $images = array_values(array_unique($images));
        $description = trim(html_entity_decode(strip_tags((string) $product->description_short), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $description = (string) preg_replace('/\s+/u', ' ', $description);

        $data = [
            'id' => (string) $idProduct,
            'groupId' => (string) ($product->reference ?: $idProduct),
            'name' => (string) $product->name,
            'description' => mb_substr($description, 0, 5000),
            'url' => $url,
            'images' => $images,
            'sku' => (string) $product->reference,
            'mpn' => (string) $product->mpn,
            'gtin13' => self::gtin((string) $product->ean13, 13),
            'gtin12' => self::gtin((string) $product->upc, 12),
            'brand' => $product->id_manufacturer ? (string) Manufacturer::getNameById((int) $product->id_manufacturer) : '',
            'category' => (string) (new Category((int) $product->id_category_default, $idLang, $idShop))->name,
            'rating' => $this->rating($idProduct, $idShop),
            'offer' => $this->offer($product, 0, $currency, $url, $idShop),
            'variants' => [],
            'variesBy' => [],
        ];

        $combinations = [];

        foreach ($product->getAttributeCombinations($idLang) ?: [] as $row) {
            $ipa = (int) $row['id_product_attribute'];
            $combinations[$ipa]['row'] = $row;
            $combinations[$ipa]['attributes'][] = $row;
        }

        $variesBy = [];

        foreach ($combinations as $ipa => $combination) {
            $row = $combination['row'];
            $names = [];
            $attributes = [];

            foreach ($combination['attributes'] as $attribute) {
                $names[] = (string) $attribute['attribute_name'];
                $kind = self::attributeKind((string) $attribute['group_name'], !empty($attribute['is_color_group']));

                if ($kind !== null) {
                    $attributes[$kind] = (string) $attribute['attribute_name'];
                    $variesBy[$kind] = true;
                }
            }

            $variantUrl = (string) $link->getProductLink($product, null, null, null, $idLang, $idShop, $ipa, false, false, false, [], false);

            // Wariant musi miec wlasny adres (Google); bez id kombinacji w regule adresu — parametr zapytania.
            if ($variantUrl === $url) {
                $variantUrl .= (str_contains($variantUrl, '?') ? '&' : '?') . 'id_product_attribute=' . $ipa;
            }

            $variantImages = Image::getImages($idLang, $idProduct, $ipa, $idShop) ?: [];
            $image = $variantImages
                ? (string) $link->getImageLink($rewrite, $idProduct . '-' . (int) $variantImages[0]['id_image'], $imageType)
                : ($images[0] ?? '');

            $data['variants'][] = [
                'name' => trim($data['name'] . ' - ' . implode(', ', $names)),
                'sku' => (string) ($row['reference'] ?: ''),
                'mpn' => (string) ($row['mpn'] ?? ''),
                'gtin13' => self::gtin((string) ($row['ean13'] ?? ''), 13),
                'gtin12' => self::gtin((string) ($row['upc'] ?? ''), 12),
                'image' => $image,
                'url' => $variantUrl,
                'attributes' => $attributes,
                'offer' => $this->offer($product, $ipa, $currency, $variantUrl, $idShop),
            ];
        }

        $data['variesBy'] = array_keys($variesBy);

        return $data;
    }

    /** Czy sklep ma kombinacje dla produktu (typ "Product variants"). */
    public function hasCombinations(int $idProduct, int $idShop): bool
    {
        return (bool) Db::getInstance()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . 'product_attribute_shop` WHERE `id_product` = ' . $idProduct . ' AND `id_shop` = ' . $idShop,
            false
        );
    }

    /**
     * Rodzaj atrybutu dla `variesBy`: grupa koloru, rozmiar, material, wzor (po nazwie grupy).
     * Inne grupy zostaja tylko w nazwie wariantu — Google nie obsluguje innych osi.
     */
    public static function attributeKind(string $groupName, bool $isColor): ?string
    {
        if ($isColor || preg_match('/colou?r|kolor|farbe|couleur/iu', $groupName)) {
            return 'color';
        }

        if (preg_match('/size|rozmiar|gr(o|ö)(ss|ß)e|taille|talla|taglia/iu', $groupName)) {
            return 'size';
        }

        if (preg_match('/material|materia(l|ł)/iu', $groupName)) {
            return 'material';
        }

        if (preg_match('/pattern|wz(o|ó)r|muster/iu', $groupName)) {
            return 'pattern';
        }

        return null;
    }

    /** GTIN tylko z cyframi i wlasciwej dlugosci; inaczej puste (zly GTIN szkodzi bardziej niz brak). */
    public static function gtin(string $value, int $length): string
    {
        $value = trim($value);

        return preg_match('/^\d{' . $length . '}$/', $value) && (int) $value > 0 ? $value : '';
    }

    /**
     * @return array<string,mixed>
     */
    private function offer(Product $product, int $ipa, string $currency, string $url, int $idShop): array
    {
        $useTax = Product::getTaxCalculationMethod() === PS_TAX_INC;
        $specific = null;
        $price = (float) Product::getPriceStatic((int) $product->id, $useTax, $ipa ?: null, 6, null, false, true, 1, false, null, null, null, $specific);
        $regular = (float) Product::getPriceStatic((int) $product->id, $useTax, $ipa ?: null, 6, null, false, false);
        $validUntil = null;

        if (is_array($specific) && $price < $regular && !empty($specific['to']) && $specific['to'] !== '0000-00-00 00:00:00') {
            $validUntil = substr((string) $specific['to'], 0, 10);
        }

        return [
            'price' => $price,
            'regular' => $regular > $price ? $regular : null,
            'currency' => $currency,
            'availability' => $this->availability($product, $ipa, $idShop),
            'condition' => in_array($product->condition, ['new', 'used', 'refurbished'], true) ? (string) $product->condition : '',
            'url' => $url,
            'validUntil' => $validUntil,
        ];
    }

    private function availability(Product $product, int $ipa, int $idShop): string
    {
        if (!Configuration::get('PS_STOCK_MANAGEMENT', null, null, $idShop)) {
            return 'InStock';
        }

        if ((int) StockAvailable::getQuantityAvailableByProduct((int) $product->id, $ipa ?: null, $idShop) > 0) {
            return 'InStock';
        }

        return Product::isAvailableWhenOutOfStock(StockAvailable::outOfStock((int) $product->id, $idShop)) ? 'BackOrder' : 'OutOfStock';
    }

    /**
     * Oceny z modulu `productcomments` (widoczne na stronie produktu) — tylko zatwierdzone.
     *
     * @return array{value:float,count:int}|null
     */
    private function rating(int $idProduct, int $idShop): ?array
    {
        if (!PsModule::isEnabled('productcomments')) {
            return null;
        }

        try {
            $row = Db::getInstance()->getRow(
                'SELECT AVG(`grade`) AS avg_grade, COUNT(*) AS total FROM `' . _DB_PREFIX_ . 'product_comment`
                 WHERE `id_product` = ' . $idProduct . ' AND `validate` = 1 AND `deleted` = 0',
                false
            );
        } catch (\Throwable $e) {
            return null;
        }

        if (!is_array($row) || (int) $row['total'] === 0) {
            return null;
        }

        return ['value' => round((float) $row['avg_grade'], 1), 'count' => (int) $row['total']];
    }

    private function homeUrl(int $idShop, int $idLang): string
    {
        return (string) Context::getContext()->link->getPageLink('index', null, $idLang, null, false, $idShop);
    }

    private function homeName(int $idShop, int $idLang): string
    {
        $home = new Category((int) Configuration::get('PS_HOME_CATEGORY', null, null, $idShop), $idLang, $idShop);

        return Validate::isLoadedObject($home) && (string) $home->name !== '' ? (string) $home->name : 'Home';
    }
}
