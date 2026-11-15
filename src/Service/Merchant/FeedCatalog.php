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

use Db;
use Quince\Insight\Core\Schema as CoreSchema;
use Quince\Insight\Service\Merchant\Dto\Feed;
use Quince\Insight\Service\MerchantTools\ConditionMap;
use Quince\Insight\Service\MerchantTools\RuleEngine;

/**
 * Oferty sklepu w kontekscie feedu — wspolne zrodlo licznika "What is sent", listy Products
 * i synchronizacji. Wszystkie trzy uzywaja tego samego `from()` i tego samego warunku
 * `FeedFilters::condition()`, wiec nie moga sie rozjechac (SPEC §5.4 [F]).
 *
 * Oferta = produkt prosty (`id_product_attribute = 0`) albo kombinacja produktu w danym
 * sklepie. Produkt z kombinacjami nigdy nie jest oferta "rodzicem" (research/02 §1.4).
 * Wszechswiat = aktywne, zapisane produkty sklepu z tekstami w jezyku feedu.
 *
 * Dwa kroki poza SQL, oba liczone tymi samymi funkcjami co przy wysylce:
 *  - filtr cen na kwocie wysylanej do Google (`OfferPrice::sent()`, decyzja #11),
 *  - reguly wykluczajace agenta M (`RuleEngine::excludedPairs()`).
 * Stany 9.2 z "Do not send" (decyzja #12) odpadaja juz w SQL (`ConditionMap`).
 */
final class FeedCatalog
{
    /** @var array<int,string> */
    private array $stockJoin = [];

    /** @var array<string,array<string,string>> */
    private array $ruleCache = [];

    /** @var array<string,array<string,bool>> */
    private array $priceCache = [];

    public function __construct(
        private readonly RuleEngine $rules,
        private readonly OfferPrice $prices,
        private readonly ConditionMap $conditions,
    ) {
    }

    /**
     * Warunek SQL filtrow feedu (bez zakresow cen) z mapowaniem stanow produktu sklepu.
     *
     * @param array<string,mixed>|null $filters
     */
    public function where(Feed $feed, ?array $filters = null): string
    {
        return FeedFilters::condition($filters ?? $feed->filters, $feed->idShop, $feed->idLang, _DB_PREFIX_, $this->conditions->forShop($feed->idShop));
    }

    /** Wszystkie oferty, ktore moglyby trafic do feedu (mianownik "of N"). */
    public function total(Feed $feed): int
    {
        return (int) Db::getInstance()->getValue('SELECT COUNT(*) ' . $this->from($feed));
    }

    /**
     * Oferty w feedzie — licznik (`$filters` z formularza przed zapisem albo z feedu):
     * pasujace do filtrow i niewykluczone regula wykluczajaca (agent M). Synchronizacja
     * wyklucza te same oferty przez `RuleEngine::apply()`, wiec licznik, lista i wysylka
     * daja ten sam zbior.
     *
     * @param array<string,mixed>|null $filters
     */
    public function count(Feed $feed, ?array $filters = null): int
    {
        $filters ??= $feed->filters;

        return max(0, $this->countCandidates($feed, $filters) - count($this->priceExcluded($feed, $filters)) - count($this->ruleExcluded($feed, $filters)));
    }

    /** Oferty pasujace do samych filtrow (z filtrem cen, bez regul) — do opisu "X of N match". */
    public function countFilters(Feed $feed): int
    {
        return max(0, $this->countCandidates($feed) - count($this->priceExcluded($feed)));
    }

    /**
     * Oferty z warunku SQL (przed filtrem cen) — zbior ocenianych przez `matching()`.
     *
     * @param array<string,mixed>|null $filters
     */
    public function countCandidates(Feed $feed, ?array $filters = null): int
    {
        return (int) Db::getInstance()->getValue('SELECT COUNT(*) ' . $this->from($feed) . ' AND ' . $this->where($feed, $filters));
    }

