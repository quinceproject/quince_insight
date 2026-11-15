<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\SearchConsole\Sitemap;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Budowa XML mapy witryny — czyste funkcje, bez PrestaShop.
 *
 * Fakty (research/05 §I): bez `priority` i `changefreq` (Google je ignoruje), `lastmod`
 * tylko gdy data jest wiarygodna, obrazy w przestrzeni `sitemap-image/1.1` tylko z
 * `image:loc` (pozostale pola Google usunal), hreflang jako komplet `xhtml:link` w kazdym
 * `<url>` lacznie z samym soba.
 */
final class SitemapXml
{
    public const NS = 'http://www.sitemaps.org/schemas/sitemap/0.9';
    public const NS_IMAGE = 'http://www.google.com/schemas/sitemap-image/1.1';
    public const NS_XHTML = 'http://www.w3.org/1999/xhtml';

    /** Do 1000 obrazow na adres (research/05 §I). */
    public const MAX_IMAGES = 1000;

    /** Otwarcie `urlset` — przestrzenie nazw zawsze te same, zeby czesci jednego strumienia byly zgodne. */
    public static function urlsetOpen(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="' . self::NS . '" xmlns:image="' . self::NS_IMAGE . '" xmlns:xhtml="' . self::NS_XHTML . '">' . "\n";
    }

    public static function urlsetClose(): string
    {
        return '</urlset>' . "\n";
    }

    /**
     * Jeden wpis `<url>`.
     *
     * @param array{loc:string,lastmod?:?string,images?:string[],alternates?:array<string,string>} $url
     *        `alternates`: kod hreflang => adres (komplet wersji jezykowych, z biezaca)
     */
    public static function url(array $url): string
    {
        $xml = '<url><loc>' . self::esc($url['loc']) . '</loc>';

        $lastmod = self::date($url['lastmod'] ?? null);

        if ($lastmod !== '') {
            $xml .= '<lastmod>' . $lastmod . '</lastmod>';
        }

        // Jedna wersja jezykowa to nie alternatywa — `xhtml:link` dopiero od dwoch.
        $alternates = $url['alternates'] ?? [];

        if (count($alternates) > 1) {
            foreach ($alternates as $hreflang => $href) {
                $xml .= '<xhtml:link rel="alternate" hreflang="' . self::esc((string) $hreflang) . '" href="' . self::esc($href) . '"/>';
            }
        }

        foreach (array_slice(array_values(array_unique($url['images'] ?? [])), 0, self::MAX_IMAGES) as $image) {
            $xml .= '<image:image><image:loc>' . self::esc($image) . '</image:loc></image:image>';
        }

        return $xml . '</url>' . "\n";
    }

    /**
     * Indeks map.
     *
     * @param array<int,array{loc:string,lastmod?:?string}> $files
     */
    public static function index(array $files): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<sitemapindex xmlns="' . self::NS . '">' . "\n";

        foreach ($files as $file) {
            $xml .= '<sitemap><loc>' . self::esc($file['loc']) . '</loc>';
            $lastmod = self::date($file['lastmod'] ?? null);

            if ($lastmod !== '') {
                $xml .= '<lastmod>' . $lastmod . '</lastmod>';
            }

            $xml .= '</sitemap>' . "\n";
        }

        return $xml . '</sitemapindex>' . "\n";
    }

    /** Escapowanie tresci i atrybutow XML (adresy z `&` w zapytaniu). */
    public static function esc(string $value): string
    {
        // Znaki sterujace sa w XML 1.0 niedozwolone — usuwamy, zeby plik zawsze sie parsowal.
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);

        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** Data W3C (`YYYY-MM-DD`) z daty bazy; '' gdy pusta albo zerowa. */
    public static function date(?string $datetime): string
    {
        if ($datetime === null || $datetime === '' || str_starts_with($datetime, '0000-00-00')) {
            return '';
        }

        $ts = strtotime($datetime);

        return $ts === false || $ts <= 0 ? '' : date('Y-m-d', $ts);
    }
}
