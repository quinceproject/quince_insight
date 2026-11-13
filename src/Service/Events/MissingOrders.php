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

use Quince\Insight\Core\Clock;

/**
 * Events -> Missing orders: zamowienia z 72 h, ktorych brakuje w GA4 (dawny skrypt backfill
 * TagPilota, research/02 §3.4), z oznaczeniem, jak trafia do GA4 (ARCHITEKTURA §12 pkt 10):
 * do 24 h — ze zrodlem ruchu, 24–72 h — tylko do przychodu.
 *
 * Kryterium „brakuje”: strona potwierdzenia nie wypisala zakupu i serwer go nie wyslal
 * (`SendRules::isMissing`), a nie „brak wiersza w dzienniku” jak w TagPilocie.
 */
final class MissingOrders
{
    public function __construct(
        private readonly OrderLog $log,
        private readonly ServerEvents $server,
    ) {
    }

    /** Powod, dla ktorego zamowienia nie da sie wyslac ('' = da sie). */
    public static function blockedText(string $reason): string
    {
        return match ($reason) {
            '' => '',
            'no_session' => 'Cannot be sent — no GA session was saved with the order',
            'no_consent' => 'Cannot be sent — the customer did not consent',
            'too_old' => 'Cannot be sent — older than GA4 accepts (72 hours)',
            default => 'Cannot be sent',
        };
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function scan(int $idShop): array
    {
        $now = Clock::time();
        $out = [];

        foreach ($this->log->since($idShop, 72) as $row) {
            if (!SendRules::isMissing($row)) {
                continue;
            }

            $placed = strtotime((string) $row['date_add']) ?: $now;
            $reason = SendRules::purchaseReason($row, true, true, $now);
            $out[] = [
                'id' => (int) $row['id_order'],
                'ref' => (string) $row['reference'],
                'date' => substr((string) $row['date_add'], 0, 16),
                'total' => (float) $row['total'],
                'currency' => (string) $row['currency'],
                'reason' => $reason === 'no_session' ? 'No GA session' : ($reason === 'no_consent' ? 'No browser event, no consent' : 'No browser event'),
                'withSource' => MpPayload::keepsSession($placed, $now),
                'sendable' => $reason === '',
                // Dlaczego zamowienia nie da sie wyslac (przelacznik i "Send to GA4" wylaczone).
                'blocked' => self::blockedText($reason),
            ];
        }

        return $out;
    }

    /**
     * Wysylka (albo przebieg testowy) wybranych zamowien.
     *
     * @param int[] $ids
     *
     * @return array{sent:int, valid:int, failed:int, skipped:int, errors:array<int,string>}
     */
    public function send(int $idShop, array $ids, bool $dryRun): array
    {
        $out = ['sent' => 0, 'valid' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => []];
        $allowed = [];

        foreach ($this->scan($idShop) as $row) {
            if ($row['sendable']) {
                $allowed[$row['id']] = $row['ref'];
            }
        }

        foreach (array_unique(array_map('intval', $ids)) as $idOrder) {
            if (!isset($allowed[$idOrder])) {
                ++$out['skipped'];
                continue;
            }

            $result = $this->server->sendPurchase($idShop, $idOrder, 'missing', $dryRun);

            if ($result['status'] === 'sent' || $result['status'] === 'valid') {
                ++$out[$result['status']];
                continue;
            }

            if ($result['status'] === 'skipped') {
                ++$out['skipped'];
                continue;
            }

            ++$out['failed'];
            $detail = $result['messages'][0]['description'] ?? $result['reason'];
            $out['errors'][] = $allowed[$idOrder] . ': ' . $detail;
        }

        return $out;
    }
}
