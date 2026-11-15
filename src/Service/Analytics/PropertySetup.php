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

use Quince\Insight\Core\Clock;
use Quince\Insight\Core\Google\GoogleApiException;
use Quince\Insight\Core\Options;

/**
 * Wybor i tworzenie zasobow GA4 dla sklepu — zawsze idempotentnie (PLAN §3 pkt 8):
 * przed kazdym "create" szukamy istniejacego obiektu (zapisany w sklepie, potem lista z Google).
 *
 * Kolejnosc jak w kreatorze (prototyp `taskLines('ga4')`): usluga -> strumien WWW ->
 * sekret Measurement Protocol (po potwierdzeniu zbierania danych) -> `purchase` jako zdarzenie kluczowe.
 */
final class PropertySetup
{
    public function __construct(private readonly Ga4Api $api)
    {
    }

    /**
     * Usluga do uzycia: istniejaca (`$choice` = `properties/…`) albo nowa (`$choice` = `new`).
     * Nowa: najpierw zapisana w sklepie o tej samej nazwie i koncie, potem usluga o tej nazwie
     * na koncie, dopiero potem `properties.create`.
     *
     * @return array<string,mixed> obiekt Property (co najmniej `name`, `displayName`)
     */
    public function ensureProperty(int $idShop, string $account, string $choice, string $name, string $timeZone, string $currency): array
    {
        Ga4Api::resource($account, 'accounts');

        if ($choice !== 'new') {
            $resource = Ga4Api::resource($choice, 'properties');

            foreach ($this->api->properties($idShop, $account) as $property) {
                if (($property['name'] ?? '') === $resource) {
                    return $property;
                }
            }

            throw new \DomainException('The selected property is not in this Analytics account.');
        }

        $name = trim($name);

        if ($name === '') {
            throw new \DomainException('Enter a name for the new property.');
        }

        $existing = self::findByName($this->api->properties($idShop, $account), $name);

        if ($existing !== null) {
            return $existing;
        }

        return $this->api->createProperty($idShop, $account, $name, $timeZone, $currency);
    }

    /**
     * Strumien WWW sklepu: wybrany (`properties/x/dataStreams/y`), albo `new` = strumien
     * o tym samym hoscie co sklep, jesli juz jest, inaczej nowy.
     *
     * @return array<string,mixed> obiekt DataStream
     */
    public function ensureStream(int $idShop, string $property, string $choice, string $shopUrl): array
    {
        $streams = $this->api->dataStreams($idShop, $property);

        if ($choice !== 'new' && $choice !== '') {
            foreach ($streams as $stream) {
                if (($stream['name'] ?? '') === $choice && ($stream['type'] ?? '') === 'WEB_DATA_STREAM') {
                    return $stream;
                }
            }

            throw new \DomainException('The selected data stream is not in this property.');
        }

        $match = self::streamForHost($streams, self::host($shopUrl));

        if ($match !== null) {
            return $match;
        }

        $uri = rtrim($shopUrl, '/');

        return $this->api->createWebStream($idShop, $property, $uri, self::host($shopUrl));
    }

    /**
     * Sekret Measurement Protocol strumienia. Zapisany sekret, ktory nadal jest w GA4, zostaje;
     * sekret modulu ("Quince Insight") z listy Google jest uzyty ponownie; dopiero potem `create`.
     *
     * Google tworzy sekret dopiero po potwierdzeniu zbierania danych (SPEC §9 pkt 5).
     * Bez zgody usera (`$acknowledge`) i bez zapisanej daty potwierdzenia odmowa
     * Google konczy sie `AckRequiredException` — kreator pokazuje wtedy przelacznik potwierdzenia.
     */
    public function ensureSecret(int $idShop, string $property, string $stream, bool $acknowledge): void
    {
        if ($acknowledge) {
            $this->api->acknowledge($idShop, $property);
            Options::set(AnalyticsOptions::ACK_AT, Clock::now(), $idShop);
        }

        $secrets = $this->api->mpSecrets($idShop, $stream);
        $savedName = Options::str(AnalyticsOptions::MP_SECRET_NAME, '', $idShop);
        $saved = $savedName !== '' && Options::secret(AnalyticsOptions::MP_SECRET, $idShop) !== '';

        foreach ($secrets as $secret) {
            if ($saved && ($secret['name'] ?? '') === $savedName) {
                Options::delete(AnalyticsOptions::ACK_NEEDED, $idShop);

                return;
            }
        }

        foreach ($secrets as $secret) {
            if (($secret['displayName'] ?? '') === AnalyticsOptions::SECRET_DISPLAY_NAME && (string) ($secret['secretValue'] ?? '') !== '') {
                $this->store($idShop, $secret);

                return;
            }
        }

        try {
            $created = $this->api->createMpSecret($idShop, $stream, AnalyticsOptions::SECRET_DISPLAY_NAME);
        } catch (GoogleApiException $e) {
            // Ksztalt odmowy bez potwierdzenia nie jest opisany (raport B §5) — kazde 400/403
            // przed potwierdzeniem traktujemy jako "potrzebne potwierdzenie".
            if (!$acknowledge && Options::str(AnalyticsOptions::ACK_AT, '', $idShop) === ''
                && in_array($e->httpCode(), [400, 403], true) && !$e->isQuota() && !$e->isApiDisabled()) {
                Options::set(AnalyticsOptions::ACK_NEEDED, 1, $idShop);

                throw new AckRequiredException('Google requires the user data collection acknowledgement for this property before a Measurement Protocol secret can be created.', 0, $e);
            }

            throw $e;
        }

        if ((string) ($created['secretValue'] ?? '') === '') {
            throw new \DomainException('Google did not return the Measurement Protocol secret.');
        }

        $this->store($idShop, $created);
    }

