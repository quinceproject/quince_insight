<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\MerchantTools;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Db;
use Quince\Insight\Core\Options;
use Quince\Insight\Core\Schema as CoreSchema;
use Quince\Insight\Core\ShopContext;
use Quince\Insight\Service\Merchant\ProductData;

/**
 * Mapowanie kategorii, identyfikatorow i atrybutow (kontrakt dla agenta E, ARCHITEKTURA §7.7).
 *
 * Mapowanie jest per sklep (wspolne dla feedow sklepu, jak w FeedForge). Pola w `ProductData::$fields`
 * — klucze `DataMapper` z FeedForge: `gtin`, `mpn`, `brand`, `identifierExists`,
 * `googleProductCategory` (id taksonomii jako napis), `color`, `sizes` (tablica), `material`,
 * `pattern`, `gender`, `ageGroup` (dwa ostatnie juz jako enum Merchant API v1).
 */
final class Mapping
{
    /** @var array<int,array{gtin:string,mpn:string,brand:string,exists:string}> */
    private array $settings = [];

    /** @var array<int,array<int,int>> */
    private array $categoryMaps = [];

    /** @var array<int,int>|null */
    private ?array $parents = null;

    /** @var array<int,int[]> */
    private array $roots = [];

    /** @var array<int,array<string,array{type:string,id:int}>> */
    private array $effective = [];

    /** Kategoria Google dla kategorii sklepu; szuka w gore drzewa kategorii. */
    public function googleCategory(int $idShop, int $idCategory): ?int
    {
        if ($idCategory <= 0) {
            return null;
        }

        return MappingValues::inheritedCategory($this->categoryMap($idShop), $this->parents(), $idCategory, $this->roots($idShop));
    }

    /** Uzupelnia pola identyfikatorow i atrybutow (gtins, mpn, brand, identifierExists, color, size...). */
    public function apply(int $idShop, ProductData $product): void
    {
        $ctx = $product->context;

        if (isset($ctx['ean13'], $ctx['reference'])) {
            // Budowniczy agenta E podal surowe dane (wartosci kombinacji juz z zapasem z produktu).
            $row = [
                'ean13' => $ctx['ean13'], 'upc' => $ctx['upc'] ?? '', 'isbn' => $ctx['isbn'] ?? '', 'mpn' => $ctx['mpn'] ?? '',
                'reference' => $ctx['reference'], 'supplier_reference' => $ctx['supplier_reference'] ?? '',
                'manufacturer' => $ctx['brand'] ?? '', 'id_category_default' => $ctx['id_category_default'] ?? 0,
            ];
            $combination = null;
        } else {
            $row = $this->productRow($idShop, $product->idProduct);

            if ($row === null) {
                return;
            }

            $combination = $product->idProductAttribute > 0 ? $this->combinationRow($product->idProduct, $product->idProductAttribute) : null;
        }

        $ids = MappingValues::identifiers($this->identifierSettings($idShop), $row, $combination, ShopContext::name($idShop));

        foreach (['gtin', 'mpn', 'brand'] as $key) {
            if ($ids[$key] !== '') {
                $product->fields[$key] = $ids[$key];
            } else {
                unset($product->fields[$key]);
            }
        }

        $product->fields['identifierExists'] = $ids['identifierExists'];

        $category = $this->googleCategory($idShop, $product->idDefaultCategory ?: (int) $row['id_category_default']);

        if ($category !== null) {
            $product->fields['googleProductCategory'] = (string) $category;
        }

        // Jezyk wartosci atrybutow = jezyk feedu (`contentLanguage` z DataMapper); brak → domyslny sklepu.
        $iso = (string) ($product->fields['contentLanguage'] ?? '');
        $idLang = (int) ($ctx['id_lang'] ?? 0)
            ?: (($iso !== '' ? (int) \Language::getIdByIso($iso) : 0) ?: (int) \Configuration::get('PS_LANG_DEFAULT', null, null, $idShop));

        foreach ($this->effectiveAttributes($idShop) as $target => $source) {
            if ($source['type'] === 'attribute' && isset($ctx['attributes']) && is_array($ctx['attributes'])) {
                $value = trim((string) ($ctx['attributes'][$source['id']]['value'] ?? ''));
            } elseif ($source['type'] === 'feature' && isset($ctx['features']) && is_array($ctx['features'])) {
                $value = trim((string) ($ctx['features'][$source['id']]['value'] ?? ''));
            } else {
                $value = $source['type'] === 'attribute'
                    ? $this->attributeValue($product->idProductAttribute, $source['id'], $idLang)
                    : $this->featureValue($product->idProduct, $source['id'], $idLang);
            }

            if ($value === '') {
                continue;
            }

            switch ($target) {
                case 'size':
                    $product->fields['sizes'] = [mb_substr($value, 0, 100)];
                    break;
                case 'gender':
                    $g = MappingValues::normalizeGender($value);
                    if ($g !== '') {
                        $product->fields['gender'] = $g;
                    }
                    break;
                case 'age_group':
                    $a = MappingValues::normalizeAgeGroup($value);
                    if ($a !== '') {
                        $product->fields['ageGroup'] = $a;
                    }
                    break;
                default:
                    $product->fields[$target] = mb_substr($value, 0, 100);
            }
        }
    }

