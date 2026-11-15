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

/**
 * Dane klienta do rozszerzonych konwersji: normalizacja i SHA-256. Czysta logika.
 *
 * ARCHITEKTURA §11: do Google tylko hasze, tylko przy zgodzie `ad_user_data`.
 * Reguly normalizacji: research/05 §G.3 i strona Google „Send user-provided data”
 * (https://developers.google.com/analytics/devguides/collection/ga4/uid-data, sprawdzone 2026-11-11):
 * e-mail malymi literami bez spacji, bez kropek przed @ w gmail.com / googlemail.com;
 * telefon E.164; imie i nazwisko bez cyfr i symboli, malymi literami; ulica bez symboli.
 * Miasto, region, kod pocztowy i kraj ida jawnie (tak wymaga Google).
 *
 * Wejscie to tablica z PrestaShop zebrana przez `OrderData` (bez obiektow):
 * `email`, `phone`, `first_name`, `last_name`, `street`, `city`, `region`, `postal_code`, `country` (ISO-2).
 */
final class UserData
{
    /** Numery kierunkowe krajow — przeniesione z TagPilota (bez domyslnego „48”). */
    private const CALLING_CODES = [
        'PL' => '48', 'DE' => '49', 'CZ' => '420', 'SK' => '421', 'AT' => '43',
        'GB' => '44', 'UK' => '44', 'FR' => '33', 'IT' => '39', 'ES' => '34',
        'NL' => '31', 'BE' => '32', 'DK' => '45', 'SE' => '46', 'NO' => '47',
        'FI' => '358', 'IE' => '353', 'PT' => '351', 'CH' => '41', 'HU' => '36',
        'RO' => '40', 'BG' => '359', 'GR' => '30', 'LT' => '370', 'LV' => '371',
        'EE' => '372', 'SI' => '386', 'HR' => '385', 'LU' => '352', 'MT' => '356',
        'CY' => '357', 'US' => '1', 'CA' => '1', 'UA' => '380',
    ];

    public static function normalizeEmail(string $email): string
    {
        $email = strtolower((string) preg_replace('/\s+/', '', $email));

        if (!str_contains($email, '@')) {
            return '';
        }

        [$local, $domain] = explode('@', $email, 2);

        if ($domain === 'gmail.com' || $domain === 'googlemail.com') {
            $local = str_replace('.', '', $local);
        }

        return $local !== '' && $domain !== '' ? $local . '@' . $domain : '';
    }

    /**
     * Telefon w E.164 (+48600100200). Numer bez kierunkowego dostaje kierunkowy kraju adresu;
     * gdy kraju nie znamy — '' (lepiej nie wyslac nic niz zly numer).
     */
    public static function normalizePhone(string $phone, string $countryIso = ''): string
    {
        $phone = trim($phone);

        if ($phone === '') {
            return '';
        }

        $plus = str_starts_with($phone, '+');
        $digits = (string) preg_replace('/\D+/', '', $phone);

        if ($digits === '') {
            return '';
        }

        if ($plus) {
            $out = '+' . $digits;
        } elseif (str_starts_with($digits, '00')) {
            $out = '+' . substr($digits, 2);
        } else {
            $code = self::CALLING_CODES[strtoupper($countryIso)] ?? '';

            if ($code === '') {
                return '';
            }

            $digits = ltrim($digits, '0');

            // Numer juz z kierunkowym, tylko bez plusa (np. 48600100200).
            if (str_starts_with($digits, $code) && strlen($digits) >= 11) {
                $out = '+' . $digits;
            } else {
                $out = '+' . $code . $digits;
            }
        }

        $length = strlen($out) - 1;

        // E.164: 11–15 cyfr razem z kierunkowym wg Google; krotsze numery (np. 10 cyfr z US) tez sa poprawne.
        return $length >= 8 && $length <= 15 ? $out : '';
    }

    public static function normalizeName(string $name): string
    {
        $name = (string) preg_replace('/[\d\p{P}\p{S}]+/u', '', $name);

        return trim(mb_strtolower((string) preg_replace('/\s+/u', ' ', $name)));
    }

