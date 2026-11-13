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

use Db;
use Quince\Insight\Core\Clock;
use Quince\Insight\Core\Google\GoogleApiException;
use Quince\Insight\Core\Log;
use Quince\Insight\Core\Options;
use Quince\Insight\Service\Events\EventsOptions;
use Quince\Insight\Service\Merchant\FeedRepository;

/**
 * Wysylka: podglad przewoznikow PrestaShop i zapis do Merchant Center (zakladka Shipping).
 *
 * `shippingSettings:insert` zastepuje CALE ustawienia wysylki konta i wymaga `etag` (research/04 §12,
 * ARCHITEKTURA §12 pkt 5). Dlatego: podglad pokazuje uslugi teraz zapisane w Google i odcisk
 * (`hash`) tego, co zostanie wyslane; wysylka idzie tylko z potwierdzeniem i tylko wtedy, gdy ani
 * przewoznicy, ani ustawienia w Google nie zmienily sie od podgladu.
 *
 * Kraje: kraje feedow sklepu (waluta i podatek feedu); bez feedow — aktywne kraje stref przewoznikow
 * w walucie domyslnej z podatkiem. Odczyt danych sklepu: przewoznicy przypisani do sklepu
 * (`carrier_shop`), nieusunieci, aktywni.
 */
final class ShippingService
{
    public function __construct(
        private readonly MerchantApi $api,
        private readonly FeedRepository $feeds,
    ) {
    }

    /**
     * Lokalna czesc podgladu (bez Google) — lewa kolumna zakladki.
     *
     * @return array{services: array<int,array<string,mixed>>, skipped: array<int,array{name:string,reason:string}>, countries: string[]}
     */
    public function local(int $idShop): array
    {
        $countries = $this->countries($idShop);
        $built = ShippingBuilder::build($this->carriers($idShop, array_keys($countries)), $countries, [
            'freePrice' => (float) \Configuration::get('PS_SHIPPING_FREE_PRICE', null, null, $idShop),
            'weightUnit' => (string) \Configuration::get('PS_WEIGHT_UNIT', null, null, $idShop),
        ]);

        $used = [];
        foreach ($built['services'] as $s) {
            foreach ($s['countries'] as $c) {
                $used[$c] = true;
            }
        }

        return $built + ['countries' => array_keys($used)];
    }

    /**
     * Pelny podglad: lokalne uslugi + to, co jest teraz w Merchant Center, etag i odcisk.
     *
     * @return array<string,mixed>
     */
    public function preview(int $idShop): array
    {
        $local = $this->local($idShop);
        $settings = $this->remote($idShop);
        $etag = (string) ($settings['etag'] ?? '');

        return [
            'services' => array_map(static fn (array $s): array => array_diff_key($s, ['google' => 1]), $local['services']),
            'skipped' => $local['skipped'],
            'countries' => $local['countries'],
            'current' => ShippingBuilder::current($settings),
            'currentBySelf' => $etag !== '' && $etag === Options::str(MerchantToolsOptions::SHIPPING_ETAG, '', $idShop),
            'sentAt' => Options::str(MerchantToolsOptions::SHIPPING_SENT_AT, '', $idShop),
            'hash' => ShippingBuilder::hash($local['services'], $etag),
        ];
    }

    /**
     * Wysylka po potwierdzeniu. $hash = odcisk z podgladu, ktory user widzial.
     *
     * @return array{0:bool,1:string,2:array<string,mixed>} [ok, blad, nowy podglad]
     */
    public function send(int $idShop, string $hash): array
    {
        if (!$this->hasFeeds($idShop)) {
            return [false, 'Add a feed first.', []];
        }

        $local = $this->local($idShop);

        if ($local['services'] === []) {
            return [false, 'There is nothing to send — no carrier can be sent to Merchant Center.', []];
        }

        $settings = $this->remote($idShop);
        $etag = (string) ($settings['etag'] ?? '');

        if (!hash_equals(ShippingBuilder::hash($local['services'], $etag), $hash)) {
            return [false, 'Carriers or the settings in Merchant Center changed since you opened this page. Check the lists again and confirm.', $this->preview($idShop)];
        }

        $body = [
            'etag' => $etag,
            'services' => array_column($local['services'], 'google'),
        ];

        // Magazyny i inne pola z Google zostaja — insert zeruje wszystko, czego nie ma w tresci.
        foreach (['warehouses'] as $keep) {
            if (!empty($settings[$keep])) {
                $body[$keep] = $settings[$keep];
            }
        }

        $client = $this->api->client($idShop);
        $url = $this->api->url($idShop, 'accounts', 'shippingSettings:insert');

        try {
            $saved = $client->post($url, $body);

            // Przyklad Google: pierwsze wstawienie z pustym etagiem moze nie zapisac ustawien —
            // wtedy jeszcze raz z etagiem z odczytu (research/04 §12).
            if ($etag === '') {
                $again = $this->remote($idShop);

                if (empty($again['services']) && (string) ($again['etag'] ?? '') !== '') {
                    $body['etag'] = (string) $again['etag'];
                    $saved = $client->post($url, $body);
                }
            }
        } catch (GoogleApiException $e) {
            Log::write($idShop, 'merchanttools', Log::WARNING, 'Shipping settings were not saved.', ['reason' => $e->reason(), 'code' => $e->httpCode()]);

            throw $e;
        }

        $newEtag = (string) ($saved['etag'] ?? ($this->remote($idShop)['etag'] ?? ''));
        Options::set(MerchantToolsOptions::SHIPPING_ETAG, $newEtag, $idShop);
        Options::set(MerchantToolsOptions::SHIPPING_SENT_AT, Clock::now(), $idShop);
        // Migawka tego, co poszlo do Google — JSON-LD (agent H) ma pokazywac to samo, nie biezacy stan przewoznikow.
        Options::set(MerchantToolsOptions::SHIPPING_SENT, array_map(static fn (array $s): array => [
            'name' => $s['name'], 'countries' => $s['countries'], 'currency' => $s['currency'],
        ] + $s['sd'], $local['services']), $idShop);
        Log::write($idShop, 'merchanttools', Log::INFO, 'Shipping settings sent to Merchant Center.', ['services' => count($body['services'])]);

        return [true, '', $this->preview($idShop)];
    }

