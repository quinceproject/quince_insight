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
 * `productStatus` przetworzonego produktu → stan i problemy (czysta logika).
 *
 * Przeniesione z FeedForge `StatusService::determineOverallStatus()` i `extractIssues()`
 * (research/02 §1.5) z jedna zmiana: stan liczony dla kraju feedu, gdy Google go podaje,
 * oraz `limited` — zatwierdzony, ale z problemem obnizajacym widocznosc (DEMOTED).
 */
final class StatusParser
{
    /**
     * @param array<string,mixed> $status `productStatus`
     *
     * @return string approved | limited | disapproved | pending
     */
    public static function overall(array $status, string $country = ''): string
    {
        $approved = $pending = $disapproved = false;
        $country = strtoupper($country);

        foreach ((array) ($status['destinationStatuses'] ?? []) as $destination) {
            $lists = [
                'approved' => array_map('strtoupper', (array) ($destination['approvedCountries'] ?? [])),
                'pending' => array_map('strtoupper', (array) ($destination['pendingCountries'] ?? [])),
                'disapproved' => array_map('strtoupper', (array) ($destination['disapprovedCountries'] ?? [])),
            ];
            $mentions = $country !== '' && in_array($country, array_merge(...array_values($lists)), true);
            $has = static fn (array $list): bool => $mentions ? in_array($country, $list, true) : $list !== [];

            $disapproved = $disapproved || $has($lists['disapproved']);
            $pending = $pending || $has($lists['pending']);
            $approved = $approved || $has($lists['approved']);
        }

        if ($disapproved) {
            return 'disapproved';
        }

        if ($pending || !$approved) {
            return 'pending';
        }

        foreach (self::issues($status) as $issue) {
            if ($issue['severity'] !== 'suggestion') {
                return 'limited';
            }
        }

        return 'approved';
    }

    /**
     * @param array<string,mixed> $status
     *
     * @return array<int,array{severity:string,code:string,message:string,attribute:string,uri:string}>
     */
    public static function issues(array $status): array
    {
        $out = [];

        foreach ((array) ($status['itemLevelIssues'] ?? []) as $issue) {
            $key = (string) ($issue['code'] ?? '') . '|' . (string) ($issue['attribute'] ?? '');

            // To samo zgloszenie przychodzi osobno dla kazdego kontekstu (Shopping ads, free listings).
            if (isset($out[$key])) {
                continue;
            }

            $out[$key] = [
                'severity' => match ((string) ($issue['severity'] ?? '')) {
                    'DISAPPROVED' => 'critical',
                    'NOT_IMPACTED' => 'suggestion',
                    default => 'warning',
                },
                'code' => (string) ($issue['code'] ?? ''),
                'message' => (string) ($issue['description'] ?? ($issue['detail'] ?? '')),
                'attribute' => (string) ($issue['attribute'] ?? ''),
                'uri' => (string) ($issue['documentation'] ?? ''),
            ];
        }

        return array_values($out);
    }
}
