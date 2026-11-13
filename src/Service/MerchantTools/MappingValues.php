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

/**
 * Czysta logika mapowania (bez bazy): identyfikatory, normalizacja plci i wieku,
 * wykrywanie atrybutow po nazwie, dziedziczenie kategorii w dol drzewa.
 */
final class MappingValues
{
    /** Zrodla identyfikatorow — opcje 1:1 z prototypu (zakladka Mapping, "Identifiers"). */
    public const GTIN_SOURCES = ['ean13' => 'EAN-13', 'upc' => 'UPC', 'isbn' => 'ISBN', 'none' => 'Do not send'];
    public const MPN_SOURCES = ['reference' => 'Reference', 'mpn' => 'MPN field', 'supplier_reference' => 'Supplier reference', 'none' => 'Do not send'];
    public const BRAND_SOURCES = ['manufacturer' => 'Manufacturer', 'shop' => 'Shop name', 'none' => 'Do not send'];
    public const EXISTS_MODES = ['auto' => 'Automatic — no when GTIN and MPN are missing', 'yes' => 'Always yes', 'no' => 'Always no'];

    public const DEFAULTS = ['gtin' => 'ean13', 'mpn' => 'reference', 'brand' => 'manufacturer', 'exists' => 'auto'];

    /**
     * Atrybuty Google (kolejnosc i nazwy jak w prototypie) => slowa w nazwie grupy atrybutow
     * albo cechy, po ktorych modul sam je rozpoznaje (angielski, polski, niemiecki, czeski).
     */
    public const ATTRIBUTE_TARGETS = [
        'color' => ['color', 'colour', 'kolor', 'barwa', 'farbe', 'barva', 'couleur'],
        'size' => ['size', 'rozmiar', 'größe', 'grosse', 'velikost', 'taille'],
        'material' => ['material', 'materiał', 'tworzywo', 'composition', 'skład', 'materiál', 'matière'],
        'pattern' => ['pattern', 'wzór', 'deseń', 'muster', 'vzor', 'motif'],
        'gender' => ['gender', 'płeć', 'plec', 'geschlecht', 'pohlaví', 'sexe'],
        'age_group' => ['age group', 'age', 'grupa wiekowa', 'wiek', 'altersgruppe', 'věk', 'âge'],
    ];

    /** Klucz atrybutu w tabeli `attribute_map` => nazwa pola Google pokazywana w interfejsie (prototyp). */
    public const TARGET_LABELS = [
        'color' => 'color', 'size' => 'size', 'material' => 'material',
        'pattern' => 'pattern', 'gender' => 'gender', 'age_group' => 'ageGroup',
    ];

    /**
     * Identyfikatory produktu wg ustawien sklepu.
     *
     * @param array{gtin:string,mpn:string,brand:string,exists:string} $settings
     * @param array<string,mixed> $product wiersz produktu (ean13, upc, isbn, reference, mpn, supplier_reference, manufacturer)
     * @param array<string,mixed>|null $combination wiersz kombinacji albo null
     *
     * @return array{gtin:string,mpn:string,brand:string,identifierExists:bool}
     */
    public static function identifiers(array $settings, array $product, ?array $combination, string $shopName): array
    {
        $value = static function (string $column) use ($product, $combination): string {
            return RuleContext::pick($combination[$column] ?? null, $product[$column] ?? null);
        };

        $gtinSource = $settings['gtin'];
        $gtin = isset(self::GTIN_SOURCES[$gtinSource]) && $gtinSource !== 'none' ? $value($gtinSource) : '';
        // Puste i same zera ("0000000000000" z importow) to nie GTIN.
        if ($gtin !== '' && trim($gtin, '0') === '') {
            $gtin = '';
        }

        $mpnSource = $settings['mpn'];
        $mpn = isset(self::MPN_SOURCES[$mpnSource]) && $mpnSource !== 'none' ? $value($mpnSource) : '';

        $brand = match ($settings['brand']) {
            'manufacturer' => trim((string) ($product['manufacturer'] ?? '')),
            'shop' => trim($shopName),
            default => '',
        };

        $exists = match ($settings['exists']) {
            'yes' => true,
            'no' => false,
            default => $gtin !== '' || $mpn !== '',
        };

        return ['gtin' => $gtin, 'mpn' => $mpn, 'brand' => $brand, 'identifierExists' => $exists];
    }

