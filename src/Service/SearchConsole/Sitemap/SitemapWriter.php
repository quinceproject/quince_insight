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

/**
 * Zapis plikow mapy porcjami, z podzialem i wznowieniem miedzy krokami zadania.
 *
 * Strumien = rodzaj + jezyk (np. `products` + `en`). Kazdy strumien dopisuje adresy do
 * biezacej czesci; po `$maxUrls` adresach albo po przekroczeniu `$maxBytes` zamyka czesc
 * i zaczyna nastepna (`qi-sitemap-products-en-2.xml`). Stan (`state()`) jest zwyklym
 * JSON-em — zadanie w tle zapisuje go po kazdym kroku, a nastepny krok tworzy pisarza
 * z tego stanu (`fromState()`) i dopisuje dalej do tych samych plikow.
 *
 * Limity Google (research/05 §I): 50 000 adresow albo 50 MB bez kompresji na plik.
 * Bajty liczymy z zapasem na zamkniecie `</urlset>`.
 */
final class SitemapWriter
{
    public const MAX_URLS = 50000;

    /** 50 MB minus zapas na zamkniecie i ostatni wpis. */
    public const MAX_BYTES = 52428800 - 65536;

    /** @var array<string,array{kind:string,iso:string,id_lang:int,part:int,count:int,bytes:int,lastmod:string,files:array<int,array<string,mixed>>}> */
    private array $streams = [];

    public function __construct(
        private string $dir = '',
        private int $maxUrls = self::MAX_URLS,
        private int $maxBytes = self::MAX_BYTES,
    ) {
        $this->dir = rtrim($dir, '/\\') . '/';
        $this->maxUrls = max(1, $maxUrls);
        $this->maxBytes = max(1024, $maxBytes);
    }

    /**
     * @param array<string,mixed> $state wynik `state()` z poprzedniego kroku
     */
    public static function fromState(string $dir, array $state, int $maxUrls = self::MAX_URLS, int $maxBytes = self::MAX_BYTES): self
    {
        $writer = new self($dir, $maxUrls, $maxBytes);
        $writer->streams = is_array($state['streams'] ?? null) ? $state['streams'] : [];

        return $writer;
    }

    /** @return array<string,mixed> */
    public function state(): array
    {
        return ['streams' => $this->streams];
    }

    /** Nazwa pliku czesci: `qi-sitemap-<kind>-<iso>[-<n>].xml` (ARCHITEKTURA §7.8). */
    public static function filename(string $kind, string $iso, int $part = 1): string
    {
        return 'qi-sitemap-' . $kind . '-' . $iso . ($part > 1 ? '-' . $part : '') . '.xml';
    }

    /**
     * Dopisuje adres do strumienia rodzaju i jezyka.
     *
     * @param array{loc:string,lastmod?:?string,images?:string[],alternates?:array<string,string>} $url
     */
    public function add(string $kind, int $idLang, string $iso, array $url): void
    {
        $key = $kind . '|' . $iso;
        $entry = SitemapXml::url($url);
        $size = strlen($entry);

        if (!isset($this->streams[$key])) {
            $this->streams[$key] = [
                'kind' => $kind, 'iso' => $iso, 'id_lang' => $idLang,
                'part' => 0, 'count' => 0, 'bytes' => 0, 'lastmod' => '', 'files' => [],
            ];
        }

        $stream = &$this->streams[$key];

        if ($stream['part'] === 0 || $stream['count'] >= $this->maxUrls || $stream['bytes'] + $size > $this->maxBytes) {
            if ($stream['part'] > 0) {
                $this->closePart($stream);
            }

            ++$stream['part'];
            $stream['count'] = 0;
            $stream['lastmod'] = '';
            $open = SitemapXml::urlsetOpen();
            file_put_contents($this->dir . self::filename($kind, $iso, $stream['part']), $open);
            $stream['bytes'] = strlen($open);
        }

        file_put_contents($this->dir . self::filename($kind, $iso, $stream['part']), $entry, FILE_APPEND);
        ++$stream['count'];
        $stream['bytes'] += $size;

        $lastmod = SitemapXml::date($url['lastmod'] ?? null);

        if ($lastmod > $stream['lastmod']) {
            $stream['lastmod'] = $lastmod;
        }

        unset($stream);
    }

    /**
     * Zamyka wszystkie otwarte czesci. Zwraca liste gotowych plikow w kolejnosci strumieni.
     *
     * @return array<int,array{filename:string,kind:string,iso:string,id_lang:int,url_count:int,bytes:int,lastmod:string}>
     */
    public function finish(): array
    {
        $out = [];

        foreach ($this->streams as &$stream) {
            if ($stream['part'] > 0 && $stream['count'] > 0) {
                $this->closePart($stream);
                $stream['part'] = -abs($stream['part']);
            }

            foreach ($stream['files'] as $file) {
                $out[] = $file;
            }
        }

        unset($stream);

        return $out;
    }

    /** Laczna liczba adresow zapisanych do tej pory. */
    public function urlCount(): int
    {
        $n = 0;

        foreach ($this->streams as $stream) {
            $n += $stream['count'];

            foreach ($stream['files'] as $file) {
                $n += (int) $file['url_count'];
            }
        }

        return $n;
    }

    /** @param array<string,mixed> $stream */
    private function closePart(array &$stream): void
    {
        $name = self::filename($stream['kind'], $stream['iso'], (int) $stream['part']);
        $close = SitemapXml::urlsetClose();
        file_put_contents($this->dir . $name, $close, FILE_APPEND);

        $stream['files'][] = [
            'filename' => $name,
            'kind' => $stream['kind'],
            'iso' => $stream['iso'],
            'id_lang' => (int) $stream['id_lang'],
            'url_count' => (int) $stream['count'],
            'bytes' => (int) $stream['bytes'] + strlen($close),
            'lastmod' => (string) $stream['lastmod'],
        ];

        $stream['count'] = 0;
        $stream['bytes'] = 0;
    }
}
