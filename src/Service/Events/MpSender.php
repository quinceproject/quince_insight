<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\Events;

use Quince\Insight\Core\Google\Http;
use Quince\Insight\Core\Log;
use Quince\Insight\Service\Analytics\Ga4Settings;

/**
 * Wysylka do GA4 Measurement Protocol (research/05 §D).
 *
 * Wlasne wywolanie zamiast `GoogleClient`, bo:
 *  - MP nie uzywa OAuth (sekret w parametrze `api_secret`),
 *  - przy skladaniu zamowienia klient czeka na odpowiedz — jedna proba, limit 3 s
 *    (ARCHITEKTURA §8: co sie nie zmiesci, dosyla cron `orders`), a nie 4 proby po 20 s.
 * Transport ten sam (`Http::transport`), wiec tryb atrap czyta `tests/fixtures/google/measurement/`.
 *
 * Produkcja zawsze odpowiada 2xx, nawet dla zlej tresci — dlatego tresc kazdej wysylki
 * jest najpierw sprawdzana lokalnie przez `MpPayload` (wymagane pola), a test i przebieg
 * testowy uzywaja serwera walidacji `/debug/mp/collect`.
 */
final class MpSender
{
    public const ENDPOINT = 'https://www.google-analytics.com/mp/collect';
    public const DEBUG_ENDPOINT = 'https://www.google-analytics.com/debug/mp/collect';

    private const TIMEOUT_CHECKOUT = 3;
    private const TIMEOUT_BACKGROUND = 10;

    public function __construct(private readonly Ga4Settings $ga4)
    {
    }

    public function hasCredentials(int $idShop): bool
    {
        return $this->ga4->measurementId($idShop) !== '' && $this->ga4->mpSecret($idShop) !== '';
    }

    /**
     * Wysyla tresc. Wynik: ok, reason ('' albo `http_<kod>` / `timeout` / `no_secret` / `invalid`),
     * messages (tylko walidacja).
     *
     * @param array<string,mixed> $payload
     *
     * @return array{ok:bool, reason:string, messages:array<int,array<string,mixed>>}
     */
    public function send(int $idShop, array $payload, bool $validateOnly = false, bool $inCheckout = false): array
    {
        $measurementId = $this->ga4->measurementId($idShop);
        $secret = $this->ga4->mpSecret($idShop);

        if ($measurementId === '' || $secret === '') {
            return ['ok' => false, 'reason' => 'no_secret', 'messages' => []];
        }

        $url = ($validateOnly ? self::DEBUG_ENDPOINT : self::ENDPOINT) . '?' . http_build_query([
            'measurement_id' => $measurementId,
            'api_secret' => $secret,
        ], '', '&', PHP_QUERY_RFC3986);

        $body = Json::encode($payload);

        // Limit Google: tresc ponizej 130 kB.
        if (strlen($body) > 130000) {
            return ['ok' => false, 'reason' => 'invalid', 'messages' => [['description' => 'Payload is larger than 130 kB.']]];
        }

        $started = microtime(true);

        try {
            $response = Http::transport('measurement')->send(
                'POST',
                $url,
                ['Content-Type: application/json', 'Accept: application/json'],
                $body,
                $inCheckout ? self::TIMEOUT_CHECKOUT : self::TIMEOUT_BACKGROUND
            );
        } catch (\Throwable $e) {
            $response = ['status' => 0, 'headers' => [], 'body' => ''];
        }

        $ms = (int) round((microtime(true) - $started) * 1000);
        $status = (int) ($response['status'] ?? 0);
        $event = (string) ($payload['events'][count($payload['events'] ?? []) - 1]['name'] ?? '');
        $context = ['event' => $event, 'fixture' => $response['headers']['x-qi-fixture'] ?? null];

        if ($status < 200 || $status >= 300) {
            $reason = $status === 0 ? 'timeout' : 'http_' . $status;
            Log::http($idShop, 'measurement', Log::ERROR, 'Measurement Protocol call failed.', 'POST', $url, $status, $ms, $context);

            return ['ok' => false, 'reason' => $reason, 'messages' => []];
        }

        $messages = [];

        if ($validateOnly) {
            $data = json_decode((string) ($response['body'] ?? ''), true);
            $messages = is_array($data['validationMessages'] ?? null) ? $data['validationMessages'] : [];
        }

        Log::http($idShop, 'measurement', $messages === [] ? Log::INFO : Log::WARNING, $validateOnly
            ? ($messages === [] ? 'Validation passed.' : 'Validation returned messages.')
            : 'Write call succeeded.', 'POST', $url, $status, $ms, $context + ($messages ? ['messages' => $messages] : []));

        return ['ok' => $messages === [], 'reason' => $messages === [] ? '' : 'invalid', 'messages' => $messages];
    }
}
