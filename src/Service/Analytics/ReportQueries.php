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

/**
 * Tresci zadan GA4 Data API v1beta dla ekranu Analytics (research/05 §C.2). Czysta logika.
 *
 * Okres konczy sie "yesterday": dane dnia biezacego sa niepelne, a wymiary zrodla ruchu
 * dochodza dopiero z danymi dziennymi (research/05 §C.4).
 * Konwersja zakupowa = `sessionKeyEventRate:purchase` (ARCHITEKTURA §12 pkt 11) — tylko gdy
 * `purchase` jest zdarzeniem kluczowym; inaczej Google odrzuca cale zadanie i konwersje
 * liczymy z `ecommercePurchases / sessions`.
 */
final class ReportQueries
{
    public const PERIODS = [7, 28, 90];

    /** Kroki lejka w kolejnosci (SPEC §5.2). */
    public const FUNNEL_EVENTS = ['view_item', 'add_to_cart', 'begin_checkout', 'add_shipping_info', 'add_payment_info', 'purchase'];

    public const PURCHASE_RATE = 'sessionKeyEventRate:purchase';

    public static function period(mixed $value): int
    {
        $days = (int) $value;

        return in_array($days, self::PERIODS, true) ? $days : 28;
    }

    /** @return array{startDate:string,endDate:string,name:string} */
    public static function current(int $days): array
    {
        return ['startDate' => $days . 'daysAgo', 'endDate' => 'yesterday', 'name' => 'current'];
    }

    /** Poprzedni okres tej samej dlugosci, tuz przed biezacym. @return array{startDate:string,endDate:string,name:string} */
    public static function previous(int $days): array
    {
        return ['startDate' => (2 * $days) . 'daysAgo', 'endDate' => ($days + 1) . 'daysAgo', 'name' => 'previous'];
    }

    /** @return array<string,mixed> */
    public static function summary(int $days, bool $purchaseIsKey): array
    {
        $metrics = ['totalUsers', 'sessions', 'purchaseRevenue', 'ecommercePurchases', 'averagePurchaseRevenue'];

        if ($purchaseIsKey) {
            $metrics[] = self::PURCHASE_RATE;
        }

        return [
            'dateRanges' => [self::current($days), self::previous($days)],
            'metrics' => self::names($metrics),
        ];
    }

    /** @return array<string,mixed> */
    public static function daily(int $days): array
    {
        return [
            'dateRanges' => [self::current($days)],
            'dimensions' => [['name' => 'date']],
            'metrics' => self::names(['sessions', 'totalUsers', 'purchaseRevenue', 'ecommercePurchases']),
            'orderBys' => [['dimension' => ['dimensionName' => 'date']]],
            'keepEmptyRows' => true,
            'limit' => 400,
        ];
    }

    /** @return array<string,mixed> */
    public static function channels(int $days, bool $purchaseIsKey): array
    {
        $metrics = ['sessions', 'ecommercePurchases', 'purchaseRevenue'];

        if ($purchaseIsKey) {
            $metrics[] = self::PURCHASE_RATE;
        }

        return [
            'dateRanges' => [self::current($days)],
            'dimensions' => [['name' => 'sessionDefaultChannelGroup']],
            'metrics' => self::names($metrics),
            'orderBys' => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
            'limit' => 50,
        ];
    }

    /** @return array<string,mixed> */
    public static function products(int $days, int $limit = 4): array
    {
        return [
            'dateRanges' => [self::current($days)],
            'dimensions' => [['name' => 'itemName']],
            'metrics' => self::names(['itemRevenue']),
            'orderBys' => [['metric' => ['metricName' => 'itemRevenue'], 'desc' => true]],
            'limit' => $limit,
        ];
    }

    /** @return array<string,mixed> */
    public static function devices(int $days): array
    {
        return self::bySessions($days, 'deviceCategory', 10);
    }

    /** @return array<string,mixed> */
    public static function countries(int $days): array
    {
        return self::bySessions($days, 'country', 50);
    }

    /**
     * Lejek "otwarty" z v1beta: liczba zdarzen kazdego kroku (`eventCount` po `eventName`),
     * nie runFunnelReport (ARCHITEKTURA §12 pkt 11).
     *
     * @return array<string,mixed>
     */
    public static function funnel(int $days): array
    {
        return [
            'dateRanges' => [self::current($days)],
            'dimensions' => [['name' => 'eventName']],
            'metrics' => self::names(['eventCount', 'totalUsers']),
            'dimensionFilter' => ['filter' => [
                'fieldName' => 'eventName',
                'inListFilter' => ['values' => self::FUNNEL_EVENTS],
            ]],
        ];
    }

    /** Ostatnie 30 minut — do karty na pulpicie ("Receiving data"). @return array<string,mixed> */
    public static function realtime(): array
    {
        return [
            'dimensions' => [['name' => 'minutesAgo']],
            'metrics' => self::names(['eventCount']),
            'minuteRanges' => [['startMinutesAgo' => 29, 'endMinutesAgo' => 0]],
        ];
    }

    /** @return array<string,mixed> */
    private static function bySessions(int $days, string $dimension, int $limit): array
    {
        return [
            'dateRanges' => [self::current($days)],
            'dimensions' => [['name' => $dimension]],
            'metrics' => self::names(['sessions']),
            'orderBys' => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
            'limit' => $limit,
        ];
    }

    /**
     * @param string[] $names
     *
     * @return array<int,array{name:string}>
     */
    private static function names(array $names): array
    {
        return array_map(static fn (string $n): array => ['name' => $n], $names);
    }
}
