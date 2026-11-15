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
use Quince\Insight\Core\Dto\JobStatus;
use Quince\Insight\Core\PanelAccess;
use Quince\Insight\Core\JobRunner;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Postep zadan w tle: komponent `QI.job` popycha zadanie zapytaniem `job_tick` co sekunde.
 *
 * Kontroler jest wspolny dla wszystkich uslug — usluga tylko startuje zadanie
 * (`JobRunner::start()`) i oddaje jego identyfikator do szablonu.
 */
final class JobController extends PanelController
{
    public function __construct(private readonly JobRunner $jobs)
    {
    }

    // Uprawnienie do zakladki sprawdza find() wg typu zadania (R-13); atrybut wpuszcza pracownika panelu.
    #[AdminSecurity("is_granted('ROLE_EMPLOYEE')", message: 'You do not have permission to change this.')]
    public function tickAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_job_tick');

        $job = $this->find($request, 'update');

        if ($job === null) {
            return $this->fail($this->t('This task no longer exists.'), 404);
        }

        return $this->ok(['job' => $this->translatedJob($this->jobs->tick($job->id)->toArray())]);
    }

    #[AdminSecurity("is_granted('ROLE_EMPLOYEE')", message: 'You do not have permission to view this.')]
    public function statusAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_job_status');

        $job = $this->find($request, 'read');

        if ($job === null) {
            return $this->fail($this->t('This task no longer exists.'), 404);
        }

        return $this->ok(['job' => $this->translatedJob($job->toArray())]);
    }

    #[AdminSecurity("is_granted('ROLE_EMPLOYEE')", message: 'You do not have permission to change this.')]
    public function cancelAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_job_cancel');

        $job = $this->find($request, 'update');

        if ($job === null) {
            return $this->fail($this->t('This task no longer exists.'), 404);
        }

        $this->jobs->cancel($job->id);

        return $this->ok(['job' => $this->translatedJob($this->jobs->status($job->id)->toArray())]);
    }

    /**
     * Zadanie z zadania HTTP — tylko ze sklepow, ktore pracownik ma w biezacym kontekscie.
     * Bez tego identyfikator z innego sklepu dawalby dostep do cudzego zadania.
     *
     * Uprawnienie (`read` / `update`) wynika z typu zadania: zakladka uslugi, ktora je
     * uruchamia, kreator albo Overview (`PanelAccess`). Brak uprawnienia = 403.
     */
    private function find(Request $request, string $permission): ?JobStatus
    {
        $job = $this->jobs->find((int) ($this->payload($request)['id'] ?? 0));

        if ($job === null) {
            return null;
        }

        $allowed = array_map('intval', \Shop::getContextListShopID());

        if (!in_array($job->idShop, $allowed, true)) {
            return null;
        }

        foreach (PanelAccess::tabsFor($job->type) as $tab) {
            if ($this->isGranted($permission, $tab)) {
                return $job;
            }
        }

        throw new AccessDeniedHttpException($permission === 'read'
            ? $this->t('You do not have permission to view this.')
            : $this->t('You do not have permission to change this.'));
    }
}
