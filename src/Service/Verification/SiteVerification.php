<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\Verification;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Quince\Insight\Core\Google\GoogleClient;

/**
 * Site Verification API v1 (research/04 §5, research/05 §H.5) — same wywolania, bez stanu.
 *
 * Klient przychodzi z zewnatrz: w module `GoogleClient::forShop($idShop, 'verification')`,
 * w testach klient z podmienionym transportem.
 */
final class SiteVerification
{
    public const BASE = 'https://www.googleapis.com/siteVerification/v1/';

    /**
     * Zasoby zweryfikowane dla zalogowanego konta (wymaga pelnego scope'u `siteverification`).
     *
     * @return array<int,array<string,mixed>>
     */
    public function resources(GoogleClient $client): array
    {
        $response = $client->get(self::BASE . 'webResource');

        return array_values(array_filter((array) ($response['items'] ?? []), 'is_array'));
    }

    /** Tresc atrybutu `content` tagu meta dla adresu; '' gdy Google nie oddal tokenu. */
    public function metaToken(GoogleClient $client, string $siteUrl): string
    {
        $response = $client->post(self::BASE . 'token', [
            'site' => ['type' => 'SITE', 'identifier' => $siteUrl],
            'verificationMethod' => 'META',
        ]);

        return VerificationState::parseToken((string) ($response['token'] ?? ''));
    }

    /**
     * Prosi Google o sprawdzenie tagu i dopisanie konta jako wlasciciela.
     *
     * @return array<string,mixed> zasob: id, site, owners
     */
    public function insert(GoogleClient $client, string $siteUrl): array
    {
        return $client->post(self::BASE . 'webResource', [
            'site' => ['type' => 'SITE', 'identifier' => $siteUrl],
        ], ['verificationMethod' => 'META']);
    }

    /**
     * Usuwa wlasnosc zalogowanego konta. Tag musi juz zniknac ze strony — inaczej Google
     * odpowiada 400 (research/04 §5).
     */
    public function delete(GoogleClient $client, string $siteUrl): void
    {
        $client->delete(self::BASE . 'webResource/' . rawurlencode($siteUrl));
    }
}
