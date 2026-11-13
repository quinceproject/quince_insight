<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\Events;

/**
 * json_encode z najkrotszym zapisem liczb.
 *
 * PrestaShop ustawia `serialize_precision` = 17, przez co 98.39 wychodzi jako
 * 98.390000000000001 — w dataLayer, w Measurement Protocol i w dziennikach. Wartosc -1
 * (domyslna w PHP) daje najkrotszy zapis, ktory odczytuje sie jako ta sama liczba.
 */
final class Json
{
    public const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    public static function encode(mixed $value, int $flags = self::FLAGS): string
    {
        $previous = ini_get('serialize_precision');
        ini_set('serialize_precision', '-1');

        try {
            return (string) json_encode($value, $flags);
        } finally {
            ini_set('serialize_precision', $previous === false ? '-1' : $previous);
        }
    }
}
