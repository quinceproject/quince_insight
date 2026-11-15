<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\Analytics;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Quince\Insight\Core\ShopContext;

/**
 * Wartosci domyslne nowej uslugi GA4 z ustawien sklepu (SPEC §3: nazwa, strefa czasowa,
 * waluta ze sklepu). Tylko odczyt konfiguracji PrestaShop dla podanego sklepu.
 */
final class ShopDefaults
{
    public function name(int $idShop): string
    {
        return ShopContext::name($idShop);
    }

    /** Adres strumienia WWW: schemat + host (+ port) — strumien GA4 dotyczy domeny, nie katalogu. */
    public function origin(int $idShop): string
    {
        $url = ShopContext::baseUrl($idShop);
        $scheme = (string) parse_url($url, PHP_URL_SCHEME);
        $host = (string) parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);

        if ($host === '') {
            return '';
        }

        return ($scheme ?: 'https') . '://' . $host . ($port ? ':' . $port : '');
    }

    public function timeZone(int $idShop): string
    {
        $tz = class_exists('Configuration') ? (string) \Configuration::get('PS_TIMEZONE', null, null, $idShop) : '';

        return in_array($tz, \DateTimeZone::listIdentifiers(), true) ? $tz : 'UTC';
    }

    /** Kod ISO waluty domyslnej sklepu. */
    public function currency(int $idShop): string
    {
        if (!class_exists('Configuration') || !class_exists('Currency')) {
            return 'USD';
        }

        $id = (int) \Configuration::get('PS_CURRENCY_DEFAULT', null, null, $idShop);
        $currency = new \Currency($id);

        return \Validate::isLoadedObject($currency) ? (string) $currency->iso_code : 'USD';
    }

    /** @return string[] kody ISO walut sklepu (domyslna pierwsza) */
    public function currencies(int $idShop): array
    {
        $codes = [$this->currency($idShop)];

        if (class_exists('Currency')) {
            foreach ((array) \Currency::getCurrenciesByIdShop($idShop) as $row) {
                $code = (string) ($row['iso_code'] ?? '');

                if ($code !== '' && (int) ($row['deleted'] ?? 0) === 0) {
                    $codes[] = $code;
                }
            }
        }

        return array_values(array_unique($codes));
    }

    /** @return string[] */
    public function timeZones(): array
    {
        return \DateTimeZone::listIdentifiers();
    }
}
