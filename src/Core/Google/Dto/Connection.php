<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Core\Google\Dto;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Polaczenie z Google: projekt Google Cloud + jedno zalogowane konto.
 *
 * Obiekt jest bezpieczny do przekazania do szablonu: **nie zawiera sekretow** —
 * tylko informacje, czy sa zapisane. Sekrety czyta `ConnectionRepository`.
 */
final class Connection
{
    public const STATUS_NEW = 'new';
    public const STATUS_OK = 'ok';
    public const STATUS_NEEDS_REAUTH = 'needs_reauth';
    public const STATUS_REVOKED = 'revoked';

    /**
     * @param string[] $scopes przyznane scope'y
     */
    public function __construct(
        public int $id,
        public string $label,
        public string $clientId,
        public bool $hasSecret,
        public bool $hasApiKey,
        public bool $productionConfirmed,
        public string $googleEmail,
        public array $scopes,
        public string $status,
        public string $lastError,
        public string $redirectUri,
        public ?string $connectedAt,
    ) {
    }

    /** Konto zalogowane i token wazny (nie wymaga ponownego logowania). */
    public function isConnected(): bool
    {
        return $this->status === self::STATUS_OK;
    }

    /** Czy sa wpisane dane projektu Google Cloud (mozna zaczac logowanie). */
    public function hasCredentials(): bool
    {
        return $this->clientId !== '' && $this->hasSecret;
    }
}
