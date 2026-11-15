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

if (!defined('_PS_VERSION_')) {
    exit;
}

use Quince\Insight\Core\Dev;

/**
 * Tryb atrap: odpowiedzi Google z plikow w `tests/fixtures/google/<usluga>/` zamiast z sieci.
 *
 * Dopasowanie w tej kolejnosci:
 *  1. wpis w `<usluga>/_routes.php` — tablica `[METODA, wyrazenie regularne adresu, plik albo funkcja]`,
 *     pierwszy pasujacy wygrywa;
 *  2. plik o nazwie z konwencji `<METODA>_<slug-sciezki>.json` (np. `GET_v1beta_accountSummaries.json`);
 *  3. brak atrapy: wywolanie zapisujace dostaje `200 {}` (potwierdzenie), odczyt dostaje
 *     `404 NOT_FOUND` z nazwa brakujacego pliku w komunikacie — ekran pokazuje stan pusty
 *     albo blad, a w dzienniku widac, jaka atrape dopisac.
 *
 * Plik atrapy to tresc odpowiedzi 200. Inny kod albo naglowki: obiekt z kluczami
 * `__status`, `__headers`, `__body`.
 */
final class FakeTransport implements HttpTransport
{
    /** @var array<string,array<int,array<int,mixed>>> */
    private static array $routes = [];

    private string $dir;

    public function __construct(private string $service = 'core', ?string $fixturesDir = null)
    {
        $this->dir = rtrim($fixturesDir ?? Dev::fixturesDir(), '/\\') . '/';
    }

    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): array
    {
        $method = strtoupper($method);
        $request = [
            'method' => $method,
            'url' => $url,
            'path' => (string) parse_url($url, PHP_URL_PATH),
            'query' => $this->query($url),
            'body' => $body ?? '',
            'json' => $this->json($body),
        ];

        foreach ($this->routes() as $route) {
            [$routeMethod, $pattern, $target] = array_pad($route, 3, null);

            if (strtoupper((string) $routeMethod) !== $method || @preg_match((string) $pattern, $url) !== 1) {
                continue;
            }

            if (is_callable($target)) {
                $result = $target($request);

                // Funkcja moze oddac nazwe pliku, gotowa tresc (tablice) albo null = "nie moja, szukaj dalej".
                if ($result === null) {
                    continue;
                }

                return is_string($result) ? $this->fromFile($result, $request) : $this->fromData((array) $result);
            }

            return $this->fromFile((string) $target, $request);
        }

        $conventional = $method . '_' . self::slug($request['path']) . '.json';

        if (is_file($this->dir . $this->service . '/' . $conventional)) {
            return $this->fromFile($conventional, $request);
        }

        if ($method !== 'GET') {
            return ['status' => 200, 'headers' => ['x-qi-fixture' => 'none'], 'body' => '{}'];
        }

        return $this->missing($conventional, $request);
    }

    /** Nazwa pliku z konwencji dla sciezki adresu: znaki spoza [A-Za-z0-9] zamieniane na "_". */
    public static function slug(string $path): string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $path), '_');
    }

    /** @return array<int,array<int,mixed>> */
    private function routes(): array
    {
        $key = $this->dir . $this->service;

        if (!isset(self::$routes[$key])) {
            $file = $key . '/_routes.php';
            $routes = is_file($file) ? require $file : [];

            self::$routes[$key] = is_array($routes) ? $routes : [];
        }

        return self::$routes[$key];
    }

    /**
     * @param array<string,mixed> $request
     *
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    private function fromFile(string $file, array $request): array
    {
        $path = $this->dir . $this->service . '/' . basename($file);

        if (!is_file($path)) {
            return $this->missing(basename($file), $request);
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (!is_array($data)) {
            return [
                'status' => 500,
                'headers' => ['x-qi-fixture' => basename($file)],
                'body' => (string) json_encode(['error' => [
                    'code' => 500,
                    'message' => 'Fixture ' . $this->service . '/' . basename($file) . ' is not valid JSON.',
                    'status' => 'INTERNAL',
                ]]),
            ];
        }

        $response = $this->fromData($data);
        $response['headers']['x-qi-fixture'] = basename($file);

        return $response;
    }

    /**
     * @param array<mixed> $data
     *
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    private function fromData(array $data): array
    {
        $status = 200;
        $headers = [];

        if (isset($data['__status']) || isset($data['__body']) || isset($data['__headers'])) {
            $status = (int) ($data['__status'] ?? 200);

            foreach ((array) ($data['__headers'] ?? []) as $name => $value) {
                $headers[strtolower((string) $name)] = (string) $value;
            }

            $data = (array) ($data['__body'] ?? []);
        }

        return [
            'status' => $status,
            'headers' => $headers,
            // 204 nie ma tresci; pusta tablica to `{}`, nie `[]` — tak odpowiada Google.
            'body' => $status === 204 ? '' : ($data === [] ? '{}' : (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        ];
    }

    /**
     * @param array<string,mixed> $request
     *
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    private function missing(string $file, array $request): array
    {
        return [
            'status' => 404,
            'headers' => ['x-qi-fixture' => 'missing'],
            'body' => (string) json_encode(['error' => [
                'code' => 404,
                'message' => 'Fake Google mode: no fixture for ' . $request['method'] . ' ' . $request['path']
                    . ' (add tests/fixtures/google/' . $this->service . '/' . $file . ' or a route in _routes.php).',
                'status' => 'NOT_FOUND',
            ]], JSON_UNESCAPED_SLASHES),
        ];
    }

    /** @return array<string,string> */
    private function query(string $url): array
    {
        $out = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $out);

        return array_map('strval', array_filter($out, 'is_scalar'));
    }

    /** @return array<mixed> */
    private function json(?string $body): array
    {
        if ($body === null || $body === '') {
            return [];
        }

        $decoded = json_decode($body, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        // Tresc formularza (wymiana kodu OAuth) tez sie przydaje funkcjom z _routes.php.
        $form = [];
        parse_str($body, $form);

        return $form;
    }
}
