<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\MerchantTools;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Db;
use Quince\Insight\Core\Schema as CoreSchema;

/**
 * Taksonomia produktow Google (tabela `google_taxonomy`, ARCHITEKTURA §7.7).
 *
 * Dane: oficjalne pliki Google `taxonomy-with-ids.<locale>.txt` w `data/google_taxonomy/`
 * (adres podany na https://support.google.com/merchants/answer/6324436 —
 * https://www.google.com/basepages/producttype/taxonomy-with-ids.en-US.txt). Format:
 * pierwsza linia `# Google_Product_Taxonomy_Version: …`, dalej `ID - Kategoria > Podkategoria`.
 * Plik jest w module, a nie pobierany z sieci przy instalacji (FeedForge wymagal `allow_url_fopen`).
 * Tabela ladowana przy instalacji i leniwie przy pierwszym wyszukiwaniu w danym jezyku.
 */
final class Taxonomy
{
    /** Jezyk feedu (ISO 639-1) => plik Google. Jezyk bez pliku → angielski. */
    public const LOCALES = [
        'en' => 'en-US', 'pl' => 'pl-PL', 'de' => 'de-DE', 'fr' => 'fr-FR', 'es' => 'es-ES',
        'it' => 'it-IT', 'nl' => 'nl-NL', 'pt' => 'pt-BR', 'cs' => 'cs-CZ', 'sk' => 'sk-SK',
    ];

    public const FALLBACK = 'en-US';

    private const BATCH = 500;

    /** @var array<string,bool> */
    private array $loaded = [];

    /** Plik i wiersz tabeli dla jezyka (np. `pl` albo `pl-PL`). */
    public static function locale(string $lang): string
    {
        $lang = strtolower(substr(trim($lang), 0, 2));

        return self::LOCALES[$lang] ?? self::FALLBACK;
    }

    public static function dataDir(): string
    {
        return dirname(__DIR__, 3) . '/data/google_taxonomy/';
    }

    /**
     * Parsuje tresc pliku Google. Linie z `#` i bez separatora `" - "` sa pomijane.
     *
     * @return array<int,string> google_id => sciezka
     */
    public static function parse(string $content): array
    {
        $out = [];

        foreach (preg_split('/\r\n|\n|\r/', $content) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $pos = strpos($line, ' - ');

            if ($pos === false) {
                continue;
            }

            $id = substr($line, 0, $pos);
            $path = trim(substr($line, $pos + 3));

            if (!ctype_digit($id) || (int) $id <= 0 || $path === '') {
                continue;
            }

            $out[(int) $id] = mb_substr($path, 0, 500);
        }

        return $out;
    }

    /** Wersja taksonomii z naglowka pliku ('' gdy brak). */
    public static function version(string $content): string
    {
        return preg_match('/^#\s*Google_Product_Taxonomy_Version:\s*(\S+)/', $content, $m) ? $m[1] : '';
    }

    /**
     * Wyrazy zapytania (min. 2 znaki) — jak `prepareSearchTerms` w FeedForge, ale pod LIKE
     * (bez FULLTEXT: tabela ma kilka tysiecy wierszy na jezyk, a FULLTEXT nie dziala na kazdym MySQL/InnoDB).
     *
     * @return string[]
     */
    public static function terms(string $query): array
    {
        $words = preg_split('/\s+/u', trim($query)) ?: [];

        return array_values(array_filter(array_map('trim', $words), static fn (string $w): bool => mb_strlen($w) >= 2));
    }

    /** Laduje plik jezyka do tabeli, gdy go tam jeszcze nie ma. Zwraca liczbe wierszy jezyka. */
    public function ensureLoaded(string $locale): int
    {
        $table = CoreSchema::table(Schema::GOOGLE_TAXONOMY);
        $count = (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . $table . '` WHERE `lang` = "' . pSQL($locale) . '"');

        if ($count > 0) {
            $this->loaded[$locale] = true;

            return $count;
        }

        return $this->load($locale);
    }

