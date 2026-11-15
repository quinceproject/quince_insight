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

/**
 * Przeliczenia raportu skutecznosci (bez bazy i bez Google).
 *
 * Wiersze wejscia: [data Y-m-d, offer_id, kraj, wyswietlenia, klikniecia]. Poprawka FeedForge
 * (research/02 §1.4): `offer_id` zostaje az do tabeli "By product", wiec lista produktow nie jest pusta.
 * CTR = suma klikniec / suma wyswietlen (nie srednia z wierszy).
 */
final class ReportMath
{
    /**
     * Wiersze z odpowiedzi `reports:search` (`productPerformanceView`).
     *
     * @param array<int,array<string,mixed>> $results
     *
     * @return array<int,array{0:string,1:string,2:string,3:int,4:int}>
     */
    public static function rows(array $results): array
    {
        $out = [];

        foreach ($results as $result) {
            $v = $result['productPerformanceView'] ?? null;

            if (!is_array($v)) {
                continue;
            }

            $d = $v['date'] ?? null;
            $date = is_array($d) ? sprintf('%04d-%02d-%02d', (int) ($d['year'] ?? 0), (int) ($d['month'] ?? 0), (int) ($d['day'] ?? 0)) : (string) $d;

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;
            }

            $out[] = [$date, (string) ($v['offerId'] ?? ''), strtoupper((string) ($v['customerCountryCode'] ?? 'ZZ')), (int) ($v['impressions'] ?? 0), (int) ($v['clicks'] ?? 0)];
        }

        return $out;
    }

    /**
     * Raport okresu z porownaniem do poprzedniego.
     *
     * @param array<int,array{0:string,1:string,2:string,3:int,4:int}> $rows
     * @param string $country 'all' albo kod kraju
     *
     * @return array<string,mixed>
     */
    public static function summarize(array $rows, int $days, string $today, string $country = 'all'): array
    {
        $end = date('Y-m-d', strtotime($today . ' -1 day'));
        $start = date('Y-m-d', strtotime($today . ' -' . $days . ' days'));
        $prevEnd = date('Y-m-d', strtotime($start . ' -1 day'));
        $prevStart = date('Y-m-d', strtotime($start . ' -' . $days . ' days'));

        $cur = ['imp' => 0, 'clk' => 0, 'products' => []];
        $prev = ['imp' => 0, 'clk' => 0, 'products' => []];
        $daily = [];
        $byProduct = [];
        $byCountry = [];
        $countries = [];

        for ($t = strtotime($start), $i = 0; $i < $days; ++$i, $t = strtotime('+1 day', $t)) {
            $daily[date('Y-m-d', $t)] = 0;
        }

        foreach ($rows as [$date, $offer, $cc, $imp, $clk]) {
            $countries[$cc] = true;

            if ($country !== 'all' && $cc !== $country) {
                continue;
            }

            if ($date >= $start && $date <= $end) {
                $cur['imp'] += $imp;
                $cur['clk'] += $clk;
                $daily[$date] = ($daily[$date] ?? 0) + $clk;

                if ($clk > 0) {
                    $cur['products'][$offer] = true;
                }

                $byProduct[$offer] ??= ['offer' => $offer, 'imp' => 0, 'clk' => 0];
                $byProduct[$offer]['imp'] += $imp;
                $byProduct[$offer]['clk'] += $clk;
                $byCountry[$cc] ??= ['country' => $cc, 'imp' => 0, 'clk' => 0];
                $byCountry[$cc]['imp'] += $imp;
                $byCountry[$cc]['clk'] += $clk;
            } elseif ($date >= $prevStart && $date <= $prevEnd) {
                $prev['imp'] += $imp;
                $prev['clk'] += $clk;

                if ($clk > 0) {
                    $prev['products'][$offer] = true;
                }
            }
        }

        $sort = static fn (array $a, array $b): int => [$b['clk'], $b['imp']] <=> [$a['clk'], $a['imp']];
        usort($byProduct, $sort);
        usort($byCountry, $sort);

        $ctr = self::ctr($cur['clk'], $cur['imp']);
        $prevCtr = self::ctr($prev['clk'], $prev['imp']);
        $max = $daily ? max($daily) : 0;
        ksort($countries);

        return [
            'from' => $start,
            'to' => $end,
            'impressions' => $cur['imp'],
            'clicks' => $cur['clk'],
            'ctr' => $ctr,
            'productsWithClicks' => count($cur['products']),
            'change' => [
                'impressions' => self::change($cur['imp'], $prev['imp']),
                'clicks' => self::change($cur['clk'], $prev['clk']),
                'ctr' => $prev['imp'] > 0 && $cur['imp'] > 0 ? round(($ctr - $prevCtr) * 100, 1) : null,
                'productsWithClicks' => self::change(count($cur['products']), count($prev['products'])),
            ],
            'trend' => array_map(static fn (string $d, int $v): array => ['day' => $d, 'clicks' => $v, 'pct' => $max > 0 ? max(2, (int) round($v / $max * 100)) : 0], array_keys($daily), $daily),
            'byProduct' => array_values($byProduct),
            'byCountry' => array_values($byCountry),
            'countries' => array_values(array_diff(array_keys($countries), ['ZZ'])),
            'hasData' => $cur['imp'] > 0 || $prev['imp'] > 0,
        ];
    }

    /** Ulamek (0.046), nie procent. */
    public static function ctr(int $clicks, int $impressions): float
    {
        return $impressions > 0 ? $clicks / $impressions : 0.0;
    }

    /** Zmiana w procentach; null gdy nie ma z czym porownac. */
    public static function change(int $current, int $previous): ?int
    {
        if ($previous <= 0) {
            return null;
        }

        return (int) round(($current - $previous) / $previous * 100);
    }
}