    /** @return array{gtin:string,mpn:string,brand:string,exists:string} */
    public function identifierSettings(int $idShop): array
    {
        if (!isset($this->settings[$idShop])) {
            $d = MappingValues::DEFAULTS;
            $read = static fn (string $key, string $default, array $allowed): string => isset($allowed[$v = Options::str($key, $default, $idShop)]) ? $v : $default;

            $this->settings[$idShop] = [
                'gtin' => $read(MerchantToolsOptions::ID_GTIN, $d['gtin'], MappingValues::GTIN_SOURCES),
                'mpn' => $read(MerchantToolsOptions::ID_MPN, $d['mpn'], MappingValues::MPN_SOURCES),
                'brand' => $read(MerchantToolsOptions::ID_BRAND, $d['brand'], MappingValues::BRAND_SOURCES),
                'exists' => $read(MerchantToolsOptions::ID_EXISTS_AUTO, $d['exists'], MappingValues::EXISTS_MODES),
            ];
        }

        return $this->settings[$idShop];
    }

    public function saveIdentifier(int $idShop, string $key, string $value): bool
    {
        $map = [
            'gtinSource' => [MerchantToolsOptions::ID_GTIN, MappingValues::GTIN_SOURCES],
            'mpnSource' => [MerchantToolsOptions::ID_MPN, MappingValues::MPN_SOURCES],
            'brandSource' => [MerchantToolsOptions::ID_BRAND, MappingValues::BRAND_SOURCES],
            'identExists' => [MerchantToolsOptions::ID_EXISTS_AUTO, MappingValues::EXISTS_MODES],
        ];

        if (!isset($map[$key]) || !isset($map[$key][1][$value])) {
            return false;
        }

        Options::set($map[$key][0], $value, $idShop);
        unset($this->settings[$idShop]);

        return true;
    }

    /**
     * Grupy atrybutow i cechy sklepu do wyboru zrodla.
     *
     * @return array<int,array{key:string,type:string,id:int,label:string,name:string}>
     */
    public function attributeSources(int $idLang): array
    {
        $out = [];

        foreach (Db::getInstance()->executeS(
            'SELECT ag.`id_attribute_group` AS id, agl.`name` FROM `' . _DB_PREFIX_ . 'attribute_group` ag
             LEFT JOIN `' . _DB_PREFIX_ . 'attribute_group_lang` agl ON agl.`id_attribute_group` = ag.`id_attribute_group` AND agl.`id_lang` = ' . $idLang . '
             ORDER BY ag.`position`'
        ) ?: [] as $r) {
            $out[] = ['key' => 'attribute:' . (int) $r['id'], 'type' => 'attribute', 'id' => (int) $r['id'], 'label' => 'Attribute group: ' . $r['name'], 'name' => (string) $r['name']];
        }

        foreach (Db::getInstance()->executeS(
            'SELECT f.`id_feature` AS id, fl.`name` FROM `' . _DB_PREFIX_ . 'feature` f
             LEFT JOIN `' . _DB_PREFIX_ . 'feature_lang` fl ON fl.`id_feature` = f.`id_feature` AND fl.`id_lang` = ' . $idLang . '
             ORDER BY f.`position`'
        ) ?: [] as $r) {
            $out[] = ['key' => 'feature:' . (int) $r['id'], 'type' => 'feature', 'id' => (int) $r['id'], 'label' => 'Feature: ' . $r['name'], 'name' => (string) $r['name']];
        }

        return $out;
    }

