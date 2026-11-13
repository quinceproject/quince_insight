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

/**
 * Przewoznicy PrestaShop → `ShippingSettings.services[]` Merchant API (research/04 §12). Bez bazy.
 *
 * Napisane od nowa wzgledem FeedForge (research/02 §1.4 "Shipping"): stawka per strefa (nie maksimum
 * ze wszystkich stref), tabela z naglowkami wierszy (gornymi granicami), tylko jeden sposob naliczania
 * przewoznika (waga albo kwota — jak liczy PrestaShop), zachowanie poza ostatnim przedzialem
 * (`range_behavior`), oplata manipulacyjna, darmowa wysylka od kwoty, podatek kraju, waluta feedu,
 * czas dostawy (`deliveryTime` jest wymagany).
 */
final class ShippingBuilder
{
    /**
     * Czas dostawy z tekstu przewoznika ("1-2 days", "24h", "Delivery next day!"). null = nie wiadomo.
     *
     * @return array{0:int,1:int}|null
     */
    public static function parseDays(string $text): ?array
    {
        $t = mb_strtolower(trim($text));

        if ($t === '') {
            return null;
        }

        if (preg_match('/(\d{1,2})\s*(?:-|–|—|to|do|bis)\s*(\d{1,2})\s*(h\b|hours?|godz|std|stunden)?/u', $t, $m)) {
            $a = (int) $m[1];
            $b = (int) $m[2];

            if (!empty($m[3])) {
                $a = (int) ceil($a / 24);
                $b = (int) ceil($b / 24);
            }

            return [min($a, $b), max($a, $b)];
        }

        if (preg_match('/(\d{1,3})\s*(h\b|hours?|godz|std|stunden)/u', $t, $m)) {
            $d = max(1, (int) ceil((int) $m[1] / 24));

            return [$d, $d];
        }

        if (preg_match('/(\d{1,2})/', $t, $m)) {
            return [(int) $m[1], (int) $m[1]];
        }

        if (preg_match('/next[\s-]day|tomorrow|jutro|następn\w* dni|nastepn\w* dni|nächsten tag|naechsten tag|overnight/u', $t)) {
            return [1, 1];
        }

        if (preg_match('/same[\s-]day|today|dzisiaj|tego samego dnia|heute/u', $t)) {
            return [0, 0];
        }

        return null;
    }

    /**
     * Uslugi Merchant Center z przewoznikow.
     *
     * @param array<int,array<string,mixed>> $carriers [id, name, free, module, method (weight|price), rangeBehavior,
     *                                                  handling (kwota w walucie domyslnej), days [min,max]|null,
     *                                                  zones: [idZone => [name, countries[], ranges[[from,to,price]]]]]
     * @param array<string,array{currency:string,rate:float,tax:float|array<int,float>}> $countries kraj => waluta, kurs
     *        wzgledem waluty domyslnej, podatek (% ogolny albo per id przewoznika)
     * @param array{freePrice:float,weightUnit:string} $shop progi sklepu (waluta domyslna)
     *
     * @return array{services: array<int,array<string,mixed>>, skipped: array<int,array{name:string,reason:string}>}
     */
    public static function build(array $carriers, array $countries, array $shop): array
    {
        $services = [];
        $skipped = [];
        $usedNames = [];

        foreach ($carriers as $carrier) {
            $name = trim((string) $carrier['name']);

            if (!empty($carrier['module'])) {
                $skipped[] = ['name' => $name, 'reason' => 'Carrier from another module — its price is calculated by that module'];
                continue;
            }

            $days = $carrier['days'] ?? null;

            if ($days === null) {
                $skipped[] = ['name' => $name, 'reason' => 'Delivery time unknown — write the number of days in the carrier\'s transit time'];
                continue;
            }

            $groups = [];

            foreach ((array) $carrier['zones'] as $idZone => $zone) {
                foreach ((array) $zone['countries'] as $iso) {
                    if (!isset($countries[$iso])) {
                        continue;
                    }

                    $c = $countries[$iso];
                    $tax = is_array($c['tax']) ? (float) ($c['tax'][(int) $carrier['id']] ?? 0) : (float) $c['tax'];
                    $key = $idZone . '|' . $c['currency'] . '|' . round($c['rate'], 6) . '|' . round($tax, 3);
                    $groups[$key] ??= ['zone' => (string) $zone['name'], 'ranges' => (array) $zone['ranges'], 'currency' => $c['currency'],
                        'factor' => $c['rate'] * (1 + $tax / 100), 'rateOnly' => $c['rate'], 'countries' => []];
                    $groups[$key]['countries'][] = $iso;
                }
            }

            if ($groups === []) {
                $skipped[] = ['name' => $name, 'reason' => 'No delivery to the countries of your feeds'];
                continue;
            }

            foreach ($groups as $group) {
                $rate = self::rateGroup($carrier, $group, $shop);

                if ($rate === null) {
                    $skipped[] = ['name' => $name, 'reason' => 'No delivery prices set for some zones'];
                    continue;
                }

                // Kilka uslug z jednego przewoznika (strefy, waluty, podatek): nazwa z krajami, przy wielu krajach ze strefa.
                $countryList = $group['countries'];
                sort($countryList);
                $serviceName = count($groups) > 1
                    ? $name . ' (' . (count($countryList) <= 3 ? implode(', ', $countryList) : $group['zone']) . ')'
                    : $name;

                while (isset($usedNames[mb_strtolower($serviceName)])) {
                    $serviceName .= ' ' . $group['currency'];
                }

                $usedNames[mb_strtolower($serviceName)] = true;
                sort($group['countries']);

                $services[] = [
                    'name' => $serviceName,
                    'countries' => $group['countries'],
                    'currency' => $group['currency'],
                    'rate' => $rate['label'],
                    // Dane do JSON-LD agenta H (ShippingSettings::forStructuredData): najnizsza stawka, prog, dni.
                    'sd' => ['rate' => $rate['min'], 'freeOver' => $rate['freeOver'], 'handling' => [0, 0], 'transit' => [$days[0], $days[1]]],
                    'delivery' => $days[0] === $days[1] ? $days[0] . ($days[0] === 1 ? ' day' : ' days') : $days[0] . '–' . $days[1] . ' days',
                    'google' => [
                        'serviceName' => $serviceName,
                        'active' => true,
                        'deliveryCountries' => $group['countries'],
                        'currencyCode' => $group['currency'],
                        'shipmentType' => 'DELIVERY',
                        // Tekst przewoznika to czas od zamowienia do doreczenia — calosc idzie jako tranzyt.
                        'deliveryTime' => [
                            'minHandlingDays' => 0,
                            'maxHandlingDays' => 0,
                            'minTransitDays' => $days[0],
                            'maxTransitDays' => $days[1],
                        ],
                        'rateGroups' => [$rate['group']],
                    ],
                ];
            }
        }

        return ['services' => $services, 'skipped' => $skipped];
    }

