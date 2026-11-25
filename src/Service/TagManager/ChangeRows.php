<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\TagManager;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Quince\Insight\Service\TagManager\Dto\Plan;

/**
 * Wiersze podgladu zmian „Type / Name / Count / Change” jak w prototypie (`gtmChanges()`):
 * encje zgrupowane (np. „GA4 event tags — 15”), osobny wiersz na kazdy rodzaj zmiany.
 */
final class ChangeRows
{
    /** Kolejnosc i opisy grup jak w prototypie; folder doszedl (osobny folder modulu — PLAN §4 D). */
    private const GROUPS = [
        'folder' => ['Folder', 'Folder “Quince Insight”'],
        'google_tag' => ['Tag', 'Google tag — %mid%'],
        'ga4_events' => ['Tag', 'GA4 event tags'],
        'ads_conversion' => ['Tag', 'Google Ads conversion — %ads%'],
        'ads_linker' => ['Tag', 'Conversion Linker'],
        'ads_remarketing' => ['Tag', 'Google Ads remarketing'],
        'triggers' => ['Trigger', 'Custom event triggers'],
        'dlv' => ['Variable', 'Data layer variables'],
        'upd' => ['Variable', 'User-provided data (enhanced conversions)'],
    ];

    private const LABELS = [
        PlanDiff::CREATE => ['Will be created', 'accent'],
        PlanDiff::UPDATE => ['Will be updated', 'warning'],
        PlanDiff::DELETE => ['Will be removed', 'danger'],
        PlanDiff::KEEP => ['No change', ''],
        PlanDiff::ADOPT => ['Will be updated', 'warning'],
        PlanDiff::ADOPTED => ['No change', ''],
    ];

    /**
     * @param array<int,array<string,string>> $ops wynik `PlanDiff::diff()`
     *
     * @return array<int,array{type:string,name:string,count:int,change:string,badge:string,op:string}>
     */
    public function rows(Plan $plan, array $ops, string $measurementId, string $adsId): array
    {
        $counts = [];

        foreach ($ops as $op) {
            if ($op['op'] === PlanDiff::FORGET) {
                continue;
            }

            $counts[$op['group']][$op['op']] = ($counts[$op['group']][$op['op']] ?? 0) + 1;
        }

        $rows = [];

        foreach (self::GROUPS as $group => [$type, $name]) {
            foreach ([PlanDiff::CREATE, PlanDiff::UPDATE, PlanDiff::ADOPT, PlanDiff::DELETE, PlanDiff::KEEP, PlanDiff::ADOPTED] as $op) {
                $n = $counts[$group][$op] ?? 0;

                if ($n === 0) {
                    continue;
                }

                $rows[] = [
                    'type' => $type,
                    'name' => strtr($name, ['%mid%' => $measurementId !== '' ? $measurementId : 'G-…', '%ads%' => $adsId !== '' ? $adsId : 'AW-…']),
                    'count' => $n,
                    'change' => self::LABELS[$op][0],
                    'badge' => self::LABELS[$op][1],
                    'op' => $op,
                    // Zrodlo tlumaczenia nazwy z parametrami — kontroler tlumaczy wiersze (fala 2, agent I).
                    'nameSource' => $name,
                    'nameParams' => ['%mid%' => $measurementId !== '' ? $measurementId : 'G-…', '%ads%' => $adsId !== '' ? $adsId : 'AW-…'],
                ];
            }
        }

        return $rows;
    }

    /** @param array<int,array<string,string>> $ops */
    public static function hasChanges(array $ops): bool
    {
        return PlanDiff::writes($ops) > 0;
    }
}
