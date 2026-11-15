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

use Quince\Insight\Core\Options;
use Quince\Insight\Core\Services;
use Quince\Insight\Service\Analytics\Ga4Settings;
use Quince\Insight\Service\Events\EventsSettings;
use Quince\Insight\Service\TagManager\Dto\Plan;
use Quince\Insight\Service\TagManager\Dto\PlanInput;

/**
 * Zbiera ustawienia sklepu, od ktorych zalezy plan kontenera, i buduje plan.
 *
 * Zrodla (tylko odczyt cudzych ustawien): identyfikator pomiaru z `Ga4Settings` (agent B),
 * a przy wylaczonym Analytics — z recznego pola kroku `gtm`; wlaczone zdarzenia z
 * `EventsSettings::enabledEvents()` (agent C; domyslnie wszystkie, jak
 * w prototypie); Google Ads z `GtmOptions` i wlacznika uslugi `ads`.
 */
final class PlanSettings
{
    public function __construct(
        private readonly Ga4Settings $ga4,
        private readonly Services $services,
        private readonly ContainerPlan $planner,
        private readonly EventsSettings $events,
    ) {
    }

    public function input(int $idShop): PlanInput
    {
        // Domyslne wartosci zdarzen zna tylko `EventsSettings` (agent C).
        $events = array_values(array_intersect(ContainerPlan::EVENTS, $this->events->enabledEvents($idShop)));

        return new PlanInput(
            $this->measurementId($idShop),
            $events,
            $this->services->enabled($idShop, 'ads'),
            Options::str(GtmOptions::ADS_CONVERSION_ID, '', $idShop),
            Options::str(GtmOptions::ADS_CONVERSION_LABEL, '', $idShop),
            Options::bool(GtmOptions::ADS_REMARKETING, true, $idShop),
            Options::bool(GtmOptions::ADS_ENHANCED, true, $idShop),
        );
    }

    /**
     * Wejscie planu z formularza kroku `gtm`, bez zapisu ustawien — podglad „Review changes”
     * pokazuje, co zmieni sie po „Apply and publish”, a zapis nastepuje dopiero tam.
     * Pola jak w `GtmStep::save()`: ev_<zdarzenie> (0/1), ga4_mid (tylko przy wylaczonym Analytics).
     *
     * @param array<string,mixed> $form
     */
    public function inputFromForm(int $idShop, array $form): PlanInput
    {
        $saved = $this->input($idShop);
        $events = [];

        foreach (ContainerPlan::EVENTS as $event) {
            $key = 'ev_' . $event;
            $on = array_key_exists($key, $form) ? in_array((string) $form[$key], ['1', 'true', 'on'], true) : in_array($event, $saved->events, true);

            if ($on) {
                $events[] = $event;
            }
        }

        $mid = $saved->measurementId;

        if (!$this->services->enabled($idShop, 'ga4') && isset($form['ga4_mid']) && trim((string) $form['ga4_mid']) !== '') {
            $mid = strtoupper(trim((string) $form['ga4_mid']));
        }

        return new PlanInput($mid, $events, $saved->ads, $saved->adsConversionId, $saved->adsConversionLabel, $saved->adsRemarketing, $saved->adsEnhanced);
    }

    public function plan(int $idShop): Plan
    {
        return $this->planner->build($this->input($idShop));
    }

    /** Identyfikator pomiaru dla kontenera: z uslugi Analytics, a bez niej — wpisany recznie. */
    public function measurementId(int $idShop): string
    {
        $mid = $this->ga4->measurementId($idShop);

        if ($mid === '' && !$this->services->enabled($idShop, 'ga4')) {
            $mid = Options::str(GtmOptions::GA4_MANUAL_ID, '', $idShop);
        }

        return $mid;
    }
}
