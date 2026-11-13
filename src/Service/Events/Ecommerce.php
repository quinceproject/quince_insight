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

/**
 * Budowa danych e-commerce GA4 (produkty, zakup, zwrot) z tablic zebranych ze sklepu.
 * Czysta logika — ta sama dla dataLayer (przegladarka) i Measurement Protocol (serwer),
 * zeby jeden produkt wygladal tak samo w GA4, Google Ads i Merchant Center.
 *
 * Schemat: research/05 §G (`items[]`, `value` = suma cena × ilosc bez wysylki,
 * `currency` zawsze z `value`). Poprawki wzgledem TagPilota (research/02 §3.4):
 *  - `transaction_id` = referencja zamowienia zawsze (nie zalezy od ustawienia ID produktu);
 *  - `item_category` = najwyzszy poziom, `item_category2..5` w dol drzewa (TagPilot mial odwrotnie);
 *  - zwrot czesciowy zawiera tylko zwrocone pozycje z ich iloscia i kwota.
 *
 * Linia produktu (`$line`): id_product, id_product_attribute, reference, ean13, name, variant,
 * brand, price_incl, price_excl, quantity, categories (nazwy od gory do liscia), id_order_detail.
 */
final class Ecommerce
{
    /** Identyfikator produktu wg ustawienia; przy referencji / EAN — wartosc kombinacji, gdy ja ma. */
    public static function itemId(array $line, string $idField): string
    {
        $id = (string) (int) ($line['id_product'] ?? 0);

        if ($idField === 'reference' && trim((string) ($line['reference'] ?? '')) !== '') {
            return trim((string) $line['reference']);
        }

        if ($idField === 'ean' && trim((string) ($line['ean13'] ?? '')) !== '') {
            return trim((string) $line['ean13']);
        }

        return $id;
    }

