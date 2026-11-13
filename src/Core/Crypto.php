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
 */
final class Crypto
{
    private const PREFIX = 'qi1:';
    private const CIPHER = 'aes-256-gcm';
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;

    public static function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }

        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LENGTH);

        if ($cipher === false) {
            return '';
        }

        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    /** Zwraca '' dla pustej wartosci i dla wszystkiego, czego nie da sie odszyfrowac. */
    public static function decrypt(string $stored): string
    {
        if ($stored === '' || strncmp($stored, self::PREFIX, strlen(self::PREFIX)) !== 0) {
            return '';
        }

        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);

        if ($raw === false || strlen($raw) <= self::IV_LENGTH + self::TAG_LENGTH) {
            return '';
        }

        $iv = substr($raw, 0, self::IV_LENGTH);
        $tag = substr($raw, self::IV_LENGTH, self::TAG_LENGTH);
        $cipher = substr($raw, self::IV_LENGTH + self::TAG_LENGTH);

        $plain = openssl_decrypt($cipher, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);

        return $plain === false ? '' : $plain;
    }

    /** Czy wartosc wyglada na zaszyfrowana przez te klase (do migracji i testow). */
    public static function isEncrypted(string $stored): bool
    {
        return strncmp($stored, self::PREFIX, strlen(self::PREFIX)) === 0;
    }

    private static function key(): string
    {
        return hash_hkdf('sha256', (string) _COOKIE_KEY_, 32, 'quince_insight');
    }
}
