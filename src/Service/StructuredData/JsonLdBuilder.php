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

/**
 * Budowa obiektow JSON-LD z danych juz zebranych ze sklepu (tablice) — czysta logika,
 * bez PrestaShop i bez bazy, testowana jednostkowo.
 *
 * Pola i nazwy wedlug dokumentacji Google (research/05 §K): Product (merchant listing),
 * ProductGroup + hasVariant, Organization (`OnlineStore`) z `hasShippingService` i
 * `hasMerchantReturnPolicy`, OfferShippingDetails, MerchantReturnPolicy, BreadcrumbList,
 * WebSite, ItemList.
 *
 * Dane wejsciowe (klucze tablic):
 *  - oferta: price (float, juz z promocja), regular (float|null — cena przed promocja),
 *    currency, availability (InStock | OutOfStock | BackOrder | PreOrder), url,
 *    validUntil ('Y-m-d'|null — koniec promocji)
 *  - produkt: id, name, description, url, images[], sku, mpn, gtin13, gtin12, brand, category,
 *    condition (new | used | refurbished), rating {value, count}|null, offer
 *  - grupa: jak produkt + groupId, variesBy[] (color | size | material | pattern),
 *    variants[] {name, sku, gtin13, gtin12, mpn, image, url, attributes {color, size…}, offer}
 *  - wysylka (od Merchant Center, patrz ShippingSource): currency, services[] {name,
 *    countries[], rate, freeOver|null, handling [min,max], transit [min,max]},
 *    returns {countries[], days, fees (free|customer|fixed), feeAmount, method (mail|store|kiosk), link}|null
 */
final class JsonLdBuilder
{
    private const SCHEMA = 'https://schema.org';

    private const AVAILABILITY = ['InStock', 'OutOfStock', 'BackOrder', 'PreOrder', 'LimitedAvailability', 'Discontinued'];

    private const CONDITION = ['new' => 'NewCondition', 'used' => 'UsedCondition', 'refurbished' => 'RefurbishedCondition'];

    /** Atrybuty wariantow, ktore Google obsluguje w `variesBy` (research/05 §K). */
    public const VARIES_BY = ['color', 'size', 'material', 'pattern', 'suggestedAge', 'suggestedGender'];

    /** Najwiecej wariantow w jednym ProductGroup (strona nie moze puchnac bez konca). */
    public const MAX_VARIANTS = 100;

