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
 * Krok kreatora dostarczany przez wlasciciela uslugi.
 *
 * Szablon kroku: `views/templates/admin/wizard/steps/<key>.html.twig` — sam blok tresci;
 * powloke, pasek krokow, przelacznik sklepu i przyciski Back / Skip / Continue daje kreator.
 * Klasy krokow sa wpisane w `ServiceRegistry::WIZARD_STEPS`.
 */
interface WizardStep
{
    /** ga4 | ads | gtm | verify | gmc | gcr | gsc */
    public function key(): string;

    /**
     * Dane dla szablonu kroku (listy kont z Google itp.). $shopIds = sklepy objete kreatorem.
     *
     * @param int[] $shopIds
     *
     * @return array<string,mixed>
     */
    public function data(array $shopIds): array;

    /**
     * Zapis wyboru. Zwraca liste bledow per pole ([] = ok).
     *
     * @param int[] $shopIds
     * @param array<string,mixed> $input
     *
     * @return array<string,string>
     */
    public function save(array $shopIds, array $input): array;

    /** done | todo | skipped | error — stan kroku dla sklepu. */
    public function status(int $idShop): string;

    /**
     * Wiersze do ekranu Summary. `params` (opcjonalnie) — parametry tlumaczenia `value`.
     *
     * @return array<int,array{label:string,value:string,state:string,params?:array<string,string>}>
     */
    public function summary(int $idShop): array;
}