    /**
     * Wiersze tabeli `attribute_map` sklepu (zapisane wybory usera).
     *
     * @return array<string,array{type:string,id:int}>
     */
    public function savedAttributes(int $idShop): array
    {
        $out = [];

        foreach (Db::getInstance()->executeS(
            'SELECT * FROM `' . CoreSchema::table(Schema::ATTRIBUTE_MAP) . '` WHERE `id_shop` = ' . $idShop
        ) ?: [] as $r) {
            $out[(string) $r['target']] = ['type' => (string) $r['source_type'], 'id' => (int) $r['source_id']];
        }

        return $out;
    }

    /**
     * Zrodlo kazdego atrybutu: zapisane przez usera, a gdy nic nie zapisal — wykryte po nazwie.
     * Zapis z pustym `source_type` = user wylaczyl atrybut.
     *
     * @return array<string,array{type:string,id:int}>
     */
    public function effectiveAttributes(int $idShop): array
    {
        if (isset($this->effective[$idShop])) {
            return $this->effective[$idShop];
        }

        $saved = $this->savedAttributes($idShop);
        $detected = $this->detectedAttributes((int) \Configuration::get('PS_LANG_DEFAULT', null, null, $idShop));
        $out = [];

        foreach (array_keys(MappingValues::ATTRIBUTE_TARGETS) as $target) {
            if (isset($saved[$target])) {
                if (in_array($saved[$target]['type'], ['attribute', 'feature'], true) && $saved[$target]['id'] > 0) {
                    $out[$target] = $saved[$target];
                }
            } elseif (isset($detected[$target])) {
                $out[$target] = $detected[$target];
            }
        }

        return $this->effective[$idShop] = $out;
    }

    /**
     * Atrybuty wykryte po nazwie grupy atrybutow (najpierw) lub cechy.
     *
     * @return array<string,array{type:string,id:int}>
     */
    public function detectedAttributes(int $idLang): array
    {
        $out = [];

        foreach ($this->attributeSources($idLang) as $source) {
            $target = MappingValues::detectTarget($source['name']);

            if ($target !== null && !isset($out[$target])) {
                $out[$target] = ['type' => $source['type'], 'id' => $source['id']];
            }
        }

        return $out;
    }

    /** Zapis zrodla atrybutu: `attribute:3`, `feature:1` albo '' (nie wysylac). */
    public function saveAttribute(int $idShop, string $target, string $source): bool
    {
        if (!isset(MappingValues::ATTRIBUTE_TARGETS[$target])) {
            return false;
        }

        $type = '';
        $id = 0;

        if ($source !== '') {
            if (!preg_match('/^(attribute|feature):(\d+)$/', $source, $m)) {
                return false;
            }

            $type = $m[1];
            $id = (int) $m[2];
        }

        Db::getInstance()->execute(
            'REPLACE INTO `' . CoreSchema::table(Schema::ATTRIBUTE_MAP) . '` (`id_shop`, `target`, `source_type`, `source_id`, `fixed_value`)
             VALUES (' . $idShop . ', "' . pSQL($target) . '", "' . pSQL($type) . '", ' . $id . ', "")'
        );
        unset($this->effective[$idShop]);

        return true;
    }

    /** @return array<int,int> id_category => google_id */
    public function categoryMap(int $idShop): array
    {
        if (!isset($this->categoryMaps[$idShop])) {
            $map = [];

            foreach (Db::getInstance()->executeS(
                'SELECT `id_category`, `google_id` FROM `' . CoreSchema::table(Schema::CATEGORY_MAP) . '`
                 WHERE `id_shop` = ' . $idShop . ' AND `google_id` > 0'
            ) ?: [] as $r) {
                $map[(int) $r['id_category']] = (int) $r['google_id'];
            }

            $this->categoryMaps[$idShop] = $map;
        }

        return $this->categoryMaps[$idShop];
    }

