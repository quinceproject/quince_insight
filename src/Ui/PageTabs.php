<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Ui;

use Symfony\Component\HttpFoundation\Request;

/**
 * Zakladki wewnatrz ekranu, wybierane parametrem `?tab=<klucz>`.
 *
 * Kazda zakladka to osobny plik Twig dolaczany przez kontroler; serwer renderuje
 * tylko wybrana. Wynik `resolve()` idzie do `_partials/tabs.html.twig`.
 */
final class PageTabs
{
    /**
     * @param array<string,string> $tabs klucz => etykieta (angielskie zrodlo tlumaczenia), w kolejnosci wyswietlania
     * @param string|null $default zakladka domyslna; null = pierwsza
     *
     * @return array{current:string,items:array<int,array{key:string,label:string,active:bool}>}
     */
    public function resolve(Request $request, array $tabs, ?string $default = null, string $param = 'tab'): array
    {
        return self::build((string) $request->query->get($param, ''), $tabs, $default);
    }

    /**
     * To samo bez obiektu zadania — do testow i miejsc, gdzie klucz przychodzi inna droga.
     *
     * @param array<string,string> $tabs
     *
     * @return array{current:string,items:array<int,array{key:string,label:string,active:bool}>}
     */
    public static function build(string $requested, array $tabs, ?string $default = null): array
    {
        $keys = array_map('strval', array_keys($tabs));
        $fallback = $default !== null && in_array($default, $keys, true) ? $default : ($keys[0] ?? '');

        // Nieznany klucz z adresu nie jest bledem — pokazujemy zakladke domyslna.
        $current = in_array($requested, $keys, true) ? $requested : $fallback;
        $items = [];

        foreach ($tabs as $key => $label) {
            $items[] = ['key' => (string) $key, 'label' => (string) $label, 'active' => (string) $key === $current];
        }

        return ['current' => $current, 'items' => $items];
    }
}
