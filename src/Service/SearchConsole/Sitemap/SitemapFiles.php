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
 * Pliki mapy na dysku i ich adresy publiczne (ARCHITEKTURA §7.8).
 *
 * Kazdy sklep ma wlasny katalog `var/sitemaps/{id_shop}/` — nazwy plikow moga byc
 * jednakowe w kilku sklepach, a katalog i tak ich nie miesza. Katalog jest zamkniety
 * `.htaccess` (bez bezposredniego dostepu); pliki serwuje `controllers/front/sitemap.php`,
 * ktory szuka ich wylacznie w katalogu sklepu z kontekstu.
 *
 * Budowa idzie do podkatalogu `.build/`, a gotowe pliki zastepuja stare dopiero po
 * udanej przebudowie — robot Google nigdy nie dostaje mapy w polowie.
 */
final class SitemapFiles
{
    /** Plik indeksu (adres zglaszany do Google). */
    public const INDEX = 'qi-sitemap.xml';

    /** Nazwa dopuszczalna w adresie: tylko male litery, cyfry i myslniki. Zadnych sciezek. */
    public const NAME_PATTERN = '/^qi-sitemap(-[a-z0-9]+)*\.xml$/';

    private static ?string $baseDir = null;

    /** Tylko testy: katalog bazowy zamiast `modules/quince_insight/var/sitemaps/`. `null` przywraca. */
    public static function useBaseDir(?string $dir): void
    {
        self::$baseDir = $dir === null ? null : rtrim($dir, '/\\') . '/';
    }

    public static function baseDir(): string
    {
        return self::$baseDir ?? rtrim(_PS_MODULE_DIR_, '/\\') . '/quince_insight/var/sitemaps/';
    }

    public static function shopDir(int $idShop): string
    {
        return self::baseDir() . max(0, $idShop) . '/';
    }

    public static function buildDir(int $idShop): string
    {
        return self::shopDir($idShop) . '.build/';
    }

    public static function isValidName(string $name): bool
    {
        return preg_match(self::NAME_PATTERN, $name) === 1;
    }

    /**
     * Sciezka pliku sklepu albo null, gdy nazwa jest niedozwolona albo pliku nie ma.
     * Kontroler frontu dodatkowo sprawdza, czy plik jest na liscie `sitemap_file` sklepu.
     */
    public static function path(int $idShop, string $name): ?string
    {
        if ($idShop <= 0 || !self::isValidName($name)) {
            return null;
        }

        $path = self::shopDir($idShop) . $name;

        return is_file($path) ? $path : null;
    }

    /** Zaklada katalogi z ochrona przed bezposrednim dostepem. */
    public static function ensure(int $idShop): void
    {
        foreach ([self::baseDir(), self::shopDir($idShop), self::buildDir($idShop)] as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }

            if (!is_file($dir . '.htaccess')) {
                @file_put_contents($dir . '.htaccess', "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n");
            }

            if (!is_file($dir . 'index.php')) {
                @file_put_contents($dir . 'index.php', "<?php\nheader('Location: ../');\nexit;\n");
            }
        }
    }

    /** Czysci katalog budowy (nowa przebudowa zaczyna od zera). */
    public static function resetBuild(int $idShop): void
    {
        self::ensure($idShop);

        foreach (glob(self::buildDir($idShop) . 'qi-sitemap*.xml') ?: [] as $file) {
            @unlink($file);
        }
    }

    /**
     * Przenosi gotowe pliki z `.build/` do katalogu sklepu i usuwa pliki, ktorych nie ma
     * w nowej przebudowie (np. czesc `-2` po zmniejszeniu katalogu).
     *
     * @param string[] $names pliki nowej przebudowy (razem z indeksem)
     */
    public static function publish(int $idShop, array $names): void
    {
        $shopDir = self::shopDir($idShop);
        $buildDir = self::buildDir($idShop);
        $keep = array_flip($names);

        foreach ($names as $name) {
            if (self::isValidName($name) && is_file($buildDir . $name)) {
                @rename($buildDir . $name, $shopDir . $name);
            }
        }

        foreach (glob($shopDir . 'qi-sitemap*.xml') ?: [] as $file) {
            if (!isset($keep[basename($file)])) {
                @unlink($file);
            }
        }
    }

    /** Usuwa wszystkie pliki sklepu razem z katalogiem (usuniecie sklepu, odinstalowanie). */
    public static function purge(int $idShop): void
    {
        foreach ([self::buildDir($idShop), self::shopDir($idShop)] as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            // Bez GLOB_BRACE (nie ma go w PHP na musl) — scandir widzi tez `.htaccess`.
            foreach (scandir($dir) ?: [] as $file) {
                if (is_file($dir . $file)) {
                    @unlink($dir . $file);
                }
            }
        }

        @rmdir(self::buildDir($idShop));
        @rmdir(self::shopDir($idShop));
    }

    /**
     * Adres publiczny pliku: przyjazny (`{sklep}qi-sitemap.xml`), gdy sklep ma przyjazne adresy,
     * inaczej przez kontroler (`index.php?fc=module&module=quince_insight&controller=sitemap&file=…`).
     */
    public static function publicUrl(string $shopBaseUrl, string $name, bool $friendly): string
    {
        if ($friendly) {
            return $shopBaseUrl . $name;
        }

        return $shopBaseUrl . 'index.php?fc=module&module=quince_insight&controller=sitemap&file=' . rawurlencode($name);
    }
}
