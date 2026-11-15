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

/**
 * Podglad zmian przed „Apply and publish”: plan sklepu porownany z kontenerem.
 * Tylko odczyty (workspace'y + 4 listy encji). Nowy kontener = wszystko „Create”, bez Google.
 */
final class PlanPreview
{
    public function __construct(
        private readonly PlanSettings $settings,
        private readonly GtmSettings $gtm,
        private readonly EntityStore $store,
        private readonly GtmApi $api,
        private readonly PlanDiff $differ,
        private readonly ChangeRows $rows,
        private readonly ContainerPlan $planner,
    ) {
    }

    /**
     * @return array{rows:array<int,array<string,mixed>>,writes:int,hash:string,notes:string[],newContainer:bool,eventCount:int}
     */
    public function preview(int $idShop): array
    {
        $container = $this->gtm->container($idShop);

        return $this->previewFor($idShop, $this->settings->input($idShop), $container, $container === '' || Options::bool(GtmOptions::GTM_NEW_CONTAINER, false, $idShop));
    }

    /**
     * Podglad dla wejscia z formularza (niezapisanego) i wybranego kontenera.
     *
     * @return array{rows:array<int,array<string,mixed>>,writes:int,hash:string,notes:string[],newContainer:bool,eventCount:int}
     */
    public function previewFor(int $idShop, \Quince\Insight\Service\TagManager\Dto\PlanInput $input, string $container, bool $new): array
    {
        $plan = $this->planner->build($input);
        $new = $new || $container === '';
        $live = [];
        $tracked = [];

        if (!$new) {
            $api = $this->api->forShop($idShop);
            $workspaces = $api->workspaces($container);
            $ws = $workspaces[0] ?? null;

            foreach ($workspaces as $candidate) {
                if (($candidate['name'] ?? '') === ApplyJob::WORKSPACE) {
                    $ws = $candidate;

                    break;
                }

                if (($candidate['name'] ?? '') === 'Default Workspace') {
                    $ws = $candidate;
                }
            }

            if ($ws !== null) {
                foreach (array_keys(PlanDiff::ID_FIELDS) as $kind) {
                    $live[$kind] = $api->entities((string) $ws['path'], $kind);
                }
            }

            $tracked = $this->store->all($idShop, $container);
        }

        $ops = $this->differ->diff($plan, $live, $tracked);

        return [
            'rows' => $this->rows->rows($plan, $ops, strtoupper(trim($input->measurementId)), trim($input->adsConversionId)),
            'writes' => PlanDiff::writes($ops),
            'hash' => $plan->hash,
            'notes' => $plan->notes,
            'newContainer' => $new,
            'eventCount' => count($input->events),
        ];
    }
}
