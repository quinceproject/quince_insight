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
use Quince\Insight\Service\Merchant\Dto\Feed;

/**
 * Formularz feedu (Add feed i pierwszy feed w kreatorze): listy krajow, jezykow i walut,
 * podpowiedzi po kraju, walidacja, utworzenie wersji roboczej ze zrodlem danych w Google.
 */
final class FeedForm
{
    /**
     * Podpowiedz jezyka i waluty po kraju (prototyp `GMC_COUNTRY_DEFAULTS`) — tylko jako
     * wybor wstepny; user moze zmienic. Jezyk urzedowy i waluta kraju (ISO 639-1 / ISO 4217).
     */
    public const COUNTRY_DEFAULTS = [
        'PL' => ['pl', 'PLN'], 'DE' => ['de', 'EUR'], 'AT' => ['de', 'EUR'], 'CH' => ['de', 'CHF'], 'CZ' => ['cs', 'CZK'],
        'SK' => ['sk', 'EUR'], 'GB' => ['en', 'GBP'], 'IE' => ['en', 'EUR'], 'US' => ['en', 'USD'], 'CA' => ['en', 'CAD'],
        'AU' => ['en', 'AUD'], 'FR' => ['fr', 'EUR'], 'BE' => ['fr', 'EUR'], 'LU' => ['fr', 'EUR'], 'NL' => ['nl', 'EUR'],
        'ES' => ['es', 'EUR'], 'IT' => ['it', 'EUR'], 'PT' => ['pt', 'EUR'], 'SE' => ['sv', 'SEK'], 'DK' => ['da', 'DKK'],
        'NO' => ['no', 'NOK'], 'FI' => ['fi', 'EUR'], 'HU' => ['hu', 'HUF'], 'RO' => ['ro', 'RON'], 'LT' => ['lt', 'EUR'],
        'LV' => ['lv', 'EUR'], 'EE' => ['et', 'EUR'], 'SI' => ['sl', 'EUR'], 'HR' => ['hr', 'EUR'], 'GR' => ['el', 'EUR'],
        'BG' => ['bg', 'BGN'], 'UA' => ['uk', 'UAH'],
    ];

    public function __construct(
        private readonly FeedRepository $feeds,
        private readonly MerchantApi $api,
        private readonly \Quince\Insight\Service\MerchantTools\RuleRepository $ruleRepository,
    ) {
    }

    /** @return array<string,string> ISO => nazwa (aktywne kraje sklepu, w jezyku panelu) */
    public function countries(int $idShop, int $idLang): array
    {
        $rows = Db::getInstance()->executeS('SELECT c.`iso_code`, cl.`name` FROM `' . _DB_PREFIX_ . 'country` c
            INNER JOIN `' . _DB_PREFIX_ . 'country_shop` cs ON cs.`id_country` = c.`id_country` AND cs.`id_shop` = ' . $idShop . '
            LEFT JOIN `' . _DB_PREFIX_ . 'country_lang` cl ON cl.`id_country` = c.`id_country` AND cl.`id_lang` = ' . $idLang . '
            WHERE c.`active` = 1 ORDER BY cl.`name` ASC');
        $out = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[(string) $row['iso_code']] = (string) ($row['name'] ?: $row['iso_code']);
        }

        return $out;
    }