    /** `GET shippingSettings`; brak ustawien (404) = puste. @return array<string,mixed> */
    private function remote(int $idShop): array
    {
        try {
            return $this->api->client($idShop)->get($this->api->url($idShop, 'accounts', 'shippingSettings'));
        } catch (GoogleApiException $e) {
            if ($e->httpCode() === 404) {
                return [];
            }

            throw $e;
        }
    }

    /**
     * Kraje docelowe = kraje feedow sklepu. Bez feedu nie ma krajow i nic nie jest wysylane
     * (decyzja #17: "Add a feed first").
     *
     * @return array<string,array{currency:string,rate:float,tax:array<int,float>}>
     */
    /** Decyzja #17: zakladka Shipping wymaga co najmniej jednego feedu sklepu. */
    public function hasFeeds(int $idShop): bool
    {
        return $this->feeds->forShop($idShop) !== [];
    }

    private function countries(int $idShop): array
    {
        $db = Db::getInstance();
        $default = (int) \Configuration::get('PS_CURRENCY_DEFAULT', null, null, $idShop);
        $currency = static function (int $id) use ($db): array {
            $row = $db->getRow('SELECT `iso_code`, `conversion_rate` FROM `' . _DB_PREFIX_ . 'currency` WHERE `id_currency` = ' . $id);

            return [(string) ($row['iso_code'] ?? ''), (float) ($row['conversion_rate'] ?? 1) ?: 1.0];
        };

        $targets = [];

        foreach ($this->feeds->forShop($idShop) as $feed) {
            $targets[strtoupper($feed->country)] ??= [$feed->idCurrency ?: $default, $feed->withTax];
        }

        [$defaultIso, $defaultRate] = $currency($default);
        $out = [];

        foreach ($targets as $iso => [$idCurrency, $withTax]) {
            [$code, $rate] = $currency((int) $idCurrency);

            if ($code === '') {
                [$code, $rate] = [$defaultIso, $defaultRate];
            }

            $out[$iso] = ['currency' => $code, 'rate' => $rate / ($defaultRate ?: 1.0), 'tax' => $withTax ? $this->taxRates($idShop, $iso) : []];
        }

        return $out;
    }

    /** Podatek przewoznikow w kraju (id przewoznika => %). @return array<int,float> */
    private function taxRates(int $idShop, string $iso): array
    {
        $out = [];

        foreach (Db::getInstance()->executeS(
            'SELECT ct.`id_carrier`, MAX(t.`rate`) AS rate
             FROM `' . _DB_PREFIX_ . 'carrier_tax_rules_group_shop` ct
             INNER JOIN `' . _DB_PREFIX_ . 'tax_rule` tr ON tr.`id_tax_rules_group` = ct.`id_tax_rules_group`
             INNER JOIN `' . _DB_PREFIX_ . 'country` c ON c.`id_country` = tr.`id_country` AND c.`iso_code` = "' . pSQL($iso) . '"
             INNER JOIN `' . _DB_PREFIX_ . 'tax` t ON t.`id_tax` = tr.`id_tax` AND t.`active` = 1 AND t.`deleted` = 0
             WHERE ct.`id_shop` = ' . $idShop . ' AND tr.`zipcode_from` IN ("", "0") AND tr.`id_state` = 0
             GROUP BY ct.`id_carrier`'
        ) ?: [] as $r) {
            $out[(int) $r['id_carrier']] = (float) $r['rate'];
        }

        return $out;
    }

    /**
     * Przewoznicy sklepu z strefami, krajami i przedzialami cen (SQL odczytu z FeedForge, po poprawkach).
     *
     * @param string[] $targetCountries
     *
     * @return array<int,array<string,mixed>>
     */
    private function carriers(int $idShop, array $targetCountries): array
    {
        $db = Db::getInstance();
        $idLang = (int) \Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);
        $defaultMethod = (int) \Configuration::get('PS_SHIPPING_METHOD', null, null, $idShop) ? 'weight' : 'price';
        $handlingFee = (float) \Configuration::get('PS_SHIPPING_HANDLING', null, null, $idShop);
        $carrierDays = Options::json(EventsOptions::GCR_CARRIER_DAYS, [], $idShop);
        $shopName = (string) \Configuration::get('PS_SHOP_NAME', null, null, $idShop);

