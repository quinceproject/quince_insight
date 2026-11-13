<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Core;

use Db;

/**
 * Dziennik modulu (tabela `api_log`): bledy, wywolania zapisujace do Google, przebiegi crona.
 *
 * Zapis nigdy nie rzuca wyjatku — dziennik nie moze zepsuc sklepu ani akcji, ktora opisuje.
 * Do dziennika nie trafiaja tokeny, sekrety ani dane osobowe: `context` to krotkie
 * fakty techniczne (identyfikatory, liczby, powod bledu), a adres jest zapisywany bez
 * parametru `key`.
 */
final class Log
{
    public const INFO = 'info';
    public const WARNING = 'warning';
    public const ERROR = 'error';

    /** @var array<int,array<string,mixed>>|null testy: wpisy w pamieci zamiast w bazie */
    private static ?array $memory = null;

    /**
     * @param array<string,mixed> $context
     */
    public static function write(int $idShop, string $service, string $level, string $message, array $context = []): void
    {
        self::insert($idShop, $service, $level, $message, '', '', 0, 0, $context);
    }

    /**
     * Wpis o wywolaniu HTTP (uzywa `GoogleClient`).
     *
     * @param array<string,mixed> $context
     */
    public static function http(
        int $idShop,
        string $service,
        string $level,
        string $message,
        string $method,
        string $url,
        int $httpCode,
        int $durationMs,
        array $context = [],
    ): void {
        self::insert($idShop, $service, $level, $message, $method, self::safeUrl($url), $httpCode, $durationMs, $context);
    }

    /** Adres bez sekretow: parametry `key`, `access_token`, `api_secret` i `token` sa zamazywane. */
    public static function safeUrl(string $url): string
    {
        return (string) preg_replace('/([?&](?:key|access_token|api_secret|token|client_secret|code)=)[^&#]*/i', '$1***', $url);
    }

    /**
     * Tylko testy: wpisy do pamieci. Zwraca dotychczasowe wpisy.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function useMemory(): array
    {
        $before = self::$memory ?? [];
        self::$memory = [];

        return $before;
    }

    /** @return array<int,array<string,mixed>> */
    public static function memory(): array
    {
        return self::$memory ?? [];
    }

    /**
     * @param array<string,mixed> $context
     */
    private static function insert(
        int $idShop,
        string $service,
        string $level,
        string $message,
        string $method,
        string $url,
        int $httpCode,
        int $durationMs,
        array $context,
    ): void {
        if (!in_array($level, [self::INFO, self::WARNING, self::ERROR], true)) {
            $level = self::INFO;
        }

        $row = [
            'id_shop' => $idShop,
            'service' => substr($service, 0, 30),
            'level' => $level,
            'message' => mb_substr($message, 0, 500),
            'http_method' => substr($method, 0, 8),
            'url' => mb_substr($url, 0, 500),
            'http_code' => max(0, min(65535, $httpCode)),
            'duration_ms' => max(0, $durationMs),
            'context' => $context ? (string) json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) : '',
        ];

        if (self::$memory !== null) {
            self::$memory[] = $row;

            return;
        }

        try {
            Db::getInstance()->insert(Schema::name(Schema::API_LOG), [
                'id_shop' => $row['id_shop'],
                'service' => pSQL($row['service']),
                'level' => pSQL($row['level']),
                'message' => pSQL($row['message'], true),
                'http_method' => pSQL($row['http_method']),
                'url' => pSQL($row['url'], true),
                'http_code' => $row['http_code'],
                'duration_ms' => $row['duration_ms'],
                'context' => pSQL($row['context'], true),
                'date_add' => pSQL(Clock::now()),
            ]);
        } catch (\Throwable $e) {
            // Celowo nic: brak tabeli albo blad bazy nie moze wywrocic strony sklepu.
        }
    }
}
