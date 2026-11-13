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

use Quince\Insight\Core\Cache;

/**
 * Konta, uslugi i strumienie GA4 dostepne dla zalogowanego konta — do kreatora.
 *
 * `accountSummaries` daje konta i uslugi jednym wywolaniem; strefe czasowa, walute i strumienie
 * pobieramy tylko dla wybranego konta (najwyzej 20 uslug), bo to po jednym wywolaniu na usluge.
 */
final class AccountBrowser
{
    private const TTL = 600;
    private const MAX_PROPERTIES = 20;

    public function __construct(
        private readonly Ga4Api $api,
        private readonly Cache $cache,
    ) {
    }

    /**
     * @return array<int,array{name:string,displayName:string,properties:int}>
     */
    public function accounts(int $idShop, bool $fresh = false): array
    {
        if ($fresh) {
            $this->cache->forget($idShop, 'ga4.browse.');
        }

        return $this->cache->remember($idShop, 'ga4.browse.accounts', self::TTL, function () use ($idShop): array {
            $out = [];

            foreach ($this->api->accountSummaries($idShop) as $summary) {
                $out[] = [
                    'name' => (string) ($summary['account'] ?? ''),
                    'displayName' => (string) ($summary['displayName'] ?? ''),
                    'properties' => count((array) ($summary['propertySummaries'] ?? [])),
                    'editable' => array_values(array_map(
                        static fn ($p): string => (string) ($p['property'] ?? ''),
                        array_filter((array) ($summary['propertySummaries'] ?? []), static fn ($p): bool => !isset($p['canEdit']) || (bool) $p['canEdit'])
                    )),
                ];
            }

            return $out;
        });
    }

    /**
     * Uslugi konta ze strumieniami WWW; `matches` = strumien o hoscie sklepu.
     *
     * @return array<int,array{name:string,displayName:string,timeZone:string,currencyCode:string,canEdit:bool,matches:bool,streams:array<int,array{name:string,displayName:string,defaultUri:string,measurementId:string,matches:bool}>}>
     */
    public function properties(int $idShop, string $account, string $shopOrigin): array
    {
        Ga4Api::resource($account, 'accounts');

        $editable = null;

        foreach ($this->accounts($idShop) as $row) {
            if ($row['name'] === $account) {
                $editable = $row['editable'] ?? null;
            }
        }

        $key = 'ga4.browse.props.' . preg_replace('#\D#', '', $account);
        $list = $this->cache->remember($idShop, $key, self::TTL, function () use ($idShop, $account): array {
            $out = [];

            foreach (array_slice($this->api->properties($idShop, $account), 0, self::MAX_PROPERTIES) as $property) {
                $name = (string) ($property['name'] ?? '');

                if ($name === '' || ($property['propertyType'] ?? 'PROPERTY_TYPE_ORDINARY') !== 'PROPERTY_TYPE_ORDINARY') {
                    continue;
                }

                $streams = [];

                foreach ($this->api->dataStreams($idShop, $name) as $stream) {
                    if (($stream['type'] ?? '') !== 'WEB_DATA_STREAM') {
                        continue;
                    }

                    $streams[] = [
                        'name' => (string) ($stream['name'] ?? ''),
                        'displayName' => (string) ($stream['displayName'] ?? ''),
                        'defaultUri' => (string) ($stream['webStreamData']['defaultUri'] ?? ''),
                        'measurementId' => (string) ($stream['webStreamData']['measurementId'] ?? ''),
                    ];
                }

                $out[] = [
                    'name' => $name,
                    'displayName' => (string) ($property['displayName'] ?? ''),
                    'timeZone' => (string) ($property['timeZone'] ?? ''),
                    'currencyCode' => (string) ($property['currencyCode'] ?? ''),
                    'streams' => $streams,
                ];
            }

            return $out;
        });

        $host = PropertySetup::host($shopOrigin);

        foreach ($list as &$property) {
            $property['canEdit'] = $editable === null || in_array($property['name'], $editable, true);
            $property['matches'] = false;

            foreach ($property['streams'] as &$stream) {
                $stream['matches'] = $host !== '' && PropertySetup::host($stream['defaultUri']) === $host;
                $property['matches'] = $property['matches'] || $stream['matches'];
            }
            unset($stream);
        }
        unset($property);

        // Pasujace do sklepu na gorze — tak jak w prototypie ("Matches your shop").
        usort($list, static fn (array $a, array $b): int => (int) $b['matches'] <=> (int) $a['matches']);

        return $list;
    }
}
