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
 * Sprawdzenie pol wymaganych przez Google (research/05 §K) przed wypisaniem bloku.
 *
 * Modul nie wypisuje bloku, ktory nie spelnia wymagan — lepiej nic niz blad w Search Console.
 * Nie zastepuje Rich Results Test (brak API); sprawdza to, co da sie sprawdzic lokalnie.
 */
final class Validator
{
    /**
     * @param array<string,mixed> $node obiekt najwyzszego poziomu
     *
     * @return string[] brakujace / bledne pola (pusta lista = poprawny)
     */
    public static function errors(array $node): array
    {
        $type = (string) ($node['@type'] ?? '');
        $errors = [];

        if (($node['@context'] ?? '') !== 'https://schema.org') {
            $errors[] = '@context';
        }

        switch ($type) {
            case 'Product':
                self::product($node, '', $errors, true);
                break;

            case 'ProductGroup':
                if (trim((string) ($node['name'] ?? '')) === '') {
                    $errors[] = 'name';
                }

                $variants = $node['hasVariant'] ?? null;

                if (!is_array($variants) || $variants === []) {
                    $errors[] = 'hasVariant';
                    break;
                }

                foreach (array_values($variants) as $n => $variant) {
                    self::product((array) $variant, 'hasVariant[' . $n . '].', $errors, false);
                }
                break;

            case 'BreadcrumbList':
                $items = $node['itemListElement'] ?? null;

                if (!is_array($items) || count($items) < 2) {
                    $errors[] = 'itemListElement (at least 2)';
                    break;
                }

                $last = count($items) - 1;

                foreach (array_values($items) as $n => $item) {
                    if ((int) ($item['position'] ?? 0) !== $n + 1) {
                        $errors[] = 'itemListElement[' . $n . '].position';
                    }

                    if (trim((string) ($item['name'] ?? '')) === '') {
                        $errors[] = 'itemListElement[' . $n . '].name';
                    }

                    if ($n < $last && trim((string) ($item['item'] ?? '')) === '') {
                        $errors[] = 'itemListElement[' . $n . '].item';
                    }
                }
                break;

            case 'ItemList':
                $items = $node['itemListElement'] ?? null;

                if (!is_array($items) || $items === []) {
                    $errors[] = 'itemListElement';
                    break;
                }

                foreach (array_values($items) as $n => $item) {
                    if ((int) ($item['position'] ?? 0) <= 0 || trim((string) ($item['url'] ?? '')) === '') {
                        $errors[] = 'itemListElement[' . $n . ']';
                    }
                }
                break;

            case 'WebSite':
                if (trim((string) ($node['url'] ?? '')) === '' || trim((string) ($node['name'] ?? '')) === '') {
                    $errors[] = 'url, name';
                }
                break;

            case 'OnlineStore':
            case 'Organization':
                // Google nie ma pol wymaganych dla Organization; zagniezdzone polityki — tak.
                if (trim((string) ($node['name'] ?? '')) === '' || trim((string) ($node['url'] ?? '')) === '') {
                    $errors[] = 'name, url';
                }

                foreach (self::list($node['hasShippingService'] ?? null) as $n => $service) {
                    if (empty($service['shippingConditions'])) {
                        $errors[] = 'hasShippingService[' . $n . '].shippingConditions';
                    }
                }

                if (isset($node['hasMerchantReturnPolicy'])) {
                    self::returnPolicy((array) $node['hasMerchantReturnPolicy'], 'hasMerchantReturnPolicy.', $errors);
                }
                break;

            default:
                $errors[] = '@type';
        }

        return $errors;
    }

    /**
     * @param array<string,mixed> $p
     * @param string[] $errors
     */
    private static function product(array $p, string $path, array &$errors, bool $needsImage): void
    {
        if (trim((string) ($p['name'] ?? '')) === '') {
            $errors[] = $path . 'name';
        }

        // Merchant listing wymaga zdjecia na produkcie; wariant moze korzystac ze zdjecia grupy.
        if ($needsImage && empty($p['image'])) {
            $errors[] = $path . 'image';
        }

        $offer = $p['offers'] ?? null;

        if (!is_array($offer)) {
            $errors[] = $path . 'offers';

            return;
        }

        if ((float) ($offer['price'] ?? 0) <= 0) {
            $errors[] = $path . 'offers.price';
        }

        if (strlen((string) ($offer['priceCurrency'] ?? '')) !== 3) {
            $errors[] = $path . 'offers.priceCurrency';
        }

        foreach (self::list($offer['shippingDetails'] ?? null) as $n => $details) {
            $prefix = $path . 'offers.shippingDetails[' . $n . '].';

            if (!isset($details['deliveryTime']['handlingTime'], $details['deliveryTime']['transitTime'])) {
                $errors[] = $prefix . 'deliveryTime';
            }

            if (empty($details['shippingDestination']['addressCountry'])) {
                $errors[] = $prefix . 'shippingDestination.addressCountry';
            }

            if (!isset($details['shippingRate']['value'], $details['shippingRate']['currency'])) {
                $errors[] = $prefix . 'shippingRate';
            } elseif ($details['shippingRate']['currency'] !== $offer['priceCurrency']) {
                $errors[] = $prefix . 'shippingRate.currency';
            }
        }

        if (isset($offer['hasMerchantReturnPolicy'])) {
            self::returnPolicy((array) $offer['hasMerchantReturnPolicy'], $path . 'offers.hasMerchantReturnPolicy.', $errors);
        }
    }

    /**
     * @param array<string,mixed> $policy
     * @param string[] $errors
     */
    private static function returnPolicy(array $policy, string $path, array &$errors): void
    {
        if (!empty($policy['merchantReturnLink']) && !isset($policy['applicableCountry'])) {
            return;
        }

        if (empty($policy['applicableCountry'])) {
            $errors[] = $path . 'applicableCountry';
        }

        $category = (string) ($policy['returnPolicyCategory'] ?? '');

        if ($category === '') {
            $errors[] = $path . 'returnPolicyCategory';
        } elseif (str_ends_with($category, 'MerchantReturnFiniteReturnWindow') && (int) ($policy['merchantReturnDays'] ?? 0) <= 0) {
            $errors[] = $path . 'merchantReturnDays';
        }
    }

    /**
     * Pole moze byc obiektem albo lista obiektow.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function list(mixed $value): array
    {
        if (!is_array($value) || $value === []) {
            return [];
        }

        return array_is_list($value) ? array_values(array_filter($value, 'is_array')) : [$value];
    }
}
