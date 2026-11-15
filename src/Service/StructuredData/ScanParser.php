<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\StructuredData;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Odczyt danych strukturalnych z HTML strony tak, jak czyta je Google: bloki
 * `<script type="application/ld+json">`, typy najwyzszego poziomu (takze w `@graph`)
 * i zrodlo kazdego typu. Czysta logika — testowana na HTML-u motywu Quince i classic.
 *
 * Zrodlo:
 *  - `Quince Insight` — blok z atrybutem `data-qi` (wypisuje go ten modul),
 *  - `Quince Blog` — na stronie wpisu: BlogPosting, FAQPage i okruszki stojace zaraz za nimi
 *    (`post.tpl` bloga wypisuje te trzy bloki razem),
 *  - `Theme` — typ, ktory wypisuje aktywny motyw (lista z `ThemeTypes`: szablony motywu,
 *    a przy `$structured_data` takze dane z rdzenia PrestaShop 9.2, ktore wypisuje motyw),
 *  - `Other` — wszystko inne (inne moduly).
 */
final class ScanParser
{
    public const SOURCE_MODULE = 'Quince Insight';
    public const SOURCE_BLOG = 'Quince Blog';
    public const SOURCE_THEME = 'Theme';
    public const SOURCE_OTHER = 'Other';

    private const BLOG_TYPES = ['BlogPosting', 'FAQPage'];

    /**
     * @return array<int,array{types:string[],qi:bool,valid:bool,shipping:bool}>
     */
    public static function blocks(string $html): array
    {
        $out = [];

        if (!preg_match_all('#<script\b([^>]*)>(.*?)</script>#is', $html, $matches, PREG_SET_ORDER)) {
            return [];
        }

        foreach ($matches as $match) {
            $attributes = $match[1];

            if (!preg_match('#\btype\s*=\s*["\']?application/ld\+json#i', $attributes)) {
                continue;
            }

            $json = trim($match[2]);
            $data = json_decode($json, true);
            $valid = is_array($data);
            $nodes = $valid ? self::nodes($data) : [];
            $types = [];
            $shipping = false;

            foreach ($nodes as $node) {
                foreach ((array) ($node['@type'] ?? []) as $type) {
                    if (is_string($type) && $type !== '') {
                        $types[] = self::shortType($type);
                    }
                }

                $shipping = $shipping || self::hasShipping($node);
            }

            $out[] = [
                'types' => array_values(array_unique($types)),
                'qi' => (bool) preg_match('#\bdata-qi\b#i', $attributes),
                'valid' => $valid,
                'shipping' => $shipping,
            ];
        }

        return $out;
    }

    /**
     * Typy znalezione na stronie ze zrodlem, w kolejnosci wystapienia, bez powtorzen.
     *
     * @param array<int,array{types:string[],qi:bool,valid:bool,shipping:bool}> $blocks
     * @param string[] $themeTypes typy, ktore wypisuje aktywny motyw
     *
     * @return array<int,array{0:string,1:string}> [typ, zrodlo]
     */
    public static function found(array $blocks, string $pageKind, array $themeTypes): array
    {
        $out = [];
        $seen = [];
        $afterBlog = false;

        // Quince Blog podmienia okruszki rdzenia na wlasne w tablicy danych strukturalnych rdzenia
        // (`Seo::applyStructuredData`, klucz `breadcrumbs`) — motyw wypisuje je wtedy PRZED BlogPosting.
        // Na wpisie bloga (strona z BlogPosting) BreadcrumbList jest wiec bloga, niezaleznie od kolejnosci.
        $blogPage = false;

        foreach ($blocks as $block) {
            $blogPage = $blogPage || ($pageKind === 'blog' && array_intersect($block['types'], self::BLOG_TYPES) !== []);
        }

        foreach ($blocks as $block) {
            $isBlogBlock = $pageKind === 'blog' && array_intersect($block['types'], self::BLOG_TYPES) !== [];

            foreach ($block['types'] as $type) {
                if ($block['qi']) {
                    $source = self::SOURCE_MODULE;
                } elseif ($isBlogBlock || ($type === 'BreadcrumbList' && ($afterBlog || $blogPage))) {
                    $source = self::SOURCE_BLOG;
                } elseif (in_array($type, $themeTypes, true)) {
                    $source = self::SOURCE_THEME;
                } else {
                    $source = self::SOURCE_OTHER;
                }

                $key = $type . '|' . $source;

                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $out[] = [$type, $source];
                }
            }

            // Okruszki bloga stoja tuz za BlogPosting — nastepny blok, potem koniec.
            $afterBlog = $isBlogBlock;
        }

