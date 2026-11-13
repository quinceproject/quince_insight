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

use Quince\Insight\Service\Merchant\Dto\Feed;
use Quince\Insight\Service\Merchant\FeedRepository;
use Quince\Insight\Ui\Pagination;
use Symfony\Component\HttpFoundation\Request;

/**
 * Dane zakladek Mapping, Rules, Promotions, Shipping, Reports ekranu Merchant Center
 * (kontrakt ARCHITEKTURA §9).
 *
 * Agent E renderuje ekran i wola `for()`; wynik ma jeden klucz `mt`, wiec dolacza sie go bez
 * kolizji ze zmiennymi ekranu:
 *
 *   {% include '@Modules/quince_insight/views/templates/admin/merchant/tabs/' ~ tab ~ '.html.twig' with tabData %}
 *
 * Szablony zakladek ladowane z pliku `views/js/admin/merchant-tools.js` (dolaczaja go same).
 * `mt.route` / `mt.routeParams` — trasa ekranu do odnosnikow stronicowania (domyslnie `quince_insight_merchant`).
 */
final class TabData
{
    /** Zakladki nalezace do agenta M. */
    public const TABS = ['mapping', 'rules', 'promotions', 'shipping', 'reports'];

    public function __construct(
        private readonly Mapping $mapping,
        private readonly Taxonomy $taxonomy,
        private readonly RuleRepository $rules,
        private readonly PromotionService $promotions,
        private readonly ShippingService $shipping,
        private readonly MerchantApi $api,
        private readonly ConditionMap $conditions,
        private readonly FeedRepository $feeds,
    ) {
    }

    /**
     * Feedy sklepu do wyboru zasiegu reguly: id => nazwa ("PL · pl · EUR").
     *
     * @param Feed[] $feeds
     *
     * @return array<int,array{id:int,name:string}>
     */
    public static function feedOptions(array $feeds): array
    {
        return array_map(static fn (Feed $f): array => ['id' => $f->id, 'name' => $f->name], array_values($feeds));
    }

    /**
     * Liczba wlaczonych regul cenowych obowiazujacych w feedzie (wlasne + wspolne). Decyzja #11a:
     * zakladka What is sent ostrzega przy sekcji "Price", bo przedzialy porownuja cene przed tymi regulami.
     */
    public function activePricingRules(int $idShop, int $idFeed): int
    {
        return count($this->rules->forFeed($idShop, $idFeed, RuleDefinitions::TYPES['pricing'], true));
    }

    /**
     * @return array<string,mixed>
     */
    public function for(string $tab, int $idShop, ?Feed $feed, Request $r): array
    {
        if (!in_array($tab, self::TABS, true)) {
            return [];
        }

        $route = (string) $r->attributes->get('_route', 'quince_insight_merchant');
        $base = [
            'tab' => $tab,
            'feedId' => $feed?->id ?? 0,
            'country' => $feed?->country ?? '',
            'hasAccount' => $this->api->accountId($idShop) !== '',
            'route' => str_starts_with($route, 'quince_insight_merchant') ? $route : 'quince_insight_merchant',
            'routeParams' => array_filter(['tab' => $tab, 'feed' => $feed?->id] + ($r->query->has('country') ? ['country' => (string) $r->query->get('country')] : [])),
        ];

        return ['mt' => $base + match ($tab) {
            'mapping' => $this->mappingData($idShop, $feed, $r),
            'rules' => $this->rulesData($idShop, $feed),
            'promotions' => $this->promotionsData($idShop, $feed),
            'shipping' => $this->shipping->local($idShop) + ['hasFeeds' => $this->shipping->hasFeeds($idShop)],
            'reports' => $this->reportsData($feed),
        }];
    }

    /** @return array<string,mixed> */
    private function mappingData(int $idShop, ?Feed $feed, Request $r): array
    {
        $idLang = $feed?->idLang ?: (int) \Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);
        $locale = Taxonomy::locale($feed?->contentLanguage ?: (string) \Language::getIsoById($idLang));
        $ids = $this->mapping->identifierSettings($idShop);

        $saved = $this->mapping->savedAttributes($idShop);
        $detected = $this->mapping->detectedAttributes($idLang);
        $sources = $this->mapping->attributeSources($idLang);
        $attrs = [];

        foreach (MappingValues::TARGET_LABELS as $target => $label) {
            $source = '';
            $auto = false;

            if (isset($saved[$target])) {
                $source = $saved[$target]['type'] !== '' ? $saved[$target]['type'] . ':' . $saved[$target]['id'] : '';
            } elseif (isset($detected[$target])) {
                $source = $detected[$target]['type'] . ':' . $detected[$target]['id'];
                $auto = true;
            }

            $attrs[] = ['google' => $label, 'target' => $target, 'source' => $source, 'auto' => $auto, 'on' => $source !== ''];
        }