    /**
     * Oferty z warunku SQL, ktorych cena wysylana do Google nie przechodzi zakresow cen:
     * "idProduct-idAttribute" => true. Puste, gdy zakresow brak.
     *
     * @param array<string,mixed>|null $filters
     *
     * @return array<string,bool>
     */
    public function priceExcluded(Feed $feed, ?array $filters = null): array
    {
        $filters ??= $feed->filters;

        if (!FeedFilters::hasPriceRanges($filters)) {
            return [];
        }

        $key = self::cacheKey($feed, $filters);

        if (isset($this->priceCache[$key])) {
            return $this->priceCache[$key];
        }

        $out = [];
        $condition = $this->where($feed, $filters);

        for ($offset = 0; ; $offset += 1000) {
            $rows = Db::getInstance()->executeS('SELECT p.`id_product`, IFNULL(pas.`id_product_attribute`, 0) AS id_product_attribute '
                . $this->from($feed) . ' AND ' . $condition
                . ' ORDER BY p.`id_product` ASC, id_product_attribute ASC LIMIT 1000 OFFSET ' . $offset);

            if (!is_array($rows) || $rows === []) {
                break;
            }

            foreach ($rows as $r) {
                $idProduct = (int) $r['id_product'];
                $idAttribute = (int) $r['id_product_attribute'];

                if (!FeedFilters::priceMatches($filters, $this->prices->sent($feed, $idProduct, $idAttribute))) {
                    $out[$idProduct . '-' . $idAttribute] = true;
                }
            }
        }

        return $this->priceCache[$key] = $out;
    }

    /**
     * Oferty z filtrow wykluczone regula: "idProduct-idAttribute" => nazwa reguly
     * (`RuleEngine::excludedPairs`, te same reguly co przy wysylce).
     *
     * @param array<string,mixed>|null $filters
     *
     * @return array<string,string>
     */
    public function ruleExcluded(Feed $feed, ?array $filters = null): array
    {
        $filters ??= $feed->filters;
        $key = self::cacheKey($feed, $filters);

        if (isset($this->ruleCache[$key])) {
            return $this->ruleCache[$key];
        }

        if (!$this->rules->hasExclusions($feed->idShop, $feed->id)) {
            return $this->ruleCache[$key] = [];
        }

        $out = [];
        $condition = $this->where($feed, $filters);
        // Oferte odrzucona juz filtrem cen liczymy raz — jako spoza filtrow, nie jako regule.
        $byPrice = $this->priceExcluded($feed, $filters);

        for ($offset = 0; ; $offset += 1000) {
            $rows = Db::getInstance()->executeS('SELECT p.`id_product`, IFNULL(pas.`id_product_attribute`, 0) AS id_product_attribute '
                . $this->from($feed) . ' AND ' . $condition
                . ' ORDER BY p.`id_product` ASC, id_product_attribute ASC LIMIT 1000 OFFSET ' . $offset);

            if (!is_array($rows) || $rows === []) {
                break;
            }

            $pairs = [];

            foreach ($rows as $r) {
                if (!isset($byPrice[(int) $r['id_product'] . '-' . (int) $r['id_product_attribute']])) {
                    $pairs[] = [(int) $r['id_product'], (int) $r['id_product_attribute']];
                }
            }

            $out += $pairs ? $this->rules->excludedPairs($feed->idShop, $feed->id, $pairs) : [];
        }

        return $this->ruleCache[$key] = $out;
    }

    /**
     * Klucz pamieci: feed, ustawienia wplywajace na cene (podatek, waluta) i filtry.
     *
     * @param array<string,mixed> $filters
     */
    private static function cacheKey(Feed $feed, array $filters): string
    {
        return $feed->id . ':' . ($feed->withTax ? 1 : 0) . ':' . $feed->idCurrency . ':' . md5((string) json_encode(FeedFilters::normalize($filters)));
    }

    /** Po zmianie regul albo filtrow w tym samym zadaniu. */
    public function resetRules(): void
    {
        $this->ruleCache = [];
        $this->priceCache = [];
    }

