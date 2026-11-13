<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\Events;

/**
 * Odczyt identyfikatorow Google Analytics z ciasteczek przegladarki. Czysta logika.
 *
 * Zasada z SPEC §5.3: modul NIGDY nie wymysla identyfikatora. Brak albo zly format
 * ciasteczka = pusta wartosc, a zakup z serwera dostaje `skipped` z powodem.
 *
 * - `client_id` = pelna wartosc ciasteczka `_ga` (research/05 §D: „A client ID cookie.
 *   Send the full value of the cookie.”), np. `GA1.1.1234567890.1700000000`.
 * - `session_id` = liczba z ciasteczka `_ga_<ID strumienia>`. Parser GS1/GS2 przeniesiony
 *   z TagPilota (research/02 §3.5 „keep this regex”). Wysylamy liczbe, bo aktualna strona
 *   referencyjna wymaga `^\d+$`; pelna wartosc ciasteczka jest opisana jako dopuszczalna,
 *   ale tej drogi nie sprawdzilismy (raport C, sekcja 5).
 */
final class GaCookies
{
    /**
     * Pelna wartosc `_ga`, gdy ma poprawny ksztalt `GA1.<n>.<liczba>.<liczba>`; inaczej ''.
     */
    public static function clientId(?string $gaCookie): string
    {
        $value = trim((string) $gaCookie);

        if ($value === '' || strlen($value) > 64) {
            return '';
        }

        return preg_match('/^GA\d+\.\d+\.\d+\.\d+$/', $value) === 1 ? $value : '';
    }

    /** Nazwa ciasteczka sesji dla identyfikatora pomiaru: G-ABC123 -> _ga_ABC123. */
    public static function sessionCookieName(string $measurementId): string
    {
        $suffix = str_starts_with($measurementId, 'G-') ? substr($measurementId, 2) : $measurementId;

        return '_ga_' . $suffix;
    }

    /**
     * Identyfikator sesji z ciasteczka `_ga_<ID>`: GS1.1.<sid>.… albo GS2.1.s<sid>$o…; '' gdy brak.
     */
    public static function sessionId(?string $sessionCookie): string
    {
        $value = trim((string) $sessionCookie);

        if ($value === '' || !preg_match('/^GS[12]\.\d+\.(?:s(\d+)|(\d+))/', $value, $m)) {
            return '';
        }

        $id = ($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? '');

        return ctype_digit($id) && strlen($id) <= 20 ? $id : '';
    }

    /**
     * Para identyfikatorow z tablicy ciasteczek ($_COOKIE).
     *
     * @param array<string,mixed> $cookies
     *
     * @return array{client_id:string, session_id:string}
     */
    public static function fromCookies(array $cookies, string $measurementId): array
    {
        $client = self::clientId(is_string($cookies['_ga'] ?? null) ? $cookies['_ga'] : null);
        $session = '';

        if ($client !== '' && $measurementId !== '') {
            $name = self::sessionCookieName($measurementId);
            // PHP zamienia kropki i spacje w nazwach ciasteczek na podkreslenia; ID strumienia ich nie ma.
            $session = self::sessionId(is_string($cookies[$name] ?? null) ? $cookies[$name] : null);
        }

        return ['client_id' => $client, 'session_id' => $session];
    }
}
