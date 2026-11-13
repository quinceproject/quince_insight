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

use Context;
use Quince\Insight\Core\Clock;
use Quince\Insight\Core\Dto\Job;
use Quince\Insight\Core\Dto\JobProgress;
use Quince\Insight\Core\JobHandler;
use Quince\Insight\Service\PageSpeed\SamplePages;

/**
 * Zadanie `structureddata.scan`: po jednej stronie kazdego typu (glowna, kategoria, produkt,
 * wpis bloga) pobrana po HTTP i odczytana jak przez Google. Jedna strona na krok;
 * na koncu typy wypisywane przez motyw (z jego szablonow) i zapis wyniku (`sd.scan`).
 */
final class ScanJob implements JobHandler
{
    public const TYPE = 'structureddata.scan';

    /** Najwiecej plikow szablonow motywu czytanych przy ustalaniu jego typow. */
    private const MAX_TEMPLATES = 2000;

    public function __construct(
        private readonly SamplePages $samples,
        private readonly PageFetcher $fetcher,
        private readonly Settings $settings,
    ) {
    }

    public function step(Job $job): JobProgress
    {
        $idShop = $job->idShop;
        $state = $job->state;

        if (!isset($state['pages'])) {
            $state['pages'] = array_values($this->samples->forShop($idShop));
            $state['next'] = 0;
            $state['results'] = [];
        }

        $pages = (array) $state['pages'];
        $next = (int) $state['next'];
        $total = count($pages) + 1;

        if ($next < count($pages)) {
            $page = $pages[$next];
            $response = $this->fetcher->fetch((string) $page['url']);
            $blocks = $response['status'] >= 200 && $response['status'] < 300 ? ScanParser::blocks($response['body']) : [];

            $state['results'][$next] = [
                'kind' => (string) $page['kind'],
                'label' => (string) $page['label'],
                'url' => (string) $page['url'],
                'id' => (int) $page['id'],
                'status' => (int) $response['status'],
                'error' => $response['error'] !== '' ? $response['error'] : ($response['status'] >= 400 ? 'HTTP ' . $response['status'] : ''),
                'blocks' => $blocks,
            ];
            $state['next'] = ++$next;
        } else {
            $themeTypes = ScanParser::themeTypes($this->themeTemplates());
            $results = [];

            foreach ((array) $state['results'] as $result) {
                $result['found'] = ScanParser::found($result['blocks'], $result['kind'], $themeTypes);
                $results[$result['kind']] = $result;
            }

            $scan = [
                'pages' => array_values($results),
                'present' => ScanParser::present($results),
                'themeTypes' => $themeTypes,
                'blog' => SamplePages::blogEnabled($idShop),
                'at' => Clock::now(),
            ];

            $this->settings->saveScan($idShop, $scan, $scan['at']);
            $state['next'] = ++$next;
        }

        $finished = $next >= $total;
        $items = [];

        foreach ($pages as $n => $page) {
            $result = $state['results'][$n] ?? null;
            $items[] = [
                'text' => (string) $page['label'],
                'st' => $result === null ? ($n === $next ? 'run' : 'wait') : ($result['error'] !== '' ? 'err' : 'ok'),
                'note' => (string) ($result['error'] ?? ''),
            ];
        }

        $state['items'] = $items;

        return new JobProgress(
            $next,
            $total,
            $finished ? 'Scanned ' . count($pages) . ' pages' : 'Reading ' . ($pages[$next]['label'] ?? 'the theme'),
            $finished,
            0,
            $state,
            $finished ? ['pages' => count($pages)] : [],
        );
    }

    /**
     * Tresc szablonow aktywnego motywu sklepu (i motywu nadrzednego, gdy to motyw potomny).
     *
     * @return array<string,string>
     */
    private function themeTemplates(): array
    {
        $shop = Context::getContext()->shop;
        $dirs = [];
        $theme = (string) ($shop->theme_name ?? '');

        if ($theme !== '' && defined('_PS_ALL_THEMES_DIR_')) {
            $dirs[] = _PS_ALL_THEMES_DIR_ . $theme;
            $config = _PS_ALL_THEMES_DIR_ . $theme . '/config/theme.yml';

            if (is_file($config) && preg_match('/^parent:\s*([\w-]+)/m', (string) file_get_contents($config), $m)) {
                $dirs[] = _PS_ALL_THEMES_DIR_ . $m[1];
            }
        }

        $out = [];

        foreach ($dirs as $dir) {
            if (!is_dir($dir . '/templates')) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir . '/templates', \FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if (count($out) >= self::MAX_TEMPLATES) {
                    break 2;
                }

                if ($file->isFile() && $file->getExtension() === 'tpl') {
                    $out[$file->getPathname()] = (string) file_get_contents($file->getPathname());
                }
            }
        }

        return $out;
    }
}
