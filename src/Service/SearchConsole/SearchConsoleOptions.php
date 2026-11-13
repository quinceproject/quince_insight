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

/**
 * Klucze ustawien uslugi (per sklep) — Search Console i mapa witryny. Odczyt i zapis przez `Core\Options`.
 *
 * Lista z ARCHITEKTURA §7.8; klucze ponizej `SM_ROBOTS_LINE` to rozszerzenia agenta G
 * (opisane w raporcie G). Fala 3 (decyzja #21): bez `_SM_REBUILD`, `_SM_AUTO_SUBMIT` i `_SM_STALE` —
 * mapa przebudowuje sie przy kazdym uruchomieniu crona `sitemap` i zawsze jest zglaszana.
 */
final class SearchConsoleOptions
{
    /** `https://.../` albo `sc-domain:...` */
    public const GSC_SITE = 'QUINCE_INSIGHT_GSC_SITE';

    public const GSC_PERMISSION = 'QUINCE_INSIGHT_GSC_PERMISSION';

    /** module | existing */
    public const SM_MODE = 'QUINCE_INSIGHT_SM_MODE';

    public const SM_EXISTING_URL = 'QUINCE_INSIGHT_SM_EXISTING_URL';

    /** JSON: products, categories, cms, brands, suppliers, images, blog_list, blog_posts, blog_categories, blog_tags. */
    public const SM_INCLUDE = 'QUINCE_INSIGHT_SM_INCLUDE';

    public const SM_LAST_BUILD = 'QUINCE_INSIGHT_SM_LAST_BUILD';

    /** JSON: powod => liczba z ostatniej przebudowy. */
    public const SM_SKIPPED = 'QUINCE_INSIGHT_SM_SKIPPED';

    /** 0/1 — wpis `Sitemap:` do robots.txt tylko po zgodzie. */
    public const SM_ROBOTS_LINE = 'QUINCE_INSIGHT_SM_ROBOTS_LINE';

    /** Rozszerzenie G: ostatnie zgloszenie mapy do Search Console (data) i zgloszony adres. */
    public const SM_SUBMITTED_AT = 'QUINCE_INSIGHT_SM_SUBMITTED_AT';

    public const SM_SUBMITTED_URL = 'QUINCE_INSIGHT_SM_SUBMITTED_URL';

    /** Rozszerzenie G: stan mapy w Google (JSON z `SearchConsoleApi::sitemapState`) i chwila odczytu. */
    public const SM_GOOGLE = 'QUINCE_INSIGHT_SM_GOOGLE';

    /** Rozszerzenie G: inne mapy znane Google dla uslugi (JSON lista `{path, lastDownloaded}`). */
    public const SM_OTHERS = 'QUINCE_INSIGHT_SM_OTHERS';

    /** Rozszerzenie G: skroty tresci CMS => data zmiany (prawdziwy `lastmod`, CMS nie ma `date_upd`). */
    public const SM_CMS_SEEN = 'QUINCE_INSIGHT_SM_CMS_SEEN';

    /** Rozszerzenie G: ostatni import wynikow (data) i najstarszy dzien w `gsc_daily`. */
    public const GSC_IMPORTED_AT = 'QUINCE_INSIGHT_GSC_IMPORTED_AT';

    /** Domyslny zakres mapy — jak w prototypie (`sc.include`, `sc.blog`). */
    public const INCLUDE_DEFAULTS = [
        'products' => true,
        'images' => true,
        'categories' => true,
        'cms' => true,
        'brands' => true,
        'suppliers' => false,
        'blog_list' => true,
        'blog_posts' => true,
        'blog_categories' => true,
        'blog_tags' => false,
    ];
}
