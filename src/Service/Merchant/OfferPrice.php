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

use Quince\Insight\Service\Merchant\Dto\Feed;

/**
 * Cena oferty w kontekscie feedu — JEDNO miejsce dla budowy oferty (`ProductBuilder`)
 * i filtra cen "What is sent" (decyzja #11): licznik, lista Products i synchronizacja
 * porownuja z zakresami dokladnie te kwote, ktora idzie do Google.
 *
 * `Product::priceCalculation()` dostaje jawnie sklep, kraj feedu, walute feedu i grupe
 * "Visitor" — PrestaShop sam przelicza kurs, dolicza podatek kraju feedu (gdy feed z VAT)
 * i dobiera ceny specyficzne / promocje dla tej waluty i kraju.
 */
final class OfferPrice
{
    /** @var array<string,array{regular:float,final:float}> */
    private array $memo = [];

    /** @var array<string,int> */
    private array $countries = [];

    /**
     * Cena regularna i koncowa (z rabatami) w walucie feedu, z podatkiem kraju feedu albo bez.
     *
     * @return array{regular:float,final:float}
     */
    public function prices(Feed $feed, int $idProduct, int $idProductAttribute): array
    {
        $key = $feed->id . ':' . $feed->idCurrency . ':' . ($feed->withTax ? 1 : 0) . ':' . $idProduct . '-' . $idProductAttribute;

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        if (count($this->memo) > 20000) {
            $this->memo = [];
        }

        $idCountry = $this->countryId($feed->country, $feed->idShop);
        $idGroup = (int) \Configuration::get('PS_UNIDENTIFIED_GROUP', null, null, $feed->idShop) ?: 1;
        $specific = null;

        $calc = function (bool $useReduction) use ($feed, $idProduct, $idProductAttribute, $idCountry, $idGroup, &$specific): float {
            return (float) \Product::priceCalculation(
                $feed->idShop,
                $idProduct,
                $idProductAttribute,
                $idCountry,
                0,
                '',
                $feed->idCurrency,
                $idGroup,
                1,
                $feed->withTax,
                6,
                false,
                $useReduction,
                true,
                $specific,
                true,
                0,
                true,
                0,
                0
            );
        };

        $final = $calc(true);
        $regular = $calc(false);

        return $this->memo[$key] = ['regular' => max($regular, $final), 'final' => $final];
    }

    /**
     * Kwota wysylana do Google (po zaokragleniu do groszy): `salePrice`, gdy jest promocja,
     * inaczej `price` — tak samo jak w `ProductBuilder::build()`.
     */
    public function sent(Feed $feed, int $idProduct, int $idProductAttribute): float
    {
        return self::sentValue($this->prices($feed, $idProduct, $idProductAttribute));
    }

    /** @param array{regular:float,final:float} $prices */
    public static function hasSale(array $prices): bool
    {
        return $prices['final'] < $prices['regular'] - 0.004;
    }

    /** @param array{regular:float,final:float} $prices */
    public static function sentValue(array $prices): float
    {
        return round(self::hasSale($prices) ? $prices['final'] : $prices['regular'], 2);
    }

    public function reset(): void
    {
        $this->memo = [];
    }

    private function countryId(string $iso, int $idShop): int
    {
        $key = $iso . '.' . $idShop;

        return $this->countries[$key] ??= (int) \Country::getByIso($iso) ?: (int) \Configuration::get('PS_COUNTRY_DEFAULT', null, null, $idShop);
    }
}
