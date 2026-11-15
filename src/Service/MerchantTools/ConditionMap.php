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

use Quince\Insight\Core\Options;

/**
 * Mapowanie stanow produktu PrestaShop 9.2 (`open_box`, `damaged`, `new_with_defects`)
 * na stan Google (decyzja #12 w DECYZJE-PO-FALI-1). Google zna tylko NEW / USED /
 * REFURBISHED (research/04 §7.5); "Do not send" (`skip`) wyklucza produkt z feedu.
 *
 * Jedno miejsce dla licznika, listy Products i synchronizacji: `FeedFilters::condition()`
 * dostaje mape i wpuszcza stan PrestaShop tylko wtedy, gdy stan Google, na ktory jest
 * mapowany, jest zaznaczony w filtrze "Condition" ("skip" nigdy). `ProductBuilder` wysyla
 * stan Google z tej samej mapy.
 *
 * Stany wykrywane po `ProductCondition::AVAILABLE_CONDITIONS` (nie po numerze wersji):
 * na PrestaShop bez tych stanow mapa jest pusta i sekcja w Mapping jest ukryta.
 */
final class ConditionMap
{
    public const OPTION = 'QUINCE_INSIGHT_GMC_CONDITION_MAP';

    /** Stany PrestaShop, ktorych Google nie zna — etykiety do sekcji "Product condition". */
    public const STATES = [
        'open_box' => 'Open box',
        'damaged' => 'Damaged',
        'new_with_defects' => 'New with defects',
    ];

    /** Wybor w Mapping: stan Google albo "nie wysylaj". */
    public const TARGETS = [
        'used' => 'Used',
        'refurbished' => 'Refurbished',
        'new' => 'New',
        'skip' => 'Do not send',
    ];

    public const DEFAULT_TARGET = 'used';

    /** Stany, ktore Google zna wprost (filtr "Condition"). */
    public const GOOGLE = ['new', 'used', 'refurbished'];

    /** @var array<int,array<string,string>> */
    private array $memo = [];

    /** @var string[]|null */
    private static ?array $supported = null;

    /**
     * Stany z `STATES`, ktore zna ta instalacja PrestaShop.
     *
     * @return string[]
     */
    public static function supported(): array
    {
        if (self::$supported === null) {
            $class = 'PrestaShop\\PrestaShop\\Core\\Domain\\Product\\ValueObject\\ProductCondition';
            $known = class_exists($class) && defined($class . '::AVAILABLE_CONDITIONS') ? (array) constant($class . '::AVAILABLE_CONDITIONS') : [];
            self::$supported = array_values(array_intersect(array_keys(self::STATES), array_map('strval', $known)));
        }

        return self::$supported;
    }

    /** Tylko dla testow jednostkowych (bez PrestaShop). */
    public static function setSupportedForTests(?array $states): void
    {
        self::$supported = $states;
    }

    /**
     * Mapa sklepu: stan PrestaShop => used | refurbished | new | skip (tylko stany znane instalacji).
     *
     * @return array<string,string>
     */
    public function forShop(int $idShop): array
    {
        if (isset($this->memo[$idShop])) {
            return $this->memo[$idShop];
        }

        $saved = json_decode(Options::str(self::OPTION, '', $idShop), true);

        return $this->memo[$idShop] = self::normalize(is_array($saved) ? $saved : [], self::supported());
    }

    public function save(int $idShop, string $state, string $target): bool
    {
        if (!in_array($state, self::supported(), true) || !isset(self::TARGETS[$target])) {
            return false;
        }

        $map = $this->forShop($idShop);
        $map[$state] = $target;
        Options::set(self::OPTION, (string) json_encode($map), $idShop);
        unset($this->memo[$idShop]);

        return true;
    }

    /**
     * @param array<mixed> $saved
     * @param string[] $supported
     *
     * @return array<string,string>
     */
    public static function normalize(array $saved, array $supported): array
    {
        $out = [];

        foreach ($supported as $state) {
            $value = (string) ($saved[$state] ?? '');
            $out[$state] = isset(self::TARGETS[$value]) ? $value : self::DEFAULT_TARGET;
        }

        return $out;
    }

    /**
     * Stan Google dla stanu PrestaShop: new | used | refurbished; null = "Do not send".
     *
     * @param array<string,string> $map
     */
    public static function google(string $condition, array $map): ?string
    {
        $condition = strtolower($condition);

        if (in_array($condition, self::GOOGLE, true)) {
            return $condition;
        }

        $target = $map[$condition] ?? self::DEFAULT_TARGET;

        return $target === 'skip' ? null : $target;
    }

    /**
     * Wartosci `product_shop.condition`, ktore przechodza filtr "Condition" feedu.
     *
     * @param string[] $filter zaznaczone stany Google (new / used / refurbished)
     * @param array<string,string> $map
     *
     * @return string[]
     */
    public static function shopValues(array $filter, array $map): array
    {
        $out = array_values(array_intersect(self::GOOGLE, $filter));

        foreach ($map as $state => $target) {
            if ($target !== 'skip' && in_array($target, $filter, true)) {
                $out[] = (string) $state;
            }
        }

        return $out;
    }
}
