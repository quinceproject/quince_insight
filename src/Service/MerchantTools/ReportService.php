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

use Db;
use Quince\Insight\Core\Cache;
use Quince\Insight\Core\Clock;
use Quince\Insight\Core\Google\GoogleApiException;
use Quince\Insight\Core\Schema as CoreSchema;

/**
 * Raporty Merchant Center (zakladka Reports): `reports:search` w Merchant API v1 (research/04 §10).
 *
 * Skutecznosc z `product_performance_view` — jedno zapytanie za 2 × okres (biezacy i poprzedni do
 * porownania), w pamieci podrecznej 6 h (`gmc.report.<dni>`). Raporty rynkowe (konkurencyjnosc cen,
 * bestsellery) wymagaja spelnienia warunkow Market Insights — brak dostepu to informacja, nie blad.
 */
final class ReportService
{
    public const PERIODS = [7, 14, 30, 90];

    private const TTL = 6 * 3600;

    private const MARKET_TTL = 24 * 3600;

    public function __construct(
        private readonly MerchantApi $api,
        private readonly Cache $cache,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function performance(int $idShop, int $days, string $country, bool $refresh = false): array
    {
        $days = in_array($days, self::PERIODS, true) ? $days : 30;
        $key = 'gmc.report.' . $days;

        if ($refresh) {
            $this->cache->forget($idShop, $key);
        }

        $today = date('Y-m-d', Clock::time());
        $cached = $this->cache->remember($idShop, $key, self::TTL, function () use ($idShop, $days, $today): array {
            $from = date('Y-m-d', strtotime($today . ' -' . (2 * $days) . ' days'));
            $to = date('Y-m-d', strtotime($today . ' -1 day'));
            $query = 'SELECT date, offer_id, customer_country_code, impressions, clicks FROM product_performance_view'
                . " WHERE date BETWEEN '" . $from . "' AND '" . $to . "'";

            return ['rows' => ReportMath::rows($this->search($idShop, $query)), 'today' => $today];
        });

        $report = ReportMath::summarize((array) $cached['rows'], $days, (string) ($cached['today'] ?? $today), preg_match('/^[A-Za-z]{2}$/', $country) ? strtoupper($country) : 'all');
        $names = $this->titles($idShop, array_slice(array_column($report['byProduct'], 'offer'), 0, 500));

        foreach ($report['byProduct'] as &$row) {
            $row['name'] = $names[$row['offer']] ?? $row['offer'];
        }
        unset($row);

        $report['updatedAt'] = $this->cache->updatedAt($idShop, $key);

        return $report;
    }

    /**
     * Konkurencyjnosc cen: cena oferty a cena wzorcowa u innych sprzedawcow.
     *
     * @return array{available:bool,rows:array<int,array<string,mixed>>}
     */
    public function priceCompetitiveness(int $idShop, string $country): array
    {
        $country = strtoupper($country);

        return $this->market($idShop, 'gmc.report.price.' . $country, function () use ($idShop, $country): array {
            $where = preg_match('/^[A-Z]{2}$/', $country) ? " WHERE report_country_code = '" . $country . "'" : '';
            $rows = [];

            foreach ($this->search($idShop, 'SELECT offer_id, title, price, benchmark_price, report_country_code FROM price_competitiveness_product_view' . $where, 200) as $r) {
                $v = (array) ($r['priceCompetitivenessProductView'] ?? []);
                $price = self::money($v['price'] ?? null);
                $bench = self::money($v['benchmarkPrice'] ?? null);

                if ($price === null || $bench === null) {
                    continue;
                }

                $rows[] = [
                    'offer' => (string) ($v['offerId'] ?? ''),
                    'name' => (string) ($v['title'] ?? ''),
                    'price' => $price,
                    'benchmark' => $bench,
                    'diff' => $bench[0] > 0 ? (int) round(($price[0] - $bench[0]) / $bench[0] * 100) : null,
                ];
            }

            usort($rows, static fn (array $a, array $b): int => ($b['diff'] ?? 0) <=> ($a['diff'] ?? 0));

            return array_slice($rows, 0, 50);
        });
    }

    /**
     * Bestsellery w kraju feedu (najnowszy raport tygodniowy) z informacja, czy produkt jest w sklepie.
     *
     * @return array{available:bool,rows:array<int,array<string,mixed>>}
     */
    public function bestSellers(int $idShop, string $country): array
    {
        $country = strtoupper($country);

        if (!preg_match('/^[A-Z]{2}$/', $country)) {
            return ['available' => false, 'rows' => []];
        }

        return $this->market($idShop, 'gmc.report.best.' . $country, function () use ($idShop, $country): array {
            $rows = [];
            $query = 'SELECT report_date, report_granularity, report_country_code, report_category_id, rank, previous_rank, title, brand, inventory_status'
                . " FROM best_sellers_product_cluster_view WHERE report_country_code = '" . $country . "' AND report_granularity = 'WEEKLY'"
                . ' ORDER BY rank LIMIT 20';

            foreach ($this->search($idShop, $query, 20) as $r) {
                $v = (array) ($r['bestSellersProductClusterView'] ?? []);
                $rows[] = [
                    'rank' => (int) ($v['rank'] ?? 0),
                    'previous' => isset($v['previousRank']) ? (int) $v['previousRank'] : null,
                    'name' => (string) ($v['title'] ?? ''),
                    'brand' => (string) ($v['brand'] ?? ''),
                    'inventory' => (string) ($v['inventoryStatus'] ?? ''),
                ];
            }

            return $rows;
        });
    }

    /**
     * @param callable():array<int,array<string,mixed>> $loader
     *
     * @return array{available:bool,rows:array<int,array<string,mixed>>}
     */
    private function market(int $idShop, string $key, callable $loader): array
    {
        $cached = $this->cache->get($idShop, $key);

        if ($cached !== null) {
            return ['available' => (bool) $cached['available'], 'rows' => (array) $cached['rows']];
        }

        try {
            $value = ['available' => true, 'rows' => $loader()];
        } catch (GoogleApiException $e) {
            // 403/400 = konto nie spelnia warunkow Market Insights albo nie przyjelo ich warunkow.
            if (!in_array($e->httpCode(), [400, 403, 404], true)) {
                throw $e;
            }

            $value = ['available' => false, 'rows' => []];
        }

        $this->cache->set($idShop, $key, $value, self::MARKET_TTL);

        return $value;
    }

    /**
     * `reports:search` ze stronicowaniem (`nextPageToken`).
     *
     * @return array<int,array<string,mixed>>
     */
    private function search(int $idShop, string $query, int $limit = 100000): array
    {
        $client = $this->api->client($idShop);
        $url = $this->api->url($idShop, 'reports', 'reports:search');
        $out = [];
        $token = '';

        for ($page = 0; $page < 20; ++$page) {
            $body = ['query' => $query, 'pageSize' => min(10000, $limit)];

            if ($token !== '') {
                $body['pageToken'] = $token;
            }

            $response = $client->post($url, $body);

            foreach ((array) ($response['results'] ?? []) as $r) {
                $out[] = (array) $r;
            }

            $token = (string) ($response['nextPageToken'] ?? '');

            if ($token === '' || count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * Nazwy produktow dla ofert — z feedow sklepu (agent E), gdy produkt tam jest.
     *
     * @param string[] $offers
     *
     * @return array<string,string>
     */
    private function titles(int $idShop, array $offers): array
    {
        if ($offers === []) {
            return [];
        }

        $in = implode(',', array_map(static fn (string $o): string => '"' . pSQL($o) . '"', $offers));
        $out = [];

        foreach (Db::getInstance()->executeS(
            'SELECT `offer_id`, `title` FROM `' . CoreSchema::table(\Quince\Insight\Service\Merchant\Schema::FEED_PRODUCT) . '`
             WHERE `id_shop` = ' . $idShop . ' AND `offer_id` IN (' . $in . ') AND `title` != ""'
        ) ?: [] as $r) {
            $out[(string) $r['offer_id']] ??= (string) $r['title'];
        }

        return $out;
    }

    /** @return array{0:float,1:string}|null */
    private static function money(mixed $price): ?array
    {
        if (!is_array($price) || !isset($price['amountMicros'])) {
            return null;
        }

        return [round((float) $price['amountMicros'] / 1000000, 2), (string) ($price['currencyCode'] ?? '')];
    }
}
