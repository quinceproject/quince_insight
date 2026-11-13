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

use Configuration;
use Db;
use Group;
use ImageType;
use Language;
use Link;
use Module;
use Quince\Insight\Core\Clock;
use Quince\Insight\Core\Options;
use Quince\Insight\Core\ShopContext;
use Quince\Insight\Service\SearchConsole\SearchConsoleOptions;
use Tools;

/**
 * Adresy mapy z danych sklepu — porcjami po identyfikatorze, wszystkie jezyki naraz.
 *
 * Zapytania encji przeniesione z Rankateera (`SitemapGeneratorService`: produkty, kategorie,
 * CMS, producenci, dostawcy) i poprawione: wszystkie jezyki w jednym przebiegu (hreflang),
 * zdjecia jednym zapytaniem na porcje (nie na produkt), `getImageLink()` z `link_rewrite`
 * i typem obrazka, kategoria glowna sklepu i kategorie spoza drzewa sklepu pominiete,
 * prawdziwy `lastmod` CMS (skrot tresci, bo tabela `cms` nie ma daty zmiany).
 *
 * Adres pomijany zamiast wpisany, gdy sklep go nie pokaze (powody: `SkippedReasons`).
 * Reguly 404 / 403 / noindex wziete z kontrolerow PrestaShop 9.2 (`ProductController`,
 * `CategoryController`, `CmsController`, `ManufacturerController`, `SupplierController`)
 * i Quince Blog (`controllers/front/post.php`, `Repository`).
 *
 * Wolac wewnatrz `ShopContext::run($idShop, …)` — linki i obrazki licza sie dla sklepu z kontekstu.
 */
final class SitemapSource
{
    /**
     * Rodzaje w kolejnosci budowy; czesci bloga laduja w jednym pliku `blog`. Strona glowna
     * (`home`, decyzja #22) zawsze w mapie, w pliku stron razem z CMS.
     */
    public const KINDS = ['home', 'products', 'categories', 'cms', 'brands', 'suppliers', 'blog_list', 'blog_categories', 'blog_posts', 'blog_tags'];

    /** Rodzaj => plik (strumien) mapy. */
    public const FILE_KIND = [
        'home' => 'cms', 'products' => 'products', 'categories' => 'categories', 'cms' => 'cms', 'brands' => 'brands', 'suppliers' => 'suppliers',
        'blog_list' => 'blog', 'blog_categories' => 'blog', 'blog_posts' => 'blog', 'blog_tags' => 'blog',
    ];

    private ?Link $link = null;

    /** @var array<string,string> */
    private array $imageTypes = [];

    /** @var array<int,array<int,string>> sklep => jezyk => `{sklep}{jezyk/}` */
    private array $langBase = [];

    /**
     * Rodzaje, ktore wchodza do mapy wg ustawien sklepu (blog tylko z wlaczonym Quince Blog).
     *
     * @param array<string,bool> $include
     *
     * @return string[]
     */
    public static function kindsFor(array $include, bool $blog): array
    {
        $out = [];

        foreach (self::KINDS as $kind) {
            // Strona glowna nie ma przelacznika — jest w mapie zawsze.
            if ($kind === 'home') {
                $out[] = $kind;

                continue;
            }

            if (str_starts_with($kind, 'blog_') && !$blog) {
                continue;
            }

            if (!empty($include[$kind])) {
                $out[] = $kind;
            }
        }

        return $out;
    }

