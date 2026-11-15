<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\Merchant;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Db;
use Quince\Insight\Core\ShopContext;
use Quince\Insight\Service\Merchant\Dto\BuiltOffer;
use Quince\Insight\Service\Merchant\Dto\Feed;
use Quince\Insight\Service\MerchantTools\ConditionMap;
use Quince\Insight\Service\MerchantTools\Mapping;
use Quince\Insight\Service\MerchantTools\RuleEngine;

/**
 * Buduje oferte (produkt prosty albo kombinacje) w kontekscie feedu: jezyk, waluta, kraj
 * podatku, sklep. Potem `Mapping::apply()` i `RuleEngine::apply()` (agent M) i kontrola
 * lokalna.
 *
 * Cena napisana od nowa (research/02 §1.4 "Price / tax / currency"): FeedForge bral walute,
 * kraj podatku i grupe klienta z biezacego kontekstu, wiec feed w drugiej walucie albo kraju
 * wysylal zle ceny. Tu `Product::priceCalculation()` dostaje jawnie sklep, kraj feedu, walute
 * feedu i grupe "Visitor" — PrestaShop sam przelicza kurs, dolicza podatek kraju feedu i
 * dobiera ceny specyficzne dla tej waluty / kraju.
 */
final class ProductBuilder
{
    /** @var array<string,mixed> */
    private array $cache = [];

    public function __construct(
        private readonly Mapping $mapping,
        private readonly RuleEngine $rules,
        private readonly OfferPrice $offerPrice,
        private readonly ConditionMap $conditions,
    ) {
    }

    /** null = oferty nie ma (produkt usuniety, nieaktywny w sklepie, brak tekstow w jezyku feedu). */
    public function build(Feed $feed, int $idProduct, int $idProductAttribute): ?BuiltOffer
    {
        $product = $this->productRow($feed, $idProduct);

        if ($product === null) {
            return null;
        }

        $combination = null;

        if ($idProductAttribute > 0) {
            $combination = $this->combinationRow($feed, $idProduct, $idProductAttribute);

            if ($combination === null) {
                return null;
            }
        }

        $data = new ProductData();
        $data->idShop = $feed->idShop;
        $data->idFeed = $feed->id;
        $data->idProduct = $idProduct;
        $data->idProductAttribute = $idProductAttribute;
        $data->idDefaultCategory = (int) $product['id_category_default'];
        $data->categoryIds = $this->categoryIds($idProduct);

        // Pusta wartosc kombinacji = wartosc produktu (FeedForge uzywal `??`, ktore nie lapie '').
        $pick = static fn (string $key): string => trim((string) (($combination[$key] ?? '') !== '' ? $combination[$key] : ($product[$key] ?? '')));
        $reference = $pick('reference');
        $ean = $pick('ean13');
        $brand = trim((string) ($product['manufacturer_name'] ?? ''));
        $categoryName = $this->categoryName($data->idDefaultCategory, $feed->idLang);
        $quantity = (int) \StockAvailable::getQuantityAvailableByProduct($idProduct, $idProductAttribute, $feed->idShop);
        $weight = (float) $product['weight'] + (float) ($combination['weight'] ?? 0);
        $attributes = $combination['attributes'] ?? [];
        $currency = $feed->currencyIso !== '' ? $feed->currencyIso : $this->currencyIso($feed->idCurrency);

        $offerId = OfferId::build($feed->offerPrefix, $feed->idSource, $idProduct, $idProductAttribute, $reference, $ean);
        $images = $this->images($feed, $idProduct, $idProductAttribute, (string) $product['link_rewrite']);
        $price = $this->offerPrice->prices($feed, $idProduct, $idProductAttribute);

        $fields = [
            'offerId' => $offerId,
            'contentLanguage' => $feed->contentLanguage,
            'feedLabel' => $feed->feedLabel,
            'title' => OfferText::title($feed->titleTemplate, [
                'name' => (string) $product['name'],
                'brand' => $brand,
                'category' => $categoryName,
                'reference' => $reference,
                'ean' => $ean,
            ], array_column($attributes, 'value')),
            'description' => OfferText::description($feed->descriptionSource, (string) $product['description_short'], (string) $product['description']),
            'link' => $this->link($feed, $idProduct, $idProductAttribute),
            'imageLink' => $images[0] ?? '',
            'additionalImageLinks' => array_slice($images, 1, 10),
            'availability' => $this->availability($feed->idShop, $idProduct, $quantity),
            // Stany 9.2 wg mapowania z Mapping (decyzja #12); "Do not send" wyklucza juz filtr feedu.
            'condition' => ConditionMap::google((string) $product['condition'], $this->conditions->forShop($feed->idShop)) ?? 'used',
            'price' => ['value' => self::money($price['regular']), 'currency' => $currency],
            'gtin' => $ean,
            'mpn' => $reference,
            'brand' => $brand,
            'identifierExists' => $ean !== '' || $reference !== '',
            'productType' => $this->categoryPath($data->idDefaultCategory, $feed->idLang),
        ];

        if (OfferPrice::hasSale($price)) {
            $fields['salePrice'] = ['value' => self::money($price['final']), 'currency' => $currency];
        }

        if ($idProductAttribute > 0) {
            $fields['itemGroupId'] = (string) $idProduct;
        }

        if ($weight > 0) {
            $fields['shippingWeight'] = ['value' => (string) round($weight, 3), 'unit' => 'kg'];
        }

        $google = $this->mapping->googleCategory($feed->idShop, $data->idDefaultCategory);

        if ($google !== null && $google > 0) {
            $fields['googleProductCategory'] = (string) $google;
        }

        $data->fields = $fields;
        $data->context = [
            'id_product' => $idProduct,
            'id_product_attribute' => $idProductAttribute,
            'id_category_default' => $data->idDefaultCategory,
            'id_manufacturer' => (int) $product['id_manufacturer'],
            'id_supplier' => (int) $product['id_supplier'],
            'price' => (float) $product['price'] + (float) ($combination['price'] ?? 0),
            'quantity' => $quantity,
            'weight' => $weight,
            'condition' => (string) $product['condition'],
            'visibility' => (string) $product['visibility'],
            'brand' => $brand,
            'ean13' => $ean,
            'upc' => $pick('upc'),
            'isbn' => $pick('isbn'),
            'mpn' => $pick('mpn'),
            'reference' => $reference,
            'supplier_reference' => $pick('supplier_reference'),
            'country_code' => $feed->country,
            'has_combination' => $idProductAttribute > 0,
            'attributes' => $attributes,
            'features' => $this->features($idProduct, $feed->idLang),
            'id_feed' => $feed->id,
            'id_lang' => $feed->idLang,
            'id_currency' => $feed->idCurrency,
        ];

        $this->mapping->apply($feed->idShop, $data);
        // `identifierExists` po regule identyfikatora przelicza sam RuleEngine z uwzglednieniem
        // ustawienia Mapping ("Always no" / "Always yes" / automatycznie) — tu nic nie nadpisujemy.
        $rule = $this->rules->apply($feed->idShop, $feed->id, $data);

        $input = PayloadBuilder::productInput($data->fields);

        return new BuiltOffer(
            $data,
            $rule->excluded,
            $rule->reason,
            $input,
            PayloadBuilder::hash($input),
            PayloadBuilder::coreHash($input),
            LocalCheck::issues($data->fields),
        );
    }

