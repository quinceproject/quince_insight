<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Core;

/**
 * Szyfrowanie sekretow w bazie (client secret, tokeny, klucz API, sekret Measurement Protocol).
 *
 * AES-256-GCM z kluczem wyprowadzonym z `_COOKIE_KEY_` sklepu. Kopia bazy bez pliku
 * `app/config/parameters.php` nie wystarcza wiec do odczytania tokenow. PrestaShop 9
 * nie ma juz `Tools::encrypt`, stad wlasna klasa.
 *
 * Kontekst (np. `connection|3|refresh_token`) wchodzi do szyfrowania jako dane
 * uwierzytelnione (AAD): wartosc przeniesiona w bazie do innego pola, polaczenia albo
 * sklepu nie da sie odczytac. Wartosci `qi1:` sa sprzed wersji 0.1.3, bez kontekstu;
 * aktualizacja 0.1.3 przepisuje je na `qi2:`, a odczyt przyjmuje je nadal na wypadek
 * kopii bazy sprzed aktualizacji.
 */
final class Crypto
{
    private const PREFIX = 'qi1:';
    private const PREFIX_BOUND = 'qi2:';
    private const CIPHER = 'aes-256-gcm';
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;

    public static function encrypt(string $plain, string $context): string
    {
        if ($plain === '') {
            return '';
        }

        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag, $context, self::TAG_LENGTH);

        if ($cipher === false) {
            return '';
        }

        return self::PREFIX_BOUND . base64_encode($iv . $tag . $cipher);
    }

    /** Zwraca '' dla pustej wartosci i dla wszystkiego, czego nie da sie odszyfrowac w tym kontekscie. */
    public static function decrypt(string $stored, string $context): string
    {
        if (strncmp($stored, self::PREFIX_BOUND, strlen(self::PREFIX_BOUND)) === 0) {
            return self::open(substr($stored, strlen(self::PREFIX_BOUND)), $context);
        }

        if (strncmp($stored, self::PREFIX, strlen(self::PREFIX)) === 0) {
            return self::open(substr($stored, strlen(self::PREFIX)), '');
        }

        return '';
    }

    /** Czy wartosc wyglada na zaszyfrowana przez te klase (do migracji i testow). */
    public static function isEncrypted(string $stored): bool
    {
        return strncmp($stored, self::PREFIX_BOUND, strlen(self::PREFIX_BOUND)) === 0
            || strncmp($stored, self::PREFIX, strlen(self::PREFIX)) === 0;
    }

    /** Czy wartosc jest w starej postaci, bez kontekstu (do przepisania przy aktualizacji). */
    public static function isLegacy(string $stored): bool
    {
        return strncmp($stored, self::PREFIX, strlen(self::PREFIX)) === 0;
    }

    private static function open(string $encoded, string $context): string
    {
        $raw = base64_decode($encoded, true);

        if ($raw === false || strlen($raw) <= self::IV_LENGTH + self::TAG_LENGTH) {
            return '';
        }

        $iv = substr($raw, 0, self::IV_LENGTH);
        $tag = substr($raw, self::IV_LENGTH, self::TAG_LENGTH);
        $cipher = substr($raw, self::IV_LENGTH + self::TAG_LENGTH);

        $plain = openssl_decrypt($cipher, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag, $context);

        return $plain === false ? '' : $plain;
    }

    private static function key(): string
    {
        return hash_hkdf('sha256', (string) _COOKIE_KEY_, 32, 'quince_insight');
    }
}
