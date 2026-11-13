<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\Events;

/**
 * Czysta logika ograniczen publicznego adresu `event` (R-05) — bez bazy, testowana w PHPUnit.
 *
 *  - licznik w oknie stalym (60 s, atomowy w SQL): osobno na „kubelek” adresu IP i na caly sklep;
 *  - lista innych kontenerow GTM: tylko dopisywanie, zapis najwyzej raz na 30 minut;
 *    pusta lista niczego nie kasuje — ostrzezenie gasnie samo po 7 dniach bez potwierdzenia
 *    (`Events\Module::attention`).
 */
final class BeaconLimit
{
    public const WINDOW = 60;
    public const PER_IP = 60;
    public const PER_SHOP = 3000;
    public const OTHER_GTM_EVERY = 1800;
    public const OTHER_GTM_MAX_AGE = 7 * 86400;

    /**
     * Kubelek adresu IP (65 536 mozliwych) — liczba wierszy licznika jest ograniczona, a sam
     * adres nie trafia do bazy.
     */
    public static function bucket(string $ip): string
    {
        return substr(hash('sha256', 'qi.beacon.' . $ip), 0, 4);
    }

    /**
     * Jedno zgloszenie w liczniku okna stalego — jedno zapytanie, atomowe w MySQL, wiec rownolegle
     * zadania nie gubia zliczen (odczyt-zapis w PHP przepuszczal serie rownoleglych POST-ow).
     *
     * Wiersz w tabeli `cache` (klucz `ev.beacon.*`, tresc = sama liczba, koniec okna =
     * `expires_at`). Po koncu okna licznik startuje od 1. Przy ON DUPLICATE KEY UPDATE MySQL
     * przypisuje kolumny od lewej, wiec `payload` widzi jeszcze stare `expires_at`.
     */
    public static function hitSql(string $table, int $idShop, string $key, string $now, string $windowEnd): string
    {
        $k = addslashes($key);
        $n = addslashes($now);
        $e = addslashes($windowEnd);

        return 'INSERT INTO `' . $table . '` (`id_shop`, `cache_key`, `payload`, `expires_at`, `date_upd`)'
            . ' VALUES (' . $idShop . ', "' . $k . '", "1", "' . $e . '", "' . $n . '")'
            . ' ON DUPLICATE KEY UPDATE'
            . ' `payload` = IF(`expires_at` <= "' . $n . '", "1", CAST(CAST(`payload` AS UNSIGNED) + 1 AS CHAR)),'
            . ' `expires_at` = IF(`expires_at` <= "' . $n . '", "' . $e . '", `expires_at`),'
            . ' `date_upd` = "' . $n . '"';
    }

    public static function allowed(int $count, int $limit): bool
    {
        return $count <= $limit;
    }

    /**
     * Nowa wartosc opcji `EV_OTHER_GTM` albo null, gdy nic nie zapisujemy.
     *
     * @param array<mixed> $current zapisana wartosc {ids, at}
     * @param array<int,string> $reported zgloszone, juz zwalidowane identyfikatory
     * @param int|null $age ile sekund temu zapisano $current (null = nigdy)
     *
     * @return array{ids:array<int,string>, at:string}|null
     */
    public static function otherGtm(array $current, array $reported, ?int $age, string $now): ?array
    {
        if ($age !== null && $age >= 0 && $age < self::OTHER_GTM_EVERY) {
            return null;
        }

        $reported = array_values(array_unique($reported));

        if ($reported === []) {
            return null;
        }

        $known = $age !== null && $age < self::OTHER_GTM_MAX_AGE ? array_map('strval', (array) ($current['ids'] ?? [])) : [];

        return ['ids' => array_slice(array_values(array_unique(array_merge($known, $reported))), 0, 5), 'at' => $now];
    }
}
