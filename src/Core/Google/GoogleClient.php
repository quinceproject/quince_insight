<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Core\Google;

use Quince\Insight\Core\Container;
use Quince\Insight\Core\Log;

/**
 * Klient API Google: token, ponawianie, dziennik, tryb atrap.
 *
 * Tworzyc przez `GoogleClient::forShop($idShop, 'analytics')` albo `withApiKey()`.
 * `$service` to klucz uslugi modulu — pod nim ida wpisy do dziennika i w tym katalogu
 * `tests/fixtures/google/` tryb atrap szuka odpowiedzi.
 *
 * Zachowanie:
 *  - token odswiezany 60 s przed wygasnieciem i jeden raz po 401 (nie dla 401 `GCP_NOT_REGISTERED`
 *    — to brak rejestracji projektu w Merchant API, nie blad tokenu);
 *  - 429 i 5xx ponawiane z rosnacym odstepem i losowym rozrzutem, najwyzej 4 proby;
 *    profil `gtm` traktuje jak limit takze 403 z powodem limitu; limit dobowy nie jest ponawiany;
 *  - laczne czekanie w jednym wywolaniu najwyzej 5 s — dluzsze oddaje wyjatkiem z
 *    `retryAfterSeconds()` i zostawia silnikowi zadan (`JobRunner`);
 *  - kazdy blad i kazde wywolanie zapisujace trafia do `api_log` (bez tokenow i bez tresci).
 */
final class GoogleClient
{
    public const PROFILE_DEFAULT = 'default';
    public const PROFILE_GTM = 'gtm';
    public const PROFILE_MERCHANT = 'merchant';

    private const MAX_ATTEMPTS = 4;
    private const TIMEOUT = 20;
    private const TIMEOUT_PAGESPEED = 60;

    /** Powody 403, ktore w Tag Manager API oznaczaja limit (research/05 §E.5). */
    private const GTM_QUOTA_REASONS = ['userRateLimitExceeded', 'quotaExceeded', 'rateLimitExceeded'];

    private ?HttpTransport $transport = null;
    private ?TokenSource $tokens = null;
    private string $apiKey = '';
    private string $service = 'core';
    private int $idShop = 0;
    private string $profile = self::PROFILE_DEFAULT;
    private float $sleepBudget = 5.0;

    /** @var (callable(float):void)|null */
    private $sleeper;

    /**
     * Sam konstruktor daje klienta bez tokenu — uzywac metod `forShop()` / `withApiKey()`.
     * Jest publiczny i bez argumentow, bo kontener Symfony rejestruje cale `src/`.
     */
    public function __construct()
    {
    }

    /**
     * Klient z tokenem polaczenia danego sklepu. Rzuca NotConnectedException.
     */
    public static function forShop(int $idShop, string $service): self
    {
        $connections = Container::get(ConnectionRepository::class);
        $connection = $connections->forShop($idShop);

        if ($connection === null || !$connection->isConnected()) {
            throw new NotConnectedException('This shop is not connected to a Google account.');
        }

        $flow = Container::get(OAuthFlow::class);
        $idConnection = $connection->id;

        $client = new self();
        $client->idShop = $idShop;
        $client->service = $service;
        $client->transport = Http::transport($service);
        $client->tokens = new class($flow, $idConnection) implements TokenSource {
            public function __construct(private OAuthFlow $flow, private int $idConnection)
            {
            }

            public function accessToken(): string
            {
                return $this->flow->accessToken($this->idConnection);
            }

            public function refresh(): string
            {
                return $this->flow->refresh($this->idConnection);
            }
        };

        return $client;
    }

    /**
     * Klient z kluczem API (PageSpeed, CrUX). Rzuca NotConnectedException, gdy klucza nie ma.
     */
    public static function withApiKey(int $idShop, string $service): self
    {
        $connections = Container::get(ConnectionRepository::class);
        $connection = $connections->forShop($idShop);
        $key = $connection ? $connections->apiKey($connection->id) : '';

        if ($key === '') {
            throw new NotConnectedException('The Google API key is missing.');
        }

        $client = new self();
        $client->idShop = $idShop;
        $client->service = $service;
        $client->transport = Http::transport($service);
        $client->apiKey = $key;

        return $client;
    }

