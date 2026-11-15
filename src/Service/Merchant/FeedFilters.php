<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\Merchant;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Quince\Insight\Service\MerchantTools\ConditionMap;

/**
 * Filtry feedu ("What is sent") — JEDNA klasa dla licznika, listy Products i synchronizacji.
 *
 * W FeedForge licznik obslugiwal 14 grup, a synchronizacja 8 (research/02 §1.4) — szesc grup
 * bylo zapisywanych, liczonych i ignorowanych przy wysylce. Tu kazde miejsce bierze ten sam
 * warunek SQL z `condition()`: licznik w WHERE, lista w SELECT (flaga "w feedzie"),
 * synchronizacja w WHERE zbioru ofert. Bez bazy — testowalne jednostkowo.
 *
 * Warunek dotyczy OFERTY (produkt prosty albo kombinacja) i uzywa aliasow zapytania
 * `FeedCatalog`: `p` (product), `ps` (product_shop), `pa` (product_attribute, NULL dla
 * produktu prostego), `pas` (product_attribute_shop), `sa` (stock_available oferty).
 *
 * Ksztalt JSON (kolumna `feed.filters`) — `normalize()` uzupelnia brakujace klucze
 * wartosciami domyslnymi z prototypu (`gmcState()` w prototypy/js/gmc.js).
 */
final class FeedFilters
{
    public const MODES = ['include', 'exclude'];
    public const CONDITIONS = ['new', 'used', 'refurbished'];
    public const VISIBILITIES = ['both', 'catalog', 'search', 'none'];

    /**
     * Domyslne filtry nowego feedu (prototyp: skipOos=true, minStock=1, cond.new, vis.both).
     *
     * @return array<string,mixed>
     */
    public static function defaults(): array
    {
        return [
            'categories' => [],
            'categories_mode' => 'include',
            'manufacturers' => [],
            'manufacturers_mode' => 'include',
            'suppliers' => [],
            'suppliers_mode' => 'include',
            'price_ranges' => [],
            'price_mode' => 'include',
            'skip_out_of_stock' => true,
            'min_stock' => 1,
            'require_ean' => false,
            'require_image' => false,
            'condition' => ['new'],
            'visibility' => ['both'],
            'features' => [],
            'features_mode' => 'include',
            'tags_include' => [],
            'tags_exclude' => [],
            'excluded_products' => [],
        ];
    }