    /**
     * Pola kategorii: najwyzszy poziom w `item_category`, nizsze w `item_category2..5`.
     * Bez pelnej sciezki — tylko kategoria produktu (lisc) w `item_category`.
     *
     * @param string[] $topDown nazwy od najwyzszej kategorii do kategorii produktu (bez Root i Home)
     *
     * @return array<string,string>
     */
    public static function categoryFields(array $topDown, bool $fullPath): array
    {
        $names = array_values(array_filter(array_map(static fn ($n): string => trim((string) $n), $topDown), static fn (string $n): bool => $n !== ''));

        if ($names === []) {
            return [];
        }

        if (!$fullPath) {
            return ['item_category' => (string) end($names)];
        }

        $out = [];

        foreach (array_slice($names, 0, 5) as $i => $name) {
            $out[$i === 0 ? 'item_category' : 'item_category' . ($i + 1)] = $name;
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $line
     *
     * @return array<string,mixed>
     */
    public static function item(array $line, string $idField, bool $withTax, bool $fullPath, ?int $index = null): array
    {
        $price = (float) ($withTax ? ($line['price_incl'] ?? 0) : ($line['price_excl'] ?? 0));
        $item = [
            'item_id' => self::itemId($line, $idField),
            'item_name' => trim((string) ($line['name'] ?? '')),
        ];

        if (trim((string) ($line['brand'] ?? '')) !== '') {
            $item['item_brand'] = trim((string) $line['brand']);
        }

        $item += self::categoryFields((array) ($line['categories'] ?? []), $fullPath);

        if (trim((string) ($line['variant'] ?? '')) !== '') {
            $item['item_variant'] = trim((string) $line['variant']);
        }

        $item['price'] = round($price, 2);
        $item['quantity'] = max(1, (int) ($line['quantity'] ?? 1));

        if ($index !== null) {
            $item['index'] = $index;
        }

        return $item;
    }

    /**
     * Wartosc listy produktow: suma cena × ilosc.
     *
     * @param array<int,array<string,mixed>> $items
     */
    public static function itemsValue(array $items): float
    {
        $sum = 0.0;

        foreach ($items as $item) {
            $sum += (float) ($item['price'] ?? 0) * (int) ($item['quantity'] ?? 1);
        }

        return round($sum, 2);
    }

    /**
     * Blok `ecommerce` zakupu (dataLayer) — te same pola ida do Measurement Protocol.
     *
     * `$order`: reference, currency, products_incl, products_excl, discounts_incl, discounts_excl,
     * shipping_incl, shipping_excl, paid_incl, paid_excl, coupon, lines[].
     *
     * @param array<string,mixed> $order
     *
     * @return array<string,mixed>
     */
    public static function purchase(array $order, string $idField, bool $withTax, bool $fullPath): array
    {
        $items = [];

        foreach (array_values((array) ($order['lines'] ?? [])) as $i => $line) {
            $items[] = self::item((array) $line, $idField, $withTax, $fullPath, $i);
        }

        $products = (float) ($withTax ? ($order['products_incl'] ?? 0) : ($order['products_excl'] ?? 0));
        $discounts = (float) ($withTax ? ($order['discounts_incl'] ?? 0) : ($order['discounts_excl'] ?? 0));
        $shipping = (float) ($withTax ? ($order['shipping_incl'] ?? 0) : ($order['shipping_excl'] ?? 0));

        $out = [
            'transaction_id' => (string) ($order['reference'] ?? ''),
            'value' => round(max(0.0, $products - $discounts), 2),
            'tax' => round(max(0.0, (float) ($order['paid_incl'] ?? 0) - (float) ($order['paid_excl'] ?? 0)), 2),
            'shipping' => round($shipping, 2),
            'currency' => (string) ($order['currency'] ?? ''),
        ];

        if (trim((string) ($order['coupon'] ?? '')) !== '') {
            $out['coupon'] = trim((string) $order['coupon']);
        }

        $out['items'] = $items;

        return $out;
    }

    /**
     * Pozycje zwrotu z dokumentu korekty: tylko zwrocone linie, z ich iloscia i cena jednostkowa
     * wynikajaca z kwoty zwrotu (moze byc nizsza niz cena przy czesciowej kwocie).
     *
     * @param array<int,array<string,mixed>> $lines linie zamowienia (klucz id_order_detail)
     * @param array<int,array{id_order_detail:int, quantity:int, amount_incl:float, amount_excl:float}> $slipRows
     *
     * @return array<int,array<string,mixed>>
     */
    public static function refundItems(array $lines, array $slipRows, string $idField, bool $withTax, bool $fullPath): array
    {
        $byDetail = [];

        foreach ($lines as $line) {
            $byDetail[(int) ($line['id_order_detail'] ?? 0)] = $line;
        }

        $items = [];

        foreach ($slipRows as $row) {
            $detail = (int) $row['id_order_detail'];
            $quantity = (int) $row['quantity'];

            if ($quantity <= 0 || !isset($byDetail[$detail])) {
                continue;
            }

            $amount = (float) ($withTax ? $row['amount_incl'] : $row['amount_excl']);
            $line = $byDetail[$detail];
            $line['quantity'] = $quantity;

            if ($amount > 0) {
                $line[$withTax ? 'price_incl' : 'price_excl'] = $amount / $quantity;
            }

            $items[] = self::item($line, $idField, $withTax, $fullPath, count($items));
        }

        return $items;
    }

    /**
     * Zwrot calosci po zmianie statusu (anulowanie, zwrot pieniedzy): to, czego jeszcze
     * nie zwrocono dokumentami korekty. Zwraca linie z pozostala iloscia.
     *
     * @param array<int,array<string,mixed>> $lines
     * @param array<int,int> $refundedQty id_order_detail => juz zwrocona ilosc
     *
     * @return array<int,array<string,mixed>>
     */
    public static function remainingLines(array $lines, array $refundedQty): array
    {
        $out = [];

        foreach ($lines as $line) {
            $left = (int) ($line['quantity'] ?? 0) - (int) ($refundedQty[(int) ($line['id_order_detail'] ?? 0)] ?? 0);

            if ($left > 0) {
                $line['quantity'] = $left;
                $out[] = $line;
            }
        }

        return $out;
    }

    /**
     * Blok zwrotu: `transaction_id` = referencja zamowienia (GA4 laczy zwrot z zakupem po nim).
     *
     * @param array<int,array<string,mixed>> $items
     *
     * @return array<string,mixed>
     */
    public static function refund(string $reference, string $currency, float $value, float $shipping, array $items): array
    {
        $out = [
            'transaction_id' => $reference,
            'value' => round(max(0.0, $value), 2),
            'currency' => $currency,
        ];

        if ($shipping > 0) {
            $out['shipping'] = round($shipping, 2);
        }

        if ($items !== []) {
            $out['items'] = $items;
        }

        return $out;
    }
}
