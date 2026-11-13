<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\PageSpeed;

use Quince\Insight\Core\Google\GoogleApiException;
use Quince\Insight\Core\Google\GoogleClient;

/**
 * Wywolania Google dla Page speed — oba interfejsy tylko z kluczem API (research/05 §J).
 *
 *  - CrUX API: dane prawdziwych wizyt dla calej domeny (`origin`), okno 28 dni.
 *    Dane polowe wylacznie stad — Google zapowiada usuniecie ich z odpowiedzi PageSpeed
 *    Insights (ARCHITEKTURA §12 pkt 13).
 *  - PageSpeed Insights v5: test laboratoryjny jednej strony (limit czasu klienta 60 s).
 */
final class PageSpeedApi
{
    public const CRUX_URL = 'https://chromeuxreport.googleapis.com/v1/records:queryRecord';
    public const PSI_URL = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

    /** Urzadzenie modulu => `formFactor` CrUX. */
    private const FORM_FACTORS = ['mobile' => 'PHONE', 'desktop' => 'DESKTOP'];

    /** @var (callable(int,string):GoogleClient)|null tylko testy */
    private $clientFactory;

    /**
     * Tylko testy: podmienia sposob tworzenia klienta.
     *
     * @param callable(int,string):GoogleClient $factory
     */
    public function useClientFactory(callable $factory): void
    {
        $this->clientFactory = $factory;
    }

    /**
     * Dane CrUX dla domeny. `null` = Google nie ma danych (404 — za malo ruchu z Chrome),
     * co jest stanem, nie bledem.
     *
     * @return array{metrics: array<string,array{p75: float, dist: int[]}>, period: array{first: string, last: string}}|null
     */
    public function crux(int $idShop, string $origin, string $strategy): ?array
    {
        try {
            $response = $this->client($idShop, 'crux')->post(self::CRUX_URL, [
                'origin' => $origin,
                'formFactor' => self::FORM_FACTORS[$strategy] ?? 'PHONE',
                'metrics' => array_keys(WebVitals::METRICS),
            ]);
        } catch (GoogleApiException $e) {
            if ($e->httpCode() === 404) {
                return null;
            }

            throw $e;
        }

        return WebVitals::parseCrux($response);
    }

    /**
     * Test laboratoryjny strony.
     *
     * @return array{score: ?int, lcp: ?float, tbt: ?float, cls: ?float, tips: array<int,array{title:string,ms:int}>, error: string}
     */
    public function lab(int $idShop, string $url, string $strategy): array
    {
        $response = $this->client($idShop, 'pagespeed')->get(self::PSI_URL, [
            'url' => $url,
            'strategy' => strtoupper($strategy) === 'DESKTOP' ? 'DESKTOP' : 'MOBILE',
            'category' => 'PERFORMANCE',
            'locale' => 'en',
        ]);

        return WebVitals::parseLab($response);
    }

    /** `https://yourshop.com` — schemat i host adresu sklepu, bez sciezki (CrUX `origin`). */
    public static function origin(string $baseUrl): string
    {
        $parts = parse_url($baseUrl);

        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }

        return ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    private function client(int $idShop, string $service): GoogleClient
    {
        if ($this->clientFactory !== null) {
            return ($this->clientFactory)($idShop, $service);
        }

        return GoogleClient::withApiKey($idShop, $service);
    }
}
