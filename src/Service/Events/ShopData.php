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

if (!defined('_PS_VERSION_')) {
    exit;
}

use Address;
use Category;
use Country;
use Currency;
use Customer;
use Db;
use Manufacturer;
use Order;
use Product;
use State;
use Validate;

/**
 * Dane sklepu dla zdarzen: zamowienia, linie, kategorie, klient. Tylko odczyt.
 *
 * Zwraca zwykle tablice w ksztalcie, ktory rozumie `Ecommerce` / `UserData` — logika
 * budowy zdarzen zostaje testowalna bez PrestaShop.
 */
final class ShopData
{
    /** @var array<string,string[]> */
    private array $paths = [];

    /** @var array<int,string> */
    private array $brands = [];

    /**
     * Nazwy kategorii od najwyzszej do podanej, bez Root i Home sklepu.
     *
     * @return string[]
     */
    public function categoryPath(int $idCategory, int $idLang): array
    {
        $key = $idCategory . '-' . $idLang;

        if (isset($this->paths[$key])) {
            return $this->paths[$key];
        }

        $out = [];

        if ($idCategory > 0) {
            $category = new Category($idCategory, $idLang);

            if (Validate::isLoadedObject($category) && !$category->is_root_category && (int) $category->id_parent !== 0) {
                foreach (array_reverse($category->getParentsCategories($idLang)) as $row) {
                    // Root (id_parent = 0) i Home sklepu (is_root_category) nie sa kategoriami produktu.
                    if ((int) ($row['id_parent'] ?? 0) === 0 || !empty($row['is_root_category'])) {
                        continue;
                    }

                    $out[] = (string) $row['name'];
                }
            }
        }

        return $this->paths[$key] = $out;
    }

    public function brand(int $idManufacturer): string
    {
        if ($idManufacturer <= 0) {
            return '';
        }

        return $this->brands[$idManufacturer] ??= (string) Manufacturer::getNameById($idManufacturer);
    }

    /**
     * Zamowienie w ksztalcie `Ecommerce::purchase()` (linie z `id_order_detail`).
     *
     * @return array<string,mixed>
     */
    public function order(Order $order, int $idLang): array
    {
        $lines = [];

        foreach ($order->getProducts() as $row) {
            $idProduct = (int) $row['product_id'];
            $idAttribute = (int) $row['product_attribute_id'];
            $lines[] = [
                'id_order_detail' => (int) $row['id_order_detail'],
                'id_product' => $idProduct,
                'id_product_attribute' => $idAttribute,
                'reference' => (string) ($row['product_reference'] ?? ''),
                'ean13' => (string) ($row['product_ean13'] ?? ''),
                'name' => $this->baseName($idProduct, $idLang, (string) $row['product_name']),
                'variant' => $this->variant($idProduct, $idAttribute, $idLang),
                'brand' => $this->brand((int) ($row['id_manufacturer'] ?? 0)),
                'categories' => $this->categoryPath((int) ($row['id_category_default'] ?? 0), $idLang),
                'price_incl' => (float) $row['unit_price_tax_incl'],
                'price_excl' => (float) $row['unit_price_tax_excl'],
                'quantity' => (int) $row['product_quantity'],
            ];
        }

        $coupons = [];

        foreach ($order->getCartRules() as $rule) {
            $coupons[] = (string) $rule['name'];
        }

        return [
            'reference' => (string) $order->reference,
            'currency' => $this->currencyIso((int) $order->id_currency),
            'products_incl' => (float) $order->total_products_wt,
            'products_excl' => (float) $order->total_products,
            'discounts_incl' => (float) $order->total_discounts_tax_incl,
            'discounts_excl' => (float) $order->total_discounts_tax_excl,
            'shipping_incl' => (float) $order->total_shipping_tax_incl,
            'shipping_excl' => (float) $order->total_shipping_tax_excl,
            'paid_incl' => (float) $order->total_paid_tax_incl,
            'paid_excl' => (float) $order->total_paid_tax_excl,
            'coupon' => implode(', ', array_filter($coupons)),
            'lines' => $lines,
        ];
    }

    public function currencyIso(int $idCurrency): string
    {
        $currency = new Currency($idCurrency);

        return Validate::isLoadedObject($currency) ? (string) $currency->iso_code : '';
    }

