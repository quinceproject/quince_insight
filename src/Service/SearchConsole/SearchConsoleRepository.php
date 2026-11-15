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

if (!defined('_PS_VERSION_')) {
    exit;
}

use Db;
use Quince\Insight\Core\Clock;
use Quince\Insight\Core\Schema as CoreSchema;

/**
 * Tabele uslugi: `sitemap_file`, `gsc_daily` (ARCHITEKTURA §7.8).
 */
final class SearchConsoleRepository
{
    /** @return array<int,array<string,mixed>> pliki sklepu: indeks pierwszy, potem rodzaj, jezyk i numer czesci */
    public function files(int $idShop): array
    {
        return Db::getInstance()->executeS(
            'SELECT * FROM `' . CoreSchema::table(Schema::SITEMAP_FILE) . '`
             WHERE `id_shop` = ' . $idShop . '
             ORDER BY (`kind` = "index") DESC, FIELD(`kind`, "products", "categories", "cms", "brands", "suppliers", "blog"),
                      `id_lang` ASC, LENGTH(`filename`) ASC, `filename` ASC'
        ) ?: [];
    }

    /** Czy plik jest na liscie sklepu — kontroler frontu serwuje tylko takie. */
    public function hasFile(int $idShop, string $filename): bool
    {
        return (bool) Db::getInstance()->getValue(
            'SELECT 1 FROM `' . CoreSchema::table(Schema::SITEMAP_FILE) . '`
             WHERE `id_shop` = ' . $idShop . ' AND `filename` = "' . pSQL($filename) . '"'
        );
    }

    /**
     * Zastepuje liste plikow sklepu wynikiem przebudowy. Zachowuje stan zgloszenia i stan
     * z Google dla plikow o tej samej nazwie (Google zna je pod tym samym adresem).
     *
     * @param array<int,array{filename:string,kind:string,id_lang:int,url_count:int,bytes:int}> $files
     */
    public function replaceFiles(int $idShop, array $files): void
    {
        $db = Db::getInstance();
        $table = CoreSchema::name(Schema::SITEMAP_FILE);
        $old = [];

        foreach ($this->files($idShop) as $row) {
            $old[(string) $row['filename']] = $row;
        }

        $now = pSQL(Clock::now());
        $keep = [];

        foreach ($files as $file) {
            $name = (string) $file['filename'];
            $keep[] = '"' . pSQL($name) . '"';
            $fields = [
                'kind' => pSQL((string) $file['kind']),
                'id_lang' => (int) $file['id_lang'],
                'url_count' => (int) $file['url_count'],
                'bytes' => (int) $file['bytes'],
                'generated_at' => $now,
            ];

            if (isset($old[$name])) {
                $db->update($table, $fields, '`id_sitemap_file` = ' . (int) $old[$name]['id_sitemap_file']);
            } else {
                $db->insert($table, $fields + ['id_shop' => $idShop, 'filename' => pSQL($name), 'google_state' => '']);
            }
        }

        $db->delete($table, '`id_shop` = ' . $idShop . ($keep ? ' AND `filename` NOT IN (' . implode(',', $keep) . ')' : ''));
    }

    public function markSubmitted(int $idShop, string $filename): void
    {
        Db::getInstance()->update(CoreSchema::name(Schema::SITEMAP_FILE), ['submitted_at' => pSQL(Clock::now())],
            '`id_shop` = ' . $idShop . ' AND `filename` = "' . pSQL($filename) . '"');
    }

    /** @param array{state:string,errors:int,warnings:int} $state */
    public function saveGoogleState(int $idShop, string $filename, array $state): void
    {
        Db::getInstance()->update(CoreSchema::name(Schema::SITEMAP_FILE), [
            'google_state' => pSQL(mb_substr($state['state'], 0, 12)),
            'google_errors' => max(0, (int) $state['errors']),
            'google_warnings' => max(0, (int) $state['warnings']),
            'google_checked_at' => pSQL(Clock::now()),
        ], '`id_shop` = ' . $idShop . ' AND `filename` = "' . pSQL($filename) . '"');
    }

    public function clearFiles(int $idShop): void
    {
        Db::getInstance()->delete(CoreSchema::name(Schema::SITEMAP_FILE), '`id_shop` = ' . $idShop);
    }

    /**
     * Zapis wierszy dziennych (nadpisuje dni z odpowiedzi — dane `final` sie nie zmieniaja,
     * ale ponowny import tego samego dnia nie moze dublowac).
     *
     * @param array<int,array{day:string,clicks:int,impressions:int,position_sum:float}> $rows
     */
    public function saveDaily(int $idShop, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $values = [];

        foreach ($rows as $row) {
            $values[] = '(' . $idShop . ', "' . pSQL($row['day']) . '", ' . (int) $row['clicks'] . ', ' . (int) $row['impressions']
                . ', ' . sprintf('%.6F', (float) $row['position_sum']) . ')';
        }

        // Porcjami — 16 miesiecy to ok. 490 wierszy, ale bez ryzyka zbyt dlugiego zapytania.
        foreach (array_chunk($values, 500) as $chunk) {
            Db::getInstance()->execute(
                'INSERT INTO `' . CoreSchema::table(Schema::GSC_DAILY) . '` (`id_shop`, `day`, `clicks`, `impressions`, `position_sum`)
                 VALUES ' . implode(',', $chunk) . '
                 ON DUPLICATE KEY UPDATE `clicks` = VALUES(`clicks`), `impressions` = VALUES(`impressions`), `position_sum` = VALUES(`position_sum`)'
            );
        }

        return count($rows);
    }

    /** @return array<int,array{day:string,clicks:int,impressions:int,position_sum:float}> */
    public function daily(int $idShop, string $from, string $to): array
    {
        return Db::getInstance()->executeS(
            'SELECT `day`, `clicks`, `impressions`, `position_sum` FROM `' . CoreSchema::table(Schema::GSC_DAILY) . '`
             WHERE `id_shop` = ' . $idShop . ' AND `day` BETWEEN "' . pSQL($from) . '" AND "' . pSQL($to) . '"
             ORDER BY `day` ASC'
        ) ?: [];
    }

    public function lastDay(int $idShop): ?string
    {
        $day = Db::getInstance()->getValue('SELECT MAX(`day`) FROM `' . CoreSchema::table(Schema::GSC_DAILY) . '` WHERE `id_shop` = ' . $idShop);

        return $day ? (string) $day : null;
    }

    public function clearDaily(int $idShop): void
    {
        Db::getInstance()->delete(CoreSchema::name(Schema::GSC_DAILY), '`id_shop` = ' . $idShop);
    }
}
