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
 * Kiedy zakup albo zwrot moze isc z serwera — jedna regula dla wysylki przy zamowieniu,
 * ponownej wysylki, brakujacych zamowien i crona. Czysta logika.
 *
 * Powody (`order_log.server_reason`, `refund_log.reason`):
 *  - `disabled`   — wysylka z serwera wylaczona (ustawienie albo zdarzenie),
 *  - `no_secret`  — brak identyfikatora pomiaru albo sekretu Measurement Protocol,
 *  - `no_consent` — klient nie zgodzil sie na analityke (zapis z chwili zamowienia),
 *  - `no_session` — przegladarka nie miala ciasteczka `_ga` (modul nie wymysla identyfikatora),
 *  - `too_old`    — minelo 72 h, GA4 nie przyjmie zakupu,
 *  - `no_purchase`— zwrot zamowienia, ktore nie dotarlo do GA4 (nie ma czego pomniejszac),
 *  - `http_<kod>`, `timeout`, `invalid` — blad wysylki (`invalid` = serwer walidacji odrzucil tresc).
 */
final class SendRules
{
    /** Powody, przy ktorych ponowna wysylka nie ma sensu — nic sie nie zmieni. */
    public const FINAL_REASONS = ['no_consent', 'no_session', 'too_old', 'no_purchase'];

    /**
     * Powod pominiecia zakupu albo '' = mozna wysylac.
     *
     * @param array<string,mixed> $row wiersz `order_log` (ga_client_id, consent, date_add)
     */
    public static function purchaseReason(array $row, bool $enabled, bool $hasCredentials, int $now): string
    {
        if (!$enabled) {
            return 'disabled';
        }

        if (!$hasCredentials) {
            return 'no_secret';
        }

        $consent = self::consent($row);

        if (!ConsentMapper::granted($consent, 'analytics_storage')) {
            return 'no_consent';
        }

        if (trim((string) ($row['ga_client_id'] ?? '')) === '') {
            return 'no_session';
        }

        $placed = strtotime((string) ($row['date_add'] ?? '')) ?: $now;

        if (!MpPayload::withinWindow($placed, $now)) {
            return 'too_old';
        }

        return '';
    }

    /**
     * Powod pominiecia zwrotu albo ''. Zwrot ma sens tylko dla zakupu, ktory dotarl do GA4
     * (z serwera albo ze strony potwierdzenia), i tylko z identyfikatorem z zamowienia.
     *
     * @param array<string,mixed> $row wiersz `order_log`
     */
    /**
     * Czy nowa korekta jest juz objeta zwrotem reszty (wiersz `id_order_slip = 0`).
     *
     * Reszte liczy sie przy anulowaniu jako zamowienie minus istniejace korekty, wiec kazda
     * pozniejsza korekta jest jej czescia. Objeta jest, gdy reszta poszla do GA4 (`sent`)
     * albo moze jeszcze pojsc (`none`, `failed` — ponowienie przez cron). Reszta pominieta
     * (`skipped`: brak zgody, wysylka wylaczona...) niczego w GA4 nie zwrocila, wiec korekta
     * idzie normalnie i sama podlega tym samym regulom.
     *
     * @param array<string,mixed>|null $remaining
     */
    public static function slipCovered(?array $remaining): bool
    {
        return $remaining !== null && (string) ($remaining['status'] ?? '') !== 'skipped';
    }

    public static function refundReason(array $row, bool $enabled, bool $hasCredentials): string
    {
        if (!$enabled) {
            return 'disabled';
        }

        if (!$hasCredentials) {
            return 'no_secret';
        }

        if (!ConsentMapper::granted(self::consent($row), 'analytics_storage')) {
            return 'no_consent';
        }

        if (trim((string) ($row['ga_client_id'] ?? '')) === '') {
            return 'no_session';
        }

        if (($row['server_status'] ?? '') !== 'sent' && empty($row['browser_at'])) {
            return 'no_purchase';
        }

        return '';
    }

    /**
     * Czy zamowienie brakuje w GA4: strona potwierdzenia nie wypisala zakupu i serwer go nie wyslal.
     *
     * @param array<string,mixed> $row
     */
    public static function isMissing(array $row): bool
    {
        return empty($row['browser_at']) && ($row['server_status'] ?? '') !== 'sent';
    }

    /**
     * Czy automat w cronie moze sam doslac zamowienie: brakuje go, minelo opoznienie,
     * jest identyfikator i zgoda, i zakup zachowa jeszcze zrodlo ruchu (ponizej 24 h).
     *
     * @param array<string,mixed> $row
     */
    public static function autoSendable(array $row, int $afterMinutes, int $now): bool
    {
        if (!self::isMissing($row)) {
            return false;
        }

        if (trim((string) ($row['ga_client_id'] ?? '')) === ''
            || !ConsentMapper::granted(self::consent($row), 'analytics_storage')
            || !self::retryable($row) && ($row['server_status'] ?? '') === 'failed') {
            return false;
        }

        $placed = strtotime((string) ($row['date_add'] ?? '')) ?: $now;
        $age = $now - $placed;

        return $age >= $afterMinutes * 60 && MpPayload::keepsSession($placed, $now);
    }

    /**
     * Ponowienie po bledzie sieci / 5xx (przy zamowieniu wywolanie ma najwyzej 3 s).
     *
     * @param array<string,mixed> $row
     */
    public static function retryable(array $row, int $maxAttempts = 5): bool
    {
        $reason = (string) ($row['server_reason'] ?? '');

        return ($row['server_status'] ?? '') === 'failed'
            && ($reason === 'timeout' || preg_match('/^http_(5\d\d|429)$/', $reason) === 1)
            && (int) ($row['attempts'] ?? 0) < $maxAttempts;
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,string>
     */
    public static function consent(array $row): array
    {
        $consent = $row['consent'] ?? [];

        if (is_string($consent)) {
            $consent = json_decode($consent, true);
        }

        return ConsentMapper::normalize(is_array($consent) ? $consent : []);
    }
}
