<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Controller\Admin;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShopBundle\Security\Attribute\AdminSecurity;
use Quince\Insight\Core\Log;
use Quince\Insight\Service\TagManager\AdsStep;
use Quince\Insight\Service\TagManager\ContainerService;
use Quince\Insight\Service\TagManager\ContainerSetup;
use Quince\Insight\Service\TagManager\GtmSettings;
use Quince\Insight\Service\TagManager\GtmStep;
use Quince\Insight\Service\TagManager\PlanPreview;
use Quince\Insight\Service\TagManager\PlanSettings;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Akcje JSON Tag Managera (blok D w ARCHITEKTURA §9): lista kont i kontenerow, nowy kontener,
 * podglad zmian, „Apply and publish” (zadanie `gtm.apply`), zapis krokow `gtm` i `ads`.
 *
 * Kazda akcja przyjmuje opcjonalne `id_shop` (kreator z kilkoma sklepami) — tylko sklep
 * z biezacego kontekstu pracownika.
 */
final class TagManagerController extends PanelController
{
    public function __construct(
        private readonly ContainerService $containers,
        private readonly ContainerSetup $setup,
        private readonly PlanPreview $preview,
        private readonly GtmStep $gtmStep,
        private readonly AdsStep $adsStep,
        private readonly GtmSettings $gtm,
        private readonly PlanSettings $planSettings,
    ) {
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function accountsAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_gtm_accounts');
        $data = $this->payload($request);
        $idShop = $this->shopFrom($data);

        try {
            return $this->ok(['accounts' => $this->containers->accounts($idShop, !empty($data['refresh']))]);
        } catch (\Throwable $e) {
            return $this->fail($this->message($e, $idShop));
        }
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function containerCreateAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_gtm_container_create');
        $data = $this->payload($request);
        $idShop = $this->shopFrom($data);

        try {
            $container = $this->containers->create($idShop, (string) ($data['account'] ?? ''));

            return $this->ok(['container' => $container, 'accounts' => $this->containers->accounts($idShop)]);
        } catch (\Throwable $e) {
            return $this->fail($this->message($e, $idShop));
        }
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function planAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_gtm_plan');
        $form = $this->payload($request);
        $idShop = $this->shopFrom($form);

        try {
            // „Review changes” niczego nie zapisuje: plan liczony z formularza; bez pol formularza
            // (stare wywolania) — z zapisanych ustawien.
            if (isset($form['container'])) {
                $container = (string) $form['container'];
                $plan = $this->preview->previewFor($idShop, $this->planSettings->inputFromForm($idShop, $form), $container === 'new' ? '' : $container, $container === 'new' || $container === '');
            } else {
                $plan = $this->preview->preview($idShop);
            }

        // Wiersze podgladu skladaja klasy logiki bez tlumacza — tlumaczymy tutaj (fala 2, agent I).
        foreach ($plan['rows'] as $i => $row) {
            $plan['rows'][$i]['type'] = $this->t((string) $row['type']);
            $plan['rows'][$i]['change'] = $this->t((string) $row['change']);
            $plan['rows'][$i]['name'] = isset($row['nameSource'])
                ? $this->t((string) $row['nameSource'], (array) ($row['nameParams'] ?? []))
                : $this->t((string) $row['name']);
            unset($plan['rows'][$i]['nameSource'], $plan['rows'][$i]['nameParams']);
        }

        foreach ($plan['notes'] ?? [] as $i => $note) {
            $plan['notes'][$i] = $this->t((string) $note);
        }

        return $this->ok(['plan' => $plan]);
        } catch (\Throwable $e) {
            return $this->fail($this->message($e, $idShop));
        }
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function applyAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_gtm_apply');
        $idShop = $this->shopFrom($this->payload($request));

        try {
            return $this->ok(['job' => $this->setup->start($idShop)->toArray()]);
        } catch (\Throwable $e) {
            return $this->fail($this->message($e, $idShop));
        }
    }

    /** Zapis kroku `gtm` bez przechodzenia dalej („Review changes” w kreatorze). */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function saveAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_gtm_save');
        $data = $this->payload($request);
        $idShop = $this->shopFrom($data);

        try {
            $errors = $this->gtmStep->save([$idShop], $data);
        } catch (\Throwable $e) {
            return $this->fail($this->message($e, $idShop));
        }

        if ($errors !== []) {
            return $this->fail($this->t((string) reset($errors)), 400, ['errors' => array_map(fn (string $m): string => $this->t($m), $errors)]);
        }

        return $this->ok(['mode' => $this->gtm->mode($idShop), 'publicId' => $this->gtm->publicId($idShop)]);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function adsSaveAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_ads_save');
        $data = $this->payload($request);
        $idShop = $this->shopFrom($data);
        $errors = $this->adsStep->save([$idShop], $data);

        if ($errors !== []) {
            return $this->fail($this->t((string) reset($errors)), 400, ['errors' => array_map(fn (string $m): string => $this->t($m), $errors)]);
        }

        return $this->ok(['upToDate' => $this->gtm->upToDate($idShop)]);
    }

    /** @param array<string,mixed> $data */
    private function shopFrom(array $data): int
    {
        $requested = (int) ($data['id_shop'] ?? 0);

        if ($requested > 0 && in_array($requested, array_map('intval', \Shop::getContextListShopID()), true)) {
            return $requested;
        }

        return $this->idShop();
    }

    private function message(\Throwable $e, int $idShop): string
    {
        if (!$e instanceof \Quince\Insight\Core\Google\GoogleApiException && !$e instanceof \Quince\Insight\Core\Google\NotConnectedException) {
            if (!$e instanceof \RuntimeException || $e instanceof \UnexpectedValueException) {
                Log::write($idShop, 'tagmanager', Log::ERROR, 'Tag Manager action failed: ' . $e->getMessage(), ['file' => basename($e->getFile()) . ':' . $e->getLine()]);

                return $this->t('Something went wrong. Details are in the module log.');
            }

            return $this->t($e->getMessage());
        }

        return $this->googleError($e);
    }
}
