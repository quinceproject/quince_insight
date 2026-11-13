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

use Db;
use Quince\Insight\Core\JobRunner;
use Quince\Insight\Core\Options;
use Quince\Insight\Core\Schema as CoreSchema;
use Quince\Insight\Service\Merchant\Dto\BuiltOffer;
use Quince\Insight\Service\Merchant\Dto\Feed;
use Quince\Insight\Service\MerchantTools\Mapping;
use Quince\Insight\Service\MerchantTools\MerchantToolsOptions;

/**
 * Dane zakladek Overview / Products / What is sent ekranu Merchant Center (agent E).
 * Tylko odczyt; kontroler sklada z tego zmienne szablonow.
 */
final class MerchantScreen
{
    public function __construct(
        private readonly FeedCatalog $catalog,
        private readonly FeedSync $sync,
        private readonly FeedQueue $queue,
        private readonly FeedRepository $feeds,
        private readonly ProductBuilder $builder,
        private readonly MerchantAccount $account,
        private readonly JobRunner $jobs,
        private readonly Mapping $mapping,
    ) {
    }

    /**
     * Etykieta feedu jak w prototypie: "Poland · Polish · PLN".
     *
     * @param array<string,string> $countries
     * @param array<int,array{id:int,name:string}> $languages
     */
    public static function label(Feed $feed, array $countries, array $languages): string
    {
        $lang = $feed->contentLanguage;

        foreach ($languages as $item) {
            if ($item['id'] === $feed->idLang) {
                // "Polski (Polish)" → "Polski": nawias to dopisek PrestaShop.
                $lang = trim((string) preg_replace('/\s*\(.*\)$/', '', $item['name']));
            }
        }

        return ($countries[$feed->country] ?? $feed->country) . ' · ' . $lang . ' · ' . ($feed->currencyIso ?: '');
    }

    /** @return array<string,mixed> */
    public function overview(Feed $feed): array
    {
        $counts = $this->sync->counts($feed);
        $last = $this->feeds->lastLog($feed->id, 'Sync:');
        $job = $this->jobs->active($feed->idShop, SyncJob::TYPE);

        return [
            'counts' => $counts,
            'total' => $this->catalog->total($feed),
            'matching' => $this->catalog->count($feed),
            'checked' => $feed->lastFullSync !== null,
            'filters' => FeedFilters::summary($feed->filters),
            'mapping' => $this->mappingLine($feed),
            'issues' => $this->sync->topIssues($feed, !$feed->isDraft()),
            'toSend' => $feed->onlyReady ? $counts['ready'] : $counts['matched'],
            'claim' => Options::str(MerchantOptions::CLAIM_STATE, 'unclaimed', $feed->idShop),
            'accountIssues' => $this->account->issues($feed->idShop),
            'issuesChecked' => Options::str(MerchantOptions::ISSUES_AT, '', $feed->idShop) !== '',
            'lastSync' => $last,
            'lastSyncAgo' => self::ago($last['date_add'] ?? null),
            'waiting' => $this->queue->waiting($feed->id) + $this->queue->dirtyCount($feed->idShop),
            'job' => $job?->toArray(),
        ];
    }