    /** google_id 0 = usuniecie mapowania. */
    public function saveCategory(int $idShop, int $idCategory, int $googleId): void
    {
        $table = CoreSchema::table(Schema::CATEGORY_MAP);

        if ($googleId <= 0) {
            Db::getInstance()->execute('DELETE FROM `' . $table . '` WHERE `id_shop` = ' . $idShop . ' AND `id_category` = ' . $idCategory);
        } else {
            Db::getInstance()->execute(
                'REPLACE INTO `' . $table . '` (`id_shop`, `id_category`, `google_id`) VALUES (' . $idShop . ', ' . $idCategory . ', ' . $googleId . ')'
            );
        }

        unset($this->categoryMaps[$idShop]);
    }

    /** Kopia mapowania do innego sklepu (wizard "Copy settings from…"). */
    public function copyShop(int $fromShop, int $toShop): void
    {
        foreach ([Schema::CATEGORY_MAP, Schema::ATTRIBUTE_MAP] as $short) {
            $table = CoreSchema::table($short);
            $db = Db::getInstance();
            $db->execute('DELETE FROM `' . $table . '` WHERE `id_shop` = ' . $toShop);
            $columns = $short === Schema::CATEGORY_MAP ? '`id_category`, `google_id`' : '`target`, `source_type`, `source_id`, `fixed_value`';
            $db->execute('INSERT INTO `' . $table . '` (`id_shop`, ' . $columns . ') SELECT ' . $toShop . ', ' . $columns . ' FROM `' . $table . '` WHERE `id_shop` = ' . $fromShop);
        }

        foreach ([MerchantToolsOptions::ID_GTIN, MerchantToolsOptions::ID_MPN, MerchantToolsOptions::ID_BRAND, MerchantToolsOptions::ID_EXISTS_AUTO] as $key) {
            if (Options::has($key, $fromShop)) {
                Options::set($key, Options::str($key, '', $fromShop), $toShop);
            }
        }

        $this->categoryMaps = [];
        $this->settings = [];
        $this->effective = [];
    }

    /**
     * Kategorie sklepu w kolejnosci drzewa (bez korzenia), z zapisanym mapowaniem i odziedziczonym.
     *
     * @return array<int,array{id:int,name:string,parents:string,depth:int,google:int,inherited:int}>
     */
    public function categories(int $idShop, int $idLang): array
    {
        $root = (int) Db::getInstance()->getValue('SELECT `id_category` FROM `' . _DB_PREFIX_ . 'shop` WHERE `id_shop` = ' . $idShop);
        $bounds = Db::getInstance()->getRow('SELECT `nleft`, `nright`, `level_depth` FROM `' . _DB_PREFIX_ . 'category` WHERE `id_category` = ' . $root);

        if (!$bounds) {
            return [];
        }

        $rows = Db::getInstance()->executeS(
            'SELECT c.`id_category`, c.`id_parent`, c.`level_depth`, cl.`name`
             FROM `' . _DB_PREFIX_ . 'category` c
             INNER JOIN `' . _DB_PREFIX_ . 'category_shop` cs ON cs.`id_category` = c.`id_category` AND cs.`id_shop` = ' . $idShop . '
             LEFT JOIN `' . _DB_PREFIX_ . 'category_lang` cl ON cl.`id_category` = c.`id_category` AND cl.`id_lang` = ' . $idLang . ' AND cl.`id_shop` = ' . $idShop . '
             WHERE c.`nleft` > ' . (int) $bounds['nleft'] . ' AND c.`nright` < ' . (int) $bounds['nright'] . '
             ORDER BY c.`nleft` ASC'
        ) ?: [];

        $names = [];
        foreach ($rows as $r) {
            $names[(int) $r['id_category']] = (string) $r['name'];
        }

        $map = $this->categoryMap($idShop);
        $out = [];

        foreach ($rows as $r) {
            $id = (int) $r['id_category'];
            $path = [];
            $p = (int) $r['id_parent'];

            while (isset($names[$p])) {
                array_unshift($path, $names[$p]);
                $p = $this->parents()[$p] ?? 0;
            }

            $inherited = ($map[$id] ?? 0) > 0 ? 0 : (int) $this->googleCategory($idShop, $id);

            $out[] = [
                'id' => $id,
                'name' => $names[$id] !== '' ? $names[$id] : '#' . $id,
                'parents' => implode(' > ', $path),
                'depth' => (int) $r['level_depth'] - (int) $bounds['level_depth'],
                'google' => $map[$id] ?? 0,
                'inherited' => $inherited,
            ];
        }

        return $out;
    }

