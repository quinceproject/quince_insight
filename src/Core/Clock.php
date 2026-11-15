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

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Jedno zrodlo czasu dla calego modulu.
 *
 * Wszystkie daty w tabelach modulu pochodza z PHP, nigdy z `NOW()` bazy: serwer bazy
 * bywa w innej strefie niz PHP, a wtedy blokada "do 12:00:30" zapisana jedna strona
 * i porownana druga wygasa od razu albo nigdy.
 */
final class Clock
{
    private static ?int $frozen = null;

    public static function time(): int
    {
        return self::$frozen ?? time();
    }

    /** Biezacy czas w formacie kolumny DATETIME. */
    public static function now(): string
    {
        return date('Y-m-d H:i:s', self::time());
    }

    /** Czas przesuniety o podana liczbe sekund (ujemna = wstecz). */
    public static function at(int $offsetSeconds): string
    {
        return date('Y-m-d H:i:s', self::time() + $offsetSeconds);
    }

    /** Ile sekund minelo od podanej daty; null gdy daty brak. */
    public static function secondsSince(?string $datetime): ?int
    {
        if ($datetime === null || $datetime === '' || $datetime === '0000-00-00 00:00:00') {
            return null;
        }

        $ts = strtotime($datetime);

        return $ts === false ? null : self::time() - $ts;
    }

    /** Tylko testy: zatrzymuje zegar na podanej chwili (null = z powrotem czas rzeczywisty). */
    public static function freeze(?int $timestamp): void
    {
        self::$frozen = $timestamp;
    }
}
