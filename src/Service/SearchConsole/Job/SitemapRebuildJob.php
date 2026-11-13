<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\SearchConsole\Job;

use Quince\Insight\Core\Clock;
use Quince\Insight\Core\Dto\Job;
use Quince\Insight\Core\Dto\JobProgress;
use Quince\Insight\Core\JobHandler;
use Quince\Insight\Core\JobRunner;
use Quince\Insight\Core\Log;
use Quince\Insight\Core\Options;
use Quince\Insight\Service\SearchConsole\SearchConsoleApi;
use Quince\Insight\Service\SearchConsole\SearchConsoleOptions;
use Quince\Insight\Service\SearchConsole\SearchConsoleRepository;
use Quince\Insight\Service\SearchConsole\SearchConsoleService;
use Quince\Insight\Service\SearchConsole\Sitemap\SitemapFiles;
use Quince\Insight\Service\SearchConsole\Sitemap\SitemapSource;
use Quince\Insight\Service\SearchConsole\Sitemap\SitemapWriter;
use Quince\Insight\Service\SearchConsole\Sitemap\SitemapXml;

/**
 * Zadanie `sitemap.rebuild`: przebudowa mapy, opcjonalnie zgloszenie i pierwszy import.
 *
 * Payload: `submit` (zglos do Search Console po przebudowie), `import` (uruchom import
 * 16 miesiecy wynikow jako osobne zadanie `gsc.import`), `max_urls` (tylko testy — nizszy
 * limit adresow na plik, zeby sprawdzic podzial z indeksem na malym katalogu).
 *
 * Krok czyta porcje encji przez ok. 5 s i dopisuje je do plikow w `.build/`; gotowe pliki
 * zastepuja stare dopiero na koncu (`SitemapFiles::publish`). Przerwane zadanie wznawia
 * sie od zapisanego kursora i dopisuje do tych samych czesci.
 */
final class SitemapRebuildJob implements JobHandler
{
    public const TYPE = 'sitemap.rebuild';

    private const CHUNK = 250;
    private const STEP_SECONDS = 5.0;

    public function __construct(
        private readonly SitemapSource $source,
        private readonly SearchConsoleService $service,
        private readonly SearchConsoleRepository $repository,
        private readonly JobRunner $jobs,
    ) {
    }

    public function step(Job $job): JobProgress
    {
        $idShop = $job->idShop;
        $state = $job->state;

        if ($state === []) {
            $state = $this->init($idShop, $job->payload);
        }

        $deadline = microtime(true) + self::STEP_SECONDS;

        if ($state['phase'] === 'build') {
            $state = $this->build($idShop, $state, $job->payload, $deadline);
        }

        if ($state['phase'] === 'submit' && microtime(true) < $deadline) {
            $state = $this->runItem($state, 'submit', function () use ($idShop, $state): void {
                // Sklep bez adresow do mapy (np. bez produktow i jezykow) nie ma pliku indeksu —
                // nie ma czego zglaszac i to nie jest blad zadania (fala 2, agent I).
                if ((int) ($state['urls'] ?? 0) === 0 && $this->service->mode($idShop) === 'module') {
                    return;
                }

                $this->service->submit($idShop);
            });
        }

        if ($state['phase'] === 'import' && microtime(true) < $deadline) {
            $state = $this->runItem($state, 'import', function () use ($idShop): void {
                $this->jobs->start($idShop, GscImportJob::TYPE, [
                    'from' => gmdate('Y-m-d', Clock::time() - SearchConsoleService::HISTORY_DAYS * 86400),
                    'to' => gmdate('Y-m-d', Clock::time() - 86400),
                ]);
            });
        }

        $finished = $state['phase'] === 'done';
        $urls = (int) ($state['urls'] ?? 0);

        return new JobProgress(
            $finished ? max(1, (int) $state['total']) : min((int) $state['done'], max(1, (int) $state['total']) - 1),
            max(1, (int) $state['total']),
            $finished ? 'Sitemap rebuilt — ' . $urls . ' addresses' : $this->message($state),
            $finished,
            0,
            $state,
            $finished ? ['urls' => $urls, 'skipped' => (array) ($state['skipped'] ?? [])] : [],
        );
    }