    /**
     * Czysci dane z formularza albo z bazy: typy, dozwolone wartosci, brakujace klucze.
     *
     * @param array<string,mixed> $raw
     *
     * @return array<string,mixed>
     */
    public static function normalize(array $raw): array
    {
        $out = self::defaults();

        foreach (['categories', 'manufacturers', 'suppliers', 'excluded_products'] as $key) {
            if (isset($raw[$key])) {
                $out[$key] = self::ids($raw[$key]);
            }
        }

        foreach (['categories_mode', 'manufacturers_mode', 'suppliers_mode', 'price_mode', 'features_mode'] as $key) {
            if (isset($raw[$key]) && in_array($raw[$key], self::MODES, true)) {
                $out[$key] = $raw[$key];
            }
        }

        if (isset($raw['price_ranges']) && is_array($raw['price_ranges'])) {
            $ranges = [];

            foreach ($raw['price_ranges'] as $range) {
                if (!is_array($range)) {
                    continue;
                }

                $min = self::number($range['min'] ?? null);
                $max = self::number($range['max'] ?? null);

                // Pusty wiersz z formularza (oba pola puste) nie jest filtrem — jak w FeedForge.
                if ($min === null && $max === null) {
                    continue;
                }

                $ranges[] = ['min' => $min, 'max' => $max];
            }

            $out['price_ranges'] = $ranges;
        }

        foreach (['skip_out_of_stock', 'require_ean', 'require_image'] as $key) {
            if (array_key_exists($key, $raw)) {
                $out[$key] = filter_var($raw[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }

        if (isset($raw['min_stock'])) {
            $out['min_stock'] = max(1, (int) $raw['min_stock']);
        }

        if (isset($raw['condition']) && is_array($raw['condition'])) {
            $out['condition'] = array_values(array_intersect(self::CONDITIONS, array_map('strval', $raw['condition'])));
        }

        if (isset($raw['visibility']) && is_array($raw['visibility'])) {
            $out['visibility'] = array_values(array_intersect(self::VISIBILITIES, array_map('strval', $raw['visibility'])));
        }

        if (isset($raw['features']) && is_array($raw['features'])) {
            $features = [];

            foreach ($raw['features'] as $pair) {
                $idFeature = (int) ($pair['id_feature'] ?? 0);
                $idValue = (int) ($pair['id_feature_value'] ?? 0);

                if ($idFeature > 0 && $idValue > 0) {
                    $features[$idFeature . ':' . $idValue] = ['id_feature' => $idFeature, 'id_feature_value' => $idValue];
                }
            }

            $out['features'] = array_values($features);
        }

        foreach (['tags_include', 'tags_exclude'] as $key) {
            if (isset($raw[$key]) && is_array($raw[$key])) {
                $tags = [];

                foreach ($raw[$key] as $tag) {
                    $tag = trim((string) $tag);

                    if ($tag !== '' && mb_strlen($tag) <= 32) {
                        $tags[mb_strtolower($tag)] ??= $tag;
                    }
                }

                $out[$key] = array_values($tags);
            }
        }

        return $out;
    }

    /**
     * Warunek SQL oferty "pasuje do filtrow feedu". '1' gdy filtrow brak.
     *
     * @param array<string,mixed> $filters po `normalize()`
     * @param int $idLang jezyk feedu (tagi sa w PrestaShop per jezyk)
     * @param array<string,string> $conditionMap stany 9.2 => stan Google | skip (`ConditionMap::forShop`)
     */
    public static function condition(array $filters, int $idShop, int $idLang, string $prefix = _DB_PREFIX_, array $conditionMap = []): string
    {
        $f = self::normalize($filters);
        $parts = [];

        if ($f['categories']) {
            $sub = 'EXISTS (SELECT 1 FROM `' . $prefix . 'category_product` qcp WHERE qcp.`id_product` = p.`id_product` AND qcp.`id_category` IN (' . implode(',', $f['categories']) . '))';
            $parts[] = $f['categories_mode'] === 'exclude' ? 'NOT ' . $sub : $sub;
        }

        if ($f['manufacturers']) {
            $parts[] = 'p.`id_manufacturer` ' . ($f['manufacturers_mode'] === 'exclude' ? 'NOT IN' : 'IN') . ' (' . implode(',', $f['manufacturers']) . ')';
        }

        if ($f['suppliers']) {
            $sub = 'EXISTS (SELECT 1 FROM `' . $prefix . 'product_supplier` qsu WHERE qsu.`id_product` = p.`id_product` AND qsu.`id_supplier` IN (' . implode(',', $f['suppliers']) . '))';
            $parts[] = $f['suppliers_mode'] === 'exclude' ? 'NOT ' . $sub : $sub;
        }

        // Zakresy cen NIE sa czescia warunku SQL: cena wysylana do Google (waluta feedu, podatek
        // wg feedu, po promocjach — decyzja #11) powstaje w PHP. `FeedCatalog` stosuje
        // `priceMatches()` na tej samej cenie (`OfferPrice`) w liczniku, liscie i synchronizacji.

        if ($f['skip_out_of_stock']) {
            // Stan oferty (kombinacji, nie produktu-rodzica) — FeedForge patrzyl tylko na wiersz produktu.
            $parts[] = 'IFNULL(sa.`quantity`, 0) >= ' . max(1, (int) $f['min_stock']);
        }

        if ($f['require_ean']) {
            $parts[] = "IFNULL(NULLIF(pa.`ean13`, ''), IFNULL(p.`ean13`, '')) <> ''";
        }

        if ($f['require_image']) {
            $parts[] = 'EXISTS (SELECT 1 FROM `' . $prefix . 'image_shop` qis WHERE qis.`id_product` = p.`id_product` AND qis.`id_shop` = ' . $idShop . ')';
        }

        // Filtr "Condition" dotyczy stanu Google: stan 9.2 przechodzi, gdy jego mapowanie jest
        // zaznaczone; "Do not send" nie przechodzi nigdy (decyzja #12).
        $conditions = ConditionMap::shopValues($f['condition'], $conditionMap);
        $parts[] = $conditions ? 'ps.`condition` IN (' . self::quoted($conditions) . ')' : '0';
        $parts[] = $f['visibility'] ? 'ps.`visibility` IN (' . self::quoted($f['visibility']) . ')' : '0';

        if ($f['features']) {
            $pairs = [];

            foreach ($f['features'] as $pair) {
                $pairs[] = 'EXISTS (SELECT 1 FROM `' . $prefix . 'feature_product` qfp WHERE qfp.`id_product` = p.`id_product` AND qfp.`id_feature` = '
                    . (int) $pair['id_feature'] . ' AND qfp.`id_feature_value` = ' . (int) $pair['id_feature_value'] . ')';
            }

            // Tylko pasujace = wszystkie pary naraz; oprocz pasujacych = zadna z par.
            $parts[] = $f['features_mode'] === 'exclude'
                ? 'NOT (' . implode(' OR ', $pairs) . ')'
                : '(' . implode(' AND ', $pairs) . ')';
        }

        foreach (['tags_include' => '', 'tags_exclude' => 'NOT '] as $key => $negation) {
            if ($f[$key]) {
                $parts[] = $negation . 'EXISTS (SELECT 1 FROM `' . $prefix . 'product_tag` qpt INNER JOIN `' . $prefix . 'tag` qt ON qt.`id_tag` = qpt.`id_tag`'
                    . ' WHERE qpt.`id_product` = p.`id_product` AND qt.`id_lang` = ' . $idLang . ' AND qt.`name` IN (' . self::quoted($f[$key]) . '))';
            }
        }

        if ($f['excluded_products']) {
            $parts[] = 'p.`id_product` NOT IN (' . implode(',', $f['excluded_products']) . ')';
        }

        return $parts ? '(' . implode(' AND ', $parts) . ')' : '1';
    }

    /** Czy zakresy cen sa ustawione (wtedy licznik i lista licza cene oferty w PHP). */
    public static function hasPriceRanges(array $filters): bool
    {
        return self::normalize($filters)['price_ranges'] !== [];
    }

    /**
     * Filtr cen na kwocie wysylanej do Google (`OfferPrice::sent()`), z trybem include/exclude.
     *
     * @param array<string,mixed> $filters
     */
    public static function priceMatches(array $filters, float $price): bool
    {
        $f = self::normalize($filters);

        if (!$f['price_ranges']) {
            return true;
        }

        $in = false;

        foreach ($f['price_ranges'] as $range) {
            if (($range['min'] === null || $price >= $range['min'] - 0.000001) && ($range['max'] === null || $price <= $range['max'] + 0.000001)) {
                $in = true;
                break;
            }
        }

        return $f['price_mode'] === 'exclude' ? !$in : $in;
    }

    /**
     * Opis aktywnych filtrow — teksty 1:1 z `gmActiveFilters()` prototypu. Kazda pozycja to
     * [zrodlo tlumaczenia, parametry], tlumaczone w szablonie.
     *
     * @param array<string,mixed> $filters
     *
     * @return array<int,array{0:string,1:array<string,string|int>}>
     */
    public static function summary(array $filters): array
    {
        $f = self::normalize($filters);
        $out = [];

        foreach ([
            ['categories', 'categories_mode', 'category', 'categories'],
            ['manufacturers', 'manufacturers_mode', 'manufacturer', 'manufacturers'],
            ['suppliers', 'suppliers_mode', 'supplier', 'suppliers'],
        ] as [$key, $mode, $one, $many]) {
            $n = count($f[$key]);

            if ($n > 0) {
                $word = $n === 1 ? $one : $many;
                $out[] = [($f[$mode] === 'include' ? 'Only %count% ' : 'Without %count% ') . $word, ['%count%' => $n]];
            }
        }

        if ($f['price_ranges']) {
            $n = count($f['price_ranges']);
            $out[] = [$n === 1 ? '%count% price range' : '%count% price ranges', ['%count%' => $n]];
        }

        if ($f['skip_out_of_stock']) {
            $out[] = $f['min_stock'] > 1 ? ['In stock only (≥ %count%)', ['%count%' => $f['min_stock']]] : ['In stock only', []];
        }

        if ($f['require_ean']) {
            $out[] = ['Requires EAN/GTIN', []];
        }

        if ($f['require_image']) {
            $out[] = ['Requires an image', []];
        }

        if ($f['features']) {
            $out[] = ['%count% feature filters', ['%count%' => count($f['features'])]];
        }

        if ($f['tags_include'] || $f['tags_exclude']) {
            $out[] = ['Tags: %in% in, %out% out', ['%in%' => count($f['tags_include']), '%out%' => count($f['tags_exclude'])]];
        }

        if ($f['excluded_products']) {
            $out[] = ['%count% excluded products', ['%count%' => count($f['excluded_products'])]];
        }

        return $out;
    }

    /** @return int[] */
    private static function ids(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $value), static fn (int $id): bool => $id > 0)));
        sort($ids);

        return $ids;
    }

    private static function number(mixed $value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return max(0.0, (float) $value);
    }

    /** @param string[] $values */
    private static function quoted(array $values): string
    {
        return implode(',', array_map(static fn (string $v): string => "'" . self::escape($v) . "'", $values));
    }

    private static function escape(string $value): string
    {
        if (function_exists('pSQL')) {
            return (string) pSQL($value);
        }

        // Testy jednostkowe nie maja PrestaShop: to samo, co robi pSQL() bez polaczenia z baza.
        return addslashes(strip_tags($value));
    }
}
