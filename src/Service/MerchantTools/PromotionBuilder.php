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
 * Regula koszyka PrestaShop → promocja Merchant API (`promotions:insert`, research/04 §13). Bez bazy.
 *
 * Przeniesione z FeedForge `PromotionService` (wykrycie typu, kod, okres, minimalna kwota) z poprawkami
 * z research/02 §1.4: zakres produktow (`itemIdInclusion` z identyfikatorami ofert feedu), okres zawsze
 * podany (Google go wymaga), procent tylko calkowity (Google: int64; FeedForge ucinal po cichu),
 * kwoty w walucie feedu, reguly prywatne / dla grup / dla innych krajow pomijane z powodem.
 */
final class PromotionBuilder
{
    public const DESTINATIONS = ['SHOPPING_ADS', 'FREE_LISTINGS'];

    /** Typ promocji wyswietlany w tabeli (jak w prototypie). */
    public static function valueType(array $rule): ?string
    {
        if ((float) ($rule['reduction_percent'] ?? 0) > 0) {
            return 'PERCENT_OFF';
        }

        if ((float) ($rule['reduction_amount'] ?? 0) > 0) {
            return 'MONEY_OFF';
        }

        if ((int) ($rule['free_shipping'] ?? 0) === 1) {
            return 'FREE_SHIPPING';
        }

        return null;
    }

    /**
     * Powod, dla ktorego regula nie moze byc promocja w Google dla feedu ('' = moze).
     *
     * @param array<string,mixed> $rule wiersz `cart_rule` + `name`, `countries` (ISO, gdy country_restriction)
     */
    public static function skipReason(array $rule, string $feedCountry, int $now): string
    {
        $type = self::valueType($rule);

        return match (true) {
            (int) ($rule['id_customer'] ?? 0) > 0 => 'Assigned to one customer',
            (int) ($rule['group_restriction'] ?? 0) === 1 => 'Limited to customer groups',
            self::ended($rule, $now) => 'Already ended',
            $type === null && (int) ($rule['gift_product'] ?? 0) > 0 => 'Free gift — not supported',
            $type === null => 'No discount to show',
            (int) ($rule['reduction_product'] ?? 0) === -1 => 'Discount on the cheapest product — not supported',
            $type === 'PERCENT_OFF' && floor((float) $rule['reduction_percent']) != (float) $rule['reduction_percent'] => 'Google accepts whole percentages only',
            (int) ($rule['country_restriction'] ?? 0) === 1 && !in_array(strtoupper($feedCountry), array_map('strtoupper', (array) ($rule['countries'] ?? [])), true) => 'Not valid in the feed country',
            default => '',
        };
    }

    public static function ended(array $rule, int $now): bool
    {
        $to = self::time((string) ($rule['date_to'] ?? ''));

        return $to !== null && $to <= $now;
    }

    /** Identyfikator promocji w Google: staly dla sklepu i reguly koszyka. */
    public static function promotionId(int $idShop, int $idCartRule): string
    {
        return 'qi_' . $idShop . '_' . $idCartRule;
    }

