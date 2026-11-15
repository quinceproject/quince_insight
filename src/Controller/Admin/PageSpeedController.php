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
use Quince\Insight\Core\Google\ConnectionRepository;
use Quince\Insight\Core\JobRunner;
use Quince\Insight\Core\Options;
use Quince\Insight\Core\ShopContext;
use Quince\Insight\Service\PageSpeed\CheckJob;
use Quince\Insight\Service\PageSpeed\PageSpeedOptions;
use Quince\Insight\Service\PageSpeed\PageSpeedScreen;
use Quince\Insight\Service\PageSpeed\PsiRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ekran Page speed (ARCHITEKTURA §9 blok H): CrUX dla domeny, test PageSpeed Insights
 * stron z listy, lista stron i harmonogram. Jeden widok bez zakladek, jak w prototypie.
 */
final class PageSpeedController extends PanelController
{
    public function __construct(
        private readonly PageSpeedScreen $screen,
        private readonly PsiRepository $repository,
        private readonly JobRunner $jobs,
        private readonly ConnectionRepository $connections,
    ) {
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", message: 'You do not have permission to view this.')]
    public function indexAction(Request $request): Response
    {
        $allShops = ShopContext::current() === null;

        return $this->renderPanel('psi/index.html.twig', 'psi', $allShops ? [] : $this->screen->data($this->idShop()));
    }

    /** "Run check now": zadanie `pagespeed.check` (CrUX + wszystkie strony, oba urzadzenia). */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function runAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_psi_run');

        $idShop = $this->idShop();
        $connection = $this->connections->forShop($idShop);

        if ($connection === null || !$connection->hasApiKey) {
            return $this->fail($this->t('Page speed needs a Google API key. Add it in the Google Cloud project step of the wizard.'), 400);
        }

        $this->repository->materialize($idShop);

        if ($this->repository->pages($idShop) === []) {
            return $this->fail($this->t('Add at least one page to test.'), 400);
        }

        $idJob = $this->jobs->start($idShop, CheckJob::TYPE, ['source' => 'panel']);

        return $this->ok(['job' => $this->jobs->status($idJob)->toArray()]);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function pageSaveAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_psi_page_save');

        $idShop = $this->idShop();
        $data = $this->payload($request);
        $url = PageSpeedScreen::normalizeUrl((string) ($data['url'] ?? ''), ShopContext::baseUrl($idShop));

        if ($url === null) {
            return $this->fail($this->t('Enter the address of a page of this shop.'), 400);
        }

        $this->repository->materialize($idShop);

        foreach ($this->repository->pages($idShop) as $page) {
            if ($page['url'] === $url) {
                return $this->fail($this->t('This page is already on the list.'), 400);
            }
        }

        $id = $this->repository->add($idShop, 'Custom page', $url);

        if ($id === 0) {
            return $this->fail($this->t('Up to %max% pages can be tested.', ['%max%' => PsiRepository::MAX_PAGES]), 400);
        }

        return $this->ok([
            'message' => $this->t('Added — tested at the next check'),
            'pages' => $this->pagesForScreen($idShop),
        ]);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function pageDeleteAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_psi_page_delete');

        $idShop = $this->idShop();

        if (!$this->repository->delete($idShop, (int) ($this->payload($request)['id'] ?? 0))) {
            return $this->fail($this->t('This page is no longer on the list.'), 404);
        }

        return $this->ok(['pages' => $this->pagesForScreen($idShop)]);
    }

    /** Harmonogram: weekly | daily | manual. */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function saveAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_psi_save');

        $schedule = (string) ($this->payload($request)['schedule'] ?? '');

        if (!in_array($schedule, PageSpeedOptions::SCHEDULES, true)) {
            return $this->fail($this->t('Choose how often to check.'), 400);
        }

        Options::set(PageSpeedOptions::SCHEDULE, $schedule, $this->idShop());

        return $this->ok(['message' => $this->t('Saved')]);
    }

    /** @return array<int,array<string,mixed>> */
    private function pagesForScreen(int $idShop): array
    {
        return $this->screen->data($idShop)['pages'];
    }
}
