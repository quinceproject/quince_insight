<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Ui;

use Quince\Insight\Core\Services;

/**
 * Pozycje bocznego paska panelu — kolejnosc i nazwy jak w prototypie (`NAV`).
 *
 * Usluga niewlaczona w sklepie zostaje na liscie wyszarzona, ze znacznikiem "Connect",
 * i prowadzi do swojego kroku kreatora zamiast do ekranu.
 */
final class PanelNavigation
{
    /**
     * `svc` = usluga z kreatora, od ktorej zalezy ekran; `step` = krok kreatora, do ktorego
     * prowadzi "Connect" (Page speed nie ma wlasnego kroku — klucz API podaje sie w kroku
     * Google Cloud project).
     */
    private const ITEMS = [
        ['key' => 'overview', 'label' => 'Overview', 'icon' => 'layout', 'route' => 'quince_insight_overview'],
        ['key' => 'analytics', 'label' => 'Analytics', 'icon' => 'eye', 'route' => 'quince_insight_analytics', 'svc' => 'ga4', 'step' => 'ga4'],
        ['key' => 'events', 'label' => 'Events', 'icon' => 'link', 'route' => 'quince_insight_events', 'svc' => 'gtm', 'step' => 'gtm'],
        ['key' => 'merchant', 'label' => 'Merchant Center', 'icon' => 'box', 'route' => 'quince_insight_merchant', 'svc' => 'gmc', 'step' => 'gmc'],
        ['key' => 'gsc', 'label' => 'Search Console', 'icon' => 'search', 'route' => 'quince_insight_gsc', 'svc' => 'gsc', 'step' => 'gsc'],
        ['key' => 'psi', 'label' => 'Page speed', 'icon' => 'refresh', 'route' => 'quince_insight_psi', 'svc' => 'psi', 'step' => 'cloud'],
        ['key' => 'schema', 'label' => 'Structured data', 'icon' => 'puzzle', 'route' => 'quince_insight_schema'],
        ['key' => 'settings', 'label' => 'Settings', 'icon' => 'settings', 'route' => 'quince_insight_settings'],
        ['key' => 'support', 'label' => 'Support', 'icon' => 'info', 'route' => 'quince_insight_support'],
    ];

    public function __construct(private readonly Services $services)
    {
    }

    /**
     * @param int|null $idShop   sklep, ktorego dotycza dane; null = "wszystkie sklepy" (albo grupa)
     * @param int[]    $shopIds  "wszystkie sklepy": sklepy kontekstu — usluga niewlaczona w zadnym
     *                           z nich jest wyszarzona z "Connect" (prowadzi do kroku Shops, decyzja #23)
     *
     * @return array<int,array{key:string,label:string,icon:string,route:string,off:bool,step:string}>
     */
    public function items(?int $idShop, array $shopIds = []): array
    {
        $out = [];

        foreach (self::ITEMS as $item) {
            $svc = $item['svc'] ?? null;

            $out[] = [
                'key' => $item['key'],
                'label' => $item['label'],
                'icon' => $item['icon'],
                'route' => $item['route'],
                'off' => $svc !== null && ($idShop !== null ? !$this->services->enabled($idShop, $svc) : $this->offEverywhere($svc, $shopIds)),
                'step' => (string) ($item['step'] ?? ''),
            ];
        }

        return $out;
    }

    /** @param int[] $shopIds */
    private function offEverywhere(string $svc, array $shopIds): bool
    {
        if ($shopIds === []) {
            return false;
        }

        foreach ($shopIds as $id) {
            if ($this->services->enabled((int) $id, $svc)) {
                return false;
            }
        }

        return true;
    }

    /** Nazwa ekranu po kluczu pozycji ('' gdy klucz nie nalezy do paska). */
    public function label(string $key): string
    {
        foreach (self::ITEMS as $item) {
            if ($item['key'] === $key) {
                return $item['label'];
            }
        }

        return '';
    }

    /** Usluga z kreatora, od ktorej zalezy ekran; null gdy ekran jest zawsze dostepny. */
    public function serviceOf(string $key): ?string
    {
        foreach (self::ITEMS as $item) {
            if ($item['key'] === $key) {
                return $item['svc'] ?? null;
            }
        }

        return null;
    }
}