    /**
     * @param array<string,mixed> $payload
     *
     * @return array<string,mixed>
     */
    private function init(int $idShop, array $payload): array
    {
        $module = $this->service->mode($idShop) === 'module';
        $include = $this->service->include($idShop);
        $kinds = $module ? SitemapSource::kindsFor($include, SitemapSource::blogDetected($idShop)) : [];
        $total = 0;

        foreach ($kinds as $kind) {
            $total += $this->source->count($kind, $idShop);
        }

        // Kroki pod paskiem postepu — teksty 1:1 z prototypu (`taskLines('gsc')`).
        $items = [];
        $items['build'] = ['text' => $module ? 'Generating sitemap for all shop languages' : 'Using the existing sitemap', 'st' => $module ? 'run' : 'ok'];

        if (!empty($payload['submit'])) {
            $items['submit'] = ['text' => 'Submitting sitemap to Search Console', 'st' => 'wait'];
        }

        if (!empty($payload['import'])) {
            $items['import'] = ['text' => 'Importing 16 months of search data — continues in the background', 'st' => 'wait'];
        }

        if ($module) {
            SitemapFiles::resetBuild($idShop);
        }

        $phase = $module ? 'build' : $this->after('build', $items);

        return [
            'phase' => $phase,
            'kinds' => $kinds,
            'ki' => 0,
            'cursor' => 0,
            'writer' => [],
            'skipped' => [],
            'total' => $total + 1,
            'done' => 0,
            'urls' => 0,
            'keys' => array_keys($items),
            'items' => $this->visible($items, $phase),
            'raw' => $items,
        ];
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $payload
     *
     * @return array<string,mixed>
     */
    private function build(int $idShop, array $state, array $payload, float $deadline): array
    {
        $maxUrls = max(1, min(SitemapWriter::MAX_URLS, (int) ($payload['max_urls'] ?? SitemapWriter::MAX_URLS)));
        $writer = SitemapWriter::fromState(SitemapFiles::buildDir($idShop), (array) $state['writer'], $maxUrls);
        $langs = SitemapSource::languages($idShop);
        $include = $this->service->include($idShop);

        while ($state['ki'] < count($state['kinds']) && microtime(true) < $deadline) {
            $kind = (string) $state['kinds'][$state['ki']];
            $chunk = $this->source->chunk($kind, $idShop, $langs, (int) $state['cursor'], self::CHUNK, $include);

            if ($chunk['count'] === 0) {
                ++$state['ki'];
                $state['cursor'] = 0;

                continue;
            }

            foreach ($chunk['items'] as $item) {
                self::write($writer, SitemapSource::FILE_KIND[$kind], $item['urls'], $langs, $item['xdefault'] ?? null);
            }

            foreach ($chunk['skipped'] as $reason => $n) {
                $state['skipped'][$reason] = (int) ($state['skipped'][$reason] ?? 0) + (int) $n;
            }

            $state['cursor'] = (int) $chunk['last'];
            $state['done'] = (int) $state['done'] + (int) $chunk['count'];
        }

        $state['writer'] = $writer->state();
        $state['urls'] = $writer->urlCount();

        if ($state['ki'] < count($state['kinds'])) {
            return $state;
        }

        $this->publish($idShop, $writer->finish(), (array) $state['skipped']);

        $state['writer'] = [];
        $state['phase'] = $this->after('build', (array) $state['raw']);
        $state['raw']['build']['st'] = 'ok';
        $state['items'] = $this->visible((array) $state['raw'], $state['phase'] === 'done' ? null : (string) $state['phase']);

        return $state;
    }

    /**
     * Zapis jednej encji do plikow jej jezykow: kazda wersja dostaje komplet `xhtml:link`.
     * `$xDefault` (strona glowna, decyzja #22) dochodzi do kompletu jako `hreflang="x-default"`
     * — tylko przy co najmniej dwoch wersjach (jedna wersja nie ma alternatyw).
     *
     * @param array<int,array{loc:string,lastmod:?string,images:string[],hreflang:string}> $urls jezyk => adres
     * @param array<int,array{id_lang:int,iso:string,code:string}> $langs
     */
    public static function write(SitemapWriter $writer, string $fileKind, array $urls, array $langs, ?string $xDefault = null): void
    {
        $alternates = [];

        foreach ($urls as $url) {
            $alternates[$url['hreflang']] = $url['loc'];
        }

        if ($xDefault !== null && $xDefault !== '' && count($alternates) > 1) {
            $alternates['x-default'] = $xDefault;
        }

        foreach ($urls as $idLang => $url) {
            $writer->add($fileKind, (int) $idLang, (string) ($langs[$idLang]['iso'] ?? 'xx'), [
                'loc' => $url['loc'],
                'lastmod' => $url['lastmod'],
                'images' => $url['images'],
                'alternates' => $alternates,
            ]);
        }
    }

    /**
     * @param array<int,array{filename:string,kind:string,iso:string,id_lang:int,url_count:int,bytes:int,lastmod:string}> $files
     * @param array<string,int> $skipped
     */
    private function publish(int $idShop, array $files, array $skipped): void
    {
        $names = [];
        $rows = [];

        if ($files !== []) {
            $entries = [];

            foreach ($files as $file) {
                $entries[] = ['loc' => $this->service->fileUrl($idShop, $file['filename']), 'lastmod' => $file['lastmod'] ?: null];
                $names[] = $file['filename'];
                $rows[] = $file;
            }

            $index = SitemapXml::index($entries);
            file_put_contents(SitemapFiles::buildDir($idShop) . SitemapFiles::INDEX, $index);
            $names[] = SitemapFiles::INDEX;
            array_unshift($rows, [
                'filename' => SitemapFiles::INDEX, 'kind' => 'index', 'id_lang' => 0,
                'url_count' => array_sum(array_column($files, 'url_count')), 'bytes' => strlen($index),
            ]);
        }

        SitemapFiles::publish($idShop, $names);
        $this->repository->replaceFiles($idShop, $rows);

        Options::set(SearchConsoleOptions::SM_LAST_BUILD, Clock::now(), $idShop);
        Options::set(SearchConsoleOptions::SM_SKIPPED, $skipped, $idShop);

        Log::write($idShop, SearchConsoleApi::SERVICE, Log::INFO, 'Sitemap rebuilt.', [
            'files' => count($files),
            'urls' => array_sum(array_column($files, 'url_count')),
            'skipped' => $skipped,
        ]);
    }

    /**
     * Wykonuje krok listy (zgloszenie, start importu). Blad zgloszenia konczy zadanie
     * bledem z komunikatem — mapa jest juz opublikowana, wiec „Try again” tylko ja odbuduje i zglosi.
     *
     * @param array<string,mixed> $state
     *
     * @return array<string,mixed>
     */
    private function runItem(array $state, string $key, callable $fn): array
    {
        $fn();

        $state['raw'][$key]['st'] = 'ok';
        $state['phase'] = $this->after($key, (array) $state['raw']);
        $state['done'] = (int) $state['total'];
        $state['items'] = $this->visible((array) $state['raw'], $state['phase'] === 'done' ? null : (string) $state['phase']);

        return $state;
    }

    /** @param array<string,array{text:string,st:string}> $items */
    private function after(string $key, array $items): string
    {
        $keys = array_keys($items);
        $pos = array_search($key, $keys, true);

        return $keys[$pos === false ? count($keys) : $pos + 1] ?? 'done';
    }

    /**
     * @param array<string,array{text:string,st:string}> $items
     *
     * @return array<int,array{text:string,st:string}>
     */
    private function visible(array $items, ?string $running): array
    {
        $out = [];

        foreach ($items as $key => $item) {
            $st = $item['st'] === 'ok' ? 'ok' : ($key === $running ? 'run' : 'wait');
            $out[] = ['text' => $item['text'], 'st' => $st];
        }

        return $out;
    }

    /** @param array<string,mixed> $state */
    private function message(array $state): string
    {
        return match ((string) $state['phase']) {
            'submit' => 'Submitting sitemap to Search Console',
            'import' => 'Starting the search data import',
            default => 'Generating sitemap — ' . (int) ($state['urls'] ?? 0) . ' addresses so far',
        };
    }
}