    /**
     * Klient zlozony z podanych czesci — testy jednostkowe (podmieniony transport i token).
     */
    public static function custom(HttpTransport $transport, ?TokenSource $tokens = null, string $service = 'core', int $idShop = 0, string $apiKey = ''): self
    {
        $client = new self();
        $client->transport = $transport;
        $client->tokens = $tokens;
        $client->service = $service;
        $client->idShop = $idShop;
        $client->apiKey = $apiKey;

        return $client;
    }

    /**
     * @param array<string,mixed> $query
     *
     * @return array<mixed> zdekodowany JSON ([] przy 204)
     */
    public function get(string $url, array $query = []): array
    {
        return $this->request('GET', $url, $query, null);
    }

    /**
     * @param array<mixed> $body
     * @param array<string,mixed> $query
     *
     * @return array<mixed>
     */
    public function post(string $url, array $body = [], array $query = []): array
    {
        return $this->request('POST', $url, $query, $body);
    }

    /**
     * @param array<mixed> $body
     * @param array<string,mixed> $query
     *
     * @return array<mixed>
     */
    public function patch(string $url, array $body = [], array $query = []): array
    {
        return $this->request('PATCH', $url, $query, $body);
    }

    /**
     * @param array<mixed> $body
     * @param array<string,mixed> $query
     *
     * @return array<mixed>
     */
    public function put(string $url, array $body = [], array $query = []): array
    {
        return $this->request('PUT', $url, $query, $body);
    }

    /**
     * @param array<string,mixed> $query
     *
     * @return array<mixed>
     */
    public function delete(string $url, array $query = []): array
    {
        return $this->request('DELETE', $url, $query, null);
    }

    /**
     * Stronicowanie po nextPageToken; zwraca polaczona liste spod klucza $itemsKey.
     *
     * @param array<string,mixed> $query
     *
     * @return array<int,mixed>
     */
    public function paginate(string $url, string $itemsKey, array $query = [], int $maxPages = 50): array
    {
        $items = [];
        $page = 0;

        do {
            $data = $this->get($url, $query);

            foreach ((array) ($data[$itemsKey] ?? []) as $item) {
                $items[] = $item;
            }

            $next = (string) ($data['nextPageToken'] ?? '');
            $query['pageToken'] = $next;
            ++$page;
        } while ($next !== '' && $page < $maxPages);

        return $items;
    }

    /** Profil ponawiania: 'default' | 'gtm' | 'merchant'. */
    public function withRetryProfile(string $profile): self
    {
        $clone = clone $this;
        $clone->profile = in_array($profile, [self::PROFILE_GTM, self::PROFILE_MERCHANT], true) ? $profile : self::PROFILE_DEFAULT;

        return $clone;
    }

    /**
     * Ile sekund lacznie klient moze przeczekac w jednym wywolaniu (domyslnie 5).
     * W cronie mozna dac wiecej; w zadaniu panelu nie podnosic.
     */
    public function withSleepBudget(float $seconds): self
    {
        $clone = clone $this;
        $clone->sleepBudget = max(0.0, $seconds);

        return $clone;
    }

    /**
     * Tylko testy: funkcja wolana zamiast usypiania (dostaje liczbe sekund).
     *
     * @param callable(float):void $sleeper
     */
    public function withSleeper(callable $sleeper): self
    {
        $clone = clone $this;
        $clone->sleeper = $sleeper;

        return $clone;
    }

    /**
     * Zapytania POST, ktore tylko czytaja (raporty GA4, Search Analytics, raporty Merchant API,
     * CrUX, token weryfikacji). Nie trafiaja do dziennika jako zapis — inaczej codzienny import
     * Search Console i kazde otwarcie raportu zapelnialy `api_log` "zapisami" (prosba agenta G).
     * Bledy tych zapytan dalej sa zapisywane.
     */
    private const READ_ONLY_POST = [
        '#:(batchRunReports|runReport|runRealtimeReport|batchRunPivotReports|runPivotReport)(\?|$)#',
        '#/searchAnalytics/query(\?|$)#',
        '#/reports:search(\?|$)#',
        '#/records:queryRecord(\?|$)#',
        '#/siteVerification/v1/token(\?|$)#',
    ];