    /**
     * Zapis wyboru w sklepie. Zmiana uslugi albo strumienia kasuje sekret i potwierdzenie
     * poprzedniej — naleza do tamtej uslugi.
     *
     * @param array<string,mixed> $property
     * @param array<string,mixed> $stream
     */
    public function remember(int $idShop, string $account, array $property, array $stream): void
    {
        $propertyName = (string) ($property['name'] ?? '');
        $streamName = (string) ($stream['name'] ?? '');

        if (Options::str(AnalyticsOptions::PROPERTY, '', $idShop) !== $propertyName) {
            Options::delete(AnalyticsOptions::ACK_AT, $idShop);
            Options::delete(AnalyticsOptions::ADS_LINK, $idShop);
        }

        if (Options::str(AnalyticsOptions::STREAM, '', $idShop) !== $streamName) {
            Options::delete(AnalyticsOptions::MP_SECRET, $idShop);
            Options::delete(AnalyticsOptions::MP_SECRET_NAME, $idShop);
            Options::delete(AnalyticsOptions::ACK_NEEDED, $idShop);
        }

        Options::set(AnalyticsOptions::ACCOUNT, $account, $idShop);
        Options::set(AnalyticsOptions::PROPERTY, $propertyName, $idShop);
        Options::set(AnalyticsOptions::PROPERTY_NAME, (string) ($property['displayName'] ?? ''), $idShop);
        Options::set(AnalyticsOptions::STREAM, $streamName, $idShop);
        Options::set(AnalyticsOptions::MEASUREMENT_ID, (string) ($stream['webStreamData']['measurementId'] ?? ''), $idShop);
    }

    /** @param array<int,array<string,mixed>> $properties */
    public static function findByName(array $properties, string $name): ?array
    {
        $needle = self::norm($name);

        foreach ($properties as $property) {
            if (self::norm((string) ($property['displayName'] ?? '')) === $needle) {
                return $property;
            }
        }

        return null;
    }

    /**
     * Strumien WWW, ktorego adres wskazuje ten sam host (bez `www.` i portu domyslnego).
     *
     * @param array<int,array<string,mixed>> $streams
     *
     * @return array<string,mixed>|null
     */
    public static function streamForHost(array $streams, string $host): ?array
    {
        if ($host === '') {
            return null;
        }

        foreach ($streams as $stream) {
            if (($stream['type'] ?? '') === 'WEB_DATA_STREAM'
                && self::host((string) ($stream['webStreamData']['defaultUri'] ?? '')) === $host) {
                return $stream;
            }
        }

        return null;
    }

    /** Host do porownan: male litery, bez `www.`; adres bez schematu tez przechodzi. */
    public static function host(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return '';
        }

        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $url = 'https://' . $url;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $port = parse_url($url, PHP_URL_PORT);

        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return $host . ($port && !in_array((int) $port, [80, 443], true) ? ':' . $port : '');
    }

    /** @param array<string,mixed> $secret */
    private function store(int $idShop, array $secret): void
    {
        Options::setSecret(AnalyticsOptions::MP_SECRET, (string) $secret['secretValue'], $idShop);
        Options::set(AnalyticsOptions::MP_SECRET_NAME, (string) ($secret['name'] ?? ''), $idShop);
        Options::delete(AnalyticsOptions::ACK_NEEDED, $idShop);
    }

    private static function norm(string $text): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower(trim($text)) : strtolower(trim($text));
    }
}
