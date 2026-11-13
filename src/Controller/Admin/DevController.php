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

use PrestaShopBundle\Security\Attribute\AdminSecurity;
use Quince\Insight\Core\Dev;
use Quince\Insight\Core\Google\ConnectionRepository;
use Quince\Insight\Core\Google\OAuthFlow;
use Quince\Insight\Core\Google\Scopes;
use Quince\Insight\Core\JobRunner;
use Quince\Insight\Core\Scheduler;
use Quince\Insight\Core\Services;
use Quince\Insight\Ui\PageTabs;
use Quince\Insight\Ui\Pagination;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Ekran deweloperski: wzorzec uzycia wspolnych elementow panelu i sprawdzian rdzenia.
 *
 * Istnieje tylko przy pliku `config/dev.php` — bez niego kazda akcja odpowiada 404,
 * a w paczce wydania pliku nie ma. Nie ma go w bocznym pasku.
 *
 * Zakladki: Components (zakladki, stopka listy, alerty, stan pusty, ikony),
 * Background job (zadanie `core.demo` przez `QI.job`), Google sign-in (przeplyw OAuth
 * w trybie atrap), Cron (adresy grup biezacego sklepu).
 */
final class DevController extends PanelController
{
    private const TABS = [
        'components' => 'Components',
        'job' => 'Background job',
        'oauth' => 'Google sign-in',
        'cron' => 'Cron',
    ];

    public function __construct(
        private readonly PageTabs $tabs,
        private readonly Pagination $pagination,
        private readonly JobRunner $jobs,
        private readonly ConnectionRepository $connections,
        private readonly OAuthFlow $flow,
        private readonly Scheduler $scheduler,
        private readonly Services $services,
    ) {
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", message: 'You do not have permission to view this.')]
    public function indexAction(Request $request): Response
    {
        $this->guardDev();

        $idShop = $this->idShop();
        $tabs = $this->tabs->resolve($request, self::TABS);
        $data = ['tabs' => $tabs, 'tab' => $tabs['current'], 'layoutTitle' => 'Developer tools'];

        if ($tabs['current'] === 'components') {
            // Lista przykladowa: 137 pozycji liczonych w locie, zeby pokazac stopke i `?page=`.
            $pagination = $this->pagination->resolve($request, 137);
            $rows = [];

            for ($n = $pagination['from']; $n <= $pagination['to'] && $n > 0; ++$n) {
                $rows[] = ['n' => $n, 'name' => 'Row ' . $n];
            }

            $data += [
                'pagination' => $pagination,
                'rows' => $rows,
                'serviceKeys' => Services::KEYS,
                'enabled' => $this->services->enabledKeys($idShop),
            ];
        }

        if ($tabs['current'] === 'job') {
            $job = $this->jobs->last($idShop, 'core.demo');

            $data += ['job' => $job ? $job->toArray() : null];
        }

        if ($tabs['current'] === 'oauth') {
            $connection = $this->connections->forShop($idShop);

            $data += [
                'connection' => $connection,
                'redirectUri' => $this->flow->redirectUri(),
                'oauthResult' => (string) $request->query->get(OAuthFlow::RESULT_PARAM, ''),
                'missing' => $connection ? $this->connections->missingFor($connection->id, ['ga4', 'gtm', 'gmc', 'gsc']) : [],
                'denyScope' => Dev::denyScope(),
                'fake' => Dev::fakeGoogle(),
            ];
        }

        if ($tabs['current'] === 'cron') {
            $groups = [];

            foreach (array_merge(array_keys(Scheduler::GROUPS), [Scheduler::GROUP_ALL]) as $group) {
                $groups[] = [
                    'key' => $group,
                    'url' => $this->scheduler->url($idShop, $group),
                    'state' => $this->scheduler->groupState($idShop, $group),
                ];
            }

            $data += ['groups' => $groups, 'needed' => $this->scheduler->groupsFor($idShop)];
        }

        return $this->renderPanel('dev/index.html.twig', 'dev', $data);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function jobStartAction(Request $request): JsonResponse
    {
        $this->guardDev();
        $this->guardCsrf($request, 'quince_insight_dev_job_start');

        $input = $this->payload($request);

        $id = $this->jobs->start($this->idShop(), 'core.demo', [
            'steps' => (int) ($input['steps'] ?? 6),
            'wait' => (int) ($input['wait'] ?? 0),
            'fail_at' => (int) ($input['fail_at'] ?? 0),
        ]);

        return $this->ok(['job' => $this->jobs->status($id)->toArray()]);
    }

    /**
     * Start logowania do Google. W trybie atrap przegladarka idzie od razu na adres zwrotny
     * (kontroler frontowy `oauth`) i wraca tutaj z wynikiem w parametrze `qi_oauth`.
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function oauthStartAction(Request $request): RedirectResponse
    {
        $this->guardDev();

        if (!$this->csrfValid($request, 'quince_insight_dev_oauth_start')) {
            $this->addFlash('error', $this->t('Invalid security token, please try again.'));

            return $this->redirectToRoute('quince_insight_dev', ['tab' => 'oauth']);
        }

        if (!Dev::fakeGoogle()) {
            $this->addFlash('error', 'This button works only in fake Google mode.');

            return $this->redirectToRoute('quince_insight_dev', ['tab' => 'oauth']);
        }

        $idShop = $this->idShop();
        $connection = $this->connections->forShop($idShop);

        // Dane projektu Cloud wpisuje kreator; tu wystarcza wartosci umowne, bo w trybie
        // atrap nic nie idzie do Google.
        $idConnection = $connection !== null
            ? $connection->id
            : $this->connections->saveCredentials(null, '', 'fake-client-id.apps.googleusercontent.com', 'fake-client-secret', 'fake-api-key');

        $returnUrl = $this->generateUrl('quince_insight_dev', ['tab' => 'oauth'], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->redirect($this->flow->authUrl(
            $idConnection,
            $idShop,
            (int) $this->getEmployeeContext()->getEmployee()?->getId(),
            Scopes::forServices(['ga4', 'gtm', 'gmc', 'gsc']),
            $returnUrl
        ));
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function oauthDisconnectAction(Request $request): RedirectResponse
    {
        $this->guardDev();

        if ($this->csrfValid($request, 'quince_insight_dev_oauth_disconnect')) {
            $connection = $this->connections->forShop($this->idShop());

            if ($connection !== null) {
                $this->connections->disconnect($connection->id);
                $this->addFlash('success', 'Google account disconnected.');
            }
        }

        return $this->redirectToRoute('quince_insight_dev', ['tab' => 'oauth']);
    }

    /** Wlacza albo wylacza usluge w biezacym sklepie — zeby zobaczyc wyszarzona pozycje paska. */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function serviceToggleAction(Request $request): JsonResponse
    {
        $this->guardDev();
        $this->guardCsrf($request, 'quince_insight_dev_service_toggle');

        $input = $this->payload($request);
        $key = (string) ($input['key'] ?? '');

        if (!in_array($key, Services::KEYS, true)) {
            return $this->fail('Unknown service.');
        }

        $this->services->setEnabled($this->idShop(), $key, (bool) ($input['on'] ?? false));

        return $this->ok(['enabled' => $this->services->enabledKeys($this->idShop())]);
    }

    private function guardDev(): void
    {
        if (!Dev::enabled()) {
            throw new NotFoundHttpException();
        }
    }
}
