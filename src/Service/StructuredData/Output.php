<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\StructuredData;

/**
 * Co modul wypisuje na stronie danego typu — wspolne dla strony sklepu (displayHeader)
 * i podgladu w panelu, wiec podglad pokazuje dokladnie to, co trafi na strone.
 *
 * Reguly (prototyp + research/05 §K):
 *  - na stronach Quince Blog nic (blog wypisuje swoje dane sam),
 *  - Organization na kazdej stronie; polityki wysylki i zwrotow tylko na stronie glownej
 *    (Google: na jednej stronie) — gdy Organization jest wylaczony, a wysylka wlaczona,
 *    strona glowna dostaje Organization z samymi politykami,
 *  - WebSite na stronie glownej, BreadcrumbList i ItemList na kategorii, BreadcrumbList
 *    i Product / ProductGroup na produkcie; wysylka i zwroty w ofertach produktu modulu,
 *  - "Product variants" zamienia Product na ProductGroup dla produktu z kombinacjami,
 *  - blok, ktory nie spelnia wymagan Google (`Validator`), nie jest wypisywany.
 */
final class Output
{
    public const HOME = 'home';
    public const CATEGORY = 'category';
    public const PRODUCT = 'product';
    public const BLOG = 'blog';
    public const OTHER = 'other';

    public function __construct(
        private readonly JsonLdBuilder $builder,
        private readonly ShopData $shop,
        private readonly ShippingSource $shipping,
    ) {
    }

    /**
     * @param array<string,bool> $on wlaczone typy (Types::normalize)
     *
     * @return array{nodes: array<int,array<string,mixed>>, skipped: array<int,array{type:string,errors:string[]}>}
     */
    public function build(int $idShop, int $idLang, string $page, int $id, array $on, int $listPage = 1): array
    {
        $result = ['nodes' => [], 'skipped' => []];

        if ($page === self::BLOG || !in_array(true, $on, true)) {
            return $result;
        }

        $shipping = $on[Types::SHIPPING] ? $this->shipping->forShop($idShop) : null;
        $candidates = [];

        if ($on[Types::ORGANIZATION] || ($page === self::HOME && $shipping !== null)) {
            $org = $this->shop->organization($idShop, $idLang);
            $candidates[] = $this->builder->organization($org, $page === self::HOME ? $shipping : null);
        }

        if ($page === self::HOME && $on[Types::WEBSITE]) {
            $candidates[] = $this->builder->website($this->shop->website($idShop, $idLang));
        }

        if ($page === self::CATEGORY && $id > 0) {
            if ($on[Types::BREADCRUMB]) {
                $candidates[] = $this->builder->breadcrumb($this->shop->categoryPath($id, $idShop, $idLang));
            }

            if ($on[Types::ITEMLIST]) {
                $candidates[] = $this->builder->itemList($this->shop->categoryItems($id, $idShop, $idLang, max(1, $listPage)));
            }
        }

        if ($page === self::PRODUCT && $id > 0) {
            if ($on[Types::BREADCRUMB]) {
                $candidates[] = $this->builder->breadcrumb($this->shop->productPath($id, $idShop, $idLang));
            }

            $node = $this->productNode($id, $idShop, $idLang, $on, $shipping);

            if ($node !== false) {
                $candidates[] = $node;
            }
        }

        foreach ($candidates as $node) {
            if ($node === null) {
                continue;
            }

            $errors = Validator::errors($node);

            if ($errors === []) {
                $result['nodes'][] = $node;
            } else {
                $result['skipped'][] = ['type' => (string) $node['@type'], 'errors' => $errors];
            }
        }

        return $result;
    }

    /**
     * @param array<string,bool> $on
     * @param array<string,mixed>|null $shipping
     *
     * @return array<string,mixed>|null|false false = typ wylaczony (nic do sprawdzania)
     */
    private function productNode(int $id, int $idShop, int $idLang, array $on, ?array $shipping): array|null|false
    {
        $variants = $on[Types::VARIANTS] && $this->shop->hasCombinations($id, $idShop);

        if (!$variants && !$on[Types::PRODUCT]) {
            return false;
        }

        $data = $this->shop->product($id, $idShop, $idLang);

        if ($data === null) {
            return null;
        }

        return $variants && $data['variants'] !== []
            ? $this->builder->productGroup($data, $shipping)
            : $this->builder->product($data, $shipping);
    }

    /**
     * Gotowy HTML: jeden `<script type="application/ld+json" data-qi>` na blok.
     *
     * @param array<int,array<string,mixed>> $nodes
     */
    public static function html(array $nodes): string
    {
        $out = '';

        foreach ($nodes as $node) {
            $out .= '<script type="application/ld+json" data-qi="structured-data">' . JsonLdBuilder::encode($node) . '</script>' . "\n";
        }

        return $out;
    }
}
