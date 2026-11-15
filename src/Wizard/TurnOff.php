<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Wizard;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Quince\Insight\Core\Services;

/**
 * Settings → Services → "Turn off" (decyzja #24): co przestanie dzialac po wylaczeniu
 * uslugi. Czysta logika — teksty, uslugi zalezne i crony, ktore przestaja byc potrzebne.
 *
 * Wylaczenie niczego nie usuwa w Google, a ustawienia uslugi zostaja (ponowne wlaczenie
 * je przywraca). Zaleznosci jak w kreatorze: Tag Manager → Google Ads,
 * Merchant Center → Customer Reviews (`Services::DEPENDS_ON`).
 */
final class TurnOff
{
    /**
     * Co przestaje dzialac — po jednym zdaniu, zgodnie z `disable()` i hookami uslug.
     * Teksty po angielsku (tlumaczenie w XLF).
     */
    public const EFFECTS = [
        'ga4' => [
            'The Analytics screen and the dashboard card stop showing reports.',
            'Purchases and refunds are no longer sent to Analytics from the server.',
        ],
        'ads' => [
            'The module stops setting up Google Ads conversions and remarketing in Tag Manager.',
        ],
        'gtm' => [
            'The Tag Manager snippet and the dataLayer are removed from the shop pages.',
            'Shop events are no longer sent from the browser to Analytics and Google Ads.',
        ],
        'gmc' => [
            'Active feeds are paused and no more products are sent to Merchant Center.',
            'Product statuses and promotions are no longer updated.',
        ],
        'gcr' => [
            'The Google survey window is no longer shown on the order confirmation page.',
        ],
        'gsc' => [
            'The sitemap files are removed from the shop.',
            'Search results are no longer imported from Search Console.',
        ],
        'psi' => [
            'Scheduled page speed checks stop. Earlier results are kept.',
        ],
    ];

    /**
     * Usluga i uslugi, ktore wylaczaja sie razem z nia (tylko te wlaczone).
     *
     * @param string[] $enabled uslugi wlaczone w sklepie
     *
     * @return string[] pierwsza = wylaczana usluga
     */
    public static function affected(string $key, array $enabled): array
    {
        $out = [$key];

        foreach (Services::DEPENDS_ON as $dependent => $base) {
            if ($base === $key && in_array($dependent, $enabled, true)) {
                $out[] = $dependent;
            }
        }

        return $out;
    }

    /**
     * Uslugi wlaczone po wylaczeniu `$key` (razem z zaleznymi).
     *
     * @param string[] $enabled
     *
     * @return string[]
     */
    public static function enabledAfter(string $key, array $enabled): array
    {
        return array_values(array_diff($enabled, self::affected($key, $enabled)));
    }

    /**
     * Grupy crona, ktore przestaja byc potrzebne: ich uslugi (`CronBlock::GROUPS[...]['needs']`)
     * byly wlaczone przed, a po wylaczeniu nie ma zadnej.
     *
     * @param string[] $before
     * @param string[] $after
     * @param array<string,array{needs:?array}> $groups
     *
     * @return string[] klucze grup w kolejnosci `$groups`
     */
    public static function lostCronGroups(array $before, array $after, array $groups = CronBlock::GROUPS): array
    {
        $out = [];

        foreach ($groups as $key => $group) {
            $needs = $group['needs'] ?? null;

            if (!is_array($needs)) {
                continue;
            }

            if (array_intersect($needs, $before) !== [] && array_intersect($needs, $after) === []) {
                $out[] = (string) $key;
            }
        }

        return $out;
    }
}
