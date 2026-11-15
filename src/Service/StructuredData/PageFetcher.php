<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\StructuredData;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Pobranie strony wlasnego sklepu po HTTP (skan danych strukturalnych) — tak, jak widzi ja
 * odwiedzajacy bez ciasteczek.
 *
 * Gdy serwer nie moze polaczyc sie z wlasnym adresem publicznym (czeste za NAT-em,
 * w kontenerach, przy domenie wskazujacej na proxy), druga proba idzie na 127.0.0.1
 * z tym samym adresem i nazwa hosta (`CURLOPT_CONNECT_TO` — SNI i naglowek Host bez zmian,
 * certyfikat sprawdzany jak zwykle).
 */
final class PageFetcher
{
    private const TIMEOUT = 25;
    private const MAX_BYTES = 3145728;

    /** cURL: nie rozwiazano nazwy, brak polaczenia, przekroczony czas polaczenia. */
    private const CONNECT_ERRORS = [6, 7, 28];

    /**
     * @return array{status:int,body:string,url:string,error:string}
     */
    public function fetch(string $url): array
    {
        $result = $this->request($url, null);

        if ($result['status'] === 0 && in_array($result['errno'], self::CONNECT_ERRORS, true)) {
            $parts = parse_url($url);
            $host = (string) ($parts['host'] ?? '');

            if ($host !== '') {
                $https = strtolower((string) ($parts['scheme'] ?? 'http')) === 'https';
                $port = (int) ($parts['port'] ?? ($https ? 443 : 80));
                $result = $this->request($url, $host . ':' . $port . ':127.0.0.1:' . ($https ? 443 : 80));
            }
        }

        unset($result['errno']);

        return $result;
    }

    /**
     * @return array{status:int,body:string,url:string,error:string,errno:int}
     */
    private function request(string $url, ?string $connectTo): array
    {
        $handle = curl_init($url);

        if ($handle === false) {
            return ['status' => 0, 'body' => '', 'url' => $url, 'error' => 'cURL is not available.', 'errno' => 0];
        }

        $body = '';
        $options = [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; QuinceInsight structured data check)',
            CURLOPT_HTTPHEADER => ['Accept: text/html'],
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body): int {
                $body .= $chunk;

                // Za duza strona: przerywamy (zwrot innej dlugosci konczy transfer).
                return strlen($body) > self::MAX_BYTES ? 0 : strlen($chunk);
            },
        ];

        if ($connectTo !== null) {
            $options[CURLOPT_CONNECT_TO] = [$connectTo];
        }

        curl_setopt_array($handle, $options);
        curl_exec($handle);

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $final = (string) curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);
        $errno = curl_errno($handle);
        $error = $errno !== 0 && strlen($body) <= self::MAX_BYTES ? curl_error($handle) : '';
        unset($handle);

        return ['status' => $error === '' ? $status : 0, 'body' => $body, 'url' => $final ?: $url, 'error' => $error, 'errno' => $errno];
    }
}
