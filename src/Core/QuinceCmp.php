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
use Hook;
use Module;

/**
 * Wspolpraca z Quince CMP w `<head>` (decyzja #8 a/b).
 *
 * Quince CMP wypisuje deklaracje Consent Mode (`gtag("consent","default", ...)`) w swoim
 * `displayHeader`. Kontener Tag Managera tego modulu tez wychodzi w `displayHeader`, wiec
 * musi stac ZA Quince CMP — inaczej tagi Google startuja przed deklaracja zgod.
 *
 *  - `placeAfterCmp()` przestawia `displayHeader` tego modulu za `quince_cmp` (instalacja
 *    tego modulu i rejestracja `displayHeader` przez Quince CMP);
 *  - `headerOrderWrong()` — kolejnosc zla (np. zmieniona recznie w Pozycjach modulow);
 *  - `declaresConsent()` — czy Quince CMP w ogole wypisuje deklaracje. Jego API (API.md)
 *    tego nie mowi; `consentState()` oddaje sygnaly zawsze, takze bez mapowania kategorii.
 *    Dlatego pytamy jego publiczna metode hooka o to, co dostaje strona — ten sam wynik,
 *    bez czytania jego tabel i ustawien.
 */
final class QuinceCmp
{
    public const MODULE = 'quince_cmp';
    public const OURS = 'quince_insight';
    public const HOOK = 'displayHeader';

    /** Znacznik deklaracji w wyniku `quince_cmp::hookDisplayHeader()` (Signals::headScript). */
    private const DECLARATION = 'gtag("consent","default"';

    /**
     * Nowa kolejnosc modulow w hooku: nasz zaraz za Quince CMP. Null = bez zmian
     * (juz za nim albo ktoregos modulu nie ma w hooku).
     *
     * @param int[] $order id modulow w kolejnosci pozycji
     *
     * @return int[]|null
     */
    public static function moveAfter(array $order, int $ours, int $cmp): ?array
    {
        $order = array_values(array_map('intval', $order));
        $i = array_search($ours, $order, true);
        $j = array_search($cmp, $order, true);

        if ($i === false || $j === false || $i > $j) {
            return null;
        }

        array_splice($order, $i, 1);
        $j = (int) array_search($cmp, $order, true);
        array_splice($order, $j + 1, 0, [$ours]);

        return $order;
    }

    /**
     * Pozycje po przestawieniu: te same numery pozycji, nowa kolejnosc. Tylko wiersze,
     * ktorych pozycja sie zmienia (moduly miedzy nami a Quince CMP przesuwaja sie o jeden).
     *
     * @param array<int,array{id_module:int,position:int}> $rows wiersze w kolejnosci pozycji
     *
     * @return array<int,int> id_module => nowa pozycja
     */
    public static function newPositions(array $rows, int $ours, int $cmp): array
    {
        $ids = array_map(static fn (array $r): int => (int) $r['id_module'], $rows);
        $order = self::moveAfter($ids, $ours, $cmp);

        if ($order === null) {
            return [];
        }

        $positions = array_map(static fn (array $r): int => (int) $r['position'], $rows);
        sort($positions);
        $before = array_combine($ids, array_map(static fn (array $r): int => (int) $r['position'], $rows));
        $out = [];

        foreach ($order as $k => $id) {
            if ($before[$id] !== $positions[$k]) {
                $out[$id] = $positions[$k];
            }
        }

        return $out;
    }