    /** @return array<int,array{id:int,iso:string,name:string,active:bool}> jezyki zainstalowane; active = wlaczony w sklepie */
    public function languages(int $idShop): array
    {
        $rows = Db::getInstance()->executeS('SELECT l.`id_lang`, l.`iso_code`, l.`name`, l.`active`, (ls.`id_lang` IS NOT NULL) AS in_shop
            FROM `' . _DB_PREFIX_ . 'lang` l
            LEFT JOIN `' . _DB_PREFIX_ . 'lang_shop` ls ON ls.`id_lang` = l.`id_lang` AND ls.`id_shop` = ' . $idShop . '
            ORDER BY l.`name` ASC');

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id_lang'],
            'iso' => strtolower((string) $r['iso_code']),
            // Nazwa jezyka w PrestaShop ma dopisek kraju w nawiasie ("Polski (Polish)") — zostawiamy jak jest.
            'name' => (string) $r['name'],
            'active' => (bool) $r['active'] && (bool) $r['in_shop'],
        ], is_array($rows) ? $rows : []);
    }

    /** @return array<int,array{id:int,iso:string,active:bool}> waluty; active = wlaczona w sklepie */
    public function currencies(int $idShop): array
    {
        $rows = Db::getInstance()->executeS('SELECT c.`id_currency`, c.`iso_code`, c.`active`, (cs.`id_currency` IS NOT NULL) AS in_shop
            FROM `' . _DB_PREFIX_ . 'currency` c
            LEFT JOIN `' . _DB_PREFIX_ . 'currency_shop` cs ON cs.`id_currency` = c.`id_currency` AND cs.`id_shop` = ' . $idShop . '
            WHERE c.`deleted` = 0 ORDER BY c.`iso_code` ASC');

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id_currency'],
            'iso' => (string) $r['iso_code'],
            'active' => (bool) $r['active'] && (bool) $r['in_shop'],
        ], is_array($rows) ? $rows : []);
    }

    /**
     * Wartosci startowe formularza nowego feedu: kraj domyslny sklepu i jego podpowiedzi.
     *
     * @return array<string,mixed>
     */
    public function defaults(int $idShop): array
    {
        $iso = (string) \Country::getIsoById((int) \Configuration::get('PS_COUNTRY_DEFAULT', null, null, $idShop));
        $suggest = $this->suggest($idShop, $iso);
        $taken = array_map(static fn (Feed $f): string => $f->country . ':' . $f->idLang, $this->feeds->forShop($idShop));

        // Kraj domyslny ma juz feed — proponujemy pierwszy kraj sklepu bez feedu.
        if (in_array($iso . ':' . $suggest['id_lang'], $taken, true)) {
            foreach (array_keys($this->countries($idShop, (int) \Configuration::get('PS_LANG_DEFAULT', null, null, $idShop))) as $other) {
                $s = $this->suggest($idShop, $other);

                if (!in_array($other . ':' . $s['id_lang'], $taken, true)) {
                    $iso = $other;
                    $suggest = $s;

                    break;
                }
            }
        }

        return [
            'country' => $iso,
            'id_lang' => $suggest['id_lang'],
            'id_currency' => $suggest['id_currency'],
            'with_tax' => true,
            'title_template' => '{name}',
            'description_source' => 'description_short',
            'offer_prefix' => '',
            'id_source' => 'id',
        ];
    }

    /**
     * Jezyk i waluta dla kraju — tylko sposrod aktywnych w sklepie; inaczej domyslne sklepu.
     *
     * @return array{id_lang:int,id_currency:int}
     */
    public function suggest(int $idShop, string $country): array
    {
        [$langIso, $currencyIso] = self::COUNTRY_DEFAULTS[strtoupper($country)] ?? ['', ''];
        $idLang = (int) \Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);
        $idCurrency = (int) \Configuration::get('PS_CURRENCY_DEFAULT', null, null, $idShop);

        foreach ($this->languages($idShop) as $lang) {
            if ($lang['active'] && $lang['iso'] === $langIso) {
                $idLang = $lang['id'];
            }
        }

        foreach ($this->currencies($idShop) as $currency) {
            if ($currency['active'] && $currency['iso'] === $currencyIso) {
                $idCurrency = $currency['id'];
            }
        }

        return ['id_lang' => $idLang, 'id_currency' => $idCurrency];
    }

    /**
     * Walidacja danych nowego feedu. Bledy per pole (teksty z prototypu).
     *
     * @param array<string,mixed> $input
     *
     * @return array{0:array<string,mixed>,1:array<string,string>} [dane, bledy]
     */
    public function validate(int $idShop, array $input): array
    {
        $errors = [];
        $country = strtoupper(trim((string) ($input['country'] ?? '')));
        $idLang = (int) ($input['id_lang'] ?? 0);
        $idCurrency = (int) ($input['id_currency'] ?? 0);
        $countries = $this->countries($idShop, (int) \Configuration::get('PS_LANG_DEFAULT', null, null, $idShop));
        $lang = null;

        foreach ($this->languages($idShop) as $item) {
            if ($item['id'] === $idLang) {
                $lang = $item;
            }
        }

        $currency = null;

        foreach ($this->currencies($idShop) as $item) {
            if ($item['id'] === $idCurrency) {
                $currency = $item;
            }
        }

        if (!isset($countries[$country])) {
            $errors['country'] = 'Choose a country.';
        }

        if ($lang === null || !$lang['active']) {
            $errors['id_lang'] = 'Not active in the shop — products have no texts in this language.';
        }

        if ($currency === null || !$currency['active']) {
            $errors['id_currency'] = 'Not active in the shop — prices cannot be converted.';
        }

        if (!$errors && $this->feeds->exists($idShop, $country, $idLang)) {
            $errors['country'] = 'A feed for this country and language already exists. Change one of them.';
        }

        $prefix = trim((string) ($input['offer_prefix'] ?? ''));

        if ($prefix !== '' && !preg_match('/^[A-Za-z0-9_\-]{1,20}$/', $prefix)) {
            $errors['offer_prefix'] = 'Use up to 20 letters, digits, "-" or "_".';
        }

        $data = [
            'country' => $country,
            'feed_label' => $country,
            'id_lang' => $idLang,
            'content_language' => $lang['iso'] ?? '',
            'id_currency' => $idCurrency,
            'with_tax' => filter_var($input['with_tax'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'title_template' => (string) ($input['title_template'] ?? '{name}'),
            'description_source' => (string) ($input['description_source'] ?? 'description_short'),
            'offer_prefix' => $prefix,
            'id_source' => (string) ($input['id_source'] ?? 'id'),
            'name' => $country . ' · ' . strtolower((string) ($lang['iso'] ?? '')) . ' · ' . (string) ($currency['iso'] ?? ''),
        ];

        return [$data, $errors];
    }

    /**
     * Tworzy wersje robocza: najpierw zrodlo danych w Merchant Center (krok 1 z "What happens"),
     * potem wiersz feedu. Blad Google = nic nie zostaje zapisane lokalnie. Zadnych produktow.
     *
     * @param array<string,mixed> $data wynik validate()
     */
    public function createDraft(int $idShop, array $data): Feed
    {
        $idFeed = $this->feeds->create($idShop, $data);
        $feed = $this->feeds->find($idFeed);

        if ($feed === null) {
            throw new \RuntimeException('The feed could not be saved.');
        }

        try {
            $name = $this->api->ensureDataSource($feed);
        } catch (\Throwable $e) {
            $this->feeds->delete($idFeed);

            throw $e;
        }

        $this->feeds->setDataSource($idFeed, $name);
        $this->feeds->log($idShop, $idFeed, 'info', 'Feed created as a draft', ['data_source' => $name]);

        return $this->feeds->find($idFeed) ?? $feed;
    }

    /**
     * "Start from an existing feed": filtry (kopiowane do feedu) i reguly przypisane do feedu
     * zrodlowego (tabela `rule` agenta M, `id_feed` = feed zrodlowy). Mapowanie jest w modelu
     * danych per sklep (ARCHITEKTURA §7.7), wiec feedy jednego sklepu juz je dziela.
     *
     * @return array{filters:bool,rules:int}
     */
    public function copyFrom(Feed $source, Feed $target, bool $filters, bool $rules): array
    {
        $out = ['filters' => false, 'rules' => 0];

        if ($filters) {
            $this->feeds->saveFilters($target->id, $source->filters);
            $out['filters'] = true;
        }

        if ($rules) {
            // Kopia przez repozytorium agenta M (reguly wspolne `id_feed = 0` obowiazuja i tak).
            $out['rules'] = $this->ruleRepository->copyFeed($source->idShop, $source->id, $target->id);
        }

        return $out;
    }
}