        return $out;
    }

    /**
     * Typy modulu juz obecne na stronach (z innego zrodla niz ten modul) i skad pochodza.
     * Na stronach bloga modul nic nie wypisuje, wiec okruszki bloga niczego nie blokuja.
     *
     * `variants` (decyzja #18): obecne, gdy na STRONIE PRODUKTU motyw albo inny modul wypisuje
     * Product (albo ProductGroup) — warianty modulu zastapilyby ten opis, wiec sa wtedy zablokowane
     * bez „Add anyway”. Po wylaczeniu Product w motywie i ponownym skanie klucza nie ma.
     *
     * @param array<string,array{kind:string,blocks:array<int,array{types:string[],qi:bool,valid:bool,shipping:bool}>,found:array<int,array{0:string,1:string}>}> $pages
     *
     * @return array<string,string> klucz typu modulu => zrodlo (Theme | Other)
     */
    public static function present(array $pages): array
    {
        $out = [];

        foreach ($pages as $page) {
            if ($page['kind'] === 'blog') {
                continue;
            }

            foreach ($page['found'] as [$type, $source]) {
                if ($source === self::SOURCE_MODULE || $source === self::SOURCE_BLOG) {
                    continue;
                }

                foreach (Types::DETECT as $key => $types) {
                    if ($key !== Types::VARIANTS && in_array($type, $types, true) && !isset($out[$key])) {
                        $out[$key] = $source;
                    }
                }

                if ($page['kind'] === 'product' && in_array($type, Types::DETECT[Types::PRODUCT], true) && !isset($out[Types::VARIANTS])) {
                    $out[Types::VARIANTS] = $source;
                }
            }

            foreach ($page['blocks'] as $block) {
                if ($block['shipping'] && !$block['qi'] && !isset($out[Types::SHIPPING])) {
                    $out[Types::SHIPPING] = self::SOURCE_OTHER;
                }
            }
        }

        return $out;
    }

    /**
     * Typy, ktore wypisuje motyw — z jego szablonow (`"@type": "X"` w plikach z `ld+json`).
     * `$structured_data` w szablonie = motyw wypisuje dane z rdzenia PrestaShop 9.2
     * (FrontController::getStructuredData(): Organization, WebSite, WebPage, BreadcrumbList,
     * na kategorii ItemList, na produkcie Product).
     *
     * @param array<string,string> $templates sciezka => tresc
     *
     * @return string[]
     */
    public static function themeTypes(array $templates): array
    {
        $types = [];

        foreach ($templates as $content) {
            if (str_contains($content, '$structured_data')) {
                array_push($types, 'Organization', 'WebSite', 'WebPage', 'BreadcrumbList', 'ItemList', 'Product');
            }

            if (stripos($content, 'ld+json') === false && !str_contains($content, '_jsonld')) {
                continue;
            }

            if (preg_match_all('#"@type"\s*:\s*"([A-Za-z]+)"#', $content, $m)) {
                array_push($types, ...$m[1]);
            }
        }

        // Organization z panelu silnika Quince ("Brand & Schema") przychodzi gotowym JSON-em.
        foreach ($templates as $content) {
            if (str_contains($content, 'quince_organization_jsonld')) {
                $types[] = 'Organization';
            }
        }

        $types = array_values(array_unique($types));
        sort($types);

        return $types;
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<int,array<string,mixed>>
     */
    private static function nodes(array $data): array
    {
        if (array_is_list($data)) {
            $out = [];

            foreach ($data as $item) {
                if (is_array($item)) {
                    array_push($out, ...self::nodes($item));
                }
            }

            return $out;
        }

        if (isset($data['@graph']) && is_array($data['@graph'])) {
            return self::nodes($data['@graph']);
        }

        return [$data];
    }

    private static function shortType(string $type): string
    {
        $type = preg_replace('#^https?://schema\.org/#i', '', $type) ?? $type;

        return preg_replace('#^schema:#i', '', $type) ?? $type;
    }

    /** Wysylka lub zwroty: na ofercie (`shippingDetails`, `hasMerchantReturnPolicy`) albo Organization. */
    private static function hasShipping(array $node): bool
    {
        if (isset($node['hasShippingService']) || isset($node['hasMerchantReturnPolicy'])) {
            return true;
        }

        $offers = $node['offers'] ?? null;

        foreach (is_array($offers) ? (array_is_list($offers) ? $offers : [$offers]) : [] as $offer) {
            if (is_array($offer) && (isset($offer['shippingDetails']) || isset($offer['hasMerchantReturnPolicy']))) {
                return true;
            }
        }

        foreach ((array) ($node['hasVariant'] ?? []) as $variant) {
            if (is_array($variant) && self::hasShipping($variant)) {
                return true;
            }
        }

        return false;
    }
}
