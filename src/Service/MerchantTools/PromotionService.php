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
use Quince\Insight\Core\Clock;
use Quince\Insight\Core\Google\GoogleApiException;
use Quince\Insight\Core\Log;
use Quince\Insight\Core\Options;
use Quince\Insight\Core\Schema as CoreSchema;
use Quince\Insight\Core\ShopContext;
use Quince\Insight\Service\Merchant\Dto\Feed;
use Quince\Insight\Service\Merchant\FeedRepository;

/**
 * Promocje Google z regul koszyka (zakladka Promotions, ARCHITEKTURA §7.7 i §12 pkt 6).
 *
 * Promocja jest per feed (kraj i jezyk feedu). Feed z kraju spoza `PromotionCountries` — nic nie
 * jest wysylane. Usuniecia w API nie ma: regula usunieta, wylaczona albo przeterminowana jest
 * konczona data (`endTime` w przeszlosci) i dostaje status `ended`.
 */
final class PromotionService
{
    public function __construct(
        private readonly MerchantApi $api,
        private readonly FeedRepository $feeds,
    ) {
    }

    /**
     * Promocje feedu do tabeli (prototyp: Name, Type, Value, Code, Google, Last sync).
     *
     * @return array<int,array<string,mixed>>
     */
    public function listForFeed(int $idShop, Feed $feed): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT * FROM `' . CoreSchema::table(Schema::PROMOTION) . '`
             WHERE `id_shop` = ' . $idShop . ' AND `id_feed` = ' . (int) $feed->id . ' ORDER BY `id_promotion` DESC'
        ) ?: [];

        $out = [];
        $rules = $this->cartRules($idShop, array_map(static fn (array $r): int => (int) $r['id_cart_rule'], $rows));

        foreach ($rows as $row) {
            $rule = $rules[(int) $row['id_cart_rule']] ?? null;
            $payload = RuleDefinitions::decode($row['payload'] ?? '');
            $attrs = (array) ($payload['promotion']['attributes'] ?? []);

            $out[] = [
                'id' => (int) $row['id_promotion'],
                'idCartRule' => (int) $row['id_cart_rule'],
                'name' => $rule['name'] ?? (string) ($attrs['longTitle'] ?? ('#' . $row['id_cart_rule'])),
                'type' => (string) ($attrs['couponValueType'] ?? ($rule ? (PromotionBuilder::valueType($rule) ?? '') : '')),
                'value' => $rule ? PromotionBuilder::valueLabel($rule, $this->currencyIso($feed)) : '—',
                'code' => (string) ($attrs['genericRedemptionCode'] ?? ''),
                'status' => self::displayStatus((string) $row['status'], (string) ($row['google_status'] ?? '')),
                'error' => (string) $row['last_error'],
                'syncedAt' => (string) ($row['synced_at'] ?? ''),
            ];
        }

        return $out;
    }

    /** Stan w tabeli: stan z Google, a bez niego — stan lokalny. */
    public static function displayStatus(string $local, string $google): string
    {
        if ($local === 'error') {
            return 'rejected';
        }

        if ($local === 'ended') {
            return 'ended';
        }

        return $google !== '' ? $google : ($local === 'sent' ? 'pending' : 'draft');
    }

    /**
     * Reguly koszyka, ktorych nie ma jeszcze w Google dla feedu, i te, ktorych nie da sie wyslac (z powodem).
     *
     * @return array{rules: array<int,array<string,mixed>>, skipped: array<int,array<string,mixed>>}
     */
    public function scan(int $idShop, Feed $feed): array
    {
        $mapped = array_map('intval', array_column(Db::getInstance()->executeS(
            'SELECT `id_cart_rule` FROM `' . CoreSchema::table(Schema::PROMOTION) . '`
             WHERE `id_shop` = ' . $idShop . ' AND `id_feed` = ' . (int) $feed->id . ' AND `status` != "ended"'
        ) ?: [], 'id_cart_rule'));

        $now = Clock::time();
        $rules = [];
        $skipped = [];

        foreach ($this->cartRules($idShop, null, $feed->idLang) as $id => $rule) {
            if (in_array($id, $mapped, true)) {
                continue;
            }

            $base = [
                'id' => $id,
                'name' => (string) $rule['name'],
                'type' => PromotionBuilder::valueType($rule) ?? '—',
                'code' => (string) $rule['code'],
                'period' => PromotionBuilder::period($rule),
            ];
            $reason = PromotionBuilder::skipReason($rule, $feed->country, $now);

            if ($reason === '' && $this->offersFor($idShop, $feed, $rule) === []) {
                $reason = 'None of its products are in this feed yet';
            }

            if ($reason !== '') {
                $skipped[] = $base + ['reason' => $reason];
            } else {
                $rules[] = $base;
            }
        }

        return ['rules' => $rules, 'skipped' => $skipped];
    }

    /**
     * "Add to Google": zapis promocji feedu i wyslanie. Zwraca [ok, komunikat bledu].
     *
     * @return array{0:bool,1:string}
     */
    public function add(int $idShop, Feed $feed, int $idCartRule): array
    {
        if (!PromotionCountries::supports($feed->country)) {
            return [false, 'Google does not accept promotions through the API for this country.'];
        }

        $rule = $this->cartRules($idShop, [$idCartRule], $feed->idLang)[$idCartRule] ?? null;

        if ($rule === null) {
            return [false, 'This cart rule no longer exists.'];
        }

        $reason = PromotionBuilder::skipReason($rule, $feed->country, Clock::time());

        if ($reason !== '') {
            return [false, $reason . '.'];
        }

        $table = CoreSchema::name(Schema::PROMOTION);
        $existing = $this->row($feed->id, $idCartRule);

        if ($existing === null) {
            Db::getInstance()->insert($table, [
                'id_shop' => $idShop,
                'id_feed' => (int) $feed->id,
                'id_cart_rule' => $idCartRule,
                'promotion_id' => pSQL(PromotionBuilder::promotionId($idShop, $idCartRule)),
                'status' => 'draft',
            ]);
        }

        return $this->send($idShop, $feed, $idCartRule, false);
    }

    /**
     * Wysyla (albo konczy) promocje. Zwraca [ok, komunikat bledu].
     *
     * @return array{0:bool,1:string}
     */
    public function send(int $idShop, Feed $feed, int $idCartRule, bool $end): array
    {
        $row = $this->row($feed->id, $idCartRule);

        if ($row === null) {
            return [false, 'Promotion not found.'];
        }

        $rule = $this->cartRules($idShop, [$idCartRule], $feed->idLang)[$idCartRule] ?? null;
        $now = Clock::time();

        if ($rule === null) {
            // Regula usunieta — konczymy promocje ostatnia wyslana trescia.
            $payload = RuleDefinitions::decode($row['payload'] ?? '');

            if (!isset($payload['promotion'])) {
                $this->update((int) $row['id_promotion'], ['status' => 'ended']);

                return [true, ''];
            }

            $payload['promotion']['attributes']['promotionEffectiveTimePeriod'] = [
                'startTime' => (string) ($payload['promotion']['attributes']['promotionEffectiveTimePeriod']['startTime'] ?? gmdate('Y-m-d\TH:i:s\Z', $now - 120)),
                'endTime' => gmdate('Y-m-d\TH:i:s\Z', $now - 60),
            ];
            $end = true;
        } else {
            $offers = $this->offersFor($idShop, $feed, $rule);

            if ($offers === []) {
                $this->update((int) $row['id_promotion'], ['status' => 'error', 'last_error' => 'None of its products are in this feed yet.']);

                return [false, 'None of its products are in this feed yet.'];
            }

            try {
                $dataSource = $this->dataSource($idShop, $feed);
            } catch (\Throwable $e) {
                $this->update((int) $row['id_promotion'], ['status' => 'error', 'last_error' => mb_substr($e->getMessage(), 0, 500)]);

                throw $e;
            }

            $end = $end || PromotionBuilder::ended($rule, $now) || (int) $rule['active'] !== 1;
            $payload = PromotionBuilder::build($idShop, $rule, [
                'country' => $feed->country,
                'language' => $feed->contentLanguage,
                'currency' => $this->currencyIso($feed),
                'url' => ShopContext::baseUrl($idShop),
            ], $offers === null ? null : $offers, fn (float $amount, string $iso): float => $this->convert($amount, $iso, $this->currencyIso($feed)), $now, $dataSource, $end);
        }

        try {
            $response = $this->api->client($idShop)->post($this->api->url($idShop, 'promotions', 'promotions:insert'), $payload);
        } catch (GoogleApiException $e) {
            $this->update((int) $row['id_promotion'], ['status' => 'error', 'last_error' => mb_substr($e->getMessage(), 0, 500)]);
            Log::write($idShop, 'merchanttools', Log::WARNING, 'Promotion rejected by Google.', ['id_cart_rule' => $idCartRule, 'reason' => $e->reason()]);

            throw $e;
        }

        $this->update((int) $row['id_promotion'], [
            'status' => $end ? 'ended' : 'sent',
            'payload' => (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'payload_hash' => sha1((string) json_encode($this->comparable($payload))),
            'google_status' => $end ? 'ended' : PromotionBuilder::googleState($response['promotionStatus'] ?? null),
            'last_error' => '',
            'synced_at' => Clock::now(),
        ]);

        return [true, ''];
    }

    /**
     * Cron (grupa `statuses`): wysyla zmienione reguly, konczy usuniete i przeterminowane,
     * odswieza stany z Google. Zwraca komunikat do dziennika.
     */
    public function sync(int $idShop): string
    {
        if ($this->api->accountId($idShop) === '') {
            return 'No Merchant Center account.';
        }

        $sent = $ended = $failed = 0;
        $feeds = [];

        foreach ($this->feeds->forShop($idShop) as $feed) {
            $feeds[$feed->id] = $feed;
        }

        foreach (Db::getInstance()->executeS(
            'SELECT * FROM `' . CoreSchema::table(Schema::PROMOTION) . '` WHERE `id_shop` = ' . $idShop . ' AND `status` != "ended"'
        ) ?: [] as $row) {
            $feed = $feeds[(int) $row['id_feed']] ?? null;

            if ($feed === null || !PromotionCountries::supports($feed->country)) {
                continue;
            }

            $rule = $this->cartRules($idShop, [(int) $row['id_cart_rule']], $feed->idLang)[(int) $row['id_cart_rule']] ?? null;
            $now = Clock::time();
            $mustEnd = $rule === null || (int) $rule['active'] !== 1 || PromotionBuilder::ended($rule, $now);

            if (!$mustEnd && (string) $row['status'] === 'sent' && !$this->changed($idShop, $feed, $rule, $row)) {
                continue;
            }

            try {
                [$ok] = $this->send($idShop, $feed, (int) $row['id_cart_rule'], $mustEnd);
                $ok ? ($mustEnd ? ++$ended : ++$sent) : ++$failed;
            } catch (\Throwable $e) {
                ++$failed;
            }
        }

        $refreshed = $this->refreshStatuses($idShop);

        return sprintf('Promotions: %d sent, %d ended, %d failed, %d statuses refreshed.', $sent, $ended, $failed, $refreshed);
    }

    /** Stany promocji z `promotions.list`. Zwraca liczbe zaktualizowanych. */
    public function refreshStatuses(int $idShop): int
    {
        try {
            $list = $this->api->client($idShop)->paginate($this->api->url($idShop, 'promotions', 'promotions'), 'promotions', ['pageSize' => 250]);
        } catch (\Throwable $e) {
            Log::write($idShop, 'merchanttools', Log::WARNING, 'Could not read promotion statuses.', ['error' => mb_substr($e->getMessage(), 0, 200)]);

            return 0;
        }

        $n = 0;

        foreach ($list as $promotion) {
            $id = (string) ($promotion['promotionId'] ?? '');
            $state = PromotionBuilder::googleState($promotion['promotionStatus'] ?? null);

            if ($id === '' || $state === '' || !str_starts_with($id, 'qi_' . $idShop . '_')) {
                continue;
            }

            Db::getInstance()->update(
                CoreSchema::name(Schema::PROMOTION),
                ['google_status' => pSQL($state)],
                '`id_shop` = ' . $idShop . ' AND `promotion_id` = "' . pSQL($id) . '"
                 AND `id_feed` IN (SELECT `id_feed` FROM `' . CoreSchema::table(\Quince\Insight\Service\Merchant\Schema::FEED) . '`
                    WHERE `country` = "' . pSQL(strtoupper((string) ($promotion['targetCountry'] ?? ''))) . '")'
            );
            $n += (int) Db::getInstance()->Affected_Rows();
        }

        return $n;
    }

    /** Hook zmiany/usuniecia reguly koszyka: promocja wysle sie ponownie (albo zakonczy) przy cronie. */
    public function markCartRule(int $idCartRule): void
    {
        Db::getInstance()->update(
            CoreSchema::name(Schema::PROMOTION),
            ['payload_hash' => ''],
            '`id_cart_rule` = ' . $idCartRule . ' AND `status` != "ended"'
        );
    }

    /** Promocje z bledem (dla `attention()`). */
    public function errorCount(int $idShop): int
    {
        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . CoreSchema::table(Schema::PROMOTION) . '` WHERE `id_shop` = ' . $idShop . ' AND `status` = "error"'
        );
    }

    /**
     * Oferty feedu, ktorych dotyczy regula. null = wszystkie produkty (bez ograniczenia),
     * [] = ograniczenie, ale zadnego produktu nie ma w feedzie.
     *
     * @param array<string,mixed> $rule
     *
     * @return string[]|null
     */
    public function offersFor(int $idShop, Feed $feed, array $rule): ?array
    {
        $idProduct = (int) ($rule['reduction_product'] ?? 0);
        $restricted = (int) ($rule['product_restriction'] ?? 0) === 1;

        if (!$restricted && $idProduct <= 0) {
            return null;
        }

        $fp = CoreSchema::table(\Quince\Insight\Service\Merchant\Schema::FEED_PRODUCT);
        $where = ['fp.`id_feed` = ' . (int) $feed->id, 'fp.`id_shop` = ' . $idShop, 'fp.`offer_id` != ""', 'fp.`sync_state` NOT IN ("excluded", "removed")'];

        if ($idProduct > 0) {
            $where[] = 'fp.`id_product` = ' . $idProduct;
        }

        if ($restricted) {
            foreach ($this->restrictionGroups((int) $rule['id_cart_rule']) as $group) {
                $or = [];

                foreach ($group as $type => $ids) {
                    $list = implode(',', array_map('intval', $ids)) ?: '0';
                    $or[] = match ($type) {
                        'products' => 'fp.`id_product` IN (' . $list . ')',
                        'categories' => 'fp.`id_product` IN (SELECT `id_product` FROM `' . _DB_PREFIX_ . 'category_product` WHERE `id_category` IN (' . $list . '))',
                        'manufacturers' => 'fp.`id_product` IN (SELECT `id_product` FROM `' . _DB_PREFIX_ . 'product` WHERE `id_manufacturer` IN (' . $list . '))',
                        'suppliers' => 'fp.`id_product` IN (SELECT `id_product` FROM `' . _DB_PREFIX_ . 'product_supplier` WHERE `id_supplier` IN (' . $list . '))',
                        'attributes' => 'fp.`id_product_attribute` IN (SELECT `id_product_attribute` FROM `' . _DB_PREFIX_ . 'product_attribute_combination` WHERE `id_attribute` IN (' . $list . '))',
                        default => '0',
                    };
                }

                // W PrestaShop grupy ograniczen lacza sie AND, wartosci w grupie — OR.
                $where[] = '(' . implode(' OR ', $or) . ')';
            }
        }

        return array_map(static fn (array $r): string => (string) $r['offer_id'], Db::getInstance()->executeS(
            'SELECT DISTINCT fp.`offer_id` FROM `' . $fp . '` fp WHERE ' . implode(' AND ', $where) . ' LIMIT 1000'
        ) ?: []);
    }

    /**
     * Zrodlo danych promocji dla kraju i jezyku feedu: najpierw szuka istniejacego (idempotencja),
     * potem zaklada. Nazwy zapamietane w `_GMC_PROMO_DATASOURCE` (JSON "jezyk~KRAJ" => nazwa).
     */
    public function dataSource(int $idShop, Feed $feed): string
    {
        $key = strtolower($feed->contentLanguage) . '~' . strtoupper($feed->country);
        $known = Options::json(MerchantToolsOptions::PROMO_DATASOURCE, [], $idShop);

        if (!empty($known[$key])) {
            return (string) $known[$key];
        }

        $client = $this->api->client($idShop);
        $name = '';

        foreach ($client->paginate($this->api->url($idShop, 'datasources', 'dataSources'), 'dataSources', ['pageSize' => 1000]) as $ds) {
            $promo = $ds['promotionDataSource'] ?? null;

            if (is_array($promo) && strtolower((string) ($promo['contentLanguage'] ?? '')) === strtolower($feed->contentLanguage)
                && strtoupper((string) ($promo['targetCountry'] ?? '')) === strtoupper($feed->country)) {
                $name = (string) ($ds['name'] ?? '');
                break;
            }
        }

        if ($name === '') {
            $created = $client->post($this->api->url($idShop, 'datasources', 'dataSources'), [
                'displayName' => 'Quince Insight promotions ' . strtoupper($feed->country) . ' ' . strtolower($feed->contentLanguage),
                'promotionDataSource' => [
                    'contentLanguage' => strtolower($feed->contentLanguage),
                    'targetCountry' => strtoupper($feed->country),
                ],
            ]);
            $name = (string) ($created['name'] ?? '');
        }

        if ($name === '') {
            throw new \RuntimeException('Google did not return the promotion data source.');
        }

        $known[$key] = $name;
        Options::set(MerchantToolsOptions::PROMO_DATASOURCE, $known, $idShop);

        return $name;
    }

    /**
     * Reguly koszyka sklepu z nazwa w jezyku feedu, walutami ISO i krajami ograniczenia.
     *
     * @param int[]|null $ids null = wszystkie aktywne
     *
     * @return array<int,array<string,mixed>>
     */
    public function cartRules(int $idShop, ?array $ids, int $idLang = 0): array
    {
        if ($ids === []) {
            return [];
        }

        $idLang = $idLang ?: (int) \Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);
        $where = $ids === null
            ? 'cr.`active` = 1 AND cr.`date_to` > "' . pSQL(Clock::now()) . '"'
            : 'cr.`id_cart_rule` IN (' . implode(',', array_map('intval', $ids)) . ')';

        $rows = Db::getInstance()->executeS(
            'SELECT cr.*, IFNULL(crl.`name`, "") AS name, c1.`iso_code` AS reduction_currency_iso, c2.`iso_code` AS minimum_amount_currency_iso
             FROM `' . _DB_PREFIX_ . 'cart_rule` cr
             LEFT JOIN `' . _DB_PREFIX_ . 'cart_rule_lang` crl ON crl.`id_cart_rule` = cr.`id_cart_rule` AND crl.`id_lang` = ' . $idLang . '
             LEFT JOIN `' . _DB_PREFIX_ . 'currency` c1 ON c1.`id_currency` = cr.`reduction_currency`
             LEFT JOIN `' . _DB_PREFIX_ . 'currency` c2 ON c2.`id_currency` = cr.`minimum_amount_currency`
             WHERE ' . $where . '
               AND (cr.`shop_restriction` = 0 OR cr.`id_cart_rule` IN (SELECT `id_cart_rule` FROM `' . _DB_PREFIX_ . 'cart_rule_shop` WHERE `id_shop` = ' . $idShop . '))
             ORDER BY cr.`date_from` DESC, cr.`id_cart_rule` DESC'
        ) ?: [];

        $out = [];

        foreach ($rows as $row) {
            $row['countries'] = [];

            if ((int) $row['country_restriction'] === 1) {
                $row['countries'] = array_column(Db::getInstance()->executeS(
                    'SELECT c.`iso_code` FROM `' . _DB_PREFIX_ . 'cart_rule_country` crc
                     INNER JOIN `' . _DB_PREFIX_ . 'country` c ON c.`id_country` = crc.`id_country`
                     WHERE crc.`id_cart_rule` = ' . (int) $row['id_cart_rule']
                ) ?: [], 'iso_code');
            }

            $out[(int) $row['id_cart_rule']] = $row;
        }

        return $out;
    }

    /** @return array<int,array<string,int[]>> grupy ograniczen produktow reguly */
    private function restrictionGroups(int $idCartRule): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT g.`id_product_rule_group`, r.`type`, v.`id_item`
             FROM `' . _DB_PREFIX_ . 'cart_rule_product_rule_group` g
             INNER JOIN `' . _DB_PREFIX_ . 'cart_rule_product_rule` r ON r.`id_product_rule_group` = g.`id_product_rule_group`
             INNER JOIN `' . _DB_PREFIX_ . 'cart_rule_product_rule_value` v ON v.`id_product_rule` = r.`id_product_rule`
             WHERE g.`id_cart_rule` = ' . $idCartRule
        ) ?: [];

        $groups = [];

        foreach ($rows as $r) {
            $groups[(int) $r['id_product_rule_group']][(string) $r['type']][] = (int) $r['id_item'];
        }

        return array_values($groups);
    }

    /** @param array<string,mixed> $rule */
    private function changed(int $idShop, Feed $feed, array $rule, array $row): bool
    {
        if ((string) ($row['payload_hash'] ?? '') === '') {
            return true;
        }

        $offers = $this->offersFor($idShop, $feed, $rule);
        $payload = PromotionBuilder::build($idShop, $rule, [
            'country' => $feed->country,
            'language' => $feed->contentLanguage,
            'currency' => $this->currencyIso($feed),
            'url' => ShopContext::baseUrl($idShop),
        ], $offers, fn (float $a, string $iso): float => $this->convert($a, $iso, $this->currencyIso($feed)), Clock::time(), '');

        return sha1((string) json_encode($this->comparable($payload))) !== (string) $row['payload_hash'];
    }

    /** Tresc bez pol, ktore zmieniaja sie same (zrodlo danych). @return array<string,mixed> */
    private function comparable(array $payload): array
    {
        unset($payload['dataSource']);

        return $payload;
    }

    /** @return array<string,mixed>|null */
    private function row(int $idFeed, int $idCartRule): ?array
    {
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . CoreSchema::table(Schema::PROMOTION) . '` WHERE `id_feed` = ' . $idFeed . ' AND `id_cart_rule` = ' . $idCartRule
        );

        return is_array($row) && $row ? $row : null;
    }

    /** @param array<string,mixed> $data */
    private function update(int $idPromotion, array $data): void
    {
        $clean = [];

        foreach ($data as $k => $v) {
            $clean[$k] = is_string($v) ? pSQL($v, true) : $v;
        }

        Db::getInstance()->update(CoreSchema::name(Schema::PROMOTION), $clean, '`id_promotion` = ' . $idPromotion);
    }

    private function currencyIso(Feed $feed): string
    {
        $iso = (string) Db::getInstance()->getValue('SELECT `iso_code` FROM `' . _DB_PREFIX_ . 'currency` WHERE `id_currency` = ' . (int) $feed->idCurrency);

        return $iso !== '' ? $iso : (string) Db::getInstance()->getValue(
            'SELECT `iso_code` FROM `' . _DB_PREFIX_ . 'currency` WHERE `id_currency` = ' . (int) \Configuration::get('PS_CURRENCY_DEFAULT', null, null, (int) $feed->idShop)
        );
    }

    /** Przeliczenie po kursach PrestaShop (kurs wzgledem waluty domyslnej). */
    public function convert(float $amount, string $fromIso, string $toIso): float
    {
        if ($fromIso === '' || strtoupper($fromIso) === strtoupper($toIso)) {
            return $amount;
        }

        $rate = static fn (string $iso): float => (float) Db::getInstance()->getValue(
            'SELECT `conversion_rate` FROM `' . _DB_PREFIX_ . 'currency` WHERE `iso_code` = "' . pSQL($iso) . '" AND `deleted` = 0'
        );
        $from = $rate($fromIso);
        $to = $rate($toIso);

        return $from > 0 && $to > 0 ? $amount / $from * $to : $amount;
    }
}
