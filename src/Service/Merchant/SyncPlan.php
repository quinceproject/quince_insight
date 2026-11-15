<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\Merchant;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Decyzje kolejki i synchronizacji — czysta logika (testy bez bazy i bez Google).
 *
 * Napisane od nowa wzgledem FeedForge (research/02 §1.4 "Sync queue"): priorytet 1 =
 * najwyzszy i tak jest sortowany, ponawianie do limitu prob z rosnacym odstepem, decyzja
 * per feed i per kombinacja, usuniecie tylko z feedu, w ktorym oferta przestala wystepowac.
 */
final class SyncPlan
{
    public const ACTION_INSERT = 'insert';
    public const ACTION_PATCH = 'patch';
    public const ACTION_DELETE = 'delete';
    public const ACTION_SKIP = 'skip';       // bez zmian w Google
    public const ACTION_FORGET = 'forget';   // nigdy nie wyslana — tylko lokalny wiersz

    /** Ile razy ponawiac pozycje kolejki, zanim zostanie oznaczona jako blad. */
    public const MAX_ATTEMPTS = 5;

    /** Priorytet pozycji wg powodu zmiany (1 = najwyzszy). */
    public static function priority(string $reason): int
    {
        return match ($reason) {
            'delete' => 1,
            'stock' => 2,
            'price' => 3,
            'full' => 6,
            default => 5,
        };
    }

    /**
     * Odstep przed kolejna proba po bledzie (sekundy): 5 min, 15 min, 1 h, 3 h.
     * `null` = limit prob wyczerpany.
     */
    public static function retryDelay(int $attempts): ?int
    {
        if ($attempts >= self::MAX_ATTEMPTS) {
            return null;
        }

        return [1 => 300, 2 => 900, 3 => 3600, 4 => 10800][$attempts] ?? 300;
    }

    /**
     * Co zrobic z oferta w jednym feedzie.
     *
     * @param array{sync_state?:string,payload_hash?:string,core_hash?:string}|null $previous wiersz `feed_product` albo null
     * @param bool $wanted oferta jest w zbiorze feedu (filtry), nie wykluczona regula i — przy
     *                     "tylko gotowe" — bez bledu lokalnego
     */
    public static function decide(?array $previous, bool $wanted, string $hash = '', string $coreHash = ''): string
    {
        $state = (string) ($previous['sync_state'] ?? '');
        $inGoogle = in_array($state, ['synced', 'error'], true);

        if (!$wanted) {
            // Usuwamy tylko to, co tym feedem trafilo do Google — inne feedy maja wlasne wiersze.
            return $inGoogle ? self::ACTION_DELETE : self::ACTION_FORGET;
        }

        if ($state === 'synced' && (string) ($previous['payload_hash'] ?? '') === $hash) {
            return self::ACTION_SKIP;
        }

        if ($state === 'synced' && $coreHash !== '' && (string) ($previous['core_hash'] ?? '') === $coreHash) {
            return self::ACTION_PATCH;
        }

        return self::ACTION_INSERT;
    }

    /**
     * Czy feed w tym stanie moze cokolwiek wyslac do Google. Wersja robocza i wstrzymany —
     * nie (wymaganie usera: wersja robocza nie wysyla nic).
     */
    public static function feedSends(string $status): bool
    {
        return $status === 'active';
    }
}
