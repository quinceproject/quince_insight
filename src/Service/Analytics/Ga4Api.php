<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\Analytics;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Quince\Insight\Core\Google\GoogleClient;

/**
 * Cienka warstwa nad GA4 Admin API v1beta i Data API v1beta (research/05 §B, §C).
 *
 * Tylko adresy i ksztalty zadan — bez decyzji. Decyzje (idempotencja, wybor strumienia)
 * sa w `PropertySetup`, raporty w `Reports`. Klient budowany per sklep; w testach
 * podmieniany przez `useClient()`.
 */
final class Ga4Api
{
    public const ADMIN = 'https://analyticsadmin.googleapis.com/v1beta/';
    public const DATA = 'https://analyticsdata.googleapis.com/v1beta/';

    /** Tekst potwierdzenia dokladnie z discovery (research/05 §B). */
    public const ACKNOWLEDGEMENT = 'I acknowledge that I have the necessary privacy disclosures and rights from my end users for the collection and processing of their data, including the association of such data with the visitation information Google Analytics collects from my site and/or app property.';

    private ?GoogleClient $client = null;

    /** Tylko testy: klient z podmienionym transportem dla wszystkich sklepow. */
    public function useClient(?GoogleClient $client): void
    {
        $this->client = $client;
    }

    public function client(int $idShop): GoogleClient
    {
        return $this->client ?? GoogleClient::forShop($idShop, 'analytics');
    }

    /** @return array<int,array<string,mixed>> */
    public function accountSummaries(int $idShop): array
    {
        return $this->client($idShop)->paginate(self::ADMIN . 'accountSummaries', 'accountSummaries', ['pageSize' => 200]);
    }

    /** @return array<int,array<string,mixed>> pelne obiekty Property (strefa czasowa, waluta) */
    public function properties(int $idShop, string $account): array
    {
        return $this->client($idShop)->paginate(self::ADMIN . 'properties', 'properties', ['filter' => 'parent:' . $account, 'pageSize' => 200]);
    }

    /** @return array<string,mixed> */
    public function property(int $idShop, string $property): array
    {
        return $this->client($idShop)->get(self::ADMIN . self::resource($property, 'properties'));
    }

    /** @return array<string,mixed> */
    public function createProperty(int $idShop, string $account, string $name, string $timeZone, string $currency): array
    {
        return $this->client($idShop)->post(self::ADMIN . 'properties', [
            'parent' => $account,
            'displayName' => self::cut($name, 100),
            'timeZone' => $timeZone,
            'currencyCode' => $currency,
            'industryCategory' => 'SHOPPING',
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    public function dataStreams(int $idShop, string $property): array
    {
        return $this->client($idShop)->paginate(self::ADMIN . self::resource($property, 'properties') . '/dataStreams', 'dataStreams', ['pageSize' => 200]);
    }

    /** @return array<string,mixed> */
    public function createWebStream(int $idShop, string $property, string $uri, string $displayName): array
    {
        return $this->client($idShop)->post(self::ADMIN . self::resource($property, 'properties') . '/dataStreams', [
            'type' => 'WEB_DATA_STREAM',
            'displayName' => self::cut($displayName, 100),
            'webStreamData' => ['defaultUri' => $uri],
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    public function mpSecrets(int $idShop, string $stream): array
    {
        return $this->client($idShop)->paginate(self::ADMIN . self::stream($stream) . '/measurementProtocolSecrets', 'measurementProtocolSecrets', ['pageSize' => 200]);
    }

    /** @return array<string,mixed> z `secretValue` */
    public function createMpSecret(int $idShop, string $stream, string $displayName): array
    {
        return $this->client($idShop)->post(self::ADMIN . self::stream($stream) . '/measurementProtocolSecrets', ['displayName' => $displayName]);
    }

    public function acknowledge(int $idShop, string $property): void
    {
        $this->client($idShop)->post(self::ADMIN . self::resource($property, 'properties') . ':acknowledgeUserDataCollection', [
            'acknowledgement' => self::ACKNOWLEDGEMENT,
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    public function keyEvents(int $idShop, string $property): array
    {
        return $this->client($idShop)->paginate(self::ADMIN . self::resource($property, 'properties') . '/keyEvents', 'keyEvents', ['pageSize' => 200]);
    }

    /** @return array<string,mixed> */
    public function createKeyEvent(int $idShop, string $property, string $event, string $countingMethod = 'ONCE_PER_EVENT'): array
    {
        return $this->client($idShop)->post(self::ADMIN . self::resource($property, 'properties') . '/keyEvents', [
            'eventName' => $event,
            'countingMethod' => $countingMethod,
        ]);
    }

    // Bez `keyEvents.delete`: modul nie usuwa zdarzen kluczowych w GA4 (decyzja #5).

    /** @return array<int,array<string,mixed>> */
    public function googleAdsLinks(int $idShop, string $property): array
    {
        return $this->client($idShop)->paginate(self::ADMIN . self::resource($property, 'properties') . '/googleAdsLinks', 'googleAdsLinks', ['pageSize' => 200]);
    }

    /** @return array<string,mixed> */
    public function dataRetention(int $idShop, string $property): array
    {
        return $this->client($idShop)->get(self::ADMIN . self::resource($property, 'properties') . '/dataRetentionSettings');
    }

    /** @return array<string,mixed> */
    public function updateEventRetention(int $idShop, string $property, string $value): array
    {
        return $this->client($idShop)->patch(
            self::ADMIN . self::resource($property, 'properties') . '/dataRetentionSettings',
            ['eventDataRetention' => $value],
            ['updateMask' => 'eventDataRetention']
        );
    }

    /**
     * @param array<int,array<string,mixed>> $requests najwyzej 5 (limit batchRunReports)
     *
     * @return array<int,array<string,mixed>> odpowiedzi w kolejnosci zadan
     */
    public function batchRunReports(int $idShop, string $property, array $requests): array
    {
        $out = [];

        foreach (array_chunk($requests, 5) as $chunk) {
            $response = $this->client($idShop)->post(
                self::DATA . self::resource($property, 'properties') . ':batchRunReports',
                ['requests' => array_values($chunk)]
            );

            foreach (array_values($chunk) as $i => $unused) {
                $out[] = (array) ($response['reports'][$i] ?? []);
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $body
     *
     * @return array<string,mixed>
     */
    public function runRealtimeReport(int $idShop, string $property, array $body): array
    {
        return $this->client($idShop)->post(self::DATA . self::resource($property, 'properties') . ':runRealtimeReport', $body);
    }

    /** `properties/123` albo `123` -> `properties/123`; inny tekst -> wyjatek (nie skladamy adresu z byle czego). */
    public static function resource(string $name, string $collection): string
    {
        $name = trim($name);

        if (preg_match('#^' . preg_quote($collection, '#') . '/\d+$#', $name) === 1) {
            return $name;
        }

        if (preg_match('#^\d+$#', $name) === 1) {
            return $collection . '/' . $name;
        }

        throw new \InvalidArgumentException('Invalid ' . $collection . ' resource name.');
    }

    /** `properties/1/dataStreams/2` — tylko taki ksztalt trafia do adresu. */
    public static function stream(string $name): string
    {
        if (preg_match('#^properties/\d+/dataStreams/\d+$#', trim($name)) !== 1) {
            throw new \InvalidArgumentException('Invalid data stream resource name.');
        }

        return trim($name);
    }

    private static function cut(string $text, int $max): string
    {
        return function_exists('mb_substr') ? mb_substr($text, 0, $max) : substr($text, 0, $max);
    }
}
