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
use Quince\Insight\Core\Clock;
use Quince\Insight\Core\Options;

/**
 * Dane zakladek Overview, Traffic i Funnel ekranu Analytics.
 *
 * Jedna zakladka = jedno `batchRunReports` (do 5 raportow), zapamietane na godzine
 * (`ga4.report.<usluga>.<zakladka>.<dni>`, ARCHITEKTURA §7.3). Gdy Google nie odpowiada,
 * ekran dostaje ostatnie znane dane z `stale` i bledem do pokazania.
 */
final class Reports
{
    public const PREFIX = 'ga4.report.';
    private const TTL = 3600;

    public function __construct(
        private readonly Ga4Api $api,
        private readonly Cache $cache,
        private readonly Ga4Settings $settings,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function overview(int $idShop, int $days): array
    {
        $isKey = $this->purchaseIsKey($idShop);
        $load = $this->load($idShop, 'overview', $days, [
            ReportQueries::summary($days, $isKey),
            ReportQueries::daily($days),
            ReportQueries::channels($days, $isKey),
            ReportQueries::products($days),
        ], $isKey);

        if ($load['responses'] === null) {
            return $load;
        }

        [$summary, $daily, $channels, $products] = $load['responses'] + [[], [], [], []];
        $currency = $this->currency($load['responses']);
        $productRows = [];

        foreach (ReportMath::rows($products) as $row) {
            if ((float) ($row['metrics']['itemRevenue'] ?? 0) <= 0) {
                continue;
            }

            $productRows[] = [
                'name' => (string) ($row['dims']['itemName'] ?? ''),
                'revenue' => ReportMath::money((float) $row['metrics']['itemRevenue'], $currency),
            ];
        }

        return $load + [
            'empty' => !ReportMath::hasRows([$summary, $daily]),
            'metrics' => ReportMath::summary($summary, $load['isKey'], $currency),
            'daily' => ReportMath::daily($daily, $days, date('Y-m-d', Clock::time() - 86400)),
            'channels' => array_slice(ReportMath::channels($channels, $load['isKey'], $currency), 0, 4),
            'products' => array_slice($productRows, 0, 4),
            'quality' => ReportMath::quality($load['responses']),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function traffic(int $idShop, int $days): array
    {
        $isKey = $this->purchaseIsKey($idShop);
        $load = $this->load($idShop, 'traffic', $days, [
            ReportQueries::channels($days, $isKey),
            ReportQueries::devices($days),
            ReportQueries::countries($days),
        ], $isKey);

        if ($load['responses'] === null) {
            return $load;
        }

        [$channels, $devices, $countries] = $load['responses'] + [[], [], []];
        $currency = $this->currency($load['responses']);

        return $load + [
            'empty' => !ReportMath::hasRows([$channels]),
            'channels' => ReportMath::channels($channels, $load['isKey'], $currency),
            'devices' => ReportMath::shares($devices, 'deviceCategory'),
            'countries' => ReportMath::shares($countries, 'country', 3),
            'quality' => ReportMath::quality($load['responses']),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function funnel(int $idShop, int $days): array
    {
        $load = $this->load($idShop, 'funnel', $days, [ReportQueries::funnel($days)], false);

        if ($load['responses'] === null) {
            return $load;
        }

        // Decyzja #4: obie miary z jednego zapytania; przelacznik w przegladarce, domyslnie Events.
        $steps = ReportMath::funnel($load['responses'][0] ?? [], 'eventCount');
        $users = ReportMath::funnel($load['responses'][0] ?? [], 'totalUsers');

        return $load + [
            'empty' => ($steps[0]['n'] ?? 0) <= 0 && ($users[0]['n'] ?? 0) <= 0,
            'steps' => $steps,
            'biggest' => ReportMath::biggestDrop($steps),
            'stepsUsers' => $users,
            'biggestUsers' => ReportMath::biggestDrop($users),
            'quality' => ReportMath::quality($load['responses']),
        ];
    }

    /** "Refresh": usuwa raporty i stan uslugi z pamieci (nastepne otwarcie pyta Google). */
    public function refresh(int $idShop): void
    {
        $this->cache->forget($idShop, 'ga4.');
    }

    public function purchaseIsKey(int $idShop): bool
    {
        try {
            return in_array('purchase', $this->settings->keyEvents($idShop), true);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @param array<int,array<string,mixed>> $requests
     *
     * @return array{responses:?array,isKey:bool,updatedAt:?string,stale:bool,error:?\Throwable}
     */
    private function load(int $idShop, string $tab, int $days, array $requests, bool $isKey): array
    {
        $property = Options::str(AnalyticsOptions::PROPERTY, '', $idShop);
        // Klucz zalezy tez od tego, czy purchase jest kluczowe — inne zapytanie, inna odpowiedz.
        $key = self::PREFIX . Ga4Settings::id($property) . '.' . $tab . '.' . $days . ($isKey ? '.k' : '');

        try {
            $data = $this->cache->remember($idShop, $key, self::TTL, fn (): array => [
                'responses' => $this->api->batchRunReports($idShop, $property, $requests),
            ]);

            return ['responses' => (array) $data['responses'], 'isKey' => $isKey, 'updatedAt' => $this->cache->updatedAt($idShop, $key), 'stale' => false, 'error' => null];
        } catch (\Throwable $e) {
            $stale = $this->cache->stale($idShop, $key);

            return [
                'responses' => $stale !== null ? (array) ($stale['responses'] ?? []) : null,
                'isKey' => $isKey,
                'updatedAt' => $stale !== null ? $this->cache->updatedAt($idShop, $key) : null,
                'stale' => $stale !== null,
                'error' => $e,
            ];
        }
    }

    /** @param array<int,array<string,mixed>> $responses */
    private function currency(array $responses): string
    {
        foreach ($responses as $response) {
            $code = (string) ($response['metadata']['currencyCode'] ?? '');

            if ($code !== '') {
                return $code;
            }
        }

        return '';
    }
}
