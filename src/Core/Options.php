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
 * Ustawienia modulu — zawsze dla konkretnego sklepu.
 *
 * Magazynem jest wlasna tabela `quince_insight_setting`, **nie** `Configuration`
 * PrestaShop. Powod: `Configuration::get()` dla sklepu bez wlasnej wartosci oddaje
 * wartosc grupy, a potem ogolna (classes/Configuration.php, metoda `get`), a przy
 * jednym sklepie `updateValue()` zapisuje wszystko jako ogolne. Drugi sklep dodany
 * pozniej odziedziczylby po cichu identyfikatory pierwszego (GTM, Analytics,
 * Merchant Center) i zaczal wysylac dane. Tu dziedziczenia nie ma: brak wiersza
 * = wartosc domyslna podana przy odczycie.
 *
 * Klucze to stale `QUINCE_INSIGHT_*` z klas `*Options` uslug; w tabeli leza bez prefiksu.
 * Wartosci domyslne podaje sie przy odczycie, nie przy instalacji.
 */
final class Options
{
    public const KEY_PREFIX = 'QUINCE_INSIGHT_';

    /** @var array<int,array<string,string>> pamiec w obrebie zadania: sklep => nazwa => wartosc */
    private static array $loaded = [];

    /** Testy jednostkowe: ustawienia tylko w pamieci, bez bazy. */
    private static bool $memoryOnly = false;

    public static function str(string $key, string $default = '', ?int $idShop = null): string
    {
        $value = self::raw($key, $idShop);

        return $value ?? $default;
    }

    public static function int(string $key, int $default = 0, ?int $idShop = null): int
    {
        $value = self::raw($key, $idShop);

        return $value === null || $value === '' ? $default : (int) $value;
    }

    public static function bool(string $key, bool $default = false, ?int $idShop = null): bool
    {
        $value = self::raw($key, $idShop);

        return $value === null || $value === '' ? $default : (bool) (int) $value;
    }