    /** Atrybut Google, ktory pasuje do nazwy grupy atrybutow lub cechy; null gdy zaden. */
    public static function detectTarget(string $name): ?string
    {
        $n = mb_strtolower(trim($name));

        if ($n === '') {
            return null;
        }

        foreach (self::ATTRIBUTE_TARGETS as $target => $words) {
            foreach ($words as $word) {
                if ($n === $word) {
                    return $target;
                }
            }
        }

        // Druga runda: slowo jako czesc nazwy ("Kolor obudowy", "Shoe size").
        foreach (self::ATTRIBUTE_TARGETS as $target => $words) {
            foreach ($words as $word) {
                if (mb_strlen($word) >= 4 && preg_match('/(^|[^\p{L}])' . preg_quote($word, '/') . '($|[^\p{L}])/u', $n)) {
                    return $target;
                }
            }
        }

        return null;
    }

    /** Wartosc plci w enum Merchant API v1 (`MALE`, `FEMALE`, `UNISEX`); slowa z FeedForge. */
    public static function normalizeGender(string $value): string
    {
        $lower = mb_strtolower(trim($value));

        return match (true) {
            in_array($lower, ['male', 'men', 'man', 'mężczyzna', 'mężczyźni', 'męski', 'męska', 'herren', 'männlich'], true) => 'MALE',
            in_array($lower, ['female', 'women', 'woman', 'kobieta', 'kobiety', 'damski', 'damska', 'damen', 'weiblich'], true) => 'FEMALE',
            in_array($lower, ['unisex', 'both', 'uniseks'], true) => 'UNISEX',
            default => '',
        };
    }

    /** Grupa wiekowa w enum Merchant API v1 (`ADULT`, `KIDS`, `TODDLER`, `INFANT`, `NEWBORN`). */
    public static function normalizeAgeGroup(string $value): string
    {
        $lower = mb_strtolower(trim($value));

        return match (true) {
            in_array($lower, ['adult', 'adults', 'dorośli', 'dorosły', 'dorosła', 'erwachsene'], true) => 'ADULT',
            in_array($lower, ['kids', 'children', 'child', 'dzieci', 'dziecięcy', 'dziecięca', 'kinder'], true) => 'KIDS',
            in_array($lower, ['toddler', 'maluch'], true) => 'TODDLER',
            in_array($lower, ['infant', 'baby', 'niemowlę', 'niemowlęcy'], true) => 'INFANT',
            in_array($lower, ['newborn', 'noworodek'], true) => 'NEWBORN',
            default => '',
        };
    }

    /**
     * Kategoria Google dla kategorii sklepu — wlasna albo najblizszego przodka z mapowaniem.
     *
     * @param array<int,int> $map id_category => google_id (tylko zmapowane, > 0)
     * @param array<int,int> $parents id_category => id_parent
     * @param int[] $roots kategorie, na ktorych przestaje sie szukac (korzen sklepu i wyzej)
     */
    public static function inheritedCategory(array $map, array $parents, int $idCategory, array $roots = []): ?int
    {
        $seen = [];
        $current = $idCategory;

        while ($current > 0 && !isset($seen[$current])) {
            $seen[$current] = true;

            if (($map[$current] ?? 0) > 0) {
                return $map[$current];
            }

            if (in_array($current, $roots, true)) {
                return null;
            }

            $current = $parents[$current] ?? 0;
        }

        return null;
    }
}
