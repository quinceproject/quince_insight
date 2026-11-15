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

/**
 * `ProductData::$fields` → tresc `ProductInput` Merchant API v1, hashe i maska `patch`.
 *
 * Przeniesione z FeedForge (`ProductService::buildProductAttributes()`, `buildPrice()`,
 * mapowanie enumow — research/02 §1.5 "port nearly verbatim"), z poprawkami:
 * kwota w mikrojednostkach liczona na napisach (bez bcmath, ktorego hosting moze nie miec),
 * nieznany stan produktu z PrestaShop 9.2 (`open_box`, `damaged`, `new_with_defects` —
 * research/03 §12) idzie jako `USED`, nie jako niedozwolone `CONDITION_UNSPECIFIED`.
 */
final class PayloadBuilder
{
    /** Pola, ktore wolno zmieniac przez `productInputs.patch` (research/04 §9). */
    public const PATCH_FIELDS = ['price', 'salePrice', 'availability'];

    /**
     * @param array<string,mixed> $fields
     *
     * @return array<string,mixed>
     */
    public static function productInput(array $fields): array
    {
        return [
            'offerId' => (string) ($fields['offerId'] ?? ''),
            'contentLanguage' => strtolower((string) ($fields['contentLanguage'] ?? '')),
            'feedLabel' => strtoupper((string) ($fields['feedLabel'] ?? '')),
            'productAttributes' => self::attributes($fields),
        ];
    }

    /**
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     */
    public static function attributes(array $data): array
    {
        $attrs = [];

        foreach (['title', 'description', 'link', 'imageLink'] as $key) {
            if (isset($data[$key]) && (string) $data[$key] !== '') {
                $attrs[$key] = (string) $data[$key];
            }
        }

        if (!empty($data['additionalImageLinks'])) {
            $attrs['additionalImageLinks'] = array_values(array_map('strval', (array) $data['additionalImageLinks']));
        }

        if (isset($data['availability'])) {
            $attrs['availability'] = self::availability((string) $data['availability']);
        }

        if (isset($data['condition'])) {
            $attrs['condition'] = self::condition((string) $data['condition']);
        }

        if (isset($data['price']) && is_array($data['price'])) {
            $attrs['price'] = self::price($data['price']);
        }

        if (isset($data['salePrice']) && is_array($data['salePrice'])) {
            $attrs['salePrice'] = self::price($data['salePrice']);
        }

        if (isset($data['gtin']) && (string) $data['gtin'] !== '') {
            $gtins = is_array($data['gtin']) ? $data['gtin'] : [$data['gtin']];
            $attrs['gtins'] = array_slice(array_values(array_map('strval', $gtins)), 0, 10);
        }

        foreach (['mpn', 'brand', 'googleProductCategory', 'color', 'gender', 'ageGroup', 'material', 'pattern', 'itemGroupId'] as $key) {
            if (isset($data[$key]) && (string) $data[$key] !== '') {
                $attrs[$key] = (string) $data[$key];
            }
        }

        if (array_key_exists('identifierExists', $data)) {
            $attrs['identifierExists'] = (bool) $data['identifierExists'];
        }

        if (!empty($data['productType'])) {
            $attrs['productTypes'] = is_array($data['productType']) ? array_values(array_map('strval', $data['productType'])) : [(string) $data['productType']];
        }

        if (!empty($data['sizes'])) {
            $attrs['size'] = is_array($data['sizes']) ? (string) ($data['sizes'][0] ?? '') : (string) $data['sizes'];
        }

        for ($i = 0; $i < 5; ++$i) {
            $key = 'customLabel' . $i;

            if (isset($data[$key]) && (string) $data[$key] !== '') {
                $attrs[$key] = mb_substr((string) $data[$key], 0, 100);
            }
        }

        if (isset($data['shippingWeight']['value']) && (float) $data['shippingWeight']['value'] > 0) {
            $attrs['shippingWeight'] = [
                'value' => (float) $data['shippingWeight']['value'],
                'unit' => (string) ($data['shippingWeight']['unit'] ?? 'kg'),
            ];
        }

        if (!empty($data['promotionIds'])) {
            $attrs['promotionIds'] = array_values(array_map('strval', (array) $data['promotionIds']));
        }

        return $attrs;
    }

    /**
     * Cena Merchant API: `{amountMicros: "12300000", currencyCode: "PLN"}` (int64 jako napis).
     *
     * @param array{value?:mixed,currency?:mixed} $price
     *
     * @return array{amountMicros:string,currencyCode:string}
     */
    public static function price(array $price): array
    {
        return [
            'amountMicros' => self::micros((string) ($price['value'] ?? '0')),
            'currencyCode' => strtoupper((string) ($price['currency'] ?? '')),
        ];
    }

    /** "12.30" → "12300000" — dokladnie, bez bledow zmiennoprzecinkowych. */
    public static function micros(string $value): string
    {
        $value = trim($value);

        if (!is_numeric($value)) {
            return '0';
        }

        $formatted = number_format((float) $value, 6, '.', '');
        $negative = str_starts_with($formatted, '-');
        [$int, $frac] = explode('.', ltrim($formatted, '-'));
        $digits = ltrim($int . $frac, '0');

        return $digits === '' ? '0' : ($negative ? '-' : '') . $digits;
    }

    public static function availability(string $availability): string
    {
        return match (strtolower(str_replace(' ', '_', $availability))) {
            'in_stock' => 'IN_STOCK',
            'preorder' => 'PREORDER',
            'backorder' => 'BACKORDER',
            'limited_availability' => 'LIMITED_AVAILABILITY',
            default => 'OUT_OF_STOCK',
        };
    }

    public static function condition(string $condition): string
    {
        return match (strtolower($condition)) {
            'new' => 'NEW',
            'refurbished' => 'REFURBISHED',
            default => 'USED',
        };
    }

    /**
     * Hash calej tresci (wykrywanie zmian). Klucze sortowane rekurencyjnie, zeby kolejnosc
     * pol nie zmieniala wyniku.
     *
     * @param array<string,mixed> $input wynik productInput()
     */
    public static function hash(array $input): string
    {
        return sha1((string) json_encode(self::sorted($input), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Hash bez pol zmienianych przez `patch` — gdy jest taki sam jak poprzednio, a zmienil sie
     * hash calosci, wystarczy `patch` ceny i dostepnosci zamiast pelnego `insert`.
     *
     * @param array<string,mixed> $input
     */
    public static function coreHash(array $input): string
    {
        foreach (self::PATCH_FIELDS as $field) {
            unset($input['productAttributes'][$field]);
        }

        return self::hash($input);
    }

    /**
     * Tresc i maska `patch` dla pol ceny i dostepnosci. Pole w masce bez tresci jest usuwane
     * przez Google (research/04 §7.1) — tak znika `salePrice` po koncu promocji.
     *
     * @param array<string,mixed> $input
     *
     * @return array{body:array<string,mixed>,mask:string}
     */
    public static function patch(array $input): array
    {
        $attrs = [];
        $mask = [];

        foreach (self::PATCH_FIELDS as $field) {
            $mask[] = 'productAttributes.' . $field;

            if (isset($input['productAttributes'][$field])) {
                $attrs[$field] = $input['productAttributes'][$field];
            }
        }

        return ['body' => ['productAttributes' => $attrs], 'mask' => implode(',', $mask)];
    }

    /**
     * @param mixed $value
     *
     * @return mixed
     */
    private static function sorted(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map([self::class, 'sorted'], $value);
    }
}
