<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\Verification;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Czysta logika weryfikacji witryny: czy zweryfikowany zasob obejmuje adres sklepu,
 * odczyt tokenu META, zapis tagu. Bez bazy i bez Google.
 */
final class VerificationState
{
    public const NONE = 'none';
    public const PENDING = 'pending';
    public const VERIFIED = 'verified';
    public const MANUAL = 'manual';

    /**
     * Czy zweryfikowany zasob obejmuje adres. Wlasciciel adresu jest wlascicielem adresow
     * pod nim, wlasciciel domeny — subdomen i wszystkich adresow w niej (research/04 §5).
     *
     * @param string $type SITE | INET_DOMAIN
     */
    public static function covers(string $type, string $identifier, string $url): bool
    {
        $url = self::normalize($url);
        $host = (string) parse_url($url, PHP_URL_HOST);

        if ($host === '' || $identifier === '') {
            return false;
        }

        if (strtoupper($type) === 'INET_DOMAIN') {
            $domain = strtolower(trim($identifier, '. '));

            return $host === $domain || str_ends_with($host, '.' . $domain);
        }

        return str_starts_with($url, self::normalize($identifier));
    }

    /**
     * Pierwszy zasob z listy `webResource` obejmujacy adres; null gdy zaden.
     *
     * @param array<int,array<string,mixed>> $items
     *
     * @return array{type:string,identifier:string}|null
     */
    public static function coveringResource(array $items, string $url): ?array
    {
        foreach ($items as $item) {
            $type = (string) ($item['site']['type'] ?? '');
            $identifier = (string) ($item['site']['identifier'] ?? '');

            if (self::covers($type, $identifier, $url)) {
                return ['type' => $type, 'identifier' => $identifier];
            }
        }

        return null;
    }

    /**
     * Wartosc atrybutu `content` z tokenu META. Dokumentacja nie mowi jednoznacznie, czy
     * Google oddaje caly znacznik, czy sama wartosc (research/04 §5 [U]) — obslugujemy oba.
     */
    public static function parseToken(string $token): string
    {
        $token = trim($token);

        if (preg_match('/content\s*=\s*["\']([^"\']*)["\']/i', $token, $m) === 1) {
            return trim($m[1]);
        }

        // Sama wartosc: bez znacznikow, cudzyslowow i spacji.
        return preg_match('/^[A-Za-z0-9_\-]+$/', $token) === 1 ? $token : '';
    }

    /** Znacznik do `<head>`; '' gdy nie ma czego wstawic. */
    public static function metaTag(string $content): string
    {
        if ($content === '') {
            return '';
        }

        return '<meta name="google-site-verification" content="'
            . htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . "\n";
    }

    /** Czy tag ma byc na stronie: jest tresc i stan, w ktorym Google go potrzebuje. */
    public static function tagOn(string $meta, string $state): bool
    {
        return $meta !== '' && in_array($state, [self::PENDING, self::VERIFIED], true);
    }

    /** Stan liczony jako "adres zweryfikowany". */
    public static function isVerified(string $state): bool
    {
        return in_array($state, [self::VERIFIED, self::MANUAL], true);
    }

    /**
     * Inny sklep, ktorego weryfikacja obejmuje adres sklepu (ta sama domena, adres pod
     * zweryfikowanym adresem). Prototyp: `msVerifiedBy()`.
     *
     * @param array<int,array{url:string,state:string,site:string}> $shops id => stan weryfikacji
     */
    public static function verifiedBy(int $idShop, string $url, array $shops): ?int
    {
        foreach ($shops as $id => $shop) {
            if ((int) $id === $idShop || !self::isVerified($shop['state']) || $shop['site'] === '') {
                continue;
            }

            // Zrodlem moze byc tylko sklep zweryfikowany na wlasny adres (albo domena). Sklep,
            // ktory sam "pozyczyl" weryfikacje od innego, nie jest zrodlem dla kolejnych.
            if (!str_starts_with($shop['site'], 'sc-domain:') && self::normalize($shop['site']) !== self::normalize($shop['url'])) {
                continue;
            }

            $type = str_starts_with($shop['site'], 'sc-domain:') ? 'INET_DOMAIN' : 'SITE';
            $identifier = $type === 'INET_DOMAIN' ? substr($shop['site'], 10) : $shop['site'];

            if (self::covers($type, $identifier, $url)) {
                return (int) $id;
            }
        }

        return null;
    }

    /**
     * Inne sklepy, ktore trac weryfikacje razem z usunieciem jej w sklepie `$idShop`:
     * ten sam zweryfikowany adres i bez wlasnego tagu (ta sama regula co `Verifier::remove`).
     *
     * @param array<int,array{site:string,meta:string}> $shops id => zapisany adres i tag
     *
     * @return int[]
     */
    public static function sharingShops(int $idShop, string $site, array $shops): array
    {
        if ($site === '') {
            return [];
        }

        $out = [];

        foreach ($shops as $id => $shop) {
            if ((int) $id !== $idShop && (string) $shop['site'] === $site && (string) $shop['meta'] === '') {
                $out[] = (int) $id;
            }
        }

        return $out;
    }

    /** Adres w postaci do porownan: maly host, bez portu domyslnego, zakonczony "/". */
    public static function normalize(string $url): string
    {
        $parts = parse_url(trim($url));

        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = (string) ($parts['path'] ?? '/');

        return $scheme . '://' . strtolower($parts['host']) . $port . rtrim($path, '/') . '/';
    }
}