    /** Czyszczenie pamieci podrecznej miedzy partiami dlugiego zadania. */
    public function reset(): void
    {
        $this->cache = [];
        $this->offerPrice->reset();
    }

    public static function money(float $value): string
    {
        return number_format(round($value, 2), 2, '.', '');
    }

    /** @return array<string,mixed>|null */
    private function productRow(Feed $feed, int $idProduct): ?array
    {
        $px = _DB_PREFIX_;
        $row = Db::getInstance()->getRow('SELECT p.`id_product`, p.`reference`, p.`ean13`, p.`upc`, p.`isbn`, p.`mpn`, p.`supplier_reference`,
                p.`weight`, p.`id_manufacturer`, p.`id_supplier`, ps.`id_category_default`, ps.`price`, ps.`condition`, ps.`visibility`,
                pl.`name`, pl.`description`, pl.`description_short`, pl.`link_rewrite`, m.`name` AS manufacturer_name
            FROM `' . $px . 'product` p
            INNER JOIN `' . $px . 'product_shop` ps ON ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . $feed->idShop . '
            INNER JOIN `' . $px . 'product_lang` pl ON pl.`id_product` = p.`id_product` AND pl.`id_lang` = ' . $feed->idLang . ' AND pl.`id_shop` = ' . $feed->idShop . '
            LEFT JOIN `' . $px . 'manufacturer` m ON m.`id_manufacturer` = p.`id_manufacturer`
            WHERE p.`id_product` = ' . $idProduct . ' AND ps.`active` = 1 AND p.`state` = 1');

        return is_array($row) && $row ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function combinationRow(Feed $feed, int $idProduct, int $idProductAttribute): ?array
    {
        $px = _DB_PREFIX_;
        $row = Db::getInstance()->getRow('SELECT pa.`id_product_attribute`, pa.`reference`, pa.`ean13`, pa.`upc`, pa.`isbn`, pa.`mpn`,
                pa.`supplier_reference`, pas.`weight`, pas.`price`
            FROM `' . $px . 'product_attribute` pa
            INNER JOIN `' . $px . 'product_attribute_shop` pas ON pas.`id_product_attribute` = pa.`id_product_attribute` AND pas.`id_shop` = ' . $feed->idShop . '
            WHERE pa.`id_product_attribute` = ' . $idProductAttribute . ' AND pa.`id_product` = ' . $idProduct);

        if (!is_array($row) || !$row) {
            return null;
        }

        $values = Db::getInstance()->executeS('SELECT a.`id_attribute_group`, agl.`name` AS group_name, al.`name` AS value
            FROM `' . $px . 'product_attribute_combination` pac
            INNER JOIN `' . $px . 'attribute` a ON a.`id_attribute` = pac.`id_attribute`
            INNER JOIN `' . $px . 'attribute_group` ag ON ag.`id_attribute_group` = a.`id_attribute_group`
            LEFT JOIN `' . $px . 'attribute_group_lang` agl ON agl.`id_attribute_group` = a.`id_attribute_group` AND agl.`id_lang` = ' . $feed->idLang . '
            LEFT JOIN `' . $px . 'attribute_lang` al ON al.`id_attribute` = a.`id_attribute` AND al.`id_lang` = ' . $feed->idLang . '
            WHERE pac.`id_product_attribute` = ' . $idProductAttribute . '
            ORDER BY ag.`position` ASC');

        $row['attributes'] = [];

        foreach (is_array($values) ? $values : [] as $value) {
            $row['attributes'][(int) $value['id_attribute_group']] = [
                'group' => (string) $value['group_name'],
                'value' => (string) $value['value'],
            ];
        }

        return $row;
    }

    /** @return string[] adresy zdjec: najpierw zdjecia kombinacji (gdy ma wlasne), potem produktu */
    private function images(Feed $feed, int $idProduct, int $idProductAttribute, string $linkRewrite): array
    {
        $px = _DB_PREFIX_;
        $ids = [];

        if ($idProductAttribute > 0) {
            $rows = Db::getInstance()->executeS('SELECT pai.`id_image` FROM `' . $px . 'product_attribute_image` pai
                INNER JOIN `' . $px . 'image` i ON i.`id_image` = pai.`id_image`
                INNER JOIN `' . $px . 'image_shop` ish ON ish.`id_image` = i.`id_image` AND ish.`id_shop` = ' . $feed->idShop . '
                WHERE pai.`id_product_attribute` = ' . $idProductAttribute . ' ORDER BY i.`position` ASC');
            $ids = array_map('intval', array_column(is_array($rows) ? $rows : [], 'id_image'));
        }

        if (!$ids) {
            $rows = Db::getInstance()->executeS('SELECT i.`id_image` FROM `' . $px . 'image` i
                INNER JOIN `' . $px . 'image_shop` ish ON ish.`id_image` = i.`id_image` AND ish.`id_shop` = ' . $feed->idShop . '
                WHERE i.`id_product` = ' . $idProduct . ' ORDER BY ish.`cover` DESC, i.`position` ASC');
            $ids = array_map('intval', array_column(is_array($rows) ? $rows : [], 'id_image'));
        }

        $type = $this->imageType();
        $link = $this->linkObject();
        $out = [];

        foreach (array_slice(array_values(array_unique($ids)), 0, 11) as $idImage) {
            $out[] = $this->secure($feed->idShop, (string) $link->getImageLink($linkRewrite !== '' ? $linkRewrite : 'product', (string) $idImage, $type));
        }

        return $out;
    }

    /** Najwiekszy typ zdjec produktow — nazwy typow zaleza od motywu (research/03 §12). */
    private function imageType(): ?string
    {
        if (!array_key_exists('imageType', $this->cache)) {
            $types = \ImageType::getImagesTypes('products');
            usort($types, static fn (array $a, array $b): int => ((int) $b['width'] * (int) $b['height']) <=> ((int) $a['width'] * (int) $a['height']));
            $this->cache['imageType'] = isset($types[0]['name']) ? (string) $types[0]['name'] : null;
        }

        return $this->cache['imageType'];
    }

    private function link(Feed $feed, int $idProduct, int $idProductAttribute): string
    {
        $url = (string) $this->linkObject()->getProductLink($idProduct, null, null, null, $feed->idLang, $feed->idShop, $idProductAttribute);

        return $this->secure($feed->idShop, $url);
    }

    private function linkObject(): \Link
    {
        if (!isset($this->cache['link'])) {
            $this->cache['link'] = new \Link();
        }

        return $this->cache['link'];
    }

    /** Sklep na HTTPS — adres zawsze https (Link potrafi oddac http przy wylaczonym PS_SSL_ENABLED za proxy). */
    private function secure(int $idShop, string $url): string
    {
        // Poza zadaniem HTTP (cron z CLI) Link::getImageLink() oddaje adres bez protokolu.
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            $url = (ShopContext::isHttps($idShop) ? 'https://' : 'http://') . ltrim($url, '/');
        }

        if (str_starts_with($url, 'http://') && ShopContext::isHttps($idShop)) {
            return 'https://' . substr($url, 7);
        }

        return $url;
    }

    private function availability(int $idShop, int $idProduct, int $quantity): string
    {
        if ($quantity > 0) {
            return 'in_stock';
        }

        $outOfStock = (int) \StockAvailable::outOfStock($idProduct, $idShop);
        $allowed = $outOfStock === 1
            || ($outOfStock === 2 && (bool) \Configuration::get('PS_ORDER_OUT_OF_STOCK', null, null, $idShop));

        return $allowed ? 'backorder' : 'out_of_stock';
    }

    /** @return int[] */
    private function categoryIds(int $idProduct): array
    {
        $rows = Db::getInstance()->executeS('SELECT `id_category` FROM `' . _DB_PREFIX_ . 'category_product` WHERE `id_product` = ' . $idProduct);

        return array_map('intval', array_column(is_array($rows) ? $rows : [], 'id_category'));
    }

    private function categoryName(int $idCategory, int $idLang): string
    {
        $path = $this->categoryChain($idCategory, $idLang);

        return $path ? (string) end($path) : '';
    }

    private function categoryPath(int $idCategory, int $idLang): string
    {
        return implode(' > ', $this->categoryChain($idCategory, $idLang));
    }

    /** @return string[] nazwy od najwyzszej kategorii pod "Home" do domyslnej kategorii produktu */
    private function categoryChain(int $idCategory, int $idLang): array
    {
        $key = 'cat.' . $idCategory . '.' . $idLang;

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $parts = [];
        $current = $idCategory;

        for ($i = 0; $i < 10 && $current > 0; ++$i) {
            $row = Db::getInstance()->getRow('SELECT c.`id_parent`, c.`is_root_category`, cl.`name` FROM `' . _DB_PREFIX_ . 'category` c
                LEFT JOIN `' . _DB_PREFIX_ . 'category_lang` cl ON cl.`id_category` = c.`id_category` AND cl.`id_lang` = ' . $idLang . '
                WHERE c.`id_category` = ' . $current);

            // Korzen i "Home" nie sa czescia sciezki typu produktu.
            if (!is_array($row) || !$row || (int) $row['id_parent'] === 0 || (int) $row['is_root_category'] === 1) {
                break;
            }

            $parts[] = (string) $row['name'];
            $current = (int) $row['id_parent'];
        }

        return $this->cache[$key] = array_reverse($parts);
    }

    /** @return array<int,array{name:string,value:string}> */
    private function features(int $idProduct, int $idLang): array
    {
        $px = _DB_PREFIX_;
        $rows = Db::getInstance()->executeS('SELECT fp.`id_feature`, fl.`name`, fvl.`value` FROM `' . $px . 'feature_product` fp
            LEFT JOIN `' . $px . 'feature_lang` fl ON fl.`id_feature` = fp.`id_feature` AND fl.`id_lang` = ' . $idLang . '
            LEFT JOIN `' . $px . 'feature_value_lang` fvl ON fvl.`id_feature_value` = fp.`id_feature_value` AND fvl.`id_lang` = ' . $idLang . '
            WHERE fp.`id_product` = ' . $idProduct);
        $out = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[(int) $row['id_feature']] ??= ['name' => (string) $row['name'], 'value' => (string) $row['value']];
        }

        return $out;
    }

    private function currencyIso(int $idCurrency): string
    {
        return (string) Db::getInstance()->getValue('SELECT `iso_code` FROM `' . _DB_PREFIX_ . 'currency` WHERE `id_currency` = ' . $idCurrency);
    }
}
