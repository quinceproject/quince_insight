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

use Quince\Insight\Core\Google\GoogleApiException;
use Quince\Insight\Service\Merchant\Dto\Feed;

/**
 * Wywolania Merchant API v1 dla feedow: zrodla danych, `productInputs`, przetworzone produkty.
 *
 * Zrodla danych przeniesione z FeedForge `DataSourceService` (z kolejnymi, coraz mniejszymi
 * zestawami miejsc docelowych — poprawka z produkcji FeedForge 2.0.3). Tworzenie zrodla nie
 * jest idempotentne (research/04 §18) — najpierw szukamy istniejacego o tej samej nazwie,
 * `feedLabel` i `contentLanguage`.
 */
final class MerchantApi
{
    /** @var array<int,array<int,array{destination:string,state:string}>|null> */
    private const DESTINATION_FALLBACKS = [
        [['destination' => 'SHOPPING_ADS', 'state' => 'ENABLED'], ['destination' => 'FREE_LISTINGS', 'state' => 'ENABLED']],
        [['destination' => 'SHOPPING_ADS', 'state' => 'ENABLED']],
        [['destination' => 'FREE_LISTINGS', 'state' => 'ENABLED']],
        null,
    ];

    public function __construct(private readonly MerchantAccount $account)
    {
    }

    /** Nazwa zrodla w Merchant Center — jak w prototypie: „Quince Insight · PL · pl”. */
    public static function dataSourceName(Feed $feed): string
    {
        return 'Quince Insight · ' . strtoupper($feed->country) . ' · ' . strtolower($feed->contentLanguage);
    }

    /** Zrodlo danych feedu: istniejace albo nowe. Zwraca pelna nazwe `accounts/x/dataSources/y`. */
    public function ensureDataSource(Feed $feed): string
    {
        if ($feed->dataSource !== '') {
            return $feed->dataSource;
        }

        $id = $this->accountId($feed->idShop);
        $client = $this->account->client($feed->idShop);
        $url = MerchantAccount::API . '/datasources/v1/accounts/' . $id . '/dataSources';
        $displayName = self::dataSourceName($feed);

        foreach ($client->paginate($url, 'dataSources', ['pageSize' => 1000], 10) as $source) {
            $primary = (array) ($source['primaryProductDataSource'] ?? []);

            if ((string) ($source['displayName'] ?? '') === $displayName
                && strcasecmp((string) ($primary['feedLabel'] ?? ''), $feed->feedLabel) === 0
                && strcasecmp((string) ($primary['contentLanguage'] ?? ''), $feed->contentLanguage) === 0
                && (string) ($source['input'] ?? 'API') === 'API') {
                return (string) $source['name'];
            }
        }

        $base = [
            'feedLabel' => strtoupper($feed->feedLabel),
            'contentLanguage' => strtolower($feed->contentLanguage),
            'countries' => [strtoupper($feed->country)],
        ];
        $last = null;

        foreach (self::DESTINATION_FALLBACKS as $destinations) {
            $body = $base;

            if ($destinations !== null) {
                $body['destinations'] = $destinations;
            }

            try {
                $created = $client->post($url, ['displayName' => $displayName, 'primaryProductDataSource' => $body]);

                return (string) ($created['name'] ?? '');
            } catch (GoogleApiException $e) {
                $last = $e;

                // Tylko odmowa miejsc docelowych ma sens z mniejszym zestawem.
                if ($e->httpCode() !== 400 || stripos($e->getMessage(), 'destination') === false) {
                    break;
                }
            }
        }

        throw $last ?? new \RuntimeException('Could not create the data source.');
    }

    /** Usuniecie zrodla w Merchant Center (przy usuwaniu feedu na zyczenie usera). */
    public function deleteDataSource(int $idShop, string $name): void
    {
        if ($name === '') {
            return;
        }

        try {
            $this->account->client($idShop)->delete(MerchantAccount::API . '/datasources/v1/' . $name);
        } catch (GoogleApiException $e) {
            if ($e->httpCode() !== 404) {
                throw $e;
            }
        }
    }

    /** @param array<string,mixed> $input */
    public function insert(Feed $feed, array $input): void
    {
        $this->account->client($feed->idShop)->post(
            MerchantAccount::API . '/products/v1/accounts/' . $this->accountId($feed->idShop) . '/productInputs:insert',
            $input,
            ['dataSource' => $feed->dataSource]
        );
    }

    /** Zmiana samej ceny i dostepnosci (research/04 §9). @param array<string,mixed> $input */
    public function patch(Feed $feed, array $input): void
    {
        $patch = PayloadBuilder::patch($input);
        $name = OfferId::productInputName($this->accountId($feed->idShop), $feed->contentLanguage, $feed->feedLabel, (string) $input['offerId']);

        $this->account->client($feed->idShop)->patch(
            MerchantAccount::API . '/products/v1/' . $name,
            $patch['body'],
            ['dataSource' => $feed->dataSource, 'updateMask' => $patch['mask']]
        );
    }

    /** Usuniecie oferty z tego feedu; 404 = juz jej nie ma (FeedForge liczyl to jako blad). */
    public function delete(Feed $feed, string $offerId): void
    {
        $name = OfferId::productInputName($this->accountId($feed->idShop), $feed->contentLanguage, $feed->feedLabel, $offerId);

        try {
            $this->account->client($feed->idShop)->delete(MerchantAccount::API . '/products/v1/' . $name, ['dataSource' => $feed->dataSource]);
        } catch (GoogleApiException $e) {
            if ($e->httpCode() !== 404) {
                throw $e;
            }
        }
    }

    /**
     * Strona przetworzonych produktow konta (statusy).
     *
     * @return array{products:array<int,array<string,mixed>>,next:string}
     */
    public function products(int $idShop, string $pageToken = '', int $pageSize = 250): array
    {
        $data = $this->account->client($idShop)->get(
            MerchantAccount::API . '/products/v1/accounts/' . $this->accountId($idShop) . '/products',
            ['pageSize' => $pageSize, 'pageToken' => $pageToken]
        );

        return ['products' => array_values((array) ($data['products'] ?? [])), 'next' => (string) ($data['nextPageToken'] ?? '')];
    }

    /** @return array<string,mixed>|null przetworzony produkt albo null (404 — Google jeszcze go nie ma) */
    public function product(Feed $feed, string $offerId): ?array
    {
        $name = OfferId::productName($this->accountId($feed->idShop), $feed->contentLanguage, $feed->feedLabel, $offerId);

        try {
            return $this->account->client($feed->idShop)->get(MerchantAccount::API . '/products/v1/' . $name);
        } catch (GoogleApiException $e) {
            if ($e->httpCode() === 404) {
                return null;
            }

            throw $e;
        }
    }

    private function accountId(int $idShop): string
    {
        $id = $this->account->accountId($idShop);

        if ($id === '') {
            throw new \InvalidArgumentException('Choose a Merchant Center account first.');
        }

        return $id;
    }
}
