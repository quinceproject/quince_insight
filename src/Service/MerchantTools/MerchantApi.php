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

use Quince\Insight\Core\Google\GoogleClient;
use Quince\Insight\Service\Merchant\MerchantAccount;

/**
 * Wywolania Merchant API narzedzi (wysylka, promocje, raporty, zrodla promocji).
 *
 * Konto bierze z `MerchantAccount::accountId()` (agent E). Klient ma klucz uslugi `merchanttools`,
 * a nie `merchant`: osobny katalog atrap `tests/fixtures/google/merchanttools/` (bez wspolnego
 * `_routes.php` z agentem E) i osobna nazwa w dzienniku. Profil ponawiania ten sam (`merchant`).
 */
final class MerchantApi
{
    public const BASE = 'https://merchantapi.googleapis.com/';

    /** @var array<int,GoogleClient> */
    private array $clients = [];

    public function __construct(private readonly MerchantAccount $account)
    {
    }

    public function accountId(int $idShop): string
    {
        return preg_replace('/\D+/', '', $this->account->accountId($idShop)) ?? '';
    }

    /** Rzuca NotConnectedException, gdy sklep nie ma polaczenia z Google. */
    public function client(int $idShop): GoogleClient
    {
        return $this->clients[$idShop] ??= GoogleClient::forShop($idShop, 'merchanttools')->withRetryProfile(GoogleClient::PROFILE_MERCHANT);
    }

    /** Adres zasobu konta, np. `accounts/v1/accounts/{id}/shippingSettings`. */
    public function url(int $idShop, string $subapi, string $path = ''): string
    {
        return self::BASE . $subapi . '/v1/accounts/' . $this->accountId($idShop) . ($path !== '' ? '/' . ltrim($path, '/') : '');
    }
}
