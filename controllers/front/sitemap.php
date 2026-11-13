<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

use Quince\Insight\Core\Container;
use Quince\Insight\Core\Services;
use Quince\Insight\Core\ShopRepository;
use Quince\Insight\Service\SearchConsole\SearchConsoleRepository;
use Quince\Insight\Service\SearchConsole\Sitemap\SitemapFiles;

/**
 * Pliki mapy witryny sklepu (ARCHITEKTURA §7.8, §11).
 *
 * Adresy: `{sklep}index.php?fc=module&module=quince_insight&controller=sitemap&file=qi-sitemap.xml`
 * oraz przyjazny `{sklep}qi-sitemap.xml` (trasa z hooka `moduleRoutes`, parametr `name` = reszta
 * nazwy po `qi-sitemap`).
 *
 * Serwuje tylko w sklepie na zywo (`ShopRepository::isLive`, R-14) i wylacznie plik z listy
 * `sitemap_file` sklepu, w ktorego domenie przyszlo zadanie, z katalogu tego sklepu — nazwa z adresu przechodzi przez wzorzec bez ukosnikow i kropek,
 * wiec nie da sie nia wskazac innego katalogu ani pliku innego sklepu.
 */
class Quince_InsightSitemapModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $auth = false;

    /** @var bool */
    public $ajax = true;

    public function postProcess(): void
    {
        $idShop = (int) $this->context->shop->id;
        $name = (string) Tools::getValue('file', '');

        if ($name === '' && Tools::getIsset('name')) {
            $name = 'qi-sitemap' . (string) Tools::getValue('name', '') . '.xml';
        }

        try {
            // R-14: sklep po „Restart wizard” nie jest na zywo — stare pliki mapy nie sa serwowane.
            $listed = SitemapFiles::isValidName($name)
                && Container::get(ShopRepository::class)->isLive($idShop)
                && Container::get(Services::class)->enabled($idShop, 'gsc')
                && Container::get(SearchConsoleRepository::class)->hasFile($idShop, $name);
            $path = $listed ? SitemapFiles::path($idShop, $name) : null;
        } catch (\Throwable $e) {
            $path = null;
        }

        if ($path === null) {
            header('Content-Type: text/plain; charset=utf-8', true, 404);
            header('X-Robots-Tag: noindex');
            echo 'Not found';
            exit;
        }

        $mtime = (int) filemtime($path);
        $etag = '"' . sha1($name . '|' . $mtime . '|' . filesize($path)) . '"';

        header('Content-Type: application/xml; charset=utf-8', true, 200);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        header('ETag: ' . $etag);
        header('Cache-Control: public, max-age=3600');
        header('X-Robots-Tag: noindex');

        if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            http_response_code(304);
            exit;
        }

        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }
}