    /**
     * Przestawia `displayHeader` tego modulu za `quince_cmp` we wszystkich sklepach, w ktorych
     * oba sa podpiete. Zwraca liczbe sklepow, w ktorych cos sie zmienilo.
     */
    public function placeAfterCmp(): int
    {
        $ids = $this->moduleIds();
        $idHook = (int) Hook::getIdByName(self::HOOK);

        if ($ids === null || $idHook <= 0) {
            return 0;
        }

        [$ours, $cmp] = $ids;
        $changed = 0;

        foreach ($this->shopsWithBoth($idHook, $ours, $cmp) as $idShop) {
            $positions = self::newPositions($this->rows($idHook, $idShop), $ours, $cmp);

            foreach ($positions as $idModule => $position) {
                Db::getInstance()->execute(
                    'UPDATE `' . _DB_PREFIX_ . 'hook_module` SET `position` = ' . (int) $position . '
                     WHERE `id_hook` = ' . $idHook . ' AND `id_shop` = ' . $idShop . ' AND `id_module` = ' . (int) $idModule
                );
            }

            if ($positions !== []) {
                ++$changed;
                Log::write($idShop, 'core', Log::INFO, 'displayHeader placed after Quince CMP.');
            }
        }

        if ($changed > 0) {
            \Cache::clean('hook_module_list');
        }

        return $changed;
    }

    /** Quince CMP zainstalowany i wlaczony w tym sklepie. */
    public function enabledIn(int $idShop): bool
    {
        if (!Module::isInstalled(self::MODULE)) {
            return false;
        }

        $id = (int) Module::getModuleIdByName(self::MODULE);

        return $id > 0 && (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'module_shop` ms
             JOIN `' . _DB_PREFIX_ . 'module` m ON m.`id_module` = ms.`id_module` AND m.`active` = 1
             WHERE ms.`id_module` = ' . $id . ' AND ms.`id_shop` = ' . $idShop
        ) > 0;
    }

    /** Nasz `displayHeader` stoi w sklepie PRZED Quince CMP (kontener przed deklaracja zgod). */
    public function headerOrderWrong(int $idShop): bool
    {
        $ids = $this->moduleIds();
        $idHook = (int) Hook::getIdByName(self::HOOK);

        if ($ids === null || $idHook <= 0) {
            return false;
        }

        return self::newPositions($this->rows($idHook, $idShop), $ids[0], $ids[1]) !== [];
    }

    /**
     * Czy Quince CMP wypisuje w tym sklepie deklaracje Consent Mode. Null = nie da sie
     * ustalic (brak modulu, inna wersja, wyjatek) — wtedy pulpit nic nie zglasza.
     */
    public function declaresConsent(int $idShop): ?bool
    {
        $cmp = Module::getInstanceByName(self::MODULE);

        if (!$cmp instanceof Module || !method_exists($cmp, 'hookDisplayHeader')) {
            return null;
        }

        try {
            $head = (string) ShopContext::run($idShop, static fn (): string => (string) $cmp->hookDisplayHeader([]));
        } catch (\Throwable $e) {
            Log::write($idShop, 'core', Log::WARNING, 'Quince CMP header check failed: ' . $e->getMessage());

            return null;
        }

        return str_contains($head, self::DECLARATION);
    }

    /** @return array{0:int,1:int}|null [nasz id, id quince_cmp] */
    private function moduleIds(): ?array
    {
        $ours = (int) Module::getModuleIdByName(self::OURS);
        $cmp = (int) Module::getModuleIdByName(self::MODULE);

        return $ours > 0 && $cmp > 0 ? [$ours, $cmp] : null;
    }

    /** @return int[] */
    private function shopsWithBoth(int $idHook, int $ours, int $cmp): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT `id_shop` FROM `' . _DB_PREFIX_ . 'hook_module`
             WHERE `id_hook` = ' . $idHook . ' AND `id_module` IN (' . $ours . ', ' . $cmp . ')
             GROUP BY `id_shop` HAVING COUNT(DISTINCT `id_module`) = 2'
        ) ?: [];

        return array_map(static fn (array $r): int => (int) $r['id_shop'], $rows);
    }

    /** @return array<int,array{id_module:int,position:int}> */
    private function rows(int $idHook, int $idShop): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT `id_module`, `position` FROM `' . _DB_PREFIX_ . 'hook_module`
             WHERE `id_hook` = ' . $idHook . ' AND `id_shop` = ' . $idShop . '
             ORDER BY `position` ASC, `id_module` ASC'
        ) ?: [];

        return array_map(static fn (array $r): array => ['id_module' => (int) $r['id_module'], 'position' => (int) $r['position']], $rows);
    }
}
