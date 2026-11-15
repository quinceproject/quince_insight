<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\MerchantTools;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Db;
use Quince\Insight\Service\Merchant\ProductData;

/**
 * Kontekst warunkow regul z danych sklepu — klucze jak `DataMapper::buildRuleContext` w FeedForge:
 * id_product, id_category_default, price, quantity, condition, brand, ean13, reference, weight,
 * visibility, country_code, has_combination.
 *
 * Poprawki: wartosc kombinacji pusta → wartosc produktu (FeedForge bral pusty napis kombinacji),
 * stan i dane produktu z wiersza sklepu (`product_shop`, `stock_available` z `id_shop`), waga
 * z wplywem kombinacji. Cena: bazowa netto sklepu z wplywem kombinacji (jak FeedForge i budowniczy
 * agenta E), zeby licznik i synchronizacja oceniaja regule tak samo. Gdy `ProductData::$context`
 * jest wypelniony przez agenta E, uzywany jest on (bez zapytan).
 */
final class RuleContext
{
    /** @var array<string,array<string,mixed>> */
    private array $memo = [];

    /** @return array<string,mixed> */
    public function for(int $idShop, string $country, ProductData $product): array
    {
        if (isset($product->context['id_product'], $product->context['price'])) {
            // Kontekst z budowniczego agenta E — te same klucze i znaczenia (cena bazowa netto).
            $context = $product->context;
        } else {
            $base = $this->rows($idShop, [[$product->idProduct, $product->idProductAttribute]]);
            $context = $base[$product->idProduct . '-' . $product->idProductAttribute] ?? ['id_product' => $product->idProduct];
        }

        if ($product->idDefaultCategory > 0) {
            $context['id_category_default'] = $product->idDefaultCategory;
        }

        $context['country_code'] = strtoupper($country);
        $context['has_combination'] = $product->idProductAttribute > 0;

        return $context;
    }

    /**
     * Konteksty wielu produktow jednym zapytaniem (licznik feedu).
     *
     * @param array<int,array{0:int,1:int}> $pairs [id_product, id_product_attribute]
     *
     * @return array<string,array<string,mixed>> klucz "idProduct-idAttribute"
     */
    public function rows(int $idShop, array $pairs): array
    {
        $out = [];
        $missing = [];

        foreach ($pairs as [$idProduct, $idAttribute]) {
            $key = $idShop . ':' . (int) $idProduct . '-' . (int) $idAttribute;

            if (isset($this->memo[$key])) {
                $out[(int) $idProduct . '-' . (int) $idAttribute] = $this->memo[$key];
            } else {
                $missing[(int) $idProduct . '-' . (int) $idAttribute] = [(int) $idProduct, (int) $idAttribute];
            }
        }

        foreach (array_chunk($missing, 500, true) as $chunk) {
            $where = [];

            foreach ($chunk as [$idProduct, $idAttribute]) {
                $where[] = '(p.`id_product` = ' . $idProduct . ' AND IFNULL(pa.`id_product_attribute`, 0) = ' . $idAttribute . ')';
            }

            $ids = implode(',', array_unique(array_map(static fn (array $p): int => $p[0], $chunk)));

            $rows = Db::getInstance()->executeS(
                'SELECT p.`id_product`, IFNULL(pa.`id_product_attribute`, 0) AS id_product_attribute,
                        ps.`id_category_default`, ps.`price`, ps.`condition`, ps.`visibility`,
                        p.`weight`, IFNULL(pa.`weight`, 0) AS attr_weight, IFNULL(pa.`price`, 0) AS attr_price,
                        p.`ean13`, pa.`ean13` AS attr_ean13, p.`reference`, pa.`reference` AS attr_reference,
                        IFNULL(m.`name`, "") AS brand, IFNULL(sa.`quantity`, 0) AS quantity
                 FROM `' . _DB_PREFIX_ . 'product` p
                 INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . $idShop . '
                 LEFT JOIN `' . _DB_PREFIX_ . 'product_attribute` pa ON pa.`id_product` = p.`id_product`
                     AND pa.`id_product_attribute` IN (' . implode(',', array_map(static fn (array $p): int => $p[1], $chunk) ?: [0]) . ')
                 LEFT JOIN `' . _DB_PREFIX_ . 'manufacturer` m ON m.`id_manufacturer` = p.`id_manufacturer`
                 LEFT JOIN `' . _DB_PREFIX_ . 'stock_available` sa ON sa.`id_product` = p.`id_product`
                     AND sa.`id_product_attribute` = IFNULL(pa.`id_product_attribute`, 0)
                     AND (sa.`id_shop` = ' . $idShop . ' OR sa.`id_shop` = 0)
                 WHERE p.`id_product` IN (' . $ids . ') AND (' . implode(' OR ', $where) . ')
                 ORDER BY sa.`id_shop` DESC'
            ) ?: [];

            foreach ($rows as $row) {
                $k = (int) $row['id_product'] . '-' . (int) $row['id_product_attribute'];

                if (isset($out[$k])) {
                    continue; // pierwszy wiersz = stan sklepu przed wspolnym (id_shop 0)
                }

                $context = [
                    'id_product' => (int) $row['id_product'],
                    'id_category_default' => (int) $row['id_category_default'],
                    'price' => (float) $row['price'] + (float) $row['attr_price'],
                    'quantity' => (int) $row['quantity'],
                    'condition' => (string) ($row['condition'] ?: 'new'),
                    'brand' => (string) $row['brand'],
                    'ean13' => self::pick($row['attr_ean13'] ?? null, $row['ean13']),
                    'reference' => self::pick($row['attr_reference'] ?? null, $row['reference']),
                    'weight' => (float) $row['weight'] + (float) $row['attr_weight'],
                    'visibility' => (string) ($row['visibility'] ?: 'both'),
                    'has_combination' => (int) $row['id_product_attribute'] > 0,
                ];

                $out[$k] = $context;
                $this->memo[$idShop . ':' . $k] = $context;
            }
        }

        return $out;
    }

    /** Wartosc kombinacji, a gdy pusta — produktu. */
    public static function pick(mixed $combination, mixed $product): string
    {
        $c = trim((string) $combination);

        return $c !== '' ? $c : trim((string) $product);
    }
}
