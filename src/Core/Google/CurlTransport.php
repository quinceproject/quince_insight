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

/**
 * Wywolania HTTP przez cURL — jedyne miejsce w module, ktore laczy sie z siecia.
 *
 * Bez bibliotek: PrestaShop 9 nie ma Guzzle, a `google/apiclient` ciagnie zaleznosci
 * kolidujace z rdzeniem. `ext-curl` jest wymagane przez sam PrestaShop.
 */
final class CurlTransport implements HttpTransport
{
    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): array
    {
        $responseHeaders = [];
        $handle = curl_init();

        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeoutSeconds),
            // Adresy sa stale (hosty Google), przekierowan nie oczekujemy — nie podazamy za nimi.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);

                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($response === false) {
            // Brak odpowiedzi: status 0, a przyczyna w tresci w ksztalcie bledu Google,
            // zeby dalsza obsluga byla jedna.
            return [
                'status' => 0,
                'headers' => [],
                'body' => (string) json_encode(['error' => ['message' => 'Connection failed: ' . $error, 'status' => 'UNAVAILABLE']]),
            ];
        }

        return ['status' => $status, 'headers' => $responseHeaders, 'body' => (string) $response];
    }
}
