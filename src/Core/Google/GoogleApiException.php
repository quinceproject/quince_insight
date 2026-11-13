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
 * Blad odpowiedzi API Google (albo brak odpowiedzi — wtedy `httpCode() === 0`).
 *
 * Rozrozniac po `reason()` i `status()`, nie po samym kodzie HTTP: Tag Manager zglasza
 * limit jako 403, a Merchant API brak rejestracji projektu jako 401.
 */
class GoogleApiException extends \RuntimeException
{
    /** Powody oznaczajace limit wywolan (rozne interfejsy Google nazywaja to roznie). */
    private const QUOTA_REASONS = [
        'rateLimitExceeded',
        'userRateLimitExceeded',
        'quotaExceeded',
        'dailyLimitExceeded',
        'RATE_LIMIT_EXCEEDED',
        'RESOURCE_EXHAUSTED',
        'quota/request_rate_too_high',
        'quota/daily_limit_exceeded',
    ];

    /** Powody oznaczajace limit dobowy — ponawianie przed resetem nie ma sensu. */
    private const DAILY_REASONS = ['dailyLimitExceeded', 'quota/daily_limit_exceeded'];

    /**
     * @param array<mixed> $payload zdekodowana tresc bledu (bez danych wysylanych przez modul)
     */
    public function __construct(
        string $message,
        private readonly int $httpCode = 0,
        private readonly string $status = '',
        private readonly string $reason = '',
        private readonly ?int $retryAfter = null,
        private readonly array $payload = [],
    ) {
        parent::__construct($message, $httpCode);
    }

    /**
     * Buduje wyjatek z odpowiedzi HTTP. Obsluguje oba ksztalty bledow Google:
     * `{"error":{"code","message","status","errors":[{"reason"}],"details":[{"reason"}]}}`
     * oraz OAuth `{"error":"invalid_grant","error_description":"..."}`.
     *
     * @param array<string,string> $headers nazwy naglowkow malymi literami
     */
    public static function fromResponse(int $httpCode, string $body, array $headers = []): static
    {
        $data = json_decode($body, true);
        $data = is_array($data) ? $data : [];

        $message = '';
        $status = '';
        $reason = '';
        $error = $data['error'] ?? null;

        if (is_array($error)) {
            $message = (string) ($error['message'] ?? '');
            $status = (string) ($error['status'] ?? '');

            // Nowe interfejsy (Merchant, Analytics) podaja powod w `details[].reason`
            // typu ErrorInfo; starsze (Tag Manager, Search Console) w `errors[].reason`.
            foreach ((array) ($error['details'] ?? []) as $detail) {
                if (is_array($detail) && !empty($detail['reason'])) {
                    $reason = (string) $detail['reason'];

                    break;
                }
            }

            if ($reason === '') {
                foreach ((array) ($error['errors'] ?? []) as $item) {
                    if (is_array($item) && !empty($item['reason'])) {
                        $reason = (string) $item['reason'];

                        break;
                    }
                }
            }
        } elseif (is_string($error)) {
            $reason = $error;
            $message = (string) ($data['error_description'] ?? $error);
        }

        if ($message === '') {
            $message = $httpCode > 0 ? 'Google returned HTTP ' . $httpCode . '.' : 'No response from Google.';
        }

        $retryAfter = null;

        if (isset($headers['retry-after']) && ctype_digit(trim($headers['retry-after']))) {
            $retryAfter = (int) trim($headers['retry-after']);
        }

        return new static($message, $httpCode, $status, $reason, $retryAfter, $data);
    }

    /** Ten sam blad z podanym czasem oczekiwania — gdy klient nie mogl juz czekac w zadaniu. */
    public function withRetryAfter(int $seconds): static
    {
        return new static($this->getMessage(), $this->httpCode, $this->status, $this->reason, $seconds, $this->payload);
    }

    public function httpCode(): int
    {
        return $this->httpCode;
    }

    /** Np. PERMISSION_DENIED, RESOURCE_EXHAUSTED, NOT_FOUND. */
    public function status(): string
    {
        return $this->status;
    }

    /** Np. GCP_NOT_REGISTERED, rateLimitExceeded, accessNotConfigured, invalid_grant. */
    public function reason(): string
    {
        return $this->reason;
    }

    /** Limit wywolan: 429 albo powod limitu (Tag Manager: 403). */
    public function isQuota(): bool
    {
        return $this->httpCode === 429
            || $this->status === 'RESOURCE_EXHAUSTED'
            || in_array($this->reason, self::QUOTA_REASONS, true);
    }

    /** Limit dobowy — nie ponawiac do resetu. */
    public function isDailyQuota(): bool
    {
        return in_array($this->reason, self::DAILY_REASONS, true);
    }

    /**
     * Problem z logowaniem albo uprawnieniami: token niewazny, brak scope'u, brak dostepu do konta.
     * Limit zglaszany jako 403 i brak rejestracji projektu (401 GCP_NOT_REGISTERED) to nie sa
     * bledy logowania.
     */
    public function isAuth(): bool
    {
        if ($this->reason === 'GCP_NOT_REGISTERED' || $this->isQuota()) {
            return false;
        }

        return $this->httpCode === 401
            || $this->httpCode === 403
            || $this->status === 'UNAUTHENTICATED'
            || $this->status === 'PERMISSION_DENIED'
            || $this->reason === 'invalid_grant';
    }

    /** API niewlaczone w projekcie Google Cloud (403 accessNotConfigured / SERVICE_DISABLED). */
    public function isApiDisabled(): bool
    {
        return in_array($this->reason, ['accessNotConfigured', 'SERVICE_DISABLED'], true);
    }

    public function retryAfterSeconds(): ?int
    {
        return $this->retryAfter;
    }

    /** @return array<mixed> */
    public function payload(): array
    {
        return $this->payload;
    }
}
