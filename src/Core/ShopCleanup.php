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

/**
 * Plan sprzatania po usunietym sklepie — sama logika, bez bazy i bez PrestaShop.
 *
 * Oddzielony od `ShopLifecycle`, zeby dalo sie go sprawdzic testem: usuniecie sklepu
 * jest nieodwracalne i nie ma jak go przecwiczyc na zywej instalacji.
 */
final class ShopCleanup
{
    /**
     * Tabele, z ktorych trzeba usunac wiersze sklepu.
     *
     * @param array<int,array{name:string,perShop:bool}> $tables wszystkie tabele modulu
     *
     * @return string[] nazwy krotkie, bez powtorzen, w kolejnosci definicji
     */
    public static function tablesToPurge(array $tables): array
    {
        $out = [];

        foreach ($tables as $table) {
            if ($table['perShop'] && !in_array($table['name'], $out, true)) {
                $out[] = $table['name'];
            }
        }

        return $out;
    }

    /**
     * Tresc informacji na pulpit: co zostalo w Google po sklepie.
     *
     * @param array<string,string[]> $leftByService klucz uslugi => pozycje z `ServiceModule::leftInGoogle()`
     *
     * @return string[] pozycje bez pustych i bez powtorzen; [] = nie ma o czym informowac
     */
    public static function noticeItems(array $leftByService): array
    {
        $out = [];

        foreach ($leftByService as $items) {
            foreach ($items as $item) {
                $item = trim((string) $item);

                if ($item !== '' && !in_array($item, $out, true)) {
                    $out[] = $item;
                }
            }
        }

        return $out;
    }

    /**
     * Przekazanie zarzadzania wspolnym kontenerem Tag Manager.
     *
     * Kontenerem zarzadza jeden sklep (`mode = auto`), pozostale tylko wstawiaja snippet
     * (`mode = shared`). Gdy znika sklep zarzadzajacy, zarzadzanie przejmuje sklep
     * o najnizszym id sposrod korzystajacych z tego samego kontenera.
     *
     * @param int $deletedShop usuwany sklep
     * @param array<int,array{mode:string,container:string,owner:int}> $shops ustawienia GTM wszystkich sklepow (z usuwanym)
     *
     * @return array{newOwner:int,followers:int[]}|null null = nic do zrobienia
     */
    public static function gtmHandover(int $deletedShop, array $shops): ?array
    {
        $deleted = $shops[$deletedShop] ?? null;

        if ($deleted === null || $deleted['mode'] !== 'auto' || $deleted['container'] === '') {
            return null;
        }

        $users = [];

        foreach ($shops as $idShop => $settings) {
            if ((int) $idShop !== $deletedShop
                && $settings['mode'] === 'shared'
                && $settings['container'] === $deleted['container']) {
                $users[] = (int) $idShop;
            }
        }

        if ($users === []) {
            return null;
        }

        sort($users);
        $newOwner = array_shift($users);

        return ['newOwner' => $newOwner, 'followers' => $users];
    }
}
