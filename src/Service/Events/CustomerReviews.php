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

use Carrier;
use Order;
use Quince\Insight\Core\Options;
use Quince\Insight\Core\Services;
use Quince\Insight\Service\Merchant\MerchantOptions;

/**
 * Google Customer Reviews (ARCHITEKTURA §12 pkt 15, research/04 §15), per sklep.
 *
 * Na stronie potwierdzenia tylko okienko Google z pytaniem o zgode; ankiete wysyla Google
 * e-mailem po szacowanej dacie dostawy. Okienko pojawia sie dopiero, gdy:
 * usluga `gcr` wlaczona, „Ask customers after an order” wlaczone, user potwierdzil wlaczenie
 * programu w Merchant Center, sklep ma konto Merchant Center i klient sie zgodzil
 * (sygnal `ad_user_data` — e-mail klienta trafia do Google).
 */
final class CustomerReviews
{
    public const STYLES = ['CENTER_DIALOG', 'BOTTOM_RIGHT_DIALOG', 'BOTTOM_LEFT_DIALOG', 'TOP_RIGHT_DIALOG', 'TOP_LEFT_DIALOG', 'BOTTOM_TRAY'];

    /** Dni dostawy, gdy user nic nie wpisal (wartosc z prototypu `gcr.days`). */
    public const DEFAULT_DAYS = 3;

    public function __construct(private readonly Services $services)
    {
    }

    public function survey(int $idShop): bool
    {
        return Options::bool(EventsOptions::GCR_SURVEY, true, $idShop);
    }

    public function confirmed(int $idShop): bool
    {
        return Options::bool(EventsOptions::GCR_CONFIRMED, false, $idShop);
    }

    public function defaultDays(int $idShop): int
    {
        return max(1, min(60, Options::int(EventsOptions::GCR_DELIVERY_DAYS, self::DEFAULT_DAYS, $idShop)));
    }

    /** @return array<int,int> id_reference przewoznika => dni */
    public function carrierDays(int $idShop): array
    {
        $out = [];

        foreach (Options::json(EventsOptions::GCR_CARRIER_DAYS, [], $idShop) as $ref => $days) {
            if ((int) $days >= 1) {
                $out[(int) $ref] = min(60, (int) $days);
            }
        }

        return $out;
    }

    public function style(int $idShop): string
    {
        $style = Options::str(EventsOptions::GCR_STYLE, 'CENTER_DIALOG', $idShop);

        return in_array($style, self::STYLES, true) ? $style : 'CENTER_DIALOG';
    }

    public function merchantId(int $idShop): string
    {
        $id = Options::str(MerchantOptions::ACCOUNT, '', $idShop);

        return ctype_digit($id) ? $id : '';
    }

    /** Czy okienko moze sie pojawic (bez zgody klienta — ta zalezy od odwiedzajacego). */
    public function active(int $idShop): bool
    {
        return $this->services->enabled($idShop, 'gcr') && $this->survey($idShop) && $this->confirmed($idShop) && $this->merchantId($idShop) !== '';
    }

    /**
     * Przewoznicy sklepu (wg `id_reference`, bo zmiana przewoznika tworzy nowe `id_carrier`).
     *
     * @return array<int,array{ref:int, name:string, days:?int}>
     */
    public function carriers(int $idShop, int $idLang): array
    {
        $days = $this->carrierDays($idShop);
        $out = [];

        foreach (Carrier::getCarriers($idLang, true, false, false, null, Carrier::ALL_CARRIERS) as $row) {
            $ref = (int) ($row['id_reference'] ?: $row['id_carrier']);

            if (isset($out[$ref])) {
                continue;
            }

            $out[$ref] = ['ref' => $ref, 'name' => (string) $row['name'] === '0' ? (string) \Configuration::get('PS_SHOP_NAME', null, null, $idShop) : (string) $row['name'], 'days' => $days[$ref] ?? null];
        }

        return array_values($out);
    }

    /**
     * Szacowana data dostawy: data zamowienia + dni przewoznika zamowienia (albo domyslne).
     */
    public static function estimatedDate(string $orderDate, ?int $carrierDays, int $defaultDays): string
    {
        $days = $carrierDays ?? $defaultDays;
        $ts = strtotime($orderDate) ?: time();

        return date('Y-m-d', $ts + max(1, $days) * 86400);
    }

    /**
     * Dane okienka dla zamowienia albo null.
     *
     * @return array{merchant_id:int, order_id:string, email:string, delivery_country:string, estimated_delivery_date:string, opt_in_style:string}|null
     */
    public function optIn(Order $order): ?array
    {
        $idShop = (int) $order->id_shop;
        $customer = new \Customer((int) $order->id_customer);
        $address = new \Address((int) $order->id_address_delivery);
        $country = \Validate::isLoadedObject($address) ? strtoupper((string) \Country::getIsoById((int) $address->id_country)) : '';

        if (!\Validate::isLoadedObject($customer) || trim((string) $customer->email) === '' || !preg_match('/^[A-Z]{2}$/', $country) || $country === 'ZZ') {
            return null;
        }

        $carrier = new Carrier((int) $order->id_carrier);
        $ref = \Validate::isLoadedObject($carrier) ? (int) ($carrier->id_reference ?: $carrier->id) : 0;

        return [
            'merchant_id' => (int) $this->merchantId($idShop),
            'order_id' => (string) $order->reference,
            'email' => (string) $customer->email,
            'delivery_country' => $country,
            'estimated_delivery_date' => self::estimatedDate((string) $order->date_add, $this->carrierDays($idShop)[$ref] ?? null, $this->defaultDays($idShop)),
            'opt_in_style' => $this->style($idShop),
        ];
    }

    /**
     * Zapis z kroku kreatora albo z panelu. Zwraca bledy per pole.
     *
     * @param array<string,mixed> $input survey, confirmed, days, carrier[ref] => dni
     *
     * @return array<string,string>
     */
    public function save(int $idShop, array $input): array
    {
        $errors = [];
        $bool = static fn (mixed $v): bool => $v === true || $v === 1 || $v === '1' || $v === 'true' || $v === 'on';

        if (array_key_exists('days', $input)) {
            $days = $input['days'];

            if (!is_numeric($days) || (int) $days < 1 || (int) $days > 60) {
                $errors['days'] = 'Enter a number of days from 1 to 60.';
            }
        }

        $carriers = [];

        foreach ((array) ($input['carrier'] ?? []) as $ref => $days) {
            if ($days === '' || $days === null) {
                continue;
            }

            if (!is_numeric($days) || (int) $days < 1 || (int) $days > 60) {
                $errors['carrier[' . (int) $ref . ']'] = 'Enter a number of days from 1 to 60.';
                continue;
            }

            $carriers[(int) $ref] = (int) $days;
        }

        if ($errors !== []) {
            return $errors;
        }

        if (array_key_exists('survey', $input)) {
            Options::set(EventsOptions::GCR_SURVEY, $bool($input['survey']), $idShop);
        }

        if (array_key_exists('confirmed', $input)) {
            Options::set(EventsOptions::GCR_CONFIRMED, $bool($input['confirmed']), $idShop);
        }

        if (array_key_exists('days', $input)) {
            Options::set(EventsOptions::GCR_DELIVERY_DAYS, (int) $input['days'], $idShop);
        }

        if (array_key_exists('carrier', $input)) {
            Options::set(EventsOptions::GCR_CARRIER_DAYS, $carriers, $idShop);
        }

        return [];
    }
}