    public static function normalizeStreet(string $street): string
    {
        $street = (string) preg_replace('/[\p{P}\p{S}]+/u', '', $street);

        return trim(mb_strtolower((string) preg_replace('/\s+/u', ' ', $street)));
    }

    public static function normalizeCity(string $city): string
    {
        return self::normalizeName($city);
    }

    public static function normalizePostal(string $postal): string
    {
        return trim(str_replace(['.', '~'], '', $postal));
    }

    public static function sha256(string $value): string
    {
        return hash('sha256', $value);
    }

    /**
     * Blok `user_data` Measurement Protocol (nazwy pol ze strony Google — patrz naglowek).
     *
     * @param array<string,string> $raw
     *
     * @return array<string,mixed> [] gdy nie ma czego wyslac
     */
    public static function forMeasurementProtocol(array $raw): array
    {
        $out = [];
        $email = self::normalizeEmail((string) ($raw['email'] ?? ''));
        $country = strtoupper(trim((string) ($raw['country'] ?? '')));
        $phone = self::normalizePhone((string) ($raw['phone'] ?? ''), $country);

        if ($email !== '') {
            $out['sha256_email_address'] = [self::sha256($email)];
        }

        if ($phone !== '') {
            $out['sha256_phone_number'] = [self::sha256($phone)];
        }

        $address = array_filter([
            'sha256_first_name' => self::hashIf(self::normalizeName((string) ($raw['first_name'] ?? ''))),
            'sha256_last_name' => self::hashIf(self::normalizeName((string) ($raw['last_name'] ?? ''))),
            'sha256_street' => self::hashIf(self::normalizeStreet((string) ($raw['street'] ?? ''))),
            'city' => self::normalizeCity((string) ($raw['city'] ?? '')),
            'region' => self::normalizeCity((string) ($raw['region'] ?? '')),
            'postal_code' => self::normalizePostal((string) ($raw['postal_code'] ?? '')),
            'country' => preg_match('/^[A-Z]{2}$/', $country) ? $country : '',
        ], static fn (string $v): bool => $v !== '');

        if ($address !== []) {
            $out['address'] = [$address];
        }

        return $out;
    }

    /**
     * Blok `user_data` w dataLayer (dla GTM / rozszerzonych konwersji Google Ads):
     * zahaszowane klucze `sha256_*` (research/05 §G.3 — istnieja dla e-maila, telefonu,
     * imienia i nazwiska), adres bez ulicy i miasta — tylko kod pocztowy i kraj.
     *
     * @param array<string,string> $raw
     *
     * @return array<string,mixed>
     */
    public static function forDataLayer(array $raw): array
    {
        $email = self::normalizeEmail((string) ($raw['email'] ?? ''));

        // Minimum Google: e-mail. Bez niego nie wysylamy nic (adres bez e-maila to slabe dopasowanie).
        if ($email === '') {
            return [];
        }

        $country = strtoupper(trim((string) ($raw['country'] ?? '')));
        $out = ['sha256_email_address' => self::sha256($email)];
        $phone = self::normalizePhone((string) ($raw['phone'] ?? ''), $country);

        if ($phone !== '') {
            $out['sha256_phone_number'] = self::sha256($phone);
        }

        $address = array_filter([
            'sha256_first_name' => self::hashIf(self::normalizeName((string) ($raw['first_name'] ?? ''))),
            'sha256_last_name' => self::hashIf(self::normalizeName((string) ($raw['last_name'] ?? ''))),
            'postal_code' => self::normalizePostal((string) ($raw['postal_code'] ?? '')),
            'country' => preg_match('/^[A-Z]{2}$/', $country) ? $country : '',
        ], static fn (string $v): bool => $v !== '');

        if ($address !== []) {
            $out['address'] = $address;
        }

        return $out;
    }

    private static function hashIf(string $value): string
    {
        return $value === '' ? '' : self::sha256($value);
    }
}
