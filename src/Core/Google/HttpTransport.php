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
 * Jedno wywolanie HTTP. Trzy implementacje: `CurlTransport` (siec), `FakeTransport`
 * (atrapy z `tests/fixtures/google/`) i podmiany w testach jednostkowych.
 */
interface HttpTransport
{
    /**
     * @param string[] $headers gotowe linie naglowkow ("Nazwa: wartosc")
     *
     * @return array{status:int,headers:array<string,string>,body:string} naglowki z nazwami malymi literami;
     *                                                                     status 0 = brak odpowiedzi (siec, limit czasu)
     */
    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): array;
}
