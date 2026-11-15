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

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Tresc zadan Measurement Protocol (research/05 §D). Czysta logika.
 *
 * - `client_id` przychodzi zawsze z `order_log` (pelna wartosc `_ga`); pusty = wyjatek —
 *   wolajacy ma wczesniej oznaczyc zamowienie jako `skipped` (modul nie wymysla identyfikatora).
 * - `session_id` tylko, gdy zdarzenie miesci sie w 24 h od zamowienia — pozniej GA4 nie
 *   przypisze go do sesji, a zly `session_id` nic nie daje.
 * - `timestamp_micros` = czas zamowienia (cofniecie do 72 h).
 * - `consent` z 4 sygnalow zapisanych przy zamowieniu.
 */
final class MpPayload
{
    /** Limit Google na cofniecie znacznika czasu. */
    public const MAX_AGE_SECONDS = 72 * 3600;

    /** Do tylu po rozpoczeciu sesji zakup jest jeszcze laczony z sesja i jej zrodlem ruchu. */
    public const SESSION_WINDOW_SECONDS = 24 * 3600;

    /**
     * @param array<string,mixed> $ecommerce wynik `Ecommerce::purchase()`
     * @param array<string,string> $consent 4 sygnaly
     * @param array<string,mixed> $userData wynik `UserData::forMeasurementProtocol()` albo []
     * @param array<string,string> $campaign wynik `Attribution::campaignParams()` albo []
     *
     * @return array<string,mixed>
     */
    public static function purchase(
        string $clientId,
        string $sessionId,
        int $orderTimestamp,
        int $now,
        array $ecommerce,
        array $consent,
        array $userData = [],
        string $userId = '',
        array $campaign = [],
    ): array {
        return self::build('purchase', $clientId, $sessionId, $orderTimestamp, $now, $ecommerce, $consent, $userData, $userId, $campaign);
    }

    /**
     * Zwrot: wysylany w chwili zwrotu, z identyfikatorem klienta z zamowienia (bez sesji —
     * zwrot nie nalezy do zadnej wizyty).
     *
     * @param array<string,mixed> $refund wynik `Ecommerce::refund()`
     * @param array<string,string> $consent
     *
     * @return array<string,mixed>
     */
    public static function refund(string $clientId, int $now, array $refund, array $consent, string $userId = ''): array
    {
        return self::build('refund', $clientId, '', $now, $now, $refund, $consent, [], $userId, []);
    }

    /**
     * Zdarzenie testowe dla „Send a test event” (serwer walidacji — nic nie trafia do raportow).
     *
     * @return array<string,mixed>
     */
    public static function test(int $now): array
    {
        return [
            'client_id' => '1234567890.' . $now,
            'timestamp_micros' => $now * 1000000,
            'events' => [[
                'name' => 'quince_insight_test',
                'params' => ['engagement_time_msec' => 1, 'debug_mode' => 1],
            ]],
        ];
    }

    /** Czy znacznik czasu zamowienia jest jeszcze w oknie 72 h. */
    public static function withinWindow(int $orderTimestamp, int $now): bool
    {
        return $now - $orderTimestamp <= self::MAX_AGE_SECONDS - 300;
    }

    /** Czy zakup zachowa zrodlo ruchu (w oknie sesji). */
    public static function keepsSession(int $orderTimestamp, int $now): bool
    {
        return $now - $orderTimestamp < self::SESSION_WINDOW_SECONDS;
    }

    /**
     * @param array<string,mixed> $params
     * @param array<string,string> $consent
     * @param array<string,mixed> $userData
     * @param array<string,string> $campaign
     *
     * @return array<string,mixed>
     */
    private static function build(
        string $name,
        string $clientId,
        string $sessionId,
        int $eventTimestamp,
        int $now,
        array $params,
        array $consent,
        array $userData,
        string $userId,
        array $campaign,
    ): array {
        if ($clientId === '') {
            throw new \LogicException('Measurement Protocol needs the client_id saved with the order.');
        }

        // Nie starsze niz 72 h — inaczej Google przesunie zdarzenie (RELAXED) albo je odrzuci.
        $timestamp = max($eventTimestamp, $now - self::MAX_AGE_SECONDS + 60);
        $params['engagement_time_msec'] = 1;

        if ($sessionId !== '' && self::keepsSession($eventTimestamp, $now)) {
            $params['session_id'] = $sessionId;
        }

        $events = [];

        if ($campaign !== []) {
            // Dotyczy zdarzen z tym samym lub pozniejszym znacznikiem czasu — idzie pierwsze.
            $events[] = ['name' => 'campaign_details', 'params' => $campaign];
        }

        $events[] = ['name' => $name, 'params' => $params];

        $payload = [
            'client_id' => $clientId,
            'timestamp_micros' => $timestamp * 1000000,
        ];

        if ($userId !== '') {
            $payload['user_id'] = $userId;
        }

        $payload['consent'] = ConsentMapper::forMeasurementProtocol($consent);

        if ($userData !== [] && ConsentMapper::granted($consent, 'ad_user_data')) {
            $payload['user_data'] = $userData;
        }

        $payload['events'] = $events;

        return $payload;
    }
}
