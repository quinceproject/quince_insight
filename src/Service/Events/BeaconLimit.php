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

if (!defined('_PS_VERSION_')) {
    exit;
}

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
    // 10 zgloszen na sekunde na sklep: zdarzenia koszyka i kasy, nie odslony, wiec zapas jest duzy.
    public const PER_SHOP = 600;

    /** Najwiekszy zapis zdarzenia z przegladarki w dzienniku (bajty JSON). */
    public const MAX_PAYLOAD = 2000;

    /** Pola `ecommerce`, ktore wysyla skrypt frontu (`views/js/front/events.js`). */
    private const ECOMMERCE_FIELDS = ['currency', 'value', 'shipping_tier', 'payment_type', 'item_list_id', 'item_list_name'];

    /** Pola pozycji: szablony z PHP (`Ecommerce`) i pozycje z odpowiedzi koszyka. */
    private const ITEM_FIELDS = [
        'item_id', 'item_name', 'item_brand', 'item_variant', 'item_list_id', 'item_list_name',
        'item_category', 'item_category2', 'item_category3', 'item_category4', 'item_category5',
        'price', 'quantity', 'index', 'discount', 'coupon',
    ];
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
     * Tresc zgloszenia do dziennika: tylko znane pola ecommerce, krotkie napisy, najwyzej
     * `MAX_PAYLOAD` bajtow. Adres jest publiczny, wiec reszta tresci od klienta odpada.
     *
     * @param array<mixed> $data pole `d` zgloszenia: {event, ecommerce}
     *
     * @return array{event:string, ecommerce:array<string,mixed>}
     */
    public static function cleanPayload(string $event, array $data): array
    {
        $source = is_array($data['ecommerce'] ?? null) ? $data['ecommerce'] : [];
        $ecommerce = self::pick($source, self::ECOMMERCE_FIELDS);
        $items = [];

        foreach (array_slice(is_array($source['items'] ?? null) ? array_values($source['items']) : [], 0, 50) as $item) {
            if (is_array($item)) {
                $items[] = self::pick($item, self::ITEM_FIELDS);
            }
        }

        if ($items !== []) {
            $ecommerce['items'] = $items;
        }

        $out = ['event' => $event, 'ecommerce' => $ecommerce];

        // Za duzo pozycji: odcinamy od konca, zeby zapis zostal czytelnym JSON-em.
        while (strlen((string) json_encode($out)) > self::MAX_PAYLOAD && !empty($out['ecommerce']['items'])) {
            array_pop($out['ecommerce']['items']);
        }

        return $out;
    }

    /**
     * @param array<mixed> $source
     * @param string[] $fields
     *
     * @return array<string,mixed> wybrane pola: liczby jako liczby, reszta jako napis do 100 znakow
     */
    private static function pick(array $source, array $fields): array
    {
        $out = [];

        foreach ($fields as $field) {
            if (!array_key_exists($field, $source)) {
                continue;
            }

            $value = $source[$field];

            if (is_int($value) || is_float($value)) {
                $out[$field] = $value;
            } elseif (is_string($value) || is_bool($value)) {
                $out[$field] = mb_substr((string) $value, 0, 100);
            }
        }

        return $out;
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
