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

use Quince\Insight\Core\Cache;
use Quince\Insight\Core\Options;

/**
 * Stan uslugi GA4 sklepu do zakladki Property i do karty na pulpicie: usluga, strumien,
 * sekret MP (czy nadal istnieje w GA4), retencja, powiazanie z Google Ads.
 * Pamiec podreczna 10 minut (`ga4.state.property.<id>`).
 */
final class PropertyInfo
{
    public const CACHE_KEY = 'ga4.state.property.';
    private const TTL = 600;

    /** Wartosci retencji z ekranu (miesiace) <-> wartosci Admin API. */
    public const RETENTION = ['2' => 'TWO_MONTHS', '14' => 'FOURTEEN_MONTHS'];

    public function __construct(
        private readonly Ga4Api $api,
        private readonly Cache $cache,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function get(int $idShop): array
    {
        $property = Options::str(AnalyticsOptions::PROPERTY, '', $idShop);
        $stream = Options::str(AnalyticsOptions::STREAM, '', $idShop);
        $secretName = Options::str(AnalyticsOptions::MP_SECRET_NAME, '', $idShop);
        $key = self::CACHE_KEY . Ga4Settings::id($property);

        $data = $this->cache->remember($idShop, $key, self::TTL, function () use ($idShop, $property, $stream, $secretName): array {
            $details = $this->api->property($idShop, $property);
            $streamRow = null;

            foreach ($this->api->dataStreams($idShop, $property) as $row) {
                if (($row['name'] ?? '') === $stream) {
                    $streamRow = $row;
                }
            }

            $secretFound = false;

            if ($streamRow !== null && $secretName !== '') {
                foreach ($this->api->mpSecrets($idShop, $stream) as $secret) {
                    $secretFound = $secretFound || ($secret['name'] ?? '') === $secretName;
                }
            }

            $retention = $this->api->dataRetention($idShop, $property);
            $links = $this->api->googleAdsLinks($idShop, $property);

            return [
                'displayName' => (string) ($details['displayName'] ?? ''),
                'timeZone' => (string) ($details['timeZone'] ?? ''),
                'currencyCode' => (string) ($details['currencyCode'] ?? ''),
                'streamFound' => $streamRow !== null,
                'defaultUri' => (string) ($streamRow['webStreamData']['defaultUri'] ?? ''),
                'measurementId' => (string) ($streamRow['webStreamData']['measurementId'] ?? ''),
                'secretFound' => $secretFound,
                'retention' => (string) ($retention['eventDataRetention'] ?? ''),
                'adsCustomers' => array_values(array_filter(array_map(static fn ($l): string => (string) ($l['customerId'] ?? ''), $links))),
            ];
        });

        // Id klienta Ads z powiazania — tylko odczyt, dla innych uslug (ARCHITEKTURA §7.3).
        $ads = (string) ($data['adsCustomers'][0] ?? '');

        if (Options::str(AnalyticsOptions::ADS_LINK, '', $idShop) !== $ads) {
            $ads === '' ? Options::delete(AnalyticsOptions::ADS_LINK, $idShop) : Options::set(AnalyticsOptions::ADS_LINK, $ads, $idShop);
        }

        $months = array_search($data['retention'], self::RETENTION, true);
        $data['retentionMonths'] = $months === false ? '' : (string) $months;
        $data['hasSecret'] = Options::secret(AnalyticsOptions::MP_SECRET, $idShop) !== '';

        return $data;
    }

    public function setRetention(int $idShop, string $months): void
    {
        if (!isset(self::RETENTION[$months])) {
            throw new \InvalidArgumentException('Unknown retention value.');
        }

        $this->api->updateEventRetention($idShop, Options::str(AnalyticsOptions::PROPERTY, '', $idShop), self::RETENTION[$months]);
        $this->cache->forget($idShop, self::CACHE_KEY);
    }

    public function forget(int $idShop): void
    {
        $this->cache->forget($idShop, self::CACHE_KEY);
    }
}
