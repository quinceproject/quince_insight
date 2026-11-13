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

use Quince\Insight\Core\Cache;
use Quince\Insight\Core\Options;

/**
 * Ustawienia danych strukturalnych sklepu (ARCHITEKTURA §7.10) i wynik ostatniego skanu.
 *
 * Domyslnie wszystko wylaczone — bez zapisu usera modul nie wypisuje nic (SPEC §5.9).
 * Typ, ktory skan znalazl na stronach (motyw albo inny modul), jest zablokowany, dopoki
 * user nie kliknie "Add anyway" (`_SD_FORCE`). Wyjatek (decyzja #18): "Product variants" przy
 * Product z motywu / innego modulu na stronie produktu — bez "Add anyway" (`Types::NO_FORCE`).
 */
final class Settings
{
    public const SCAN_KEY = 'sd.scan';

    /** Wynik skanu nie wygasa — czytany przez stale(); 10 lat, zeby retencja pamieci go nie usunela. */
    public const SCAN_TTL = 315360000;

    public function __construct(private readonly Cache $cache)
    {
    }

    /** @return array<string,bool> */
    public function on(int $idShop): array
    {
        return Types::normalize(Options::json(StructuredDataOptions::ON, [], $idShop));
    }

    /** @return array<string,bool> */
    public function force(int $idShop): array
    {
        return Types::normalize(Options::json(StructuredDataOptions::FORCE, [], $idShop));
    }

    /**
     * Ostatni skan: pages[] (kind, label, url, status, found[[typ, zrodlo]], blocks), present, themeTypes.
     *
     * @return array<string,mixed>|null
     */
    public function scan(int $idShop): ?array
    {
        return $this->cache->stale($idShop, self::SCAN_KEY);
    }

    /** @param array<string,mixed> $scan */
    public function saveScan(int $idShop, array $scan, string $at): void
    {
        $this->cache->set($idShop, self::SCAN_KEY, $scan, self::SCAN_TTL);
        Options::set(StructuredDataOptions::SCANNED_AT, $at, $idShop);
    }

    /**
     * Zapis z kontrola blokad. Zwraca liste typow odrzuconych (zablokowanych bez "Add anyway").
     *
     * @param array<mixed> $on
     * @param array<mixed> $force
     * @param array<string,string> $present typy juz obecne na stronach (z skanu)
     *
     * @return string[]
     */
    public function save(int $idShop, array $on, array $force, array $present): array
    {
        $on = Types::normalize($on);
        $force = Types::normalize($force);

        foreach (Types::NO_FORCE as $key) {
            $force[$key] = false;
        }

        $rejected = self::rejected($on, $force, $present);

        if ($rejected !== []) {
            return $rejected;
        }

        Options::set(StructuredDataOptions::ON, $on, $idShop);
        Options::set(StructuredDataOptions::FORCE, array_filter($force), $idShop);

        return [];
    }

    /**
     * Typy wlaczone mimo blokady (obecne na stronie, bez "Add anyway").
     *
     * @param array<string,bool> $on
     * @param array<string,bool> $force
     * @param array<string,string> $present
     *
     * @return string[]
     */
    public static function rejected(array $on, array $force, array $present): array
    {
        $out = [];

        foreach ($on as $key => $enabled) {
            if ($enabled && isset($present[$key]) && (empty($force[$key]) || in_array($key, Types::NO_FORCE, true))) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /**
     * Typy wlaczone, ktore skan znalazl tez z innego zrodla — Google dostaje je dwa razy.
     *
     * @param array<string,bool> $on
     * @param array<string,string> $present
     *
     * @return string[]
     */
    public static function duplicates(array $on, array $present): array
    {
        $out = [];

        foreach ($on as $key => $enabled) {
            if ($enabled && isset($present[$key])) {
                $out[] = $key;
            }
        }

        return $out;
    }
}