    /**
     * Lista Products: strona ofert z budowa "na zywo" (dane dla Google, problemy).
     *
     * @param array{offset:int,per:int} $pagination
     *
     * @return array<int,array<string,mixed>>
     */
    public function rows(Feed $feed, array $page): array
    {
        $out = [];

        foreach ($page as $row) {
            $offer = $this->builder->build($feed, (int) $row['id_product'], (int) $row['id_product_attribute']);
            $out[] = $this->row($feed, $row, $offer);
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $row wiersz `FeedCatalog::page()`
     *
     * @return array<string,mixed>
     */
    public function row(Feed $feed, array $row, ?BuiltOffer $offer): array
    {
        $local = json_decode((string) ($row['local_issues'] ?? ''), true);
        $local = is_array($local) ? $local : ($offer?->issues ?? []);
        $google = json_decode((string) ($row['google_issues'] ?? ''), true);
        $google = is_array($google) ? $google : [];
        // Te same warunki co licznik i filtr listy (FeedCatalog): filtry feedu + reguly wykluczajace.
        $excluded = !(bool) $row['in_filters'] || !empty($row['rule_excluded']);
        $blocking = LocalCheck::blocking($local);
        $fields = $offer?->product->fields ?? [];

        if ($excluded) {
            $state = ['label' => 'Excluded', 'cls' => ''];
        } elseif ($feed->isDraft()) {
            $state = $blocking ? ['label' => 'Issue', 'cls' => 'qc-badge-warning'] : ['label' => 'Ready', 'cls' => 'qc-badge-success'];
        } elseif (($row['sync_state'] ?? '') === 'error') {
            $state = ['label' => 'Failed', 'cls' => 'qc-badge-danger'];
        } elseif ($blocking && $feed->onlyReady && ($row['sync_state'] ?? '') !== 'synced') {
            $state = ['label' => 'Issue', 'cls' => 'qc-badge-warning'];
        } else {
            $state = match ((string) ($row['google_status'] ?? '')) {
                'approved' => ['label' => 'Approved', 'cls' => 'qc-badge-success'],
                'limited' => ['label' => 'Limited', 'cls' => 'qc-badge-warning'],
                'disapproved' => ['label' => 'Disapproved', 'cls' => 'qc-badge-danger'],
                default => ['label' => 'Pending', 'cls' => 'qc-badge-warning'],
            };
        }

        $issueTexts = [];

        foreach ($google as $issue) {
            if (($issue['severity'] ?? '') !== 'suggestion' && ($issue['message'] ?? '') !== '') {
                $issueTexts[] = (string) $issue['message'];
            }
        }

        foreach ($local as $issue) {
            $issueTexts[] = (string) ($issue['message'] ?? '');
        }

        if ($excluded) {
            $reason = !(bool) $row['in_filters'] ? 'Does not match the feed filters' : (string) ($row['rule_reason'] ?? '');
            array_unshift($issueTexts, $reason);
        } elseif (($row['sync_state'] ?? '') === 'error' && ($row['sync_error'] ?? '') !== '') {
            array_unshift($issueTexts, (string) $row['sync_error']);
        }

        $key = (int) $row['id_product'] . '-' . (int) $row['id_product_attribute'];

        return [
            'key' => $key,
            'id' => (int) $row['id_product_attribute'] > 0 ? $key : (string) $row['id_product'],
            'idProduct' => (int) $row['id_product'],
            'idAttribute' => (int) $row['id_product_attribute'],
            'name' => (string) ($fields['title'] ?? $row['name']),
            'sub' => implode(' · ', array_filter([
                (string) ($fields['brand'] ?? ''),
                self::lastSegment((string) ($fields['productType'] ?? '')),
                isset($fields['price']['value']) ? self::money($fields['salePrice'] ?? $fields['price']) : '',
            ])),
            'sku' => (string) ($row['reference'] ?? ''),
            'state' => $state,
            'issues' => array_values(array_unique(array_filter($issueTexts))),
            'google' => $offer !== null ? self::googleData($offer) : [],
            'canSend' => !$excluded && !$feed->isDraft(),
        ];
    }

    /**
     * "Data for Google" w rozwinietym wierszu — kolejnosc z prototypu (`gmGoogleData`).
     *
     * @return array<int,array{0:string,1:string}>
     */
    public static function googleData(BuiltOffer $offer): array
    {
        $f = $offer->product->fields;

        return [
            ['Offer ID', $offer->offerId()],
            ['Title', (string) ($f['title'] ?? '')],
            ['Price', isset($f['price']) ? self::money($f['price']) . (isset($f['salePrice']) ? ' → ' . self::money($f['salePrice']) : '') : '—'],
            ['Availability', (string) ($f['availability'] ?? '')],
            ['Brand', (string) ($f['brand'] ?? '') ?: '—'],
            ['GTIN', (string) ($f['gtin'] ?? '') ?: '—'],
            ['MPN', (string) ($f['mpn'] ?? '') ?: '—'],
            ['Product type', (string) ($f['productType'] ?? '') ?: '—'],
            ['Google category', (string) ($f['googleProductCategory'] ?? '') ?: '— (Google assigns it)'],
            ['Image', ($f['imageLink'] ?? '') !== '' ? 'Yes' : '—'],
        ];
    }

    /**
     * Listy wyboru zakladki What is sent: kategorie z liczba produktow, producenci, dostawcy,
     * cechy z wartosciami, tagi jezyka feedu, nazwy wykluczonych produktow.
     *
     * @return array<string,mixed>
     */
    public function filterOptions(Feed $feed): array
    {
        $px = _DB_PREFIX_;
        $db = Db::getInstance();
        $s = $feed->idShop;
        $l = $feed->idLang;
        $active = 'INNER JOIN `' . $px . 'product_shop` ps ON ps.`id_product` = x.`id_product` AND ps.`id_shop` = ' . $s . ' AND ps.`active` = 1';

        $home = (int) $db->getValue('SELECT `id_category` FROM `' . $px . 'shop` WHERE `id_shop` = ' . $s);
        $cats = $db->executeS('SELECT c.`id_category`, c.`id_parent`, c.`level_depth`, cl.`name`,
                (SELECT COUNT(DISTINCT x.`id_product`) FROM `' . $px . 'category_product` x ' . $active . ' WHERE x.`id_category` = c.`id_category`) AS products
            FROM `' . $px . 'category` c
            INNER JOIN `' . $px . 'category_shop` cs ON cs.`id_category` = c.`id_category` AND cs.`id_shop` = ' . $s . '
            LEFT JOIN `' . $px . 'category_lang` cl ON cl.`id_category` = c.`id_category` AND cl.`id_lang` = ' . $l . ' AND cl.`id_shop` = ' . $s . '
            INNER JOIN `' . $px . 'category` h ON h.`id_category` = ' . $home . '
            WHERE c.`nleft` > h.`nleft` AND c.`nright` < h.`nright` ORDER BY c.`nleft` ASC');
        $baseDepth = (int) $db->getValue('SELECT `level_depth` FROM `' . $px . 'category` WHERE `id_category` = ' . $home) + 1;
        $categories = array_map(static fn (array $c): array => [
            'id' => (int) $c['id_category'],
            'name' => (string) $c['name'],
            'count' => (int) $c['products'],
            'depth' => max(0, (int) $c['level_depth'] - $baseDepth),
        ], is_array($cats) ? $cats : []);

        $brands = $db->executeS('SELECT m.`id_manufacturer` AS id, m.`name`,
                (SELECT COUNT(*) FROM `' . $px . 'product` x ' . $active . ' WHERE x.`id_manufacturer` = m.`id_manufacturer`) AS products
            FROM `' . $px . 'manufacturer` m INNER JOIN `' . $px . 'manufacturer_shop` msh ON msh.`id_manufacturer` = m.`id_manufacturer` AND msh.`id_shop` = ' . $s . '
            ORDER BY m.`name` ASC');
        $suppliers = $db->executeS('SELECT su.`id_supplier` AS id, su.`name`,
                (SELECT COUNT(DISTINCT x.`id_product`) FROM `' . $px . 'product_supplier` x ' . $active . ' WHERE x.`id_supplier` = su.`id_supplier`) AS products
            FROM `' . $px . 'supplier` su INNER JOIN `' . $px . 'supplier_shop` ssh ON ssh.`id_supplier` = su.`id_supplier` AND ssh.`id_shop` = ' . $s . '
            ORDER BY su.`name` ASC');
        $features = $db->executeS('SELECT f.`id_feature`, fl.`name` AS feature, fv.`id_feature_value`, fvl.`value`
            FROM `' . $px . 'feature` f
            INNER JOIN `' . $px . 'feature_shop` fsh ON fsh.`id_feature` = f.`id_feature` AND fsh.`id_shop` = ' . $s . '
            LEFT JOIN `' . $px . 'feature_lang` fl ON fl.`id_feature` = f.`id_feature` AND fl.`id_lang` = ' . $l . '
            INNER JOIN `' . $px . 'feature_value` fv ON fv.`id_feature` = f.`id_feature` AND fv.`custom` = 0
            LEFT JOIN `' . $px . 'feature_value_lang` fvl ON fvl.`id_feature_value` = fv.`id_feature_value` AND fvl.`id_lang` = ' . $l . '
            ORDER BY f.`position` ASC, fvl.`value` ASC');
        $featureMap = [];

        foreach (is_array($features) ? $features : [] as $row) {
            $id = (int) $row['id_feature'];
            $featureMap[$id] ??= ['id' => $id, 'name' => (string) $row['feature'], 'values' => []];
            $featureMap[$id]['values'][] = ['id' => (int) $row['id_feature_value'], 'name' => (string) $row['value']];
        }

        $tags = $db->executeS('SELECT DISTINCT t.`name` FROM `' . $px . 'tag` t WHERE t.`id_lang` = ' . $l . ' ORDER BY t.`name` ASC LIMIT 500');
        $excluded = [];

        if ($feed->filters['excluded_products']) {
            $rows = $db->executeS('SELECT `id_product`, `name` FROM `' . $px . 'product_lang` WHERE `id_lang` = ' . $l . ' AND `id_shop` = ' . $s
                . ' AND `id_product` IN (' . implode(',', array_map('intval', $feed->filters['excluded_products'])) . ')');

            foreach (is_array($rows) ? $rows : [] as $row) {
                $excluded[(int) $row['id_product']] = (string) $row['name'];
            }
        }

        $list = static fn ($rows): array => array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'count' => (int) $r['products']], is_array($rows) ? $rows : []);

        return [
            'categories' => $categories,
            'brands' => $list($brands),
            'suppliers' => $list($suppliers),
            'features' => array_values($featureMap),
            'tags' => array_column(is_array($tags) ? $tags : [], 'name'),
            'excludedNames' => $excluded,
        ];
    }

    /**
     * Wiersz "Check mapping" w Overview (prototyp: "GTIN from EAN-13 · N attributes · X of Y
     * categories mapped"). Dane mapowania naleza do agenta M — tu tylko odczyt jego tabel.
     */
    public function mappingLine(Feed $feed): array
    {
        $gtin = Options::str(MerchantToolsOptions::ID_GTIN, 'ean13', $feed->idShop);
        $labels = ['ean13' => 'EAN-13', 'ean' => 'EAN-13', 'upc' => 'UPC', 'isbn' => 'ISBN', 'none' => ''];
        $used = Db::getInstance()->executeS('SELECT DISTINCT ps.`id_category_default` AS id ' . $this->catalog->from($feed)
            . ' AND ' . $this->catalog->where($feed));
        $ids = array_map('intval', array_column(is_array($used) ? $used : [], 'id'));
        $mapped = 0;

        // Wynik mapowania agenta M: atrybuty zapisane albo wykryte po nazwie, kategorie
        // z dziedziczeniem w gore drzewa — tak samo jak `Mapping::apply()` przy budowie oferty.
        foreach ($ids as $idCategory) {
            if ($this->mapping->googleCategory($feed->idShop, $idCategory) !== null) {
                ++$mapped;
            }
        }

        return [
            'gtin' => $labels[$gtin] ?? strtoupper($gtin),
            'attributes' => count($this->mapping->effectiveAttributes($feed->idShop)),
            'mapped' => $mapped,
            'categories' => count($ids),
        ];
    }

    /**
     * "just now" / "5 min ago" / "3 h ago" / "2 days ago" — [zrodlo tlumaczenia, parametry].
     *
     * @return array{0:string,1:array<string,int>}
     */
    public static function ago(?string $datetime): array
    {
        $seconds = \Quince\Insight\Core\Clock::secondsSince($datetime);

        if ($seconds === null) {
            return ['never', []];
        }

        return match (true) {
            $seconds < 90 => ['just now', []],
            $seconds < 3600 => ['%count% min ago', ['%count%' => (int) round($seconds / 60)]],
            $seconds < 86400 => ['%count% h ago', ['%count%' => (int) round($seconds / 3600)]],
            default => ['%count% days ago', ['%count%' => (int) round($seconds / 86400)]],
        };
    }

    /** @param array{value?:mixed,currency?:mixed} $price */
    private static function money(array $price): string
    {
        return number_format((float) ($price['value'] ?? 0), 2, ',', ' ') . ' ' . (string) ($price['currency'] ?? '');
    }

    private static function lastSegment(string $path): string
    {
        $parts = explode(' > ', $path);

        return trim((string) end($parts));
    }
}