    /** Czy Quince Blog jest wlaczony w sklepie (PLAN §5: wykrycie przez `Module::isEnabled`). */
    public static function blogDetected(int $idShop): bool
    {
        if (!Module::isEnabled('quince_blog')) {
            return false;
        }

        // Modul wlaczony globalnie, ale nie w tym sklepie — PrestaShop nie uruchomi jego tras.
        return (bool) Db::getInstance()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . 'module_shop` ms
             JOIN `' . _DB_PREFIX_ . 'module` m ON m.`id_module` = ms.`id_module`
             WHERE m.`name` = "quince_blog" AND ms.`id_shop` = ' . $idShop
        );
    }

    /** Prefiks adresow bloga w sklepie (`QUINCE_BLOG_ROUTE_PREFIX`, jak w `Seo::routePrefix()`). */
    public static function blogPrefix(int $idShop): string
    {
        return trim((string) Configuration::get('QUINCE_BLOG_ROUTE_PREFIX', null, null, $idShop)) ?: 'blog';
    }

    /**
     * Aktywne jezyki sklepu.
     *
     * @return array<int,array{id_lang:int,iso:string,code:string}>
     */
    public static function languages(int $idShop): array
    {
        $out = [];

        foreach (Language::getLanguages(true, $idShop) as $lang) {
            $out[(int) $lang['id_lang']] = [
                'id_lang' => (int) $lang['id_lang'],
                'iso' => strtolower((string) $lang['iso_code']),
                'code' => strtolower((string) ($lang['language_code'] ?: $lang['iso_code'])),
            ];
        }

        return $out;
    }

    /** Ile encji danego rodzaju ma sklep (do paska postepu). */
    public function count(string $kind, int $idShop): int
    {
        $db = Db::getInstance();
        $p = _DB_PREFIX_;

        return (int) match ($kind) {
            'products' => $db->getValue('SELECT COUNT(*) FROM `' . $p . 'product_shop` WHERE `id_shop` = ' . $idShop),
            'categories' => $db->getValue('SELECT COUNT(*) FROM `' . $p . 'category_shop` WHERE `id_shop` = ' . $idShop),
            'cms' => $db->getValue('SELECT COUNT(*) FROM `' . $p . 'cms_shop` WHERE `id_shop` = ' . $idShop),
            'brands' => $db->getValue('SELECT COUNT(*) FROM `' . $p . 'manufacturer_shop` WHERE `id_shop` = ' . $idShop),
            'suppliers' => $db->getValue('SELECT COUNT(*) FROM `' . $p . 'supplier_shop` WHERE `id_shop` = ' . $idShop),
            'home', 'blog_list' => 1,
            'blog_categories' => $db->getValue('SELECT COUNT(*) FROM `' . $p . 'quince_blog_category` WHERE `id_shop` = ' . $idShop),
            'blog_posts' => $db->getValue('SELECT COUNT(*) FROM `' . $p . 'quince_blog_post` WHERE `id_shop` = ' . $idShop),
            'blog_tags' => $db->getValue('SELECT COUNT(*) FROM `' . $p . 'quince_blog_tag` WHERE `id_shop` = ' . $idShop),
            default => 0,
        };
    }

    /**
     * Jedna porcja encji rodzaju po identyfikatorze wiekszym niz `$afterId`.
     *
     * @param array<int,array{id_lang:int,iso:string,code:string}> $langs
     * @param array<string,bool> $include
     *
     * @return array{items:array<int,array{urls:array<int,array{loc:string,lastmod:?string,images:string[],hreflang:string}>,xdefault?:string}>,last:int,count:int,skipped:array<string,int>}
     *         `count` = ile encji przeczytano (0 = koniec rodzaju); `xdefault` = adres dla `hreflang="x-default"`
     */
    public function chunk(string $kind, int $idShop, array $langs, int $afterId, int $limit, array $include): array
    {
        $this->link ??= new Link();

        return match ($kind) {
            'home' => $this->home($idShop, $langs, $afterId),
            'products' => $this->products($idShop, $langs, $afterId, $limit, !empty($include['images'])),
            'categories' => $this->categories($idShop, $langs, $afterId, $limit),
            'cms' => $this->cms($idShop, $langs, $afterId, $limit),
            'brands' => $this->brands($idShop, $langs, $afterId, $limit),
            'suppliers' => $this->suppliers($idShop, $langs, $afterId, $limit),
            'blog_list' => $this->blogList($idShop, $langs, $afterId),
            'blog_categories' => $this->blogCategories($idShop, $langs, $afterId, $limit),
            'blog_posts' => $this->blogPosts($idShop, $langs, $afterId, $limit),
            'blog_tags' => $this->blogTags($idShop, $langs, $afterId, $limit),
            default => ['items' => [], 'last' => $afterId, 'count' => 0, 'skipped' => []],
        };
    }

    /**
     * @param array<int,array{id_lang:int,iso:string,code:string}> $langs
     *
     * @return array<string,mixed>
     */
    private function products(int $idShop, array $langs, int $afterId, int $limit, bool $images): array
    {
        $db = Db::getInstance();
        $p = _DB_PREFIX_;

        $rows = $db->executeS(
            'SELECT p.`id_product`, p.`ean13`, ps.`active`, ps.`visibility`, ps.`redirect_type`, ps.`date_upd`, ps.`id_category_default`
             FROM `' . $p . 'product` p
             JOIN `' . $p . 'product_shop` ps ON ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . $idShop . '
             WHERE p.`id_product` > ' . $afterId . '
             ORDER BY p.`id_product` ASC
             LIMIT ' . $limit
        ) ?: [];

        $out = ['items' => [], 'last' => $afterId, 'count' => count($rows), 'skipped' => []];

        if ($rows === []) {
            return $out;
        }

        $ids = array_map(static fn (array $r): int => (int) $r['id_product'], $rows);
        $out['last'] = max($ids);
        $in = implode(',', $ids);

        $rewrites = [];

        foreach ($db->executeS(
            'SELECT `id_product`, `id_lang`, `link_rewrite` FROM `' . $p . 'product_lang`
             WHERE `id_shop` = ' . $idShop . ' AND `id_product` IN (' . $in . ')'
        ) ?: [] as $r) {
            $rewrites[(int) $r['id_product']][(int) $r['id_lang']] = (string) $r['link_rewrite'];
        }

        $categoryIds = array_unique(array_map(static fn (array $r): int => (int) $r['id_category_default'], $rows));
        $categoryRewrites = [];

        foreach ($db->executeS(
            'SELECT `id_category`, `id_lang`, `link_rewrite` FROM `' . $p . 'category_lang`
             WHERE `id_shop` = ' . $idShop . ' AND `id_category` IN (' . implode(',', $categoryIds ?: [0]) . ')'
        ) ?: [] as $r) {
            $categoryRewrites[(int) $r['id_category']][(int) $r['id_lang']] = (string) $r['link_rewrite'];
        }

        $productImages = [];

        if ($images) {
            foreach ($db->executeS(
                'SELECT i.`id_product`, i.`id_image` FROM `' . $p . 'image` i
                 JOIN `' . $p . 'image_shop` ish ON ish.`id_image` = i.`id_image` AND ish.`id_shop` = ' . $idShop . '
                 WHERE i.`id_product` IN (' . $in . ')
                 ORDER BY i.`id_product` ASC, ish.`cover` DESC, i.`position` ASC'
            ) ?: [] as $r) {
                $productImages[(int) $r['id_product']][] = (int) $r['id_image'];
            }
        }

        // ProductController::checkAccess — gosc musi widziec choc jedna kategorie produktu.
        $accessible = null;

        if (Group::isFeatureActive()) {
            $accessible = [];
            $guest = (int) Configuration::get('PS_UNIDENTIFIED_GROUP', null, null, $idShop);

            foreach ($db->executeS(
                'SELECT DISTINCT cp.`id_product` FROM `' . $p . 'category_product` cp
                 JOIN `' . $p . 'category_group` cg ON cg.`id_category` = cp.`id_category` AND cg.`id_group` = ' . $guest . '
                 WHERE cp.`id_product` IN (' . $in . ')'
            ) ?: [] as $r) {
                $accessible[(int) $r['id_product']] = true;
            }
        }

        $defaultRedirect = (string) Configuration::get('PS_PRODUCT_REDIRECTION_DEFAULT', null, null, $idShop) ?: '404';
        $imageType = $this->imageType('large');

        foreach ($rows as $row) {
            $id = (int) $row['id_product'];

            $reason = SkippedReasons::forProduct($row, $defaultRedirect);

            if ($reason === null && $accessible !== null && !isset($accessible[$id])) {
                $reason = SkippedReasons::NOT_FOUND;
            }

            if ($reason !== null) {
                $this->skip($out, $reason);

                continue;
            }

            $urls = [];

            foreach ($langs as $idLang => $lang) {
                $rewrite = $rewrites[$id][$idLang] ?? '';

                if ($rewrite === '') {
                    continue;
                }

                $category = $categoryRewrites[(int) $row['id_category_default']][$idLang] ?? null;
                $loc = $this->link->getProductLink($id, $rewrite, $category, (string) $row['ean13'] ?: null, $idLang, $idShop, 0, false, false, false, [], false);
                $imgs = [];

                foreach ($productImages[$id] ?? [] as $idImage) {
                    $imgs[] = $this->absolute($this->link->getImageLink($rewrite, $id . '-' . $idImage, $imageType), $idShop);
                }

                $urls[$idLang] = ['loc' => $loc, 'lastmod' => (string) $row['date_upd'], 'images' => $imgs, 'hreflang' => $lang['code']];
            }

            if ($urls === []) {
                $this->skip($out, SkippedReasons::NOT_FOUND);

                continue;
            }

            $out['items'][] = ['urls' => $urls];
        }

        return $out;
    }

    /**
     * @param array<int,array{id_lang:int,iso:string,code:string}> $langs
     *
     * @return array<string,mixed>
     */
    private function categories(int $idShop, array $langs, int $afterId, int $limit): array
    {
        $db = Db::getInstance();
        $p = _DB_PREFIX_;

        // Kategoria glowna sklepu to strona glowna, a korzen drzewa (PS_ROOT_CATEGORY) nie ma strony.
        $shopRoot = (int) $db->getValue('SELECT `id_category` FROM `' . $p . 'shop` WHERE `id_shop` = ' . $idShop);
        $root = $db->getRow('SELECT `nleft`, `nright` FROM `' . $p . 'category` WHERE `id_category` = ' . $shopRoot) ?: ['nleft' => 0, 'nright' => 0];
        $treeRoot = (int) Configuration::get('PS_ROOT_CATEGORY');

        $rows = $db->executeS(
            'SELECT c.`id_category`, c.`active`, c.`date_upd`, c.`nleft`, c.`nright`
             FROM `' . $p . 'category` c
             JOIN `' . $p . 'category_shop` cs ON cs.`id_category` = c.`id_category` AND cs.`id_shop` = ' . $idShop . '
             WHERE c.`id_category` > ' . $afterId . '
             ORDER BY c.`id_category` ASC
             LIMIT ' . $limit
        ) ?: [];

        $out = ['items' => [], 'last' => $afterId, 'count' => count($rows), 'skipped' => []];

        if ($rows === []) {
            return $out;
        }

        $ids = array_map(static fn (array $r): int => (int) $r['id_category'], $rows);
        $out['last'] = max($ids);
        $in = implode(',', $ids);
        $rewrites = [];

        foreach ($db->executeS(
            'SELECT `id_category`, `id_lang`, `link_rewrite` FROM `' . $p . 'category_lang`
             WHERE `id_shop` = ' . $idShop . ' AND `id_category` IN (' . $in . ')'
        ) ?: [] as $r) {
            $rewrites[(int) $r['id_category']][(int) $r['id_lang']] = (string) $r['link_rewrite'];
        }

        $accessible = null;

        if (Group::isFeatureActive()) {
            $accessible = [];
            $guest = (int) Configuration::get('PS_UNIDENTIFIED_GROUP', null, null, $idShop);

            foreach ($db->executeS(
                'SELECT `id_category` FROM `' . $p . 'category_group` WHERE `id_group` = ' . $guest . ' AND `id_category` IN (' . $in . ')'
            ) ?: [] as $r) {
                $accessible[(int) $r['id_category']] = true;
            }
        }

        foreach ($rows as $row) {
            $id = (int) $row['id_category'];

            if ($id === $shopRoot || $id === $treeRoot) {
                continue;
            }

            $inTree = (int) $row['nleft'] > (int) $root['nleft'] && (int) $row['nright'] < (int) $root['nright'];

            // CategoryController: nieaktywna albo spoza sklepu = 404, bez dostepu dla gosci = 403.
            if (!(int) $row['active'] || !$inTree || ($accessible !== null && !isset($accessible[$id]))) {
                $this->skip($out, SkippedReasons::NOT_FOUND);

                continue;
            }

            $urls = [];

            foreach ($langs as $idLang => $lang) {
                $rewrite = $rewrites[$id][$idLang] ?? '';

                if ($rewrite !== '') {
                    $urls[$idLang] = [
                        'loc' => $this->link->getCategoryLink($id, $rewrite, $idLang, null, $idShop),
                        'lastmod' => (string) $row['date_upd'],
                        'images' => [],
                        'hreflang' => $lang['code'],
                    ];
                }
            }

            if ($urls !== []) {
                $out['items'][] = ['urls' => $urls];
            }
        }

        return $out;
    }

    /**
     * @param array<int,array{id_lang:int,iso:string,code:string}> $langs
     *
     * @return array<string,mixed>
     */
    private function cms(int $idShop, array $langs, int $afterId, int $limit): array
    {
        $db = Db::getInstance();
        $p = _DB_PREFIX_;

        $rows = $db->executeS(
            'SELECT c.`id_cms`, c.`active`, c.`indexation`
             FROM `' . $p . 'cms` c
             JOIN `' . $p . 'cms_shop` cs ON cs.`id_cms` = c.`id_cms` AND cs.`id_shop` = ' . $idShop . '
             WHERE c.`id_cms` > ' . $afterId . '
             ORDER BY c.`id_cms` ASC
             LIMIT ' . $limit
        ) ?: [];

        $out = ['items' => [], 'last' => $afterId, 'count' => count($rows), 'skipped' => []];

        if ($rows === []) {
            return $out;
        }

        $ids = array_map(static fn (array $r): int => (int) $r['id_cms'], $rows);
        $out['last'] = max($ids);
        $langRows = [];

        foreach ($db->executeS(
            'SELECT `id_cms`, `id_lang`, `link_rewrite`, `meta_title`, `meta_description`, `content`
             FROM `' . $p . 'cms_lang` WHERE `id_shop` = ' . $idShop . ' AND `id_cms` IN (' . implode(',', $ids) . ')'
        ) ?: [] as $r) {
            $langRows[(int) $r['id_cms']][(int) $r['id_lang']] = $r;
        }

        $seen = Options::json(SearchConsoleOptions::SM_CMS_SEEN, [], $idShop);
        $changed = false;

        foreach ($rows as $row) {
            $id = (int) $row['id_cms'];

            // CmsController: nieaktywna = 404; `indexation = 0` = `noindex` w naglowku strony.
            if (!(int) $row['active']) {
                $this->skip($out, SkippedReasons::NOT_FOUND);

                continue;
            }

            if (!(int) $row['indexation']) {
                $this->skip($out, SkippedReasons::NOINDEX);

                continue;
            }

            $urls = [];

            foreach ($langs as $idLang => $lang) {
                $data = $langRows[$id][$idLang] ?? null;

                if ($data === null || (string) $data['link_rewrite'] === '') {
                    continue;
                }

                [$lastmod, $entry] = self::cmsLastmod(
                    $seen[$id . '-' . $idLang] ?? null,
                    sha1($data['meta_title'] . "\n" . $data['meta_description'] . "\n" . $data['content']),
                    Clock::now()
                );

                if (($seen[$id . '-' . $idLang] ?? null) !== $entry) {
                    $seen[$id . '-' . $idLang] = $entry;
                    $changed = true;
                }

                $urls[$idLang] = [
                    'loc' => $this->link->getCMSLink($id, (string) $data['link_rewrite'], null, $idLang, $idShop),
                    'lastmod' => $lastmod,
                    'images' => [],
                    'hreflang' => $lang['code'],
                ];
            }

            if ($urls !== []) {
                $out['items'][] = ['urls' => $urls];
            }
        }

        if ($changed) {
            Options::set(SearchConsoleOptions::SM_CMS_SEEN, $seen, $idShop);
        }

        return $out;
    }

    /**
     * Prawdziwy `lastmod` strony CMS ze skrotu tresci: pierwsza przebudowa nie zna daty
     * (lastmod pominiety — Google pozwala, research/05 §I), kazda zmiana tresci zapisuje chwile przebudowy.
     *
     * @param array{0:string,1:string}|null $previous [skrot, data zmiany albo '']
     *
     * @return array{0:?string,1:array{0:string,1:string}} [lastmod, nowy wpis]
     */
    public static function cmsLastmod(?array $previous, string $hash, string $now): array
    {
        if ($previous === null) {
            return [null, [$hash, '']];
        }

        if ((string) ($previous[0] ?? '') === $hash) {
            $date = (string) ($previous[1] ?? '');

            return [$date !== '' ? $date : null, [$hash, $date]];
        }

        return [$now, [$hash, $now]];
    }

    /**
     * @param array<int,array{id_lang:int,iso:string,code:string}> $langs
     *
     * @return array<string,mixed>
     */
    private function brands(int $idShop, array $langs, int $afterId, int $limit): array
    {
        return $this->company('manufacturer', 'PS_DISPLAY_MANUFACTURERS', $idShop, $langs, $afterId, $limit);
    }

    /**
     * @param array<int,array{id_lang:int,iso:string,code:string}> $langs
     *
     * @return array<string,mixed>
     */
    private function suppliers(int $idShop, array $langs, int $afterId, int $limit): array
    {
        return $this->company('supplier', 'PS_DISPLAY_SUPPLIERS', $idShop, $langs, $afterId, $limit);
    }

    /**
     * Producenci i dostawcy — ta sama budowa; strony dzialaja tylko przy wlaczonym
     * `PS_DISPLAY_MANUFACTURERS` / `PS_DISPLAY_SUPPLIERS` (inaczej 404).
     *
     * @param array<int,array{id_lang:int,iso:string,code:string}> $langs
     *
     * @return array<string,mixed>
     */
    private function company(string $table, string $displayKey, int $idShop, array $langs, int $afterId, int $limit): array
    {
        $db = Db::getInstance();
        $p = _DB_PREFIX_;
        $key = 'id_' . $table;

        $rows = $db->executeS(
            'SELECT t.`' . $key . '` AS id, t.`name`, t.`active`, t.`date_upd`
             FROM `' . $p . $table . '` t
             JOIN `' . $p . $table . '_shop` s ON s.`' . $key . '` = t.`' . $key . '` AND s.`id_shop` = ' . $idShop . '
             WHERE t.`' . $key . '` > ' . $afterId . '
             ORDER BY t.`' . $key . '` ASC
             LIMIT ' . $limit
        ) ?: [];

        $out = ['items' => [], 'last' => $afterId, 'count' => count($rows), 'skipped' => []];

        if ($rows === []) {
            return $out;
        }

        $out['last'] = max(array_map(static fn (array $r): int => (int) $r['id'], $rows));
        $pagesOn = (bool) Configuration::get($displayKey, null, null, $idShop);

        foreach ($rows as $row) {
            $id = (int) $row['id'];

            if (!$pagesOn || !(int) $row['active']) {
                $this->skip($out, SkippedReasons::NOT_FOUND);

                continue;
            }

            $alias = Tools::str2url((string) $row['name']);
            $urls = [];

            foreach ($langs as $idLang => $lang) {
                $urls[$idLang] = [
                    'loc' => $table === 'manufacturer'
                        ? $this->link->getManufacturerLink($id, $alias, $idLang, $idShop)
                        : $this->link->getSupplierLink($id, $alias, $idLang, $idShop),
                    'lastmod' => (string) $row['date_upd'],
                    'images' => [],
                    'hreflang' => $lang['code'],
                ];
            }

            $out['items'][] = ['urls' => $urls];
        }

        return $out;
    }

    /**
     * Strona glowna sklepu w kazdym aktywnym jezyku (decyzja #22): `{sklep}{jezyk/}` z rdzenia
     * (`getPageLink('index')`, jak w `blogBase()`), hreflang miedzy wersjami i `x-default`
     * na jezyk domyslny sklepu (research/05 §I: „`x-default` jako zapas”). Bez `lastmod` —
     * strona glowna nie ma daty zmiany w bazie.
     *
     * @param array<int,array{id_lang:int,iso:string,code:string}> $langs
     *
     * @return array<string,mixed>
     */
    private function home(int $idShop, array $langs, int $afterId): array
    {
        if ($afterId > 0 || $langs === []) {
            return ['items' => [], 'last' => $afterId, 'count' => 0, 'skipped' => []];
        }

        $urls = [];

        foreach ($langs as $idLang => $lang) {
            $urls[$idLang] = [
                'loc' => (string) $this->link->getPageLink('index', null, $idLang, null, false, $idShop),
                'lastmod' => null,
                'images' => [],
                'hreflang' => $lang['code'],
            ];
        }

        $default = (int) Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);

        return ['items' => [['urls' => $urls, 'xdefault' => self::xDefault($urls, $default)]], 'last' => 1, 'count' => 1, 'skipped' => []];
    }

    /**
     * Adres `x-default`: wersja w jezyku domyslnym sklepu, a gdy ten jezyk nie jest aktywny — pierwsza.
     *
     * @param array<int,array{loc:string}> $urls jezyk => adres
     */
    public static function xDefault(array $urls, int $defaultLang): string
    {
        if ($urls === []) {
            return '';
        }

        return (string) ($urls[$defaultLang]['loc'] ?? reset($urls)['loc']);
    }

    /**
     * Strona glowna bloga: `{sklep}{jezyk/}{prefiks}` w kazdym jezyku sklepu.
     *
     * @param array<int,array{id_lang:int,iso:string,code:string}> $langs
     *
     * @return array<string,mixed>
     */
    private function blogList(int $idShop, array $langs, int $afterId): array
    {
        if ($afterId > 0 || $langs === []) {
            return ['items' => [], 'last' => $afterId, 'count' => 0, 'skipped' => []];
        }

        $latest = (string) Db::getInstance()->getValue(
            'SELECT MAX(GREATEST(p.`date_upd`, COALESCE(p.`date_pub`, p.`date_upd`))) FROM `' . _DB_PREFIX_ . 'quince_blog_post` p
             WHERE p.`id_shop` = ' . $idShop . ' AND p.`status` = "published"
               AND (p.`date_pub` IS NULL OR p.`date_pub` <= "' . pSQL(Clock::now()) . '")'
        );

        $urls = [];

        foreach ($langs as $idLang => $lang) {
            $urls[$idLang] = [
                'loc' => $this->blogUrl($idShop, $idLang, 'list'),
                'lastmod' => $latest !== '' ? $latest : null,
                'images' => [],
                'hreflang' => $lang['iso'],
            ];
        }

        return ['items' => [['urls' => $urls]], 'last' => 1, 'count' => 1, 'skipped' => []];
    }

    /**
     * Wpisy: `status = published`, `date_pub` pusta albo do teraz, `robots = index` w danym
     * jezyku (SPEC §5.1); adres `{sklep}{jezyk/}{prefiks}/{slug}` (patrz `blogBase()`); okladka jak w blogu.
     *
     * @param array<int,array{id_lang:int,iso:string,code:string}> $langs
     *
     * @return array<string,mixed>
     */
    private function blogPosts(int $idShop, array $langs, int $afterId, int $limit): array
    {
        $defaultLang = (int) Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);
        $db = Db::getInstance();
        $p = _DB_PREFIX_;

        $rows = $db->executeS(
            'SELECT p.`id_post`, p.`date_upd`, p.`featured_id_image`
             FROM `' . $p . 'quince_blog_post` p
             WHERE p.`id_shop` = ' . $idShop . ' AND p.`id_post` > ' . $afterId . '
               AND p.`status` = "published" AND (p.`date_pub` IS NULL OR p.`date_pub` <= "' . pSQL(Clock::now()) . '")
             ORDER BY p.`id_post` ASC
             LIMIT ' . $limit
        ) ?: [];

        $out = ['items' => [], 'last' => $afterId, 'count' => count($rows), 'skipped' => []];

        if ($rows === []) {
            return $out;
        }

        $ids = array_map(static fn (array $r): int => (int) $r['id_post'], $rows);
        $out['last'] = max($ids);
        $in = implode(',', $ids);
        $langRows = [];

        foreach ($db->executeS(
            'SELECT `id_post`, `id_lang`, `slug`, `robots` FROM `' . $p . 'quince_blog_post_lang`
             WHERE `id_shop` = ' . $idShop . ' AND `id_post` IN (' . $in . ')'
        ) ?: [] as $r) {
            $langRows[(int) $r['id_post']][(int) $r['id_lang']] = $r;
        }

        // Okladka: obraz wskazany jako wyrozniony, inaczej oznaczony `is_featured`, inaczej pierwszy (jak `resolveCover`).
        $covers = [];

        foreach ($db->executeS(
            'SELECT `id_image`, `id_post`, `filename`, `is_featured` FROM `' . $p . 'quince_blog_image`
             WHERE `id_post` IN (' . $in . ') ORDER BY `id_post` ASC, `position` ASC, `id_image` ASC'
        ) ?: [] as $r) {
            $covers[(int) $r['id_post']][] = $r;
        }

        $imgBase = $this->mediaBase($idShop) . 'img/blog/';

        foreach ($rows as $row) {
            $id = (int) $row['id_post'];

            $cover = self::blogCover($covers[$id] ?? [], (int) $row['featured_id_image']);
            $urls = [];
            $noindex = false;

            foreach ($langs as $idLang => $lang) {
                $data = $langRows[$id][$idLang] ?? null;

                if ($data === null || (string) $data['slug'] === '') {
                    continue;
                }

                if ((string) $data['robots'] === 'noindex') {
                    $noindex = true;

                    continue;
                }

                $urls[$idLang] = [
                    'loc' => $this->blogUrl($idShop, $idLang, 'post', (string) $data['slug']),
                    'lastmod' => (string) $row['date_upd'],
                    'images' => $cover !== '' ? [$imgBase . rawurlencode($cover)] : [],
                    'hreflang' => $lang['iso'],
                ];
            }

            if ($noindex) {
                $this->skip($out, SkippedReasons::NOINDEX);
            }

            if ($urls !== []) {
                // x-default jak w hreflang samego bloga (`Seo::buildHreflangs`: wersja w jezyku domyslnym, gdy jest).
                $out['items'][] = ['urls' => $urls, 'xdefault' => (string) ($urls[$defaultLang]['loc'] ?? '')];
            }
        }

        return $out;
    }

    /**
     * @param array<int,array<string,mixed>> $images obrazy wpisu w kolejnosci galerii
     */
    public static function blogCover(array $images, int $featuredId): string
    {
        foreach ($images as $image) {
            if ($featuredId > 0 && (int) $image['id_image'] === $featuredId) {
                return basename((string) $image['filename']);
            }
        }

        foreach ($images as $image) {
            if (!empty($image['is_featured'])) {
                return basename((string) $image['filename']);
            }
        }

        return isset($images[0]['filename']) ? basename((string) $images[0]['filename']) : '';
    }

    /**
     * Aktywne kategorie bloga: `{sklep}{jezyk/}{prefiks}/category/{slug}`.
     *
     * @param array<int,array{id_lang:int,iso:string,code:string}> $langs
     *
     * @return array<string,mixed>
     */
    private function blogCategories(int $idShop, array $langs, int $afterId, int $limit): array
    {
        return $this->blogTaxonomy('category', $idShop, $langs, $afterId, $limit);
    }

    /**
     * Strony tagow z co najmniej jednym opublikowanym wpisem: `{sklep}{jezyk/}{prefiks}/tag/{slug}`.
     *
     * @param array<int,array{id_lang:int,iso:string,code:string}> $langs
     *
     * @return array<string,mixed>
     */
    private function blogTags(int $idShop, array $langs, int $afterId, int $limit): array
    {
        return $this->blogTaxonomy('tag', $idShop, $langs, $afterId, $limit);
    }

    /**
     * @param array<int,array{id_lang:int,iso:string,code:string}> $langs
     *
     * @return array<string,mixed>
     */
    private function blogTaxonomy(string $type, int $idShop, array $langs, int $afterId, int $limit): array
    {
        $defaultLang = (int) Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);
        $db = Db::getInstance();
        $p = _DB_PREFIX_;
        $key = 'id_' . $type;
        $now = pSQL(Clock::now());

        $rows = $type === 'category'
            ? ($db->executeS(
                'SELECT c.`id_category` AS id, c.`active`, c.`date_upd`
                 FROM `' . $p . 'quince_blog_category` c
                 WHERE c.`id_shop` = ' . $idShop . ' AND c.`id_category` > ' . $afterId . '
                 ORDER BY c.`id_category` ASC LIMIT ' . $limit
            ) ?: [])
            : ($db->executeS(
                'SELECT t.`id_tag` AS id, 1 AS `active`,
                        (SELECT MAX(p.`date_upd`) FROM `' . $p . 'quince_blog_post_tag` pt
                           JOIN `' . $p . 'quince_blog_post` p ON p.`id_post` = pt.`id_post`
                          WHERE pt.`id_tag` = t.`id_tag` AND p.`status` = "published"
                            AND (p.`date_pub` IS NULL OR p.`date_pub` <= "' . $now . '")) AS `date_upd`
                 FROM `' . $p . 'quince_blog_tag` t
                 WHERE t.`id_shop` = ' . $idShop . ' AND t.`id_tag` > ' . $afterId . '
                 ORDER BY t.`id_tag` ASC LIMIT ' . $limit
            ) ?: []);

        $out = ['items' => [], 'last' => $afterId, 'count' => count($rows), 'skipped' => []];

        if ($rows === []) {
            return $out;
        }

        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $out['last'] = max($ids);
        $slugs = [];

        foreach ($db->executeS(
            'SELECT `' . $key . '` AS id, `id_lang`, `slug` FROM `' . $p . 'quince_blog_' . $type . '_lang`
             WHERE `id_shop` = ' . $idShop . ' AND `' . $key . '` IN (' . implode(',', $ids) . ')'
        ) ?: [] as $r) {
            $slugs[(int) $r['id']][(int) $r['id_lang']] = (string) $r['slug'];
        }

        foreach ($rows as $row) {
            $id = (int) $row['id'];

            if (!(int) $row['active']) {
                $this->skip($out, SkippedReasons::NOT_FOUND);

                continue;
            }

            // Tag bez opublikowanego wpisu to pusta lista — nie ma czego indeksowac.
            if ($type === 'tag' && empty($row['date_upd'])) {
                continue;
            }

            $urls = [];

            foreach ($langs as $idLang => $lang) {
                $slug = $slugs[$id][$idLang] ?? '';

                if ($slug !== '') {
                    $urls[$idLang] = ['loc' => $this->blogUrl($idShop, $idLang, $type, (string) $slug), 'lastmod' => (string) $row['date_upd'], 'images' => [], 'hreflang' => $lang['iso']];
                }
            }

            if ($urls !== []) {
                // Blog daje hreflang z x-default tylko kategoriom (tagi bez alternatyw w naglowku bloga — mapa zostawia komplet wersji).
                $out['items'][] = ['urls' => $urls, 'xdefault' => $type === 'category' ? (string) ($urls[$defaultLang]['loc'] ?? '') : ''];
            }
        }

        return $out;
    }

    /**
     * Adres strony bloga z metod samego Quince Blog (`Quince\Blog\Seo::listUrl/postUrl/categoryUrl/tagUrl`),
     * zeby mapa nie rozjechala sie z adresami i hreflang bloga (fala 3: blog sklada juz adresy z prefiksem
     * jezyka, tak jak rdzen). Gdy blog tych metod nie ma (starsza wersja) albo kontekst jest innego
     * sklepu — wlasny adres z `blogBase()`.
     *
     * @param string $type list | post | category | tag
     */
    private function blogUrl(int $idShop, int $idLang, string $type, string $slug = ''): string
    {
        $method = ['list' => 'listUrl', 'post' => 'postUrl', 'category' => 'categoryUrl', 'tag' => 'tagUrl'][$type] ?? '';
        $seo = '\Quince\Blog\Seo';

        if (!class_exists($seo, true) && Module::isEnabled('quince_blog')) {
            // W cronie / CLI klasy bloga nie sa jeszcze wczytane — plik glowny modulu rejestruje autoloader.
            Module::getInstanceByName('quince_blog');
        }

        $context = \Context::getContext();

        if ($method !== '' && class_exists($seo, true) && method_exists($seo, $method) && (int) ($context->shop->id ?? 0) === $idShop) {
            return (string) ($type === 'list' ? $seo::listUrl($context, $idLang) : $seo::$method($slug, $context, $idLang));
        }

        $base = $this->blogBase($idShop, $idLang);

        return match ($type) {
            'list' => $base,
            'post' => $base . '/' . $slug,
            default => $base . '/' . $type . '/' . $slug,
        };
    }

    /**
     * Zapas dla `blogUrl()`: `{sklep}{jezyk/}{prefiks}` bez ukosnika na koncu.
     *
     * Odstepstwo od `Seo::listUrl()/postUrl()` bloga (adres bez jezyka): PrestaShop rozpoznaje
     * jezyk z adresu, wiec wpis w jezyku innym niz domyslny pod adresem bez `/pl/` szuka sluga
     * w jezyku domyslnym i odpowiada 404 (sprawdzone na prestashop92). Adres bazowy z jezykiem
     * bierzemy z rdzenia — strona glowna w danym jezyku (`getPageLink('index')`) to dokladnie
     * `{sklep}{jezyk/}` tak, jak rdzen sklada go dla tras modulow (`getLangLink()` jest chroniona).
     */
    private function blogBase(int $idShop, int $idLang): string
    {
        $this->langBase[$idShop][$idLang] ??= (string) $this->link->getPageLink('index', null, $idLang, null, false, $idShop);

        return rtrim(strtok($this->langBase[$idShop][$idLang], '?'), '/') . '/' . self::blogPrefix($idShop);
    }

    /** Adres katalogu fizycznego sklepu (obrazy bloga leza w `img/blog/`, bez sciezki wirtualnej). */
    private function mediaBase(int $idShop): string
    {
        $row = Db::getInstance()->getRow(
            'SELECT `domain`, `domain_ssl`, `physical_uri` FROM `' . _DB_PREFIX_ . 'shop_url`
             WHERE `id_shop` = ' . $idShop . ' AND `main` = 1'
        );

        if (!$row) {
            return ShopContext::baseUrl($idShop);
        }

        $ssl = (bool) Configuration::get('PS_SSL_ENABLED', null, null, $idShop);

        return ($ssl ? 'https://' . ($row['domain_ssl'] ?: $row['domain']) : 'http://' . $row['domain'])
            . '/' . trim((string) $row['physical_uri'], '/') . (trim((string) $row['physical_uri'], '/') === '' ? '' : '/');
    }

    private function imageType(string $name): string
    {
        if (!isset($this->imageTypes[$name])) {
            $this->imageTypes[$name] = (string) ImageType::getFormattedName($name);
        }

        return $this->imageTypes[$name];
    }

    /**
     * `getImageLink()` bywa bez schematu (w cronie i CLI nie ma biezacego zadania HTTP) — Google
     * wymaga pelnych adresow; schemat jak w adresie sklepu.
     */
    private function absolute(string $url, int $idShop): string
    {
        $scheme = ShopContext::isHttps($idShop) ? 'https:' : 'http:';

        if (str_starts_with($url, '//')) {
            return $scheme . $url;
        }

        return preg_match('#^https?://#', $url) === 1 ? $url : $scheme . '//' . ltrim($url, '/');
    }

    /** @param array<string,mixed> $out */
    private function skip(array &$out, string $reason): void
    {
        $out['skipped'][$reason] = (int) ($out['skipped'][$reason] ?? 0) + 1;
    }
}
