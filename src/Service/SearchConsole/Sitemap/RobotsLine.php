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
 * Wpis `Sitemap:` w robots.txt — tylko po zgodzie usera (`_SM_ROBOTS_LINE`, ARCHITEKTURA §11).
 *
 * Modul nie pisze do robots.txt sam. Tak jak `quince_robots`, dopisuje sie przez hook
 * `actionAdminMetaAfterWriteRobotsFile`, ktory rdzen woła przy generowaniu pliku z hookami
 * (`Tools::generateRobotsFile(true)`) i ktory daje otwarty uchwyt pliku. `quince_robots`
 * nie dodaje linii `Sitemap:` (sprawdzone w `src/Robots/RobotsFile.php`, `RobotsBlock.php`);
 * rdzen dodaje ja tylko dla pliku gsitemap (`{id_shop}_index_sitemap.xml`).
 */
final class RobotsLine
{
    /**
     * Blok do dopisania: jedna linia `Sitemap:` na sklep z wlaczona zgoda. Adresy bez
     * powtorzen (robots.txt jest jeden na instalacje, sklepy dziela domene).
     *
     * @param string[] $urls adresy indeksow map
     */
    public static function render(array $urls): string
    {
        $urls = array_values(array_unique(array_filter($urls, static fn (string $u): bool => preg_match('#^https?://\S+$#', $u) === 1)));

        if ($urls === []) {
            return '';
        }

        $out = "\n# Sitemap (Quince Insight)\n";

        foreach ($urls as $url) {
            $out .= 'Sitemap: ' . $url . "\n";
        }

        return $out;
    }

    /**
     * Adresy do wpisania z danych sklepow: zgoda (`_SM_ROBOTS_LINE` przy wlaczonej usludze),
     * sklep na zywo, mapa modulu (tryb `existing` — przelacznik ukryty, linii nie piszemy)
     * i istniejacy plik indeksu.
     *
     * @param array<int,array{consent:bool,live:bool,mode:string,url:string,ready:bool}> $shops
     *
     * @return string[]
     */
    public static function urlsFor(array $shops): array
    {
        $out = [];

        foreach ($shops as $shop) {
            if ($shop['consent'] && $shop['live'] && $shop['mode'] === 'module' && $shop['ready'] && $shop['url'] !== '') {
                $out[] = $shop['url'];
            }
        }

        return $out;
    }

    /**
     * Dopisuje blok do otwartego uchwytu robots.txt (ten, ktory rdzen podaje w `write_fd`).
     * Zwraca liczbe zapisanych bajtow (0 = nic do dopisania).
     *
     * @param resource $handle
     * @param string[] $urls
     */
    public static function append($handle, array $urls): int
    {
        $block = self::render($urls);

        if ($block === '' || !is_resource($handle)) {
            return 0;
        }

        return (int) fwrite($handle, $block);
    }
}
