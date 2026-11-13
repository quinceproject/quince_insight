<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\Merchant;

/**
 * Identyfikator oferty i nazwy zasobow Merchant API v1 (research/04 §7.2).
 *
 * Nazwa produktu w v1: `accounts/{id}/productInputs/{lang}~{feedLabel}~{offerId}` — bez
 * prefiksu kanalu z v1beta. Segment zawsze w wariancie base64url bez dopelnienia (Google
 * zaleca go zawsze, a jest obowiazkowy, gdy ktoras czesc zawiera `/`, `%` albo `~`).
 */
final class OfferId
{
    /**
     * Identyfikator oferty: prefiks feedu + wartosc wg zrodla (id | reference | ean).
     * Przy `reference` / `ean` pusta wartosc konczy sie zapasowym identyfikatorem liczbowym —
     * bez tego oferta nie mialaby klucza. Kombinacja przy zrodle `id` dostaje `{id}-{atrybut}`
     * (jak w FeedForge, zeby statusy wracaly do wlasciwej kombinacji).
     */
    public static function build(string $prefix, string $source, int $idProduct, int $idProductAttribute, string $reference = '', string $ean = ''): string
    {
        $base = $idProductAttribute > 0 ? $idProduct . '-' . $idProductAttribute : (string) $idProduct;
        $value = match ($source) {
            'reference' => trim($reference),
            'ean' => trim($ean),
            default => '',
        };

        $offer = $prefix . ($value !== '' ? $value : $base);

        // Limit Google dla offerId to 50 znakow (atrybut `id` w specyfikacji danych produktu).
        return mb_substr($offer, 0, 50);
    }

    /** Segment nazwy zasobu: base64url(`lang~label~offerId`) bez dopelnienia. */
    public static function segment(string $contentLanguage, string $feedLabel, string $offerId): string
    {
        $raw = strtolower($contentLanguage) . '~' . strtoupper($feedLabel) . '~' . $offerId;

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function productInputName(string $accountId, string $contentLanguage, string $feedLabel, string $offerId): string
    {
        return 'accounts/' . $accountId . '/productInputs/' . self::segment($contentLanguage, $feedLabel, $offerId);
    }

    public static function productName(string $accountId, string $contentLanguage, string $feedLabel, string $offerId): string
    {
        return 'accounts/' . $accountId . '/products/' . self::segment($contentLanguage, $feedLabel, $offerId);
    }

    /**
     * Rozbiera nazwe produktu z odpowiedzi Google na czesci. Przyjmuje obie postaci
     * (`lang~label~offer` i base64url) oraz — defensywnie — stary prefiks kanalu
     * (`online~lang~label~offer`, research/04 §7.2 [C]).
     *
     * @return array{lang:string,label:string,offer:string}|null
     */
    public static function parse(string $name): ?array
    {
        $segment = (string) substr($name, (int) strrpos($name, '/') + (str_contains($name, '/') ? 1 : 0));

        if (!str_contains($segment, '~')) {
            $decoded = base64_decode(strtr($segment, '-_', '+/') . str_repeat('=', (4 - strlen($segment) % 4) % 4), true);

            if ($decoded === false || !str_contains($decoded, '~')) {
                return null;
            }

            $segment = $decoded;
        }

        $parts = explode('~', $segment);

        if (count($parts) >= 4 && in_array(strtolower($parts[0]), ['online', 'local'], true)) {
            array_shift($parts);
        }

        if (count($parts) < 3) {
            return null;
        }

        $lang = array_shift($parts);
        $label = array_shift($parts);

        return ['lang' => strtolower($lang), 'label' => strtoupper($label), 'offer' => implode('~', $parts)];
    }
}
