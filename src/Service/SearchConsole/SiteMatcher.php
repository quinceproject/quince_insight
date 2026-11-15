<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\SearchConsole;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Dopasowanie uslug Search Console do adresu sklepu — czysta logika.
 *
 * Zasady (SPEC §3 „Search Console”, research/02 §2.3 bez ostatniej deski ratunku
 * Rankateera „pierwsza usluga na koncie”):
 *  - domyslnie usluga URL-prefix z dokladnym adresem sklepu — tag meta z kroku weryfikacji
 *    potwierdza wylacznie taka; gdy jej nie ma na koncie, modul ja doda (`sites.add`);
 *  - Domain (`sc-domain:`) do wyboru tylko, gdy Google widzi ja na koncie jako zweryfikowana
 *    (poziom inny niz `siteUnverifiedUser`) i obejmuje domene sklepu;
 *  - pozostale uslugi konta sa pokazane jako „inna domena” — wybor mozliwy, z ostrzezeniem.
 */
final class SiteMatcher
{
    public const UNVERIFIED = 'siteUnverifiedUser';

    /** Poziomy uprawnien z `sites.list` (research/05 §H) => tekst ekranu. */
    public const PERMISSION_LABELS = [
        'siteOwner' => 'Owner',
        'siteFullUser' => 'Full user',
        'siteRestrictedUser' => 'Restricted user',
        'siteUnverifiedUser' => 'Not verified',
    ];

    /**
     * @param array<int,array{siteUrl?:string,permissionLevel?:string}> $siteEntries `siteEntry[]` z `sites.list`
     *
     * @return array{prefix:array{site:string,listed:bool,permission:string},domain:array{site:string,listed:bool,permission:string,ok:bool},others:array<int,array{site:string,permission:string}>}
     */
    public static function match(string $shopBaseUrl, array $siteEntries): array
    {
        $prefixSite = self::prefixSite($shopBaseUrl);
        $host = self::host($shopBaseUrl);
        $domainSite = 'sc-domain:' . self::registrable($host);

        $prefix = ['site' => $prefixSite, 'listed' => false, 'permission' => ''];
        $domain = ['site' => $domainSite, 'listed' => false, 'permission' => '', 'ok' => false];
        $others = [];

        foreach ($siteEntries as $entry) {
            $site = (string) ($entry['siteUrl'] ?? '');
            $permission = (string) ($entry['permissionLevel'] ?? '');

            if ($site === '') {
                continue;
            }

            if (self::samePrefix($site, $prefixSite)) {
                $prefix = ['site' => $site, 'listed' => true, 'permission' => $permission];

                continue;
            }

            if (str_starts_with($site, 'sc-domain:') && self::domainCovers(substr($site, 10), $host)) {
                // Najblizsza domena wygrywa (np. `sc-domain:shop.yourshop.com` przed `sc-domain:yourshop.com`).
                if (!$domain['listed'] || strlen($site) > strlen($domain['site'])) {
                    $domain = ['site' => $site, 'listed' => true, 'permission' => $permission, 'ok' => $permission !== '' && $permission !== self::UNVERIFIED];
                }

                continue;
            }

            $others[] = ['site' => $site, 'permission' => $permission];
        }

        return ['prefix' => $prefix, 'domain' => $domain, 'others' => $others];
    }

    /** Adres uslugi URL-prefix sklepu: schemat + host + sciezka, zakonczony "/". */
    public static function prefixSite(string $shopBaseUrl): string
    {
        $parts = parse_url($shopBaseUrl);

        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = '/' . ltrim((string) ($parts['path'] ?? '/'), '/');

        return $scheme . '://' . strtolower((string) $parts['host']) . $port . rtrim($path, '/') . '/';
    }

    /** Czy adres jest objety usluga (prefix: poczatek adresu; domain: host i subdomeny). */
    public static function covers(string $site, string $url): bool
    {
        if (str_starts_with($site, 'sc-domain:')) {
            return self::domainCovers(substr($site, 10), self::host($url));
        }

        return $site !== '' && str_starts_with(strtolower($url), strtolower($site));
    }

    public static function permissionLabel(string $permission): string
    {
        return self::PERMISSION_LABELS[$permission] ?? $permission;
    }

    /** Zapis zmian (`sites.add`, `sitemaps.submit`) wymaga wlasciciela albo pelnego dostepu. */
    public static function canWrite(string $permission): bool
    {
        return in_array($permission, ['siteOwner', 'siteFullUser'], true);
    }

    private static function samePrefix(string $a, string $b): bool
    {
        return $b !== '' && rtrim(strtolower($a), '/') === rtrim(strtolower($b), '/');
    }

    private static function host(string $url): string
    {
        return strtolower((string) parse_url($url, PHP_URL_HOST));
    }

    private static function domainCovers(string $domain, string $host): bool
    {
        $domain = strtolower(trim($domain));

        return $domain !== '' && ($host === $domain || str_ends_with($host, '.' . $domain));
    }

    /** Domena bez `www.` — Domain property obejmuje `www` i inne subdomeny. */
    private static function registrable(string $host): string
    {
        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }
}
