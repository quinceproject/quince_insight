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

/**
 * Oferta (produkt prosty albo jedna kombinacja) po zebraniu danych ze sklepu, przed
 * mapowaniem i regulami (kontrakt ARCHITEKTURA §7.6).
 *
 * Kolejnosc: `ProductBuilder` wypelnia `$fields` i `$context` w kontekscie feedu (jezyk,
 * waluta, kraj podatku) → `Mapping::apply()` → `RuleEngine::apply()` → `PayloadBuilder`
 * zamienia `$fields` na `ProductInput` Merchant API. Mapowanie i reguly zmieniaja `$fields`
 * w miejscu; `$context` jest tylko do odczytu (warunki regul, zrodla atrybutow).
 *
 * Klucze `$fields` (research/02 §1.4, DataMapper FeedForge) — wszystkie opcjonalne poza
 * pierwszymi szescioma:
 *   offerId, contentLanguage, feedLabel, title, link, price          string / price
 *   description, imageLink                                              string
 *   additionalImageLinks                                                string[]
 *   availability    in_stock | out_of_stock | backorder | preorder | limited_availability
 *   condition       new | used | refurbished
 *   price, salePrice  ['value' => '12.30', 'currency' => 'PLN'] (waluta feedu, juz przeliczona,
 *                     z podatkiem kraju feedu albo bez — wg ustawienia feedu)
 *   gtin, mpn, brand                                                    string ('' = brak)
 *   identifierExists                                                    bool
 *   googleProductCategory                                               string (id albo sciezka)
 *   productType                                                         string ("A > B > C")
 *   itemGroupId                                                         string (id produktu przy kombinacji)
 *   shippingWeight  ['value' => '0.300', 'unit' => 'kg']
 *   color, sizes, gender, ageGroup, material, pattern                   string
 *   customLabel0 … customLabel4                                         string
 *
 * Wartosci domyslne identyfikatorow (gtin = EAN-13, mpn = reference, brand = producent,
 * identifierExists = gtin || mpn) ustawia budowniczy; `Mapping::apply()` moze je zmienic.
 *
 * Klucze `$context` (surowe dane sklepu dla regul i mapowania; wartosci kombinacji maja
 * pierwszenstwo, pusta wartosc kombinacji = wartosc produktu):
 *   id_product, id_product_attribute, id_category_default, id_manufacturer, id_supplier  int
 *   price         float  cena bazowa netto w walucie domyslnej sklepu (bez rabatow, z wplywem kombinacji)
 *   quantity      int    stan oferty (StockAvailable)
 *   weight        float  kg (z wplywem kombinacji)
 *   condition, visibility, brand, ean13, upc, isbn, mpn, reference, supplier_reference  string
 *   country_code  string kraj feedu
 *   has_combination  bool
 *   attributes    array<int, array{group:string, value:string}>   id_attribute_group => wartosc kombinacji
 *   features      array<int, array{name:string, value:string}>    id_feature => wartosc cechy produktu
 *   id_feed, id_lang, id_currency  int
 */
final class ProductData
{
    /** @var array<string,mixed> */
    public array $fields = [];

    public int $idProduct = 0;

    public int $idProductAttribute = 0;

    /** @var int[] */
    public array $categoryIds = [];

    public int $idDefaultCategory = 0;

    /**
     * Rozszerzenie kontraktu: surowe dane sklepu (opis kluczy wyzej).
     *
     * @var array<string,mixed>
     */
    public array $context = [];

    /** Rozszerzenie kontraktu: sklep i feed, dla ktorych zbudowano oferte. */
    public int $idShop = 0;

    public int $idFeed = 0;
}
