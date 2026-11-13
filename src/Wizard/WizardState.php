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

use Quince\Insight\Core\Dev;
use Quince\Insight\Core\Google\ConnectionRepository;
use Quince\Insight\Core\Log;
use Quince\Insight\Core\ServiceRegistry;
use Quince\Insight\Core\Services;
use Quince\Insight\Core\ShopContext;
use Quince\Insight\Core\ShopRepository;

/**
 * Stan kreatora dla biezacego zadania: ktore kroki sa w przejsciu, w jakim sa stanie,
 * ktory krok i ktory sklep pokazac.
 *
 * Trzy tryby (prototyp):
 *  - pelne przejscie (pierwsza konfiguracja albo "Run again"),
 *  - kolejny sklep (`adding`) — inny sklep jest juz skonfigurowany: bez powitania i bez
 *    kroku Shops, wybor polaczenia w kroku Google Cloud,
 *  - jedna usluga (`single`) — sklep po kreatorze otwiera krok jednej uslugi
 *    ("Connect" w bocznym pasku, Settings → Services); "Changing one service".
 */
final class WizardState
{
    public function __construct(
        private readonly WizardRun $runs,
        private readonly ShopRepository $shops,
        private readonly Services $services,
        private readonly ConnectionRepository $connections,
        private readonly ServiceRegistry $registry,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function build(int $ctx, string $requestedStep = '', int $requestedShop = 0): array
    {
        $run = $this->runs->load($ctx);
        $runShops = $run['shops'];
        $done = $this->shops->wizardDone($ctx);
        $multistore = ShopContext::isMultistore();

        $single = '';

        // Jedna usluga: sklep po kreatorze (albo instalacja deweloperska z obejsciem kreatora,
        // w ktorej panel jest dostepny bez kreatora) otwiera krok wprost.
        if (($done || Dev::skipWizard()) && !$run['started'] && $requestedStep !== ''
            && (in_array($requestedStep, WizardFlow::SERVICE_STEPS, true) || $requestedStep === 'cloud')) {
            $single = $requestedStep;
        }

        $otherDone = array_diff($this->shops->setUpShops(), $runShops);
        $adding = !$done && $otherDone !== [] && $run['mode'] !== 'several';

        $connections = $this->connections->all();
        $existing = $adding && ($run['conn'] === '' ? $connections !== [] : WizardRun::existingConnection($run) > 0);

        $services = $this->services->enabledKeys($ctx);
        $serviceOfStep = WizardFlow::serviceOf($single);

        if ($serviceOfStep !== '' && !in_array($serviceOfStep, $services, true)) {
            $services[] = $serviceOfStep;
        }

        $active = $single !== '' ? [$single] : WizardFlow::activeSteps($services, $multistore, $adding, $existing);
        // Pasek krokow zawsze pokazuje caly kreator ze stanami (jak w prototypie przy zmianie
        // jednej uslugi). `active` przy jednej usludze to tylko jej krok — gdyby pasek bral
        // je wprost, wszystkie pozostale kroki dostalyby znacznik "Off".
        $navActive = WizardFlow::activeSteps($services, $multistore, $adding, $existing);

        $states = [];
        $perShop = [];

        foreach (array_keys(WizardFlow::STEPS) as $step) {
            $perShop[$step] = [];

            foreach ($runShops as $idShop) {
                $perShop[$step][$idShop] = $this->shopStepState($idShop, $step);
            }

            $states[$step] = in_array($step, WizardFlow::SWITCHER_STEPS, true)
                ? WizardFlow::combine(array_values($perShop[$step]))
                : ($perShop[$step][$ctx] ?? 'todo');
        }

        $furthest = WizardFlow::further($run['furthest'], $this->shops->wizardStep($ctx));
        $current = $this->currentStep($single, $requestedStep, $active, $furthest, $states, $ctx);

        $formShop = $runShops[0];

        if (in_array($current, WizardFlow::SWITCHER_STEPS, true)) {
            if ($requestedShop > 0 && in_array($requestedShop, $runShops, true)) {
                $formShop = $requestedShop;
            } else {
                foreach ($runShops as $idShop) {
                    if (!in_array($perShop[$current][$idShop] ?? 'todo', ['done', 'skipped'], true)) {
                        $formShop = $idShop;

                        break;
                    }
                }
            }
        }

        return [
            'ctx' => $ctx,
            'run' => $run,
            'runShops' => $runShops,
            'done' => $done,
            'single' => $single,
            'adding' => $adding,
            'existingConnection' => $existing,
            'multistore' => $multistore,
            'services' => $services,
            'active' => $active,
            'navActive' => $navActive,
            'states' => $states,
            'perShop' => $perShop,
            'furthest' => $furthest,
            'current' => $current,
            'formShop' => $formShop,
            'otherDone' => array_values($otherDone),
        ];
    }

    /** Stan kroku w jednym sklepie: zapis kreatora + wynik wlasciciela kroku uslugi. */
    public function shopStepState(int $idShop, string $step): string
    {
        $shell = (string) ($this->shops->wizardState($idShop)[$step] ?? '');

        if (!in_array($step, WizardFlow::SERVICE_STEPS, true)) {
            return $shell !== '' ? $shell : 'todo';
        }

        return WizardFlow::mergeState($shell, $this->ownerStatus($idShop, $step));
    }

    /** `WizardStep::status()` wlasciciela; krok w budowie albo z bledem nie wywraca kreatora. */
    public function ownerStatus(int $idShop, string $step): string
    {
        try {
            $owner = $this->registry->wizardStep($step);

            return $owner === null ? '' : (string) $owner->status($idShop);
        } catch (\Throwable $e) {
            Log::write($idShop, 'core', Log::WARNING, 'Wizard step ' . $step . ' status failed: ' . $e->getMessage());

            return '';
        }
    }

    /**
     * @param string[] $active
     * @param array<string,string> $states
     */
    private function currentStep(string $single, string $requested, array $active, string $furthest, array $states, int $ctx): string
    {
        if ($single !== '') {
            return $single;
        }

        if ($requested !== '' && WizardFlow::canGo($requested, $active, $furthest, $states)) {
            return $requested;
        }

        // Wznowienie po przerwaniu: ostatni otwarty krok, jesli nadal jest w przejsciu.
        $last = $this->shops->wizardStep($ctx);

        if ($last !== '' && in_array($last, $active, true)) {
            return $last;
        }

        return $active[0] ?? 'services';
    }
}
