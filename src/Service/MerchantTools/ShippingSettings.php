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

use Quince\Insight\Core\Options;

/**
 * Wysylka wyslana do Merchant Center — odczyt dla danych strukturalnych (agent H, raport H §4).
 *
 * Bez wywolan Google i bez zapytan o przewoznikow: migawka zapisana przy ostatniej udanej wysylce
 * (`ShippingService::send`). Dopoki user nic nie wyslal — null. Zasad zwrotow modul nie wysyla
 * (Merchant API `onlineReturnPolicies` poza paczka M), wiec `returns` = null.
 */
final class ShippingSettings
{
    /**
     * @return array{currency:string,services:array<int,array{name:string,countries:string[],rate:float,freeOver:float|null,handling:int[],transit:int[]}>,returns:null}|null
     */
    public function forStructuredData(int $idShop): ?array
    {
        if (Options::str(MerchantToolsOptions::SHIPPING_SENT_AT, '', $idShop) === '') {
            return null;
        }

        $services = [];
        $currency = '';

        foreach (Options::json(MerchantToolsOptions::SHIPPING_SENT, [], $idShop) as $s) {
            if (!is_array($s) || empty($s['countries'])) {
                continue;
            }

            $currency = $currency ?: (string) ($s['currency'] ?? '');
            $services[] = [
                'name' => (string) ($s['name'] ?? ''),
                'countries' => array_values(array_map('strval', (array) $s['countries'])),
                'currency' => (string) ($s['currency'] ?? ''),
                'rate' => (float) ($s['rate'] ?? 0),
                'freeOver' => isset($s['freeOver']) ? (float) $s['freeOver'] : null,
                'handling' => array_map('intval', (array) ($s['handling'] ?? [0, 0])),
                'transit' => array_map('intval', (array) ($s['transit'] ?? [0, 0])),
            ];
        }

        return $services === [] ? null : ['currency' => $currency, 'services' => $services, 'returns' => null];
    }
}