        $rows = $db->executeS(
            'SELECT c.`id_carrier`, c.`id_reference`, c.`name`, c.`is_free`, c.`is_module`, c.`shipping_external`,
                    c.`shipping_method`, c.`range_behavior`, c.`shipping_handling`, IFNULL(cl.`delay`, "") AS delay
             FROM `' . _DB_PREFIX_ . 'carrier` c
             INNER JOIN `' . _DB_PREFIX_ . 'carrier_shop` cs ON cs.`id_carrier` = c.`id_carrier` AND cs.`id_shop` = ' . $idShop . '
             LEFT JOIN `' . _DB_PREFIX_ . 'carrier_lang` cl ON cl.`id_carrier` = c.`id_carrier` AND cl.`id_lang` = ' . $idLang . ' AND cl.`id_shop` = ' . $idShop . '
             WHERE c.`active` = 1 AND c.`deleted` = 0
             ORDER BY c.`position` ASC'
        ) ?: [];

        $iso = array_map(static fn (string $c): string => '"' . pSQL($c) . '"', $targetCountries) ?: ['""'];
        $out = [];

        foreach ($rows as $r) {
            $id = (int) $r['id_carrier'];
            $method = match ((int) $r['shipping_method']) {
                1 => 'weight',
                2 => 'price',
                default => $defaultMethod,
            };
            $free = (int) $r['is_free'] === 1 || (int) $r['shipping_method'] === 3;
            $days = isset($carrierDays[(string) $r['id_reference']]) && (int) $carrierDays[(string) $r['id_reference']] > 0
                ? [(int) $carrierDays[(string) $r['id_reference']], (int) $carrierDays[(string) $r['id_reference']]]
                : ShippingBuilder::parseDays((string) $r['delay']);

            // Tekst przewoznika bez liczby dni — dni dostawy ustawione przez usera dla Customer Reviews.
            if ($days === null && Options::int(EventsOptions::GCR_DELIVERY_DAYS, 0, $idShop) > 0) {
                $d = Options::int(EventsOptions::GCR_DELIVERY_DAYS, 0, $idShop);
                $days = [$d, $d];
            }

            $zones = [];

            foreach ($db->executeS(
                'SELECT z.`id_zone`, z.`name`, GROUP_CONCAT(DISTINCT co.`iso_code`) AS countries
                 FROM `' . _DB_PREFIX_ . 'carrier_zone` cz
                 INNER JOIN `' . _DB_PREFIX_ . 'zone` z ON z.`id_zone` = cz.`id_zone` AND z.`active` = 1
                 INNER JOIN `' . _DB_PREFIX_ . 'country` co ON co.`id_zone` = z.`id_zone` AND co.`active` = 1 AND co.`iso_code` IN (' . implode(',', $iso) . ')
                 WHERE cz.`id_carrier` = ' . $id . '
                 GROUP BY z.`id_zone`, z.`name`'
            ) ?: [] as $z) {
                $idZone = (int) $z['id_zone'];
                $rangeTable = $method === 'weight' ? 'range_weight' : 'range_price';
                $rangeId = $method === 'weight' ? 'id_range_weight' : 'id_range_price';

                $ranges = array_map(static fn (array $x): array => [(float) $x['delimiter1'], (float) $x['delimiter2'], $x['price'] === null ? null : (float) $x['price']], $db->executeS(
                    'SELECT r.`delimiter1`, r.`delimiter2`, d.`price`
                     FROM `' . _DB_PREFIX_ . $rangeTable . '` r
                     LEFT JOIN `' . _DB_PREFIX_ . 'delivery` d ON d.`' . $rangeId . '` = r.`' . $rangeId . '` AND d.`id_zone` = ' . $idZone . '
                         AND d.`id_carrier` = ' . $id . ' AND (d.`id_shop` IS NULL OR d.`id_shop` = ' . $idShop . ')
                     WHERE r.`id_carrier` = ' . $id . '
                     ORDER BY r.`delimiter1` ASC'
                ) ?: []);

                $zones[$idZone] = [
                    'name' => (string) $z['name'],
                    'countries' => array_values(array_filter(explode(',', (string) $z['countries']))),
                    'ranges' => $ranges,
                ];
            }

            $out[] = [
                'id' => $id,
                // Nazwa "0" w PrestaShop = nazwa sklepu (odbior osobisty).
                'name' => (string) $r['name'] === '0' ? $shopName : (string) $r['name'],
                'free' => $free,
                'module' => (int) $r['is_module'] === 1,
                'method' => $method,
                'rangeBehavior' => (int) $r['range_behavior'],
                'handling' => (int) $r['shipping_handling'] === 1 ? $handlingFee : 0.0,
                'days' => $days,
                'zones' => $zones,
            ];
        }

        return $out;
    }
}