    /**
     * Tresc `promotions:insert`.
     *
     * @param array<string,mixed> $rule wiersz `cart_rule` z `name`, `reduction_currency_iso`, `minimum_amount_currency_iso`
     * @param array{country:string,language:string,currency:string,url:string} $feed
     * @param string[]|null $offerIds null = wszystkie produkty, lista = tylko te oferty
     * @param callable(float,string):float $convert kwota z waluty (ISO) na walute feedu
     * @param bool $end zakonczenie promocji: okres konczy sie teraz (API nie ma usuwania)
     *
     * @return array<string,mixed>
     */
    public static function build(int $idShop, array $rule, array $feed, ?array $offerIds, callable $convert, int $now, string $dataSource, bool $end = false): array
    {
        $type = self::valueType($rule) ?? 'PERCENT_OFF';
        $code = trim((string) ($rule['code'] ?? ''));
        $currency = strtoupper($feed['currency']);

        $attributes = [
            'longTitle' => mb_substr(trim((string) ($rule['name'] ?? '')) ?: 'Promotion', 0, 60),
            'promotionDestinations' => self::DESTINATIONS,
            'offerType' => $code !== '' ? 'GENERIC_CODE' : 'NO_CODE',
            'couponValueType' => $type === 'FREE_SHIPPING' ? 'FREE_SHIPPING_STANDARD' : $type,
            'productApplicability' => $offerIds === null ? 'ALL_PRODUCTS' : 'SPECIFIC_PRODUCTS',
        ];

        if ($code !== '') {
            $attributes['genericRedemptionCode'] = $code;
        }

        if ($type === 'PERCENT_OFF') {
            $attributes['percentOff'] = (string) (int) $rule['reduction_percent'];
        } elseif ($type === 'MONEY_OFF') {
            $amount = $convert((float) $rule['reduction_amount'], (string) ($rule['reduction_currency_iso'] ?? $currency));
            $attributes['moneyOffAmount'] = self::price($amount, $currency);
        }

        if ($offerIds !== null) {
            $attributes['itemIdInclusion'] = array_values(array_slice(array_unique($offerIds), 0, 1000));
        }

        $minimum = (float) ($rule['minimum_amount'] ?? 0);

        if ($minimum > 0) {
            $attributes['minimumPurchaseAmount'] = self::price(
                $convert($minimum, (string) ($rule['minimum_amount_currency_iso'] ?? $currency)),
                $currency
            );
        }

        $start = self::time((string) ($rule['date_from'] ?? '')) ?? $now;
        $stop = self::time((string) ($rule['date_to'] ?? ''));

        if ($end) {
            // Koniec teraz; start musi byc wczesniej niz koniec.
            $stop = $now - 60;
            $start = min($start, $stop - 60);
        } elseif ($stop === null || $stop <= $start) {
            // PrestaShop wymaga daty konca; gdy jej brak lub jest bledna, okres konczy sie po roku (Google wymaga konca).
            $stop = $start + 365 * 86400;
        }

        $attributes['promotionEffectiveTimePeriod'] = [
            'startTime' => gmdate('Y-m-d\TH:i:s\Z', $start),
            'endTime' => gmdate('Y-m-d\TH:i:s\Z', $stop),
        ];

        if ($feed['url'] !== '') {
            $attributes['promotionUrl'] = $feed['url'];
        }

        return [
            'dataSource' => $dataSource,
            'promotion' => [
                'promotionId' => self::promotionId($idShop, (int) $rule['id_cart_rule']),
                'contentLanguage' => strtolower($feed['language']),
                'targetCountry' => strtoupper($feed['country']),
                'redemptionChannel' => ['ONLINE'],
                'attributes' => $attributes,
            ],
        ];
    }

    /** Stan promocji z `promotionStatus.destinationStatuses[]` (mapowanie z FeedForge). */
    public static function googleState(?array $status): string
    {
        if ($status === null) {
            return '';
        }

        $active = $pending = $rejected = $ended = false;

        foreach ((array) ($status['destinationStatuses'] ?? []) as $d) {
            $state = (string) ($d['status'] ?? '');
            $active = $active || $state === 'LIVE';
            $pending = $pending || in_array($state, ['IN_REVIEW', 'PENDING'], true);
            $rejected = $rejected || $state === 'REJECTED';
            $ended = $ended || in_array($state, ['STOPPED', 'EXPIRED'], true);
        }

        return match (true) {
            $active => 'active',
            $pending => 'pending',
            $rejected => 'rejected',
            $ended => 'ended',
            default => '',
        };
    }

    /** "2026-11-27 – 2026-11-30" albo "no end date" (prototyp). */
    public static function period(array $rule): string
    {
        $from = self::time((string) ($rule['date_from'] ?? ''));
        $to = self::time((string) ($rule['date_to'] ?? ''));

        if ($to === null) {
            return 'no end date';
        }

        return ($from !== null ? date('Y-m-d', $from) : '…') . ' – ' . date('Y-m-d', $to);
    }

    /** Wartosc do tabeli: "10%", "15.00 EUR", "—". */
    public static function valueLabel(array $rule, string $currency): string
    {
        return match (self::valueType($rule)) {
            'PERCENT_OFF' => rtrim(rtrim(number_format((float) $rule['reduction_percent'], 2, '.', ''), '0'), '.') . '%',
            'MONEY_OFF' => number_format((float) $rule['reduction_amount'], 2, '.', '') . ' ' . ($rule['reduction_currency_iso'] ?? $currency),
            default => '—',
        };
    }

    /** @return array{amountMicros:string,currencyCode:string} */
    public static function price(float $amount, string $currency): array
    {
        return ['amountMicros' => (string) (int) round(round($amount, 2) * 1000000), 'currencyCode' => strtoupper($currency)];
    }

    private static function time(string $value): ?int
    {
        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        $t = strtotime($value);

        return $t === false ? null : $t;
    }
}