    /**
     * @param array<string,mixed> $carrier
     * @param array<string,mixed> $group
     * @param array{freePrice:float,weightUnit:string} $shop
     *
     * @return array{group:array<string,mixed>,label:string,min:float,freeOver:float|null}|null
     */
    private static function rateGroup(array $carrier, array $group, array $shop): ?array
    {
        $currency = (string) $group['currency'];
        $factor = (float) $group['factor'];
        $flat = static fn (float $amount): array => ['flatRate' => PromotionBuilder::price($amount, $currency)];
        $free = ['applicableShippingLabels' => [], 'singleValue' => $flat(0.0)];

        if (!empty($carrier['free'])) {
            return ['group' => $free, 'label' => 'Free', 'min' => 0.0, 'freeOver' => null];
        }

        $ranges = array_values(array_filter((array) $group['ranges'], static fn (array $r): bool => $r[2] !== null));
        usort($ranges, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        if ($ranges === []) {
            return null;
        }

        $handling = (float) ($carrier['handling'] ?? 0);
        $cost = static fn (float $price): float => round(($price + $handling) * $factor, 2);
        $byWeight = ($carrier['method'] ?? 'weight') === 'weight';
        $unit = strtolower($shop['weightUnit']) === 'lb' || strtolower($shop['weightUnit']) === 'lbs' ? 'POUND' : 'KILOGRAM';
        // Prog darmowej wysylki jest w walucie domyslnej i bez podatku — w walucie feedu tylko kurs.
        $freeFrom = (float) $shop['freePrice'] > 0 ? round((float) $shop['freePrice'] * self::rateOnly($group, $carrier), 2) : 0.0;

        // Odcinki: [gorna granica | null = bez konca, cena | null = brak dostawy]
        $segments = [];

        foreach ($ranges as $i => [$from, $to, $price]) {
            $last = $i === count($ranges) - 1;
            $segments[] = [$last && (int) ($carrier['rangeBehavior'] ?? 0) === 0 ? null : (float) $to, $cost((float) $price)];
        }

        if ((int) ($carrier['rangeBehavior'] ?? 0) === 1) {
            $segments[] = [null, null]; // poza ostatnim przedzialem przewoznik jest niedostepny
        }

        $minRate = min(array_map(static fn (array $s): float => $s[1] ?? INF, $segments));
        $minRate = is_finite($minRate) ? $minRate : 0.0;

        $label = count($ranges) === 1
            ? self::num((float) $ranges[0][0]) . '–' . self::num((float) $ranges[0][1]) . ($byWeight ? ' ' . ($unit === 'POUND' ? 'lb' : 'kg') : ' ' . $currency) . ' · ' . self::money($cost((float) $ranges[0][2]), $currency)
            : count($ranges) . ($byWeight ? ' weight ranges' : ' order value ranges') . ' · from ' . self::money(min(array_map(static fn (array $s): float => $s[1] ?? INF, $segments)), $currency);

        if ($freeFrom > 0) {
            $label .= ' · free from ' . self::money($freeFrom, $currency);
        }

        $cell = static fn (?float $price): array => $price === null ? ['noShipping' => true] : $flat($price);

        if (!$byWeight) {
            // Naliczanie od kwoty zamowienia: granice to kwoty, powyzej progu darmowej wysylki — 0.
            $bounds = [];

            foreach ($segments as [$upper, $price]) {
                $upper = $upper === null ? null : round($upper * self::rateOnly($group, $carrier), 2);

                if ($freeFrom > 0 && ($upper === null || $upper >= $freeFrom)) {
                    $bounds[] = [round($freeFrom - 0.01, 2), $price];
                    $bounds[] = [null, 0.0];
                    break;
                }

                $bounds[] = [$upper, $price];
            }

            if (count($bounds) === 1 && $bounds[0][0] === null) {
                return ['group' => ['applicableShippingLabels' => [], 'singleValue' => $cell($bounds[0][1])], 'label' => $label, 'min' => $minRate, 'freeOver' => $freeFrom > 0 ? $freeFrom : null];
            }

            return ['group' => [
                'applicableShippingLabels' => [],
                'mainTable' => [
                    'rowHeaders' => ['prices' => array_map(static fn (array $b): array => $b[0] === null ? ['amountMicros' => '-1', 'currencyCode' => $currency] : PromotionBuilder::price($b[0], $currency), $bounds)],
                    'rows' => array_map(static fn (array $b): array => ['cells' => [$cell($b[1])]], $bounds),
                ],
            ], 'label' => $label, 'min' => $minRate, 'freeOver' => $freeFrom > 0 ? $freeFrom : null];
        }

        $weights = array_map(static fn (array $s): array => ['amountMicros' => $s[0] === null ? '-1' : (string) (int) round($s[0] * 1000000), 'unit' => $unit], $segments);

        if ($freeFrom > 0) {
            // Dwa wymiary: wiersze = kwota zamowienia (do progu / od progu), kolumny = waga.
            return ['group' => [
                'applicableShippingLabels' => [],
                'mainTable' => [
                    'rowHeaders' => ['prices' => [PromotionBuilder::price(round($freeFrom - 0.01, 2), $currency), ['amountMicros' => '-1', 'currencyCode' => $currency]]],
                    'columnHeaders' => ['weights' => $weights],
                    'rows' => [
                        ['cells' => array_map(static fn (array $s): array => $cell($s[1]), $segments)],
                        ['cells' => array_map(static fn (array $s): array => $s[1] === null ? $cell(null) : $flat(0.0), $segments)],
                    ],
                ],
            ], 'label' => $label, 'min' => $minRate, 'freeOver' => $freeFrom > 0 ? $freeFrom : null];
        }

        if (count($segments) === 1 && $segments[0][0] === null) {
            return ['group' => ['applicableShippingLabels' => [], 'singleValue' => $cell($segments[0][1])], 'label' => $label, 'min' => $minRate, 'freeOver' => $freeFrom > 0 ? $freeFrom : null];
        }

        return ['group' => [
            'applicableShippingLabels' => [],
            'mainTable' => [
                'rowHeaders' => ['weights' => $weights],
                'rows' => array_map(static fn (array $s): array => ['cells' => [$cell($s[1])]], $segments),
            ],
        ], 'label' => $label, 'min' => $minRate, 'freeOver' => $freeFrom > 0 ? $freeFrom : null];
    }

    /** Sam kurs waluty grupy (bez podatku) — dla progow kwotowych. */
    private static function rateOnly(array $group, array $carrier = []): float
    {
        return (float) ($group['rateOnly'] ?? $group['factor']);
    }

    private static function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }

    public static function money(float $amount, string $currency): string
    {
        return $amount <= 0 ? 'Free' : number_format($amount, 2, '.', ' ') . ' ' . $currency;
    }

    /**
     * Uslugi zapisane teraz w Merchant Center — do pokazania, co zostanie nadpisane.
     *
     * @param array<string,mixed> $settings odpowiedz `GET shippingSettings`
     *
     * @return array<int,array{name:string,countries:string[],active:bool,currency:string}>
     */
    public static function current(array $settings): array
    {
        $out = [];

        foreach ((array) ($settings['services'] ?? []) as $s) {
            $out[] = [
                'name' => (string) ($s['serviceName'] ?? ''),
                'countries' => array_values(array_map('strval', (array) ($s['deliveryCountries'] ?? []))),
                'active' => (bool) ($s['active'] ?? false),
                'currency' => (string) ($s['currencyCode'] ?? ''),
            ];
        }

        return $out;
    }

    /** Odcisk tego, co user zobaczyl w podgladzie (wysylka tylko, gdy sie nie zmienilo). */
    public static function hash(array $services, string $etag): string
    {
        return sha1((string) json_encode([array_column($services, 'google'), $etag]));
    }
}