    /**
     * Dane klienta zamowienia do haszowania (adres faktury, potem dostawy).
     *
     * @return array<string,string>
     */
    public function orderCustomer(Order $order): array
    {
        $customer = new Customer((int) $order->id_customer);

        if (!Validate::isLoadedObject($customer) || trim((string) $customer->email) === '') {
            return [];
        }

        $address = new Address((int) ($order->id_address_invoice ?: $order->id_address_delivery));

        return $this->customerRaw($customer, Validate::isLoadedObject($address) ? $address : null);
    }

    /**
     * @return array<string,string>
     */
    public function customerRaw(Customer $customer, ?Address $address): array
    {
        $raw = ['email' => (string) $customer->email];

        if ($address !== null) {
            $raw += [
                'phone' => (string) ($address->phone_mobile ?: $address->phone),
                'first_name' => (string) $address->firstname,
                'last_name' => (string) $address->lastname,
                'street' => trim($address->address1 . ' ' . $address->address2),
                'city' => (string) $address->city,
                'region' => $address->id_state ? (string) State::getNameById((int) $address->id_state) : '',
                'postal_code' => (string) $address->postcode,
                'country' => (string) Country::getIsoById((int) $address->id_country),
            ];
        } else {
            $raw += ['first_name' => (string) $customer->firstname, 'last_name' => (string) $customer->lastname];
        }

        return $raw;
    }

    /** Pierwszy adres zalogowanego klienta (dla „always”). */
    public function customerAddress(Customer $customer, int $idLang): ?Address
    {
        foreach ($customer->getAddresses($idLang) as $row) {
            $address = new Address((int) $row['id_address']);

            if (Validate::isLoadedObject($address)) {
                return $address;
            }
        }

        return null;
    }

    /**
     * Zrodlo ruchu zamowienia z wejsc goscia (`ps_connections_source`, zapis modulu statsdata).
     *
     * @return array<string,string>
     */
    public function orderSource(Order $order, string $shopHost): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT cs.`http_referer`, cs.`request_uri`
             FROM `' . _DB_PREFIX_ . 'cart` c
             JOIN `' . _DB_PREFIX_ . 'connections` conn ON conn.`id_guest` = c.`id_guest`
             JOIN `' . _DB_PREFIX_ . 'connections_source` cs ON cs.`id_connections` = conn.`id_connections`
             WHERE c.`id_cart` = ' . (int) $order->id_cart . ' AND c.`id_guest` > 0
             ORDER BY cs.`date_add` ASC
             LIMIT 50',
            true,
            false
        );

        return Attribution::best(is_array($rows) ? $rows : [], $shopHost);
    }

    /**
     * Pozycje dokumentu korekty.
     *
     * @return array<int,array{id_order_detail:int, quantity:int, amount_incl:float, amount_excl:float}>
     */
    public function slipRows(int $idOrderSlip): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT `id_order_detail`, `product_quantity`, `amount_tax_incl`, `amount_tax_excl`
             FROM `' . _DB_PREFIX_ . 'order_slip_detail` WHERE `id_order_slip` = ' . $idOrderSlip,
            true,
            false
        );
        $out = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[] = [
                'id_order_detail' => (int) $row['id_order_detail'],
                'quantity' => (int) $row['product_quantity'],
                'amount_incl' => (float) $row['amount_tax_incl'],
                'amount_excl' => (float) $row['amount_tax_excl'],
            ];
        }

        return $out;
    }

    /**
     * Dokumenty korekty zamowienia (najnowszy ostatni).
     *
     * @return array<int,array<string,mixed>>
     */
    public function slips(int $idOrder): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'order_slip` WHERE `id_order` = ' . $idOrder . ' ORDER BY `id_order_slip` ASC',
            true,
            false
        );

        return is_array($rows) ? $rows : [];
    }

    private function baseName(int $idProduct, int $idLang, string $fallback): string
    {
        $name = (string) Product::getProductName($idProduct, null, $idLang);

        return $name !== '' ? $name : $fallback;
    }

    private function variant(int $idProduct, int $idAttribute, int $idLang): string
    {
        if ($idAttribute <= 0) {
            return '';
        }

        $names = [];

        foreach (Product::getAttributesParams($idProduct, $idAttribute) as $row) {
            $names[] = (string) $row['name'];
        }

        return implode(', ', $names);
    }
}