    /**
     * @param array<string,mixed> $org name, url, logo, email, telephone, address {street, city, postcode, region, country}
     * @param array<string,mixed>|null $shipping dane wysylki i zwrotow (null = bez polityk)
     *
     * @return array<string,mixed>
     */
    public function organization(array $org, ?array $shipping = null): array
    {
        $out = [
            '@context' => self::SCHEMA,
            '@type' => 'OnlineStore',
            'name' => (string) $org['name'],
            'url' => (string) $org['url'],
        ];

        if (!empty($org['logo'])) {
            $out['logo'] = (string) $org['logo'];
        }

        if (!empty($org['email'])) {
            $out['email'] = (string) $org['email'];
        }

        if (!empty($org['telephone'])) {
            $out['telephone'] = (string) $org['telephone'];
        }

        $address = $this->address((array) ($org['address'] ?? []));

        if ($address !== null) {
            $out['address'] = $address;
        }

        if ($shipping !== null) {
            $services = $this->shippingServices($shipping);

            if ($services) {
                $out['hasShippingService'] = count($services) === 1 ? $services[0] : $services;
            }

            $returns = $this->returnPolicy($shipping['returns'] ?? null);

            if ($returns !== null) {
                $out['hasMerchantReturnPolicy'] = $returns;
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $site name, url, search (adres wyszukiwarki z `{search_term_string}`)
     *
     * @return array<string,mixed>
     */
    public function website(array $site): array
    {
        $out = [
            '@context' => self::SCHEMA,
            '@type' => 'WebSite',
            'name' => (string) $site['name'],
            'url' => (string) $site['url'],
        ];

        if (!empty($site['search'])) {
            $out['potentialAction'] = [
                '@type' => 'SearchAction',
                'target' => ['@type' => 'EntryPoint', 'urlTemplate' => (string) $site['search']],
                'query-input' => 'required name=search_term_string',
            ];
        }

        return $out;
    }

    /**
     * Okruszki: co najmniej 2 pozycje (Google), bez `item` w ostatniej.
     *
     * @param array<int,array{name:string,url:string}> $links
     *
     * @return array<string,mixed>|null
     */
    public function breadcrumb(array $links): ?array
    {
        $links = array_values(array_filter($links, static fn (array $l): bool => trim((string) ($l['name'] ?? '')) !== ''));

        if (count($links) < 2) {
            return null;
        }

        $items = [];
        $last = count($links) - 1;

        foreach ($links as $n => $link) {
            $item = ['@type' => 'ListItem', 'position' => $n + 1, 'name' => (string) $link['name']];

            if ($n < $last && !empty($link['url'])) {
                $item['item'] = (string) $link['url'];
            }

            $items[] = $item;
        }

        return ['@context' => self::SCHEMA, '@type' => 'BreadcrumbList', 'itemListElement' => $items];
    }

    /**
     * Lista produktow kategorii w postaci "strona podsumowania" (ListItem z adresem).
     *
     * @param array<int,array{name:string,url:string}> $items
     *
     * @return array<string,mixed>|null
     */
    public function itemList(array $items, string $name = ''): ?array
    {
        if ($items === []) {
            return null;
        }

        $list = [];

        foreach (array_values($items) as $n => $item) {
            $list[] = ['@type' => 'ListItem', 'position' => $n + 1, 'url' => (string) $item['url'], 'name' => (string) $item['name']];
        }

        $out = ['@context' => self::SCHEMA, '@type' => 'ItemList'];

        if ($name !== '') {
            $out['name'] = $name;
        }

        $out['numberOfItems'] = count($list);
        $out['itemListElement'] = $list;

        return $out;
    }

    /**
     * Produkt z jedna oferta (produkt bez kombinacji albo z wylaczonymi wariantami).
     *
     * @param array<string,mixed> $p
     * @param array<string,mixed>|null $shipping
     *
     * @return array<string,mixed>|null null gdy produktu nie da sie kupic (brak ceny)
     */
    public function product(array $p, ?array $shipping = null): ?array
    {
        $offer = $this->offer((array) ($p['offer'] ?? []), $shipping);

        if ($offer === null) {
            return null;
        }

        $out = ['@context' => self::SCHEMA, '@type' => 'Product'] + $this->productFields($p, true);
        $out['offers'] = $offer;

        return $out;
    }

    /**
     * Produkt z kombinacjami jako ProductGroup z `hasVariant`. Jedna strona z wariantami
     * wybieranymi w adresie (PrestaShop): `url` na grupie, kazdy wariant z wlasnym adresem
     * i oferta (research/05 §K "ProductGroup").
     *
     * @param array<string,mixed> $p
     * @param array<string,mixed>|null $shipping
     *
     * @return array<string,mixed>|null null gdy zaden wariant nie ma ceny
     */
    public function productGroup(array $p, ?array $shipping = null): ?array
    {
        $groupId = (string) ($p['groupId'] ?? $p['id'] ?? '');
        $variants = [];

        foreach (array_slice((array) ($p['variants'] ?? []), 0, self::MAX_VARIANTS) as $v) {
            $offer = $this->offer((array) ($v['offer'] ?? []), $shipping);

            if ($offer === null) {
                continue;
            }

            $variant = ['@type' => 'Product', 'name' => (string) $v['name']];

            foreach (['sku', 'mpn', 'gtin13', 'gtin12'] as $field) {
                if (!empty($v[$field])) {
                    $variant[$field] = (string) $v[$field];
                }
            }

            if (!empty($v['image'])) {
                $variant['image'] = (string) $v['image'];
            }

            foreach ((array) ($v['attributes'] ?? []) as $attribute => $value) {
                if (in_array($attribute, self::VARIES_BY, true) && (string) $value !== '') {
                    $variant[$attribute] = (string) $value;
                }
            }

            if ($groupId !== '') {
                $variant['inProductGroupWithID'] = $groupId;
            }

            $variant['offers'] = $offer;
            $variants[] = $variant;
        }

        if ($variants === []) {
            return null;
        }

        $out = ['@context' => self::SCHEMA, '@type' => 'ProductGroup'] + $this->productFields($p, false);

        if ($groupId !== '') {
            $out['productGroupID'] = $groupId;
        }

        $variesBy = array_values(array_intersect(self::VARIES_BY, (array) ($p['variesBy'] ?? [])));

        if ($variesBy) {
            $out['variesBy'] = array_map(static fn (string $a): string => self::SCHEMA . '/' . $a, $variesBy);
        }

        $out['hasVariant'] = $variants;

        return $out;
    }

    /**
     * Oferta. Cena przed promocja idzie jako `priceSpecification` z `StrikethroughPrice`;
     * `priceValidUntil` tylko gdy promocja ma date konca (nie wpisana na sztywno — SPEC §5.9 [F]).
     *
     * @param array<string,mixed> $o
     * @param array<string,mixed>|null $shipping
     *
     * @return array<string,mixed>|null
     */
    public function offer(array $o, ?array $shipping = null): ?array
    {
        $price = (float) ($o['price'] ?? 0);
        $currency = strtoupper((string) ($o['currency'] ?? ''));

        if ($price <= 0 || strlen($currency) !== 3) {
            return null;
        }

        $out = [
            '@type' => 'Offer',
            'price' => self::money($price),
            'priceCurrency' => $currency,
        ];

        $regular = isset($o['regular']) ? (float) $o['regular'] : 0.0;

        if ($regular > $price + 0.0001) {
            $out['priceSpecification'] = [
                '@type' => 'UnitPriceSpecification',
                'priceType' => self::SCHEMA . '/StrikethroughPrice',
                'price' => self::money($regular),
                'priceCurrency' => $currency,
            ];
        }

        if (!empty($o['validUntil'])) {
            $out['priceValidUntil'] = (string) $o['validUntil'];
        }

        $availability = in_array($o['availability'] ?? '', self::AVAILABILITY, true) ? (string) $o['availability'] : 'InStock';
        $out['availability'] = self::SCHEMA . '/' . $availability;

        if (!empty($o['condition']) && isset(self::CONDITION[$o['condition']])) {
            $out['itemCondition'] = self::SCHEMA . '/' . self::CONDITION[$o['condition']];
        }

        if (!empty($o['url'])) {
            $out['url'] = (string) $o['url'];
        }

        if ($shipping !== null) {
            $details = $this->shippingDetails($shipping, $currency, $price);

            if ($details) {
                $out['shippingDetails'] = count($details) === 1 ? $details[0] : $details;
            }

            $returns = $this->returnPolicy($shipping['returns'] ?? null);

            if ($returns !== null) {
                $out['hasMerchantReturnPolicy'] = $returns;
            }
        }

        return $out;
    }

    /**
     * OfferShippingDetails dla oferty: jedna pozycja na usluge i kraj. Tylko w walucie
     * oferty (Google: `shippingRate.currency` zgodna z oferta); prog darmowej wysylki
     * zamieniony na stawke 0 dla oferty powyzej progu.
     *
     * @param array<string,mixed> $shipping
     *
     * @return array<int,array<string,mixed>>
     */
    public function shippingDetails(array $shipping, string $currency, float $price): array
    {
        if (strtoupper((string) ($shipping['currency'] ?? '')) !== strtoupper($currency)) {
            return [];
        }

        $out = [];

        foreach ((array) ($shipping['services'] ?? []) as $service) {
            $rate = (float) ($service['rate'] ?? 0);

            if (isset($service['freeOver']) && $service['freeOver'] !== null && $price >= (float) $service['freeOver']) {
                $rate = 0.0;
            }

            foreach ((array) ($service['countries'] ?? []) as $country) {
                $out[] = [
                    '@type' => 'OfferShippingDetails',
                    'shippingRate' => ['@type' => 'MonetaryAmount', 'value' => self::money($rate), 'currency' => strtoupper($currency)],
                    'shippingDestination' => ['@type' => 'DefinedRegion', 'addressCountry' => strtoupper((string) $country)],
                    'deliveryTime' => [
                        '@type' => 'ShippingDeliveryTime',
                        'handlingTime' => self::days((array) ($service['handling'] ?? [0, 0])),
                        'transitTime' => self::days((array) ($service['transit'] ?? [0, 0])),
                    ],
                ];
            }
        }

        return $out;
    }

    /**
     * ShippingService dla Organization (`hasShippingService`, research/05 §K).
     *
     * @param array<string,mixed> $shipping
     *
     * @return array<int,array<string,mixed>>
     */
    public function shippingServices(array $shipping): array
    {
        $currency = strtoupper((string) ($shipping['currency'] ?? ''));
        $out = [];

        foreach ((array) ($shipping['services'] ?? []) as $service) {
            $countries = array_values(array_filter(array_map(static fn ($c): string => strtoupper((string) $c), (array) ($service['countries'] ?? []))));

            if ($countries === [] || strlen($currency) !== 3) {
                continue;
            }

            $destination = array_map(static fn (string $c): array => ['@type' => 'DefinedRegion', 'addressCountry' => $c], $countries);
            $destination = count($destination) === 1 ? $destination[0] : $destination;
            $transit = ['@type' => 'ServicePeriod', 'duration' => self::days((array) ($service['transit'] ?? [0, 0]))];
            $rate = (float) ($service['rate'] ?? 0);
            $freeOver = isset($service['freeOver']) && $service['freeOver'] !== null ? (float) $service['freeOver'] : null;

            $conditions = [[
                '@type' => 'ShippingConditions',
                'shippingDestination' => $destination,
                'shippingRate' => ['@type' => 'MonetaryAmount', 'value' => self::money($rate), 'currency' => $currency],
                'transitTime' => $transit,
            ]];

            if ($freeOver !== null && $rate > 0) {
                $conditions[0]['orderValue'] = ['@type' => 'MonetaryAmount', 'minValue' => '0.00', 'maxValue' => self::money($freeOver), 'currency' => $currency];
                $conditions[] = [
                    '@type' => 'ShippingConditions',
                    'shippingDestination' => $destination,
                    'orderValue' => ['@type' => 'MonetaryAmount', 'minValue' => self::money($freeOver), 'currency' => $currency],
                    'shippingRate' => ['@type' => 'MonetaryAmount', 'value' => '0.00', 'currency' => $currency],
                    'transitTime' => $transit,
                ];
            }

            $item = ['@type' => 'ShippingService'];

            if (!empty($service['name'])) {
                $item['name'] = (string) $service['name'];
            }

            $item['handlingTime'] = ['@type' => 'ServicePeriod', 'duration' => self::days((array) ($service['handling'] ?? [0, 0]))];
            $item['shippingConditions'] = count($conditions) === 1 ? $conditions[0] : $conditions;
            $out[] = $item;
        }

        return $out;
    }

    /**
     * MerchantReturnPolicy: kraje + kategoria (okno skonczone / bez zwrotow), dni, koszt i sposob.
     *
     * @param array<string,mixed>|null $returns
     *
     * @return array<string,mixed>|null
     */
    public function returnPolicy(?array $returns): ?array
    {
        if ($returns === null) {
            return null;
        }

        $countries = array_values(array_unique(array_filter(array_map(static fn ($c): string => strtoupper((string) $c), (array) ($returns['countries'] ?? [])))));
        $link = (string) ($returns['link'] ?? '');

        if ($countries === []) {
            return $link !== '' ? ['@type' => 'MerchantReturnPolicy', 'merchantReturnLink' => $link] : null;
        }

        $days = (int) ($returns['days'] ?? 0);
        $out = [
            '@type' => 'MerchantReturnPolicy',
            'applicableCountry' => count($countries) === 1 ? $countries[0] : array_slice($countries, 0, 50),
        ];

        if ($days <= 0) {
            $out['returnPolicyCategory'] = self::SCHEMA . '/MerchantReturnNotPermitted';

            return $out;
        }

        $out['returnPolicyCategory'] = self::SCHEMA . '/MerchantReturnFiniteReturnWindow';
        $out['merchantReturnDays'] = $days;

        $method = ['mail' => 'ReturnByMail', 'store' => 'ReturnInStore', 'kiosk' => 'ReturnAtKiosk'][$returns['method'] ?? ''] ?? null;

        if ($method !== null) {
            $out['returnMethod'] = self::SCHEMA . '/' . $method;
        }

        $fees = (string) ($returns['fees'] ?? '');

        if ($fees === 'free') {
            $out['returnFees'] = self::SCHEMA . '/FreeReturn';
        } elseif ($fees === 'customer') {
            $out['returnFees'] = self::SCHEMA . '/ReturnFeesCustomerResponsibility';
        } elseif ($fees === 'fixed' && isset($returns['feeAmount'], $returns['feeCurrency'])) {
            $out['returnFees'] = self::SCHEMA . '/ReturnShippingFees';
            $out['returnShippingFeesAmount'] = ['@type' => 'MonetaryAmount', 'value' => self::money((float) $returns['feeAmount']), 'currency' => strtoupper((string) $returns['feeCurrency'])];
        }

        if ($link !== '') {
            $out['merchantReturnLink'] = $link;
        }

        return $out;
    }

    /**
     * JSON do wstawienia w `<script type="application/ld+json">`: znaki `<`, `>`, `&`, `'`, `"`
     * w napisach jako \u00XX, wiec tresc ze sklepu nie zamknie znacznika (ARCHITEKTURA §11).
     *
     * @param array<string,mixed> $node
     */
    public static function encode(array $node, bool $pretty = false): string
    {
        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

        return (string) json_encode($node, $pretty ? $flags | JSON_PRETTY_PRINT : $flags);
    }

    /** Kwota z dwoma miejscami i kropka (Google przyjmuje liczbe albo napis; napis nie gubi zer). */
    public static function money(float $value): string
    {
        return number_format(round($value, 2), 2, '.', '');
    }

    /**
     * @param array<string,mixed> $p
     *
     * @return array<string,mixed>
     */
    private function productFields(array $p, bool $identifiers): array
    {
        $out = ['name' => (string) $p['name']];

        if (!empty($p['description'])) {
            $out['description'] = (string) $p['description'];
        }

        if (!empty($p['url'])) {
            $out['url'] = (string) $p['url'];
        }

        $images = array_values(array_filter(array_map('strval', (array) ($p['images'] ?? []))));

        if ($images) {
            $out['image'] = count($images) === 1 ? $images[0] : $images;
        }

        if ($identifiers) {
            foreach (['sku', 'mpn', 'gtin13', 'gtin12'] as $field) {
                if (!empty($p[$field])) {
                    $out[$field] = (string) $p[$field];
                }
            }
        }

        if (!empty($p['brand'])) {
            $out['brand'] = ['@type' => 'Brand', 'name' => (string) $p['brand']];
        }

        if (!empty($p['category'])) {
            $out['category'] = (string) $p['category'];
        }

        $rating = $p['rating'] ?? null;

        if (is_array($rating) && (int) ($rating['count'] ?? 0) > 0 && (float) ($rating['value'] ?? 0) > 0) {
            $out['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => number_format((float) $rating['value'], 1, '.', ''),
                'reviewCount' => (int) $rating['count'],
                'bestRating' => 5,
                'worstRating' => 1,
            ];
        }

        return $out;
    }

    /** @param array<string,mixed> $a */
    private function address(array $a): ?array
    {
        $map = ['street' => 'streetAddress', 'city' => 'addressLocality', 'region' => 'addressRegion', 'postcode' => 'postalCode', 'country' => 'addressCountry'];
        $out = [];

        foreach ($map as $from => $to) {
            if (!empty($a[$from])) {
                $out[$to] = (string) $a[$from];
            }
        }

        return $out ? ['@type' => 'PostalAddress'] + $out : null;
    }

    /**
     * @param array<int,mixed> $range [min, max] dni
     *
     * @return array<string,mixed>
     */
    private static function days(array $range): array
    {
        $min = max(0, (int) ($range[0] ?? 0));
        $max = max($min, (int) ($range[1] ?? $min));

        return ['@type' => 'QuantitativeValue', 'minValue' => $min, 'maxValue' => $max, 'unitCode' => 'DAY'];
    }
}