    /**
     * @param array<mixed> $default
     *
     * @return array<mixed>
     */
    public static function json(string $key, array $default = [], ?int $idShop = null): array
    {
        $value = self::raw($key, $idShop);

        if ($value === null || $value === '') {
            return $default;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : $default;
    }

    /** Sekret w postaci jawnej ('' gdy brak albo nie da sie odszyfrowac). Nigdy do HTML ani JSON. */
    public static function secret(string $key, ?int $idShop = null): string
    {
        $idShop = $idShop ?? ShopContext::currentOrDefault();

        return Crypto::decrypt((string) self::raw($key, $idShop), self::secretContext($idShop, self::name($key)));
    }

    /**
     * Kontekst szyfrowania sekretu: ustawienie w jednym sklepie. `$name` to nazwa
     * zapisana w tabeli (z prefiksem), tak jak ja widzi aktualizacja przepisujaca wartosci.
     */
    public static function secretContext(int $idShop, string $name): string
    {
        return 'setting|' . $idShop . '|' . $name;
    }

    /**
     * @param string|int|bool|array<mixed> $value
     */
    public static function set(string $key, string|int|bool|array $value, ?int $idShop = null): void
    {
        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        } elseif (is_array($value)) {
            $value = (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        self::write($key, (string) $value, $idShop);
    }

    public static function setSecret(string $key, string $plain, ?int $idShop = null): void
    {
        $idShop = $idShop ?? ShopContext::currentOrDefault();

        self::write($key, Crypto::encrypt($plain, self::secretContext($idShop, self::name($key))), $idShop);
    }

    /** Czy sklep ma wlasny wiersz tego ustawienia (takze pusty albo zero). */
    public static function has(string $key, ?int $idShop = null): bool
    {
        return self::raw($key, $idShop) !== null;
    }

    public static function delete(string $key, ?int $idShop = null): void
    {
        $idShop = $idShop ?? ShopContext::currentOrDefault();
        $name = self::name($key);

        self::all($idShop);
        unset(self::$loaded[$idShop][$name]);

        if (!self::$memoryOnly) {
            Db::getInstance()->delete(Schema::name(Schema::SETTING), '`id_shop` = ' . $idShop . ' AND `name` = "' . pSQL($name) . '"');
        }
    }

    /**
     * Wszystkie ustawienia sklepu (nazwy bez prefiksu). Jedno zapytanie na zadanie.
     *
     * @return array<string,string>
     */
    public static function all(int $idShop): array
    {
        if (isset(self::$loaded[$idShop])) {
            return self::$loaded[$idShop];
        }

        $out = [];

        if (!self::$memoryOnly) {
            // Tabela moze jeszcze nie istniec (hook w trakcie instalacji) albo juz nie
            // istniec (odinstalowanie) — brak ustawien to nie blad sklepu.
            try {
                $rows = Db::getInstance()->executeS(
                    'SELECT `name`, `value` FROM `' . Schema::table(Schema::SETTING) . '` WHERE `id_shop` = ' . $idShop
                ) ?: [];
            } catch (\Throwable $e) {
                $rows = [];
            }

            foreach ($rows as $row) {
                $out[(string) $row['name']] = (string) $row['value'];
            }
        }

        return self::$loaded[$idShop] = $out;
    }

    /** Usuwa wszystkie ustawienia sklepu (sklep usuniety). */
    public static function deleteShop(int $idShop): void
    {
        unset(self::$loaded[$idShop]);

        if (!self::$memoryOnly) {
            Db::getInstance()->delete(Schema::name(Schema::SETTING), '`id_shop` = ' . $idShop);
        }
    }

    /** Sklepy, w ktorych ustawienie ma dokladnie taka wartosc. @return int[] */
    public static function shopsWhere(string $key, string $value): array
    {
        $name = self::name($key);

        if (self::$memoryOnly) {
            $out = [];

            foreach (self::$loaded as $idShop => $values) {
                if (($values[$name] ?? null) === $value) {
                    $out[] = (int) $idShop;
                }
            }

            sort($out);

            return $out;
        }

        $rows = Db::getInstance()->executeS(
            'SELECT `id_shop` FROM `' . Schema::table(Schema::SETTING) . '`
             WHERE `name` = "' . pSQL($name) . '" AND `value` = "' . pSQL($value) . '" ORDER BY `id_shop` ASC'
        ) ?: [];

        return array_map(static fn (array $row): int => (int) $row['id_shop'], $rows);
    }

    /** Zapomina wczytane wartosci (po zmianie zrobionej poza ta klasa). */
    public static function flush(): void
    {
        self::$loaded = [];
    }

    /**
     * Tylko testy: ustawienia w pamieci zamiast w bazie.
     *
     * @param array<int,array<string,string>> $initial sklep => nazwa (z prefiksem albo bez) => wartosc
     */
    public static function useMemoryStore(array $initial = []): void
    {
        self::$memoryOnly = true;
        self::$loaded = [];

        foreach ($initial as $idShop => $values) {
            foreach ($values as $key => $value) {
                self::$loaded[(int) $idShop][self::name((string) $key)] = (string) $value;
            }
        }
    }

    private static function raw(string $key, ?int $idShop): ?string
    {
        $idShop = $idShop ?? ShopContext::currentOrDefault();

        return self::all($idShop)[self::name($key)] ?? null;
    }

    private static function write(string $key, string $value, ?int $idShop): void
    {
        $idShop = $idShop ?? ShopContext::currentOrDefault();
        $name = self::name($key);

        self::all($idShop);
        self::$loaded[$idShop][$name] = $value;

        if (self::$memoryOnly) {
            return;
        }

        Db::getInstance()->execute(
            'INSERT INTO `' . Schema::table(Schema::SETTING) . '` (`id_shop`, `name`, `value`, `date_upd`)
             VALUES (' . $idShop . ', "' . pSQL($name) . '", "' . pSQL($value, true) . '", "' . pSQL(Clock::now()) . '")
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `date_upd` = VALUES(`date_upd`)'
        );
    }

    /** Nazwa w tabeli: stala bez prefiksu `QUINCE_INSIGHT_`. */
    private static function name(string $key): string
    {
        if (strncmp($key, self::KEY_PREFIX, strlen(self::KEY_PREFIX)) === 0) {
            $key = substr($key, strlen(self::KEY_PREFIX));
        }

        if ($key === '' || strlen($key) > 64 || preg_match('/^[A-Z0-9_]+$/', $key) !== 1) {
            throw new \InvalidArgumentException('Quince Insight: invalid setting key "' . $key . '".');
        }

        return $key;
    }
}
