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

/**
 * Reguly dostepu panelu niezalezne od PrestaShop (testowalne).
 *
 * 1. Zadania w tle (R-13): trasy `job_tick` / `job_status` / `job_cancel` sa wspolne dla
 *    wszystkich uslug, wiec uprawnienie wynika z typu zadania: zakladka ekranu uslugi,
 *    ktory je uruchamia, kreator (kroki gtm, gmc, gsc startuja te same zadania) i Overview.
 * 2. Sklepy i polaczenia (R-07): pracownik zmienia tylko sklepy ze swojego kontekstu
 *    i tylko polaczenia uzywane przez ktorys z tych sklepow.
 */
final class PanelAccess
{
    /**
     * Czy pracownik z kontekstem `$contextShops` moze ruszac polaczenie uzywane przez
     * `$connectionShops`. Polaczenie bez sklepow — tylko gdy kontekst obejmuje wszystkie sklepy.
     *
     * @param int[] $contextShops sklepy kontekstu pracownika (`Shop::getContextListShopID()`)
     * @param int[] $connectionShops sklepy przypisane do polaczenia
     * @param int[] $allShops wszystkie sklepy instalacji
     */
    public static function connectionAllowed(array $contextShops, array $connectionShops, array $allShops): bool
    {
        $context = array_map('intval', $contextShops);

        if ($connectionShops === []) {
            return array_diff(array_map('intval', $allShops), $context) === [];
        }

        return array_intersect(array_map('intval', $connectionShops), $context) !== [];
    }

    /** @param int[] $contextShops */
    public static function shopAllowed(int $idShop, array $contextShops): bool
    {
        return $idShop > 0 && in_array($idShop, array_map('intval', $contextShops), true);
    }

    /** Poczatek typu zadania => zakladka ekranu uslugi. */
    private const TABS = [
        'merchant.' => 'AdminQuinceInsightMerchant',
        'pagespeed.' => 'AdminQuinceInsightPageSpeed',
        'gsc.' => 'AdminQuinceInsightSearchConsole',
        'sitemap.' => 'AdminQuinceInsightSearchConsole',
        'structureddata.' => 'AdminQuinceInsightStructuredData',
        'gtm.' => 'AdminQuinceInsightEvents',
        'analytics.' => 'AdminQuinceInsightAnalytics',
    ];

    /** Zadania startowane takze z krokow kreatora (gtm, gmc, gsc, ga4). */
    private const WIZARD_PREFIXES = ['gtm.', 'merchant.', 'sitemap.', 'gsc.', 'analytics.'];

    /**
     * @return string[] zakladki; wystarczy uprawnienie do jednej z nich
     */
    public static function tabsFor(string $type): array
    {
        $out = [];

        foreach (self::TABS as $prefix => $tab) {
            if (str_starts_with($type, $prefix)) {
                $out[] = $tab;

                break;
            }
        }

        foreach (self::WIZARD_PREFIXES as $prefix) {
            if (str_starts_with($type, $prefix)) {
                $out[] = 'AdminQuinceInsightWizard';

                break;
            }
        }

        $out[] = 'AdminQuinceInsightOverview';

        return $out;
    }
}