    /** Wczytuje (od nowa) plik jezyka. Zwraca liczbe wierszy; 0 gdy pliku nie ma. */
    public function load(string $locale): int
    {
        $file = self::dataDir() . 'taxonomy-with-ids.' . basename($locale) . '.txt';

        if (!isset(array_flip(self::LOCALES)[$locale]) || !is_file($file)) {
            return 0;
        }

        $rows = self::parse((string) file_get_contents($file));
        $db = Db::getInstance();
        $table = CoreSchema::table(Schema::GOOGLE_TAXONOMY);

        $db->execute('DELETE FROM `' . $table . '` WHERE `lang` = "' . pSQL($locale) . '"');

        foreach (array_chunk($rows, self::BATCH, true) as $chunk) {
            $values = [];

            foreach ($chunk as $id => $path) {
                $values[] = '(' . (int) $id . ', "' . pSQL($locale) . '", "' . pSQL($path, true) . '")';
            }

            $db->execute('INSERT IGNORE INTO `' . $table . '` (`google_id`, `lang`, `path`) VALUES ' . implode(',', $values));
        }

        $this->loaded[$locale] = true;

        return count($rows);
    }

    /**
     * Wyszukiwanie: po numerze (dokladnie albo prefiks) albo po wszystkich wyrazach w sciezce.
     * Najpierw kategorie, w ktorych ostatni czlon pasuje, potem krotsze sciezki.
     *
     * @return array<int,array{id:int,path:string}>
     */
    public function search(string $query, string $locale, int $limit = 8): array
    {
        $query = trim($query);

        if (mb_strlen($query) < 2) {
            return [];
        }

        $this->ensureLoaded($locale);
        $table = CoreSchema::table(Schema::GOOGLE_TAXONOMY);
        $lang = pSQL($locale);

        if (ctype_digit($query)) {
            $rows = Db::getInstance()->executeS(
                'SELECT `google_id`, `path` FROM `' . $table . '`
                 WHERE `lang` = "' . $lang . '" AND CAST(`google_id` AS CHAR) LIKE "' . pSQL($query) . '%"
                 ORDER BY (`google_id` = ' . (int) $query . ') DESC, `google_id` ASC LIMIT ' . (int) $limit
            ) ?: [];
        } else {
            $terms = self::terms($query);

            if ($terms === []) {
                return [];
            }

            $where = [];
            $last = end($terms);

            foreach ($terms as $term) {
                $where[] = '`path` LIKE "%' . pSQL(addcslashes($term, '%_\\')) . '%"';
            }

            $rows = Db::getInstance()->executeS(
                'SELECT `google_id`, `path` FROM `' . $table . '`
                 WHERE `lang` = "' . $lang . '" AND ' . implode(' AND ', $where) . '
                 ORDER BY (SUBSTRING_INDEX(`path`, " > ", -1) LIKE "%' . pSQL(addcslashes((string) $last, '%_\\')) . '%") DESC,
                          CHAR_LENGTH(`path`) ASC
                 LIMIT ' . (int) $limit
            ) ?: [];
        }

        return array_map(static fn (array $r): array => ['id' => (int) $r['google_id'], 'path' => (string) $r['path']], $rows);
    }

    /**
     * Sciezki dla listy id (z angielskim jako zapasem).
     *
     * @param int[] $ids
     *
     * @return array<int,string>
     */
    public function paths(array $ids, string $locale): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if ($ids === []) {
            return [];
        }

        $this->ensureLoaded($locale);
        $table = CoreSchema::table(Schema::GOOGLE_TAXONOMY);
        $out = [];

        foreach ([$locale, self::FALLBACK] as $lang) {
            $missing = array_values(array_diff($ids, array_keys($out)));

            if ($missing === []) {
                break;
            }

            if ($lang === self::FALLBACK && $lang !== $locale) {
                $this->ensureLoaded($lang);
            }

            foreach (Db::getInstance()->executeS(
                'SELECT `google_id`, `path` FROM `' . $table . '` WHERE `lang` = "' . pSQL($lang) . '"
                 AND `google_id` IN (' . implode(',', $missing) . ')'
            ) ?: [] as $row) {
                $out[(int) $row['google_id']] = (string) $row['path'];
            }
        }

        return $out;
    }

    /** Czy id istnieje w taksonomii (walidacja zapisu mapowania). */
    public function exists(int $googleId): bool
    {
        if ($googleId <= 0) {
            return false;
        }

        $this->ensureLoaded(self::FALLBACK);

        return (bool) Db::getInstance()->getValue(
            'SELECT 1 FROM `' . CoreSchema::table(Schema::GOOGLE_TAXONOMY) . '`
             WHERE `google_id` = ' . $googleId . ' AND `lang` = "' . pSQL(self::FALLBACK) . '"'
        );
    }

    /** Laduje wszystkie dostarczone pliki (instalacja). */
    public function loadAll(): int
    {
        $n = 0;

        foreach (self::LOCALES as $locale) {
            $n += $this->ensureLoaded($locale);
        }

        return $n;
    }
}