    /** Czy wywolanie jest odczytem wyslanym metoda POST (bez wpisu "Write call" w dzienniku). */
    public static function isReadOnlyPost(string $method, string $url): bool
    {
        if ($method !== 'POST') {
            return false;
        }

        foreach (self::READ_ONLY_POST as $pattern) {
            if (preg_match($pattern, $url) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $query
     * @param array<mixed>|null $body
     *
     * @return array<mixed>
     */
    private function request(string $method, string $url, array $query, ?array $body): array
    {
        if ($this->transport === null) {
            throw new NotConnectedException('Google client is not configured.');
        }

        if ($this->apiKey !== '') {
            $query['key'] = $this->apiKey;
        }

        $query = array_filter($query, static fn ($value): bool => $value !== null && $value !== '');

        if ($query) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $payload = null;

        if ($body !== null) {
            // Pusta tresc to obiekt `{}`, nie lista `[]` — tak oczekuje Google.
            $payload = $body === [] ? '{}' : (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $timeout = $this->service === 'pagespeed' ? self::TIMEOUT_PAGESPEED : self::TIMEOUT;
        $attempt = 0;
        $refreshed = false;
        $slept = 0.0;

        while (true) {
            ++$attempt;

            $headers = ['Accept: application/json'];

            if ($payload !== null) {
                $headers[] = 'Content-Type: application/json';
            }

            if ($this->tokens !== null) {
                $headers[] = 'Authorization: Bearer ' . $this->tokens->accessToken();
            }

            $started = microtime(true);
            $response = $this->transport->send($method, $url, $headers, $payload, $timeout);
            $ms = (int) round((microtime(true) - $started) * 1000);
            $status = (int) $response['status'];

            if ($status >= 200 && $status < 300) {
                if ($method !== 'GET' && !self::isReadOnlyPost($method, $url)) {
                    Log::http($this->idShop, $this->service, Log::INFO, 'Write call succeeded.', $method, $url, $status, $ms, [
                        'attempt' => $attempt,
                        'fixture' => $response['headers']['x-qi-fixture'] ?? null,
                    ]);
                }

                if ($status === 204 || trim($response['body']) === '') {
                    return [];
                }

                $data = json_decode($response['body'], true);

                return is_array($data) ? $data : [];
            }

            $error = GoogleApiException::fromResponse($status, $response['body'], $response['headers']);

            // 401 po raz pierwszy: token mogl wygasnac miedzy sprawdzeniem a wywolaniem.
            // Brak rejestracji projektu w Merchant API tez przychodzi jako 401, ale
            // odswiezanie tokenu niczego tu nie zmieni.
            if ($status === 401 && $this->tokens !== null && !$refreshed && $error->reason() !== 'GCP_NOT_REGISTERED') {
                $refreshed = true;
                --$attempt;
                $this->tokens->refresh();

                continue;
            }

            if ($this->shouldRetry($error) && $attempt < self::MAX_ATTEMPTS) {
                $wait = (float) ($error->retryAfterSeconds() ?? $this->backoff($attempt));

                if ($slept + $wait <= $this->sleepBudget) {
                    $slept += $wait;
                    $this->sleep($wait);

                    continue;
                }

                // Czekanie nie miesci sie w zadaniu: oddajemy blad z czasem, po ktorym
                // warto sprobowac ponownie. `JobRunner` odlozy krok o tyle sekund.
                $error = $error->withRetryAfter((int) ceil($wait));
            }

            Log::http($this->idShop, $this->service, Log::ERROR, $error->getMessage(), $method, $url, $status, $ms, [
                'attempt' => $attempt,
                'status' => $error->status(),
                'reason' => $error->reason(),
            ]);

            if ($status === 401 && $error->reason() !== 'GCP_NOT_REGISTERED') {
                throw new GoogleAuthException($error->getMessage(), $status, $error->status(), $error->reason());
            }

            throw $error;
        }
    }

    private function shouldRetry(GoogleApiException $error): bool
    {
        // Limit dobowy wraca dopiero po resecie — kolejne proby tylko zuzywaja limit.
        if ($error->isDailyQuota()) {
            return false;
        }

        $code = $error->httpCode();

        if ($code === 429 || $code === 0 || ($code >= 500 && $code <= 599)) {
            return true;
        }

        return $this->profile === self::PROFILE_GTM
            && $code === 403
            && in_array($error->reason(), self::GTM_QUOTA_REASONS, true);
    }

    /** Odstep przed kolejna proba: 1, 2, 4 s plus do 1 s losowo. */
    private function backoff(int $attempt): float
    {
        return (2 ** ($attempt - 1)) + random_int(0, 1000) / 1000;
    }

    private function sleep(float $seconds): void
    {
        if ($this->sleeper !== null) {
            ($this->sleeper)($seconds);

            return;
        }

        usleep((int) round($seconds * 1000000));
    }
}
