<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Wizard;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Quince\Insight\Core\Options;
use Quince\Insight\Core\ShopContext;

/**
 * Przejscie kreatora: ktore sklepy obejmuje i jakie wybory padly przed krokami uslug.
 *
 * Kreator jest per sklep i dziala na liscie sklepow (ARCHITEKTURA §6). Przejscie jest
 * zapisane w kazdym sklepie listy, zeby przerwany kreator wznowil sie takze wtedy, gdy
 * user wroci z kontekstu innego sklepu z tej samej listy.
 *
 * Ksztalt: shops (int[], pierwszy = sklep, z ktorego zaczeto), mode (one|several),
 * conn (''|new|<id polaczenia>), started (bool), furthest (najdalszy odwiedzony krok).
 */
final class WizardRun
{
    /** @return array{shops:int[],mode:string,conn:string,started:bool,furthest:string} */
    public function load(int $idShop): array
    {
        $run = Options::json(WizardOptions::RUN, [], $idShop);
        $shops = array_values(array_unique(array_filter(
            array_map('intval', (array) ($run['shops'] ?? [])),
            // Sklep spoza uprawnien pracownika wypada z przejscia, nawet gdy zapisal go ktos inny.
            static fn (int $id): bool => $id > 0 && ShopContext::exists($id) && ShopContext::employeeMayManage($id)
        )));

        // Sklep biezacego kontekstu zawsze nalezy do swojego przejscia.
        if (!in_array($idShop, $shops, true)) {
            $shops = [$idShop];
            $run = [];
        }

        return [
            'shops' => $shops,
            'mode' => ($run['mode'] ?? 'one') === 'several' && count($shops) > 1 ? 'several' : 'one',
            'conn' => (string) ($run['conn'] ?? ''),
            'started' => (bool) ($run['started'] ?? false),
            'furthest' => (string) ($run['furthest'] ?? ''),
        ];
    }

    /** @param array{shops:int[],mode:string,conn:string,started:bool,furthest:string} $run */
    public function save(array $run): void
    {
        $run['started'] = true;

        foreach ($run['shops'] as $idShop) {
            Options::set(WizardOptions::RUN, $run, $idShop);
        }
    }

    /**
     * Ustawia liste sklepow przejscia. Sklepy, ktore wypadly z listy, traca zapis przejscia.
     *
     * @param array{shops:int[],mode:string,conn:string,started:bool,furthest:string} $run
     * @param int[] $shops pierwszy = sklep kontekstu
     *
     * @return array{shops:int[],mode:string,conn:string,started:bool,furthest:string}
     */
    public function withShops(array $run, array $shops, string $mode): array
    {
        foreach (array_diff($run['shops'], $shops) as $removed) {
            Options::delete(WizardOptions::RUN, (int) $removed);
        }

        $run['shops'] = array_values(array_unique(array_map('intval', $shops)));
        $run['mode'] = $mode === 'several' && count($run['shops']) > 1 ? 'several' : 'one';

        return $run;
    }

    /** Konczy przejscie we wszystkich jego sklepach. @param int[] $shops */
    public function clear(array $shops): void
    {
        foreach ($shops as $idShop) {
            Options::delete(WizardOptions::RUN, (int) $idShop);
        }
    }

    /** Identyfikator istniejacego polaczenia wybranego dla kolejnego sklepu; 0 = brak wyboru albo nowe. */
    public static function existingConnection(array $run): int
    {
        return ctype_digit((string) $run['conn']) ? (int) $run['conn'] : 0;
    }
}