        $categories = $this->mapping->categories($idShop, $idLang);
        $pagination = Pagination::build((int) $r->query->get('page', 1), (int) $r->query->get('per', 0), count($categories), [10, 25, 50]);
        $page = array_slice($categories, $pagination['offset'], $pagination['per']);
        $paths = $this->taxonomy->paths(array_merge(array_column($page, 'google'), array_column($page, 'inherited')), $locale);

        foreach ($page as &$row) {
            $row['googlePath'] = $row['google'] > 0 ? ($paths[$row['google']] ?? '#' . $row['google']) : '';
            $row['inheritedPath'] = $row['inherited'] > 0 ? ($paths[$row['inherited']] ?? '#' . $row['inherited']) : '';
        }
        unset($row);

        $mapped = count(array_filter($categories, static fn (array $c): bool => $c['google'] > 0));

        return [
            'identifiers' => [
                ['k' => 'gtinSource', 'label' => 'GTIN', 'value' => $ids['gtin'], 'opts' => MappingValues::GTIN_SOURCES],
                ['k' => 'mpnSource', 'label' => 'MPN', 'value' => $ids['mpn'], 'opts' => MappingValues::MPN_SOURCES],
                ['k' => 'brandSource', 'label' => 'Brand', 'value' => $ids['brand'], 'opts' => MappingValues::BRAND_SOURCES],
                ['k' => 'identExists', 'label' => 'identifier_exists', 'value' => $ids['exists'], 'opts' => MappingValues::EXISTS_MODES],
            ],
            'attrs' => $attrs,
            'sources' => array_map(static fn (array $s): array => ['key' => $s['key'], 'label' => $s['label']], $sources),
            'categories' => $page,
            'pagination' => $pagination,
            'mappedCount' => $mapped,
            'categoryCount' => count($categories),
            'locale' => $locale,
            // Decyzja #12: sekcja "Product condition" tylko, gdy PrestaShop zna stany 9.2.
            'conditions' => $this->conditionRows($idShop),
            'conditionTargets' => ConditionMap::TARGETS,
        ];
    }

    /** @return array<int,array{state:string,label:string,value:string}> */
    private function conditionRows(int $idShop): array
    {
        $rows = [];

        foreach ($this->conditions->forShop($idShop) as $state => $target) {
            $rows[] = ['state' => $state, 'label' => ConditionMap::STATES[$state], 'value' => $target];
        }

        return $rows;
    }

    /** @return array<string,mixed> */
    private function rulesData(int $idShop, ?Feed $feed): array
    {
        $types = [];

        foreach (RuleDefinitions::TYPE_LABELS as $key => $t) {
            $types[] = ['key' => $key, 'label' => $t['label'], 'empty' => $t['empty']];
        }

        return [
            'rules' => array_map([RuleDefinitions::class, 'toEditor'], $this->rules->forFeed($idShop, $feed?->id ?? 0)),
            // Decyzja #14: zasieg reguly — 0 = wszystkie feedy sklepu albo konkretny feed.
            'feeds' => self::feedOptions($this->feeds->forShop($idShop)),
            'types' => $types,
            'fields' => RuleDefinitions::FIELDS,
            'ops' => RuleDefinitions::OPS,
            'pricingActions' => RuleDefinitions::PRICING_ACTIONS,
            'identTargets' => RuleDefinitions::IDENT_TARGETS,
        ];
    }

    /** @return array<string,mixed> */
    private function promotionsData(int $idShop, ?Feed $feed): array
    {
        $supported = $feed !== null && PromotionCountries::supports($feed->country);

        return [
            'supported' => $supported,
            'countryName' => $feed !== null ? $this->countryName($feed->country, $feed->idLang, $feed->idShop) : '',
            'countries' => PromotionCountries::sentence((int) (\Context::getContext()->language->id ?? 0)),
            'promos' => $supported ? $this->promotions->listForFeed($idShop, $feed) : [],
        ];
    }

    /** @return array<string,mixed> */
    private function reportsData(?Feed $feed): array
    {
        return [
            'periods' => ReportService::PERIODS,
            'marketCountry' => $feed?->country ?? '',
            'marketCountryName' => $feed !== null ? $this->countryName($feed->country, $feed->idLang, $feed->idShop) : '',
        ];
    }

    private function countryName(string $iso, int $idLang, int $idShop): string
    {
        $id = (int) \Country::getByIso($iso);
        // Interfejs jest po angielsku (tlumaczenie w XLF) — nazwa kraju tez, gdy sklep ma angielski.
        $lang = (int) \Language::getIdByIso('en') ?: ((int) \Configuration::get('PS_LANG_DEFAULT', null, null, $idShop) ?: $idLang);
        $name = $id > 0 ? (string) \Country::getNameById($lang, $id) : '';

        return $name !== '' ? $name : strtoupper($iso);
    }
}
