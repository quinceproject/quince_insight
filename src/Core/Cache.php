<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Core;

use Db;

/**
 * Pamiec podreczna odpowiedzi Google (tabela `cache`), per sklep.
 *
 * Klucze z prefiksem uslugi: `ga4.overview.28`, `gsc.summary.28`, `gmc.report.<feed>.28`, `sd.scan`.
 * Wiersz po wygasnieciu zostaje w tabeli — `stale()` pozwala pokazac ostatnie znane dane,
 * gdy Google nie odpowiada. Sprzata je dopiero retencja (30 dni po wygasnieciu).
 */
final class Cache
{
    /**
     * @return array<mixed>|null null gdy brak lub wygaslo
     */
    public function get(int $idShop, string $key): ?array
    {
        $row = $this->row($idShop, $key);

        if ($row === null || $row['expires_at'] <= Clock::now()) {
            return null;
        }

        return $row['payload'];
    }

    /**
     * @param array<mixed> $value
     * @param int $ttlSeconds 0 = wpis od razu "przeterminowany" (czytany tylko przez stale())
     */
    public function set(int $idShop, string $key, array $value, int $ttlSeconds): void
    {
        $now = Clock::now();

        Db::getInstance()->execute(
            'INSERT INTO `' . Schema::table(Schema::CACHE) . '` (`id_shop`, `cache_key`, `payload`, `expires_at`, `date_upd`)
             VALUES (' . $idShop . ', "' . pSQL($this->key($key)) . '", "'
            . pSQL((string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), true)
            . '", "' . pSQL(Clock::at(max(0, $ttlSeconds))) . '", "' . pSQL($now) . '")
             ON DUPLICATE KEY UPDATE `payload` = VALUES(`payload`), `expires_at` = VALUES(`expires_at`), `date_upd` = VALUES(`date_upd`)'
        );
    }

    /**
     * Zwraca wartosc z pamieci albo liczy ja i zapisuje.
     *
     * Wyjatek z $loader przechodzi dalej — to wolajacy decyduje, czy pokazac `stale()`.
     *
     * @param callable():array<mixed> $loader
     *
     * @return array<mixed>
     */
    public function remember(int $idShop, string $key, int $ttlSeconds, callable $loader): array
    {
        $cached = $this->get($idShop, $key);

        if ($cached !== null) {
            return $cached;
        }

        $value = $loader();
        $this->set($idShop, $key, $value, $ttlSeconds);

        return $value;
    }

    /** Usuwa wpisy sklepu o kluczach zaczynajacych sie od prefiksu ('' = wszystkie wpisy sklepu). */
    public function forget(int $idShop, string $keyPrefix): void
    {
        $where = '`id_shop` = ' . $idShop;

        if ($keyPrefix !== '') {
            // Znaki specjalne LIKE w prefiksie maja znaczyc samych siebie.
            $where .= ' AND `cache_key` LIKE "' . pSQL(addcslashes($keyPrefix, '%_\\')) . '%"';
        }

        Db::getInstance()->delete(Schema::name(Schema::CACHE), $where);
    }

    /**
     * Ostatnia wartosc nawet po wygasnieciu — do pokazania danych, gdy Google nie odpowiada.
     *
     * @return array<mixed>|null
     */
    public function stale(int $idShop, string $key): ?array
    {
        return $this->row($idShop, $key)['payload'] ?? null;
    }

    /** Kiedy wpis zapisano (do podpisu "Updated ..."); null gdy wpisu nie ma. */
    public function updatedAt(int $idShop, string $key): ?string
    {
        return $this->row($idShop, $key)['date_upd'] ?? null;
    }

    /**
     * @return array{payload:array<mixed>,expires_at:string,date_upd:string}|null
     */
    private function row(int $idShop, string $key): ?array
    {
        $row = Db::getInstance()->getRow(
            'SELECT `payload`, `expires_at`, `date_upd` FROM `' . Schema::table(Schema::CACHE) . '`
             WHERE `id_shop` = ' . $idShop . ' AND `cache_key` = "' . pSQL($this->key($key)) . '"'
        );

        if (!is_array($row)) {
            return null;
        }

        $payload = json_decode((string) $row['payload'], true);

        if (!is_array($payload)) {
            return null;
        }

        return ['payload' => $payload, 'expires_at' => (string) $row['expires_at'], 'date_upd' => (string) $row['date_upd']];
    }

    private function key(string $key): string
    {
        // Kolumna ma 120 znakow; dluzszy klucz skracamy z dopisanym skrotem, zeby dwa
        // rozne dlugie klucze nie wskazaly tego samego wiersza.
        return strlen($key) <= 120 ? $key : substr($key, 0, 87) . '.' . md5($key);
    }
}
