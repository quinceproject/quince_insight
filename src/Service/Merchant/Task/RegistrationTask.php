<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\Merchant\Task;

use Db;
use Quince\Insight\Core\Clock;
use Quince\Insight\Core\Google\GoogleAuthException;
use Quince\Insight\Core\Google\NotConnectedException;
use Quince\Insight\Core\Options;
use Quince\Insight\Core\Schema as CoreSchema;
use Quince\Insight\Service\Merchant\MerchantAccount;
use Quince\Insight\Service\Merchant\MerchantOptions;
use Quince\Insight\Service\Merchant\Schema;

/**
 * Zadanie `merchant.registration` (grupa `maintenance`, raz na dobe): kontrola konta
 * rejestracji projektu (ARCHITEKTURA §6) i retencja dziennika feedow (30 dni).
 */
final class RegistrationTask
{
    private const LOG_DAYS = 30;

    public function __construct(private readonly MerchantAccount $account)
    {
    }

    public function run(int $idShop): string
    {
        $cut = Clock::at(-self::LOG_DAYS * 86400);
        Db::getInstance()->delete(CoreSchema::name(Schema::FEED_LOG), '`id_shop` = ' . $idShop . ' AND `date_add` < "' . pSQL($cut) . '"');

        // Bez rejestracji w tym polaczeniu nie ma czego pilnowac (krok kreatora jeszcze nie przeszedl).
        if (Options::str(MerchantOptions::REG_ACCOUNT, '', $idShop) === '' && Options::str(MerchantOptions::REG_STATE, '', $idShop) === '') {
            return 'Developer registration not done yet.';
        }

        try {
            $state = $this->account->checkRegistration($idShop);
        } catch (NotConnectedException $e) {
            return 'Not connected to Google.';
        } catch (GoogleAuthException $e) {
            return 'Google sign-in needs attention.';
        }

        return 'Registration account check: ' . $state . '.';
    }
}
