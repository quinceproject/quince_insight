<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\PageSpeed;

use Configuration;
use Context;
use Db;
use Module as PsModule;
use Quince\Insight\Core\Clock;
use Quince\Insight\Core\ShopContext;

/**
 * Po jednej stronie kazdego typu w sklepie: strona glowna, kategoria, produkt,
 * wpis Quince Blog (gdy modul jest wlaczony). Uzywane przez Page speed (domyslna
 * lista stron do testu) i przez skan danych strukturalnych.
 *
 * Tylko odczyt danych sklepu — nic nie tworzy i nie zmienia.
 */
final class SamplePages
{
    public const HOME = 'home';
    public const CATEGORY = 'category';
    public const PRODUCT = 'product';
    public const BLOG = 'blog';

    public const LABELS = [
        self::HOME => 'Home page',
        self::CATEGORY => 'Category',
        self::PRODUCT => 'Product',
        self::BLOG => 'Blog post',
    ];

    /**
     * @return array<string,array{kind:string,label:string,url:string,id:int}> typ => strona (brakujace typy pominiete)
     */
    public function forShop(int $idShop): array
    {
        return ShopContext::run($idShop, function () use ($idShop): array {
            $idLang = (int) Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);
            $link = Context::getContext()->link;
            $out = [];

            $out[self::HOME] = ['kind' => self::HOME, 'label' => self::LABELS[self::HOME], 'url' => (string) $link->getPageLink('index', null, $idLang, null, false, $idShop), 'id' => 0];

            $idCategory = $this->category($idShop);

            if ($idCategory > 0) {
                $out[self::CATEGORY] = ['kind' => self::CATEGORY, 'label' => self::LABELS[self::CATEGORY], 'url' => (string) $link->getCategoryLink($idCategory, null, $idLang, null, $idShop), 'id' => $idCategory];
            }

            $idProduct = $this->product($idShop);

            if ($idProduct > 0) {
                $out[self::PRODUCT] = ['kind' => self::PRODUCT, 'label' => self::LABELS[self::PRODUCT], 'url' => (string) $link->getProductLink($idProduct, null, null, null, $idLang, $idShop), 'id' => $idProduct];
            }

            $blog = $this->blogPost($idShop, $idLang);

            if ($blog !== null) {
                $out[self::BLOG] = ['kind' => self::BLOG, 'label' => self::LABELS[self::BLOG], 'url' => $blog['url'], 'id' => $blog['id']];
            }

            return $out;
        });
    }

    /** Czy Quince Blog jest zainstalowany i wlaczony w sklepie. */
    public static function blogEnabled(int $idShop): bool
    {
        if (!PsModule::isInstalled('quince_blog')) {
            return false;
        }

        return (bool) Db::getInstance()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . 'module` m
             INNER JOIN `' . _DB_PREFIX_ . 'module_shop` ms ON ms.`id_module` = m.`id_module`
             WHERE m.`name` = "quince_blog" AND m.`active` = 1 AND ms.`id_shop` = ' . $idShop,
            false
        );
    }

    /** Aktywna kategoria (nie korzen, nie "Home") z najwieksza liczba aktywnych produktow. */
    private function category(int $idShop): int
    {
        $root = (int) Configuration::get('PS_HOME_CATEGORY', null, null, $idShop);

        return (int) Db::getInstance()->getValue(
            'SELECT c.`id_category`
             FROM `' . _DB_PREFIX_ . 'category` c
             INNER JOIN `' . _DB_PREFIX_ . 'category_shop` cs ON cs.`id_category` = c.`id_category` AND cs.`id_shop` = ' . $idShop . '
             INNER JOIN `' . _DB_PREFIX_ . 'category_product` cp ON cp.`id_category` = c.`id_category`
             INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON ps.`id_product` = cp.`id_product` AND ps.`id_shop` = ' . $idShop . '
                AND ps.`active` = 1 AND ps.`visibility` IN ("both", "catalog")
             WHERE c.`active` = 1 AND c.`is_root_category` = 0 AND c.`id_category` <> ' . $root . ' AND c.`level_depth` > 1
             GROUP BY c.`id_category`
             ORDER BY COUNT(*) DESC, c.`nleft` ASC',
            false
        );
    }

    /** Aktywny, widoczny produkt z cena; najpierw taki z kombinacjami (pokazuje warianty). */
    private function product(int $idShop): int
    {
        return (int) Db::getInstance()->getValue(
            'SELECT ps.`id_product`
             FROM `' . _DB_PREFIX_ . 'product_shop` ps
             LEFT JOIN `' . _DB_PREFIX_ . 'product_attribute_shop` pas ON pas.`id_product` = ps.`id_product` AND pas.`id_shop` = ' . $idShop . '
             WHERE ps.`id_shop` = ' . $idShop . ' AND ps.`active` = 1 AND ps.`visibility` IN ("both", "catalog")
                AND ps.`show_price` = 1 AND ps.`available_for_order` = 1
             GROUP BY ps.`id_product`
             ORDER BY (COUNT(pas.`id_product_attribute`) > 0) DESC, ps.`id_product` ASC',
            false
        );
    }

    /**
     * Najnowszy opublikowany, indeksowalny wpis Quince Blog. Adres jak buduje go sam blog
     * (`Seo::postUrl()`: adres sklepu + prefiks z `QUINCE_BLOG_ROUTE_PREFIX` + slug).
     *
     * @return array{id:int,url:string}|null
     */
    private function blogPost(int $idShop, int $idLang): ?array
    {
        if (!self::blogEnabled($idShop)) {
            return null;
        }

        try {
            $row = Db::getInstance()->getRow(
                'SELECT p.`id_post`, l.`slug`
                 FROM `' . _DB_PREFIX_ . 'quince_blog_post` p
                 INNER JOIN `' . _DB_PREFIX_ . 'quince_blog_post_lang` l ON l.`id_post` = p.`id_post` AND l.`id_lang` = ' . $idLang . '
                 WHERE p.`id_shop` = ' . $idShop . ' AND p.`status` = "published" AND l.`slug` <> "" AND l.`robots` = "index"
                    AND (p.`date_pub` IS NULL OR p.`date_pub` <= "' . pSQL(Clock::now()) . '")
                 ORDER BY p.`date_pub` DESC, p.`id_post` DESC',
                false
            );
        } catch (\Throwable $e) {
            // Inna wersja bloga (inne tabele) nie moze wywrocic listy stron.
            return null;
        }

        if (!is_array($row)) {
            return null;
        }

        // Adres z metody samego bloga (fala 3: z prefiksem jezyka), zeby nie rozjechal sie z blogiem;
        // starsza wersja bloga albo kontekst innego sklepu — adres skladany jak dotad.
        $seo = '\Quince\Blog\Seo';
        $context = \Context::getContext();

        if (!class_exists($seo, true)) {
            PsModule::getInstanceByName('quince_blog');
        }

        if (class_exists($seo, true) && method_exists($seo, 'postUrl') && (int) ($context->shop->id ?? 0) === $idShop) {
            return ['id' => (int) $row['id_post'], 'url' => (string) $seo::postUrl((string) $row['slug'], $context, $idLang)];
        }

        $prefix = trim((string) Configuration::get('QUINCE_BLOG_ROUTE_PREFIX', null, null, $idShop)) ?: 'blog';

        return [
            'id' => (int) $row['id_post'],
            'url' => rtrim(ShopContext::baseUrl($idShop), '/') . '/' . $prefix . '/' . $row['slug'],
        ];
    }
}