    /** @return array<int,int> */
    private function parents(): array
    {
        if ($this->parents === null) {
            $this->parents = [];

            foreach (Db::getInstance()->executeS('SELECT `id_category`, `id_parent` FROM `' . _DB_PREFIX_ . 'category`') ?: [] as $r) {
                $this->parents[(int) $r['id_category']] = (int) $r['id_parent'];
            }
        }

        return $this->parents;
    }

    /** Korzen sklepu i kategorie nad nim — tam konczy sie dziedziczenie. @return int[] */
    private function roots(int $idShop): array
    {
        if (!isset($this->roots[$idShop])) {
            $root = (int) Db::getInstance()->getValue('SELECT `id_category` FROM `' . _DB_PREFIX_ . 'shop` WHERE `id_shop` = ' . $idShop);
            $roots = [];
            $c = $root;

            while ($c > 0 && !in_array($c, $roots, true)) {
                $roots[] = $c;
                $c = $this->parents()[$c] ?? 0;
            }

            $this->roots[$idShop] = $roots;
        }

        return $this->roots[$idShop];
    }

    /** @return array<string,mixed>|null */
    private function productRow(int $idShop, int $idProduct): ?array
    {
        $row = Db::getInstance()->getRow(
            'SELECT p.`ean13`, p.`upc`, p.`isbn`, p.`mpn`, p.`reference`, p.`supplier_reference`,
                    ps.`id_category_default`, IFNULL(m.`name`, "") AS manufacturer
             FROM `' . _DB_PREFIX_ . 'product` p
             INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . $idShop . '
             LEFT JOIN `' . _DB_PREFIX_ . 'manufacturer` m ON m.`id_manufacturer` = p.`id_manufacturer`
             WHERE p.`id_product` = ' . $idProduct
        );

        return is_array($row) && $row ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function combinationRow(int $idProduct, int $idAttribute): ?array
    {
        $row = Db::getInstance()->getRow(
            'SELECT `ean13`, `upc`, `isbn`, `mpn`, `reference`, `supplier_reference`
             FROM `' . _DB_PREFIX_ . 'product_attribute`
             WHERE `id_product_attribute` = ' . $idAttribute . ' AND `id_product` = ' . $idProduct
        );

        return is_array($row) && $row ? $row : null;
    }

    private function attributeValue(int $idAttribute, int $idGroup, int $idLang): string
    {
        if ($idAttribute <= 0) {
            return '';
        }

        return (string) Db::getInstance()->getValue(
            'SELECT al.`name` FROM `' . _DB_PREFIX_ . 'product_attribute_combination` pac
             INNER JOIN `' . _DB_PREFIX_ . 'attribute` a ON a.`id_attribute` = pac.`id_attribute` AND a.`id_attribute_group` = ' . $idGroup . '
             LEFT JOIN `' . _DB_PREFIX_ . 'attribute_lang` al ON al.`id_attribute` = a.`id_attribute` AND al.`id_lang` = ' . $idLang . '
             WHERE pac.`id_product_attribute` = ' . $idAttribute
        );
    }

    private function featureValue(int $idProduct, int $idFeature, int $idLang): string
    {
        $values = Db::getInstance()->executeS(
            'SELECT fvl.`value` FROM `' . _DB_PREFIX_ . 'feature_product` fp
             LEFT JOIN `' . _DB_PREFIX_ . 'feature_value_lang` fvl ON fvl.`id_feature_value` = fp.`id_feature_value` AND fvl.`id_lang` = ' . $idLang . '
             WHERE fp.`id_product` = ' . $idProduct . ' AND fp.`id_feature` = ' . $idFeature
        ) ?: [];

        // Kilka wartosci cechy (np. dwa materialy) — Google przyjmuje je rozdzielone "/".
        return implode('/', array_filter(array_map(static fn (array $r): string => trim((string) $r['value']), $values)));
    }
}