    /**
     * Klucze ofert z warunku SQL (przed filtrem cen) — zbior synchronizacji (strona po stronie).
     * Oferty spoza zakresu cen sa tu takze: `FeedSync::evaluate()` rozstrzyga o nich przez
     * `offersOfProduct()` tym samym filtrem cen (i usuwa z Google, jesli tam sa).
     *
     * @return array<int,array{id_product:int,id_product_attribute:int}>
     */
    public function matching(Feed $feed, int $limit = 500, int $offset = 0): array
    {
        $rows = Db::getInstance()->executeS('SELECT p.`id_product`, IFNULL(pas.`id_product_attribute`, 0) AS id_product_attribute '
            . $this->from($feed) . ' AND ' . $this->where($feed)
            . ' ORDER BY p.`id_product` ASC, id_product_attribute ASC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset));

        return array_map(static fn (array $r): array => [
            'id_product' => (int) $r['id_product'],
            'id_product_attribute' => (int) $r['id_product_attribute'],
        ], is_array($rows) ? $rows : []);
    }

    /**
     * Stan wybranych ofert jednego produktu: istniejace w sklepie kombinacje i to, czy pasuja.
     *
     * @return array<int,bool> id_product_attribute => pasuje do filtrow
     */
    public function offersOfProduct(Feed $feed, int $idProduct): array
    {
        $rows = Db::getInstance()->executeS('SELECT IFNULL(pas.`id_product_attribute`, 0) AS id_product_attribute, '
            . $this->where($feed) . ' AS in_filters '
            . $this->from($feed) . ' AND p.`id_product` = ' . $idProduct);

        $out = [];
        $ranges = FeedFilters::hasPriceRanges($feed->filters);

        foreach (is_array($rows) ? $rows : [] as $row) {
            $idAttribute = (int) $row['id_product_attribute'];
            $in = (bool) $row['in_filters'];

            // Ten sam filtr cen co w liczniku (`priceExcluded`) — ta sama kwota z `OfferPrice`.
            if ($in && $ranges) {
                $in = FeedFilters::priceMatches($feed->filters, $this->prices->sent($feed, $idProduct, $idAttribute));
            }

            $out[$idAttribute] = $in;
        }

        return $out;
    }

    /**
     * Lista Products: wszystkie oferty z flaga "w filtrach" i stanem z `feed_product`.
     *
     * @param string $status all | ready | issue | excluded | approved | pending | disapproved
     *
     * @return array{total:int,rows:array<int,array<string,mixed>>}
     */
    public function page(Feed $feed, string $q, string $status, int $limit, int $offset): array
    {
        $offerKey = 'CONCAT(p.`id_product`, "-", IFNULL(pas.`id_product_attribute`, 0))';
        $priceKeys = array_keys($this->priceExcluded($feed));
        // "W filtrach" = warunek SQL i filtr cen (cena liczona w PHP, jak w liczniku).
        $in = $priceKeys
            ? '(' . $this->where($feed) . ' AND ' . $offerKey . ' NOT IN ("' . implode('","', array_map('pSQL', $priceKeys)) . '"))'
            : $this->where($feed);
        $from = $this->from($feed) . ' ';
        $fp = CoreSchema::table(Schema::FEED_PRODUCT);
        $join = ' LEFT JOIN `' . $fp . '` fp ON fp.`id_feed` = ' . $feed->id
            . ' AND fp.`id_product` = p.`id_product` AND fp.`id_product_attribute` = IFNULL(pas.`id_product_attribute`, 0)';
        // Dolaczenie stanu musi stac przed WHERE z `from()` — wstawiamy je przed nim.
        $from = preg_replace('/ WHERE /', $join . ' WHERE ', $from, 1);
        $where = '';

        $q = trim($q);

        if ($q !== '') {
            $like = '"%' . pSQL(addcslashes($q, '%_')) . '%"';
            $where .= ' AND (pl.`name` LIKE ' . $like . ' OR p.`reference` LIKE ' . $like . ' OR pa.`reference` LIKE ' . $like
                . ' OR p.`ean13` LIKE ' . $like . ' OR pa.`ean13` LIKE ' . $like
                . (ctype_digit($q) ? ' OR p.`id_product` = ' . (int) $q : '') . ')';
        }

        // Wykluczenie regula liczone na zywo (jak licznik), nie ze stanu ostatniej oceny —
        // inaczej zmiana reguly bylaby widoczna na liscie dopiero po synchronizacji.
        $ruleKeys = array_keys($this->ruleExcluded($feed));
        $byRule = $ruleKeys ? '(' . $offerKey . ' IN ("' . implode('","', array_map('pSQL', $ruleKeys)) . '"))' : '0';
        $excluded = '(NOT ' . $in . ' OR ' . $byRule . ')';
        $blocked = 'fp.`local_issues` LIKE \'%"level":"error"%\'';

        $where .= match ($status) {
            'excluded' => ' AND ' . $excluded,
            'ready' => ' AND NOT ' . $excluded . ' AND NOT IFNULL(' . $blocked . ', 0)',
            'issue' => ' AND NOT ' . $excluded . ' AND IFNULL(' . $blocked . ', 0)',
            'approved' => ' AND NOT ' . $excluded . ' AND fp.`google_status` = "approved"',
            'disapproved' => ' AND NOT ' . $excluded . ' AND fp.`google_status` = "disapproved"',
            'pending' => ' AND NOT ' . $excluded . ' AND IFNULL(fp.`google_status`, "") IN ("pending", "limited", "")',
            default => '',
        };

        $db = Db::getInstance();
        $total = (int) $db->getValue('SELECT COUNT(*) ' . $from . $where);
        $rows = $db->executeS('SELECT p.`id_product`, IFNULL(pas.`id_product_attribute`, 0) AS id_product_attribute, pl.`name`,
                IFNULL(NULLIF(pa.`reference`, ""), p.`reference`) AS reference, IFNULL(NULLIF(pa.`ean13`, ""), p.`ean13`) AS ean13,
                ' . $in . ' AS in_filters, fp.`id_feed_product`, fp.`offer_id`, fp.`sync_state`, fp.`sync_error`, fp.`local_issues`,
                fp.`google_status`, fp.`google_issues`, fp.`synced_at`, fp.`status_at` '
            . $from . $where . ' ORDER BY p.`id_product` ASC, id_product_attribute ASC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset));

        $rules = $this->ruleExcluded($feed);
        $rows = is_array($rows) ? $rows : [];

        foreach ($rows as &$row) {
            $k = (int) $row['id_product'] . '-' . (int) $row['id_product_attribute'];
            $row['rule_excluded'] = (bool) $row['in_filters'] && isset($rules[$k]);
            $row['rule_reason'] = $rules[$k] ?? '';
        }

        unset($row);

        return ['total' => $total, 'rows' => $rows];
    }

    /**
     * Wspolny poczatek zapytania (FROM … WHERE warunki wszechswiata). Aliasy jak w FeedFilters.
     */
    public function from(Feed $feed): string
    {
        $px = _DB_PREFIX_;
        $s = $feed->idShop;

        return 'FROM `' . $px . 'product` p
            INNER JOIN `' . $px . 'product_shop` ps ON ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . $s . '
            INNER JOIN `' . $px . 'product_lang` pl ON pl.`id_product` = p.`id_product` AND pl.`id_lang` = ' . $feed->idLang . ' AND pl.`id_shop` = ' . $s . '
            LEFT JOIN `' . $px . 'product_attribute_shop` pas ON pas.`id_product` = p.`id_product` AND pas.`id_shop` = ' . $s . '
            LEFT JOIN `' . $px . 'product_attribute` pa ON pa.`id_product_attribute` = pas.`id_product_attribute`
            LEFT JOIN `' . $px . 'stock_available` sa ON sa.`id_product` = p.`id_product`
                AND sa.`id_product_attribute` = IFNULL(pas.`id_product_attribute`, 0) AND ' . $this->stockShop($s) . '
            WHERE ps.`active` = 1 AND p.`state` = 1';
    }

    /**
     * Warunek sklepu dla `stock_available`: przy wspolnym stanie grupy sklepow wiersz ma
     * `id_shop = 0` i `id_shop_group` (tak dziala `StockAvailable::addSqlShopRestriction`).
     * FeedForge laczyl stan bez sklepu — w multistore brany byl przypadkowy wiersz.
     */
    private function stockShop(int $idShop): string
    {
        if (!isset($this->stockJoin[$idShop])) {
            $group = Db::getInstance()->getRow('SELECT g.`id_shop_group`, g.`share_stock` FROM `' . _DB_PREFIX_ . 'shop` s
                INNER JOIN `' . _DB_PREFIX_ . 'shop_group` g ON g.`id_shop_group` = s.`id_shop_group` WHERE s.`id_shop` = ' . $idShop);

            $this->stockJoin[$idShop] = is_array($group) && (int) ($group['share_stock'] ?? 0) === 1
                ? 'sa.`id_shop` = 0 AND sa.`id_shop_group` = ' . (int) $group['id_shop_group']
                : 'sa.`id_shop` = ' . $idShop;
        }

        return $this->stockJoin[$idShop];
    }
}
