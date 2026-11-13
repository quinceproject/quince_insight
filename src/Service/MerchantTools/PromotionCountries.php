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
 * Kraje, w ktorych Google przyjmuje promocje przez Merchant API — JEDYNE miejsce tej listy.
 *
 * Zrodlo: https://support.google.com/merchants/answer/4588460 („The Merchant API is only available
 * to merchants in Australia, Brazil, Canada, France, Germany, India, Italy, Japan, Netherlands,
 * South Korea, Spain, the United Kingdom, and the United States”), research/04 §13,
 * ARCHITEKTURA §12 pkt 6. Polski nie ma. Zmiana listy u Google = zmiana tylko tutaj.
 */
final class PromotionCountries
{
    /** ISO 3166-1 alfa-2 (Wielka Brytania = GB) => nazwa angielska. */
    public const SUPPORTED = [
        'AU' => 'Australia',
        'BR' => 'Brazil',
        'CA' => 'Canada',
        'FR' => 'France',
        'DE' => 'Germany',
        'IN' => 'India',
        'IT' => 'Italy',
        'JP' => 'Japan',
        'NL' => 'Netherlands',
        'KR' => 'South Korea',
        'ES' => 'Spain',
        'GB' => 'the United Kingdom',
        'US' => 'the United States',
    ];

    public static function supports(string $country): bool
    {
        $c = strtoupper(trim($country));

        return isset(self::SUPPORTED[$c === 'UK' ? 'GB' : $c]);
    }

    /**
     * "Australia, Brazil, … and the United States" — do informacji na ekranie.
     * Z `$idLang` nazwy krajow biora sie z PrestaShop w tym jezyku (panel po polsku nie
     * moze pokazywac angielskiej listy); kraj nieznany sklepowi zostaje po angielsku.
     */
    public static function sentence(?int $idLang = null): string
    {
        if ($idLang !== null && $idLang > 0 && class_exists(\Country::class)) {
            $names = [];

            foreach (self::SUPPORTED as $iso => $english) {
                $id = (int) \Country::getByIso($iso);
                $name = $id > 0 ? (string) \Country::getNameById($idLang, $id) : '';
                $names[] = $name !== '' ? $name : $english;
            }

            return implode(', ', $names);
        }

        $names = array_values(self::SUPPORTED);
        $last = array_pop($names);

        return implode(', ', $names) . ' and ' . $last;
    }
}