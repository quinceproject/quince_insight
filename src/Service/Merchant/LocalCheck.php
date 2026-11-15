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

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Kontrola oferty przed wysylka (bez Google). Bledy danych z `insert` nie wracaja — Google
 * zglasza je dopiero w statusie produktu (research/04 §18) — wiec to, co da sie sprawdzic
 * lokalnie, sprawdzamy tutaj.
 *
 * Poziomy:
 *  - `error`   — Google odrzuci oferte (brak wymaganego atrybutu). Przy "Send only ready
 *                products" oferta czeka na poprawke.
 *  - `warning` — Google przyjmie, ale ograniczy widocznosc (brak GTIN, brak marki).
 */
final class LocalCheck
{
    public const ERROR = 'error';
    public const WARNING = 'warning';

    /**
     * @param array<string,mixed> $fields `ProductData::$fields` po mapowaniu i regulach
     *
     * @return array<int,array{level:string,code:string,message:string}>
     */
    public static function issues(array $fields): array
    {
        $out = [];

        if (trim((string) ($fields['title'] ?? '')) === '') {
            $out[] = self::issue(self::ERROR, 'title', 'Missing title');
        }

        if (trim((string) ($fields['description'] ?? '')) === '') {
            $out[] = self::issue(self::ERROR, 'description', 'Missing description');
        }

        if (trim((string) ($fields['link'] ?? '')) === '') {
            $out[] = self::issue(self::ERROR, 'link', 'Missing product link');
        }

        if (trim((string) ($fields['imageLink'] ?? '')) === '') {
            $out[] = self::issue(self::ERROR, 'image', 'No image');
        }

        $price = $fields['price']['value'] ?? null;

        if (!is_numeric($price) || (float) $price <= 0) {
            $out[] = self::issue(self::ERROR, 'price', 'Missing price');
        }

        $identifierExists = (bool) ($fields['identifierExists'] ?? true);

        if ($identifierExists && trim((string) ($fields['gtin'] ?? '')) === '') {
            $out[] = self::issue(self::WARNING, 'gtin', 'Missing GTIN');
        }

        if (trim((string) ($fields['brand'] ?? '')) === '') {
            $out[] = self::issue(self::WARNING, 'brand', 'Missing brand');
        }

        if (isset($fields['gtin']) && (string) $fields['gtin'] !== '' && !self::validGtin((string) $fields['gtin'])) {
            $out[] = self::issue(self::ERROR, 'gtin_invalid', 'Invalid GTIN');
        }

        return $out;
    }

    /** @param array<int,array{level:string}> $issues */
    public static function blocking(array $issues): bool
    {
        foreach ($issues as $issue) {
            if (($issue['level'] ?? '') === self::ERROR) {
                return true;
            }
        }

        return false;
    }

    /** GTIN-8/12/13/14 z poprawna cyfra kontrolna. */
    public static function validGtin(string $gtin): bool
    {
        if (!preg_match('/^\d{8}$|^\d{12,14}$/', $gtin)) {
            return false;
        }

        $digits = array_map('intval', str_split(str_pad($gtin, 14, '0', STR_PAD_LEFT)));
        $check = array_pop($digits);
        $sum = 0;

        foreach ($digits as $i => $digit) {
            $sum += $digit * ($i % 2 === 0 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10 === $check;
    }

    /** @return array{level:string,code:string,message:string} */
    private static function issue(string $level, string $code, string $message): array
    {
        return ['level' => $level, 'code' => $code, 'message' => $message];
    }
}
