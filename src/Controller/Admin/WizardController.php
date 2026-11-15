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
use Quince\Insight\Core\Google\ConnectionRepository;
use Quince\Insight\Core\Google\Dto\Connection;
use Quince\Insight\Core\Google\NotConnectedException;
use Quince\Insight\Core\Google\OAuthFlow;
use Quince\Insight\Core\Google\Scopes;
use Quince\Insight\Core\Log;
use Quince\Insight\Core\ServiceRegistry;
use Quince\Insight\Core\Services;
use Quince\Insight\Core\ShopContext;
use Quince\Insight\Core\ShopRepository;
use Quince\Insight\Service\Verification\Verifier;
use Quince\Insight\Service\Verification\VerificationState;
use Quince\Insight\Wizard\CronBlock;
use Quince\Insight\Wizard\SettingsCopier;
use Quince\Insight\Wizard\WizardFlow;
use Quince\Insight\Wizard\WizardRun;
use Quince\Insight\Wizard\WizardState;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Kreator: powloka (pasek krokow, przelacznik sklepu, Back / Skip / Continue), kroki
 * rdzenia (Welcome, Shops, Services, Google Cloud project, Google account, Summary)
 * i osadzanie krokow uslug (`WizardStep` + `wizard/steps/<key>.html.twig`).
 *
 * Zapisy krokow: formularz POST -> flash -> przekierowanie (ARCHITEKTURA §5.1). Szablon
 * kroku uslugi jest renderowany wewnatrz formularza `#qi-wizard-form`; "Continue" wysyla
 * jego pola do `WizardStep::save([$sklep], $pola)`, "Use the same for all shops" — do
 * `save($wszystkieSklepyPrzejscia, $pola)`.
 */
final class WizardController extends PanelController
{
    /** Ekran panelu, na ktory wraca tryb jednej uslugi (prototyp `returnToPanel`). */
    private const RETURN_ROUTES = [
        'ga4' => 'quince_insight_analytics',
        'ads' => 'quince_insight_events',
        'gtm' => 'quince_insight_events',
        'gcr' => 'quince_insight_events',
        'gmc' => 'quince_insight_merchant',
        'gsc' => 'quince_insight_gsc',
        'cloud' => 'quince_insight_psi',
        'verify' => 'quince_insight_settings',
    ];

    public function __construct(
        private readonly WizardState $state,
        private readonly WizardRun $runs,
        private readonly ShopRepository $shops,
        private readonly Services $services,
        private readonly ConnectionRepository $connections,
        private readonly OAuthFlow $flow,
        private readonly ServiceRegistry $registry,
        private readonly CronBlock $cron,
        private readonly Verifier $verifier,
        private readonly SettingsCopier $copier,
    ) {
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", message: 'You do not have permission to view this.')]
    public function indexAction(Request $request): Response
    {
        $ctx = $this->idShop();
        $w = $this->state->build($ctx, (string) $request->query->get('step', ''), (int) $request->query->get('shop', 0));

        // Kontekst "wszystkie sklepy" (albo grupa): zawsze krok Shops, bez sklepu z gory (decyzja #23).
        if ($this->allShopsPick()) {
            $w = $this->allShopsState($w);
        }

        $step = $w['current'];

        $data = [
            'layoutTitle' => 'Setup',
            'wizard' => $this->shell($w),
            'stepKey' => $step,
            'formShop' => $w['formShop'],
            'errors' => $this->takeErrors($request),
            'oauthResult' => (string) $request->query->get(OAuthFlow::RESULT_PARAM, ''),
        ];

        $data += $this->stepData($w, $step);

        return $this->renderPanel('wizard/index.html.twig', 'wizard', $data);
    }

    /** Zapis kroku (formularz). Bledy: flash + powrot do kroku; sukces: kolejny sklep albo krok. */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function saveAction(Request $request): Response
    {
        if (!$this->csrfValid($request, 'quince_insight_wizard_save')) {
            $this->addFlash('error', $this->t('Invalid security token, please try again.'));

            return $this->redirectToRoute('quince_insight_wizard');
        }

        $ctx = $this->idShop();
        $step = (string) $request->request->get('_step', '');
        $w = $this->state->build($ctx, $step, (int) $request->request->get('_shop', 0));

        if ($w['current'] !== $step) {
            return $this->redirectToRoute('quince_insight_wizard');
        }

        $formShop = $w['formShop'];
        $input = $this->stepInput($request);
        $same = $request->request->getBoolean('_same');

        try {
            $errors = match ($step) {
                'welcome' => [],
                'services' => $this->saveServices($w, $input),
                'cloud' => $this->saveCloud($w, $input),
                'connect' => $this->saveConnect($w),
                default => $this->saveServiceStep($w, $step, $same ? $w['runShops'] : [$formShop], $input),
            };
        } catch (\Throwable $e) {
            Log::write($ctx, 'core', Log::ERROR, 'Wizard step ' . $step . ' failed to save: ' . $e->getMessage(), [
                'file' => basename($e->getFile()) . ':' . $e->getLine(),
            ]);
            $errors = ['_step' => $this->googleError($e)];
        }

        if ($errors !== []) {
            return $this->backWithErrors($request, $step, $formShop, $errors);
        }

        $touched = in_array($step, WizardFlow::SWITCHER_STEPS, true) && !$same ? [$formShop] : $w['runShops'];

        foreach ($touched as $idShop) {
            $this->shops->setStepState($idShop, $step, 'done');
        }

        // Jedna usluga: zapis wlacza usluge i wraca do panelu (prototyp "Save and return to panel").
        if ($w['single'] !== '') {
            $service = WizardFlow::serviceOf($step);

            if ($service !== '') {
                $this->services->setEnabled($ctx, $service, true);
            }

            $this->addFlash('success', $this->t('Saved'));

            return $this->redirectToRoute(self::RETURN_ROUTES[$step] ?? 'quince_insight_settings');
        }

        if ($same) {
            $this->addFlash('success', $this->t('Copied to all %count% shops', ['%count%' => count($w['runShops'])]));

            return $this->redirectToRoute('quince_insight_wizard', ['step' => $step, 'shop' => $formShop]);
        }

        // Kilka sklepow: krok jest gotowy, gdy kazdy sklep ma zapisany wybor.
        if (in_array($step, WizardFlow::SWITCHER_STEPS, true)) {
            foreach ($w['runShops'] as $idShop) {
                if ($idShop !== $formShop && !in_array($this->state->shopStepState($idShop, $step), ['done', 'skipped'], true)) {
                    return $this->redirectToRoute('quince_insight_wizard', ['step' => $step, 'shop' => $idShop]);
                }
            }
        }

        return $this->goNext($ctx, $step);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function skipAction(Request $request): Response
    {
        if (!$this->csrfValid($request, 'quince_insight_wizard_skip')) {
            return $this->redirectToRoute('quince_insight_wizard');
        }

        $ctx = $this->idShop();
        $step = (string) $request->request->get('_step', '');
        $w = $this->state->build($ctx, $step);

        if ($w['current'] !== $step || !in_array($step, WizardFlow::OPTIONAL, true) || $w['single'] !== '') {
            return $this->redirectToRoute('quince_insight_wizard');
        }

        foreach ($w['runShops'] as $idShop) {
            $this->shops->setStepState($idShop, $step, 'skipped');
        }

        return $this->goNext($ctx, $step);
    }

    /** "Open dashboard" w Summary: sklepy przejscia sa skonfigurowane. */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function finishAction(Request $request): Response
    {
        if (!$this->csrfValid($request, 'quince_insight_wizard_finish')) {
            return $this->redirectToRoute('quince_insight_wizard');
        }

        $ctx = $this->idShop();
        $w = $this->state->build($ctx, 'summary');

        if ($w['current'] !== 'summary') {
            return $this->redirectToRoute('quince_insight_wizard');
        }

        foreach ($w['runShops'] as $idShop) {
            $this->shops->setStepState($idShop, 'summary', 'done');
            $this->shops->setWizardDone($idShop, true);
            $this->shops->setWizardStep($idShop, 'summary');
        }

        $this->runs->clear($w['runShops']);
        Log::write($ctx, 'core', Log::INFO, 'Setup finished.', ['shops' => $w['runShops']]);

        $this->addFlash('success', $this->t('Setup complete'));

        return $this->redirectToRoute('quince_insight_overview');
    }

    /** "Run again" (Settings): nowe przejscie, ustawienia zostaja jako punkt wyjscia. */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function restartAction(Request $request): Response
    {
        if (!$this->csrfValid($request, 'quince_insight_wizard_restart')) {
            return $this->redirectToRoute('quince_insight_settings');
        }

        $ctx = $this->idShop();
        $old = $this->runs->load($ctx);
        $this->runs->clear($old['shops']);
        $this->runs->save(['shops' => [$ctx], 'mode' => 'one', 'conn' => '', 'started' => true, 'furthest' => '']);
        $this->shops->setWizardStep($ctx, '');

        return $this->redirectToRoute('quince_insight_wizard');
    }

    /** Krok Shops: "Only this shop" albo "Several shops at once" z lista sklepow. */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function shopsAction(Request $request): Response
    {
        if (!$this->csrfValid($request, 'quince_insight_wizard_shops')) {
            return $this->redirectToRoute('quince_insight_wizard');
        }

        $ctx = $this->idShop();
        $mode = (string) $request->request->get('mode', 'one') === 'several' ? 'several' : 'one';

        if ($this->allShopsPick()) {
            return $this->shopsFromAllShops($request, $mode);
        }

        $w = $this->state->build($ctx, 'shops');

        if ($w['current'] !== 'shops') {
            return $this->redirectToRoute('quince_insight_wizard');
        }

        $picked = [$ctx];

        if ($mode === 'several') {
            foreach ((array) $request->request->all('pick') as $id => $on) {
                $id = (int) $id;

                // Tylko sklepy, ktorymi pracownik moze zarzadzac: inaczej zmienilby konfiguracje Google cudzego sklepu.
                if ($on && $id !== $ctx && ShopContext::exists($id) && ShopContext::employeeMayManage($id) && $this->pickable($ctx, $id)) {
                    $picked[] = $id;
                }
            }
        }

        $run = $this->runs->withShops($w['run'], $picked, $mode);
        $run['furthest'] = WizardFlow::further($run['furthest'], 'shops');
        $this->runs->save($run);

        // Sklepy dolaczone do przejscia dostaja to, co juz za nimi (powitanie, wybor sklepow).
        foreach ($run['shops'] as $idShop) {
            $this->shops->setStepState($idShop, 'welcome', 'done');
            $this->shops->setStepState($idShop, 'shops', 'done');
        }

        return $this->goNext($ctx, 'shops');
    }

    /** "Check" w kroku Google Cloud: sprawdzenie formy wpisanych danych (bez zapisu). */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function cloudCheckAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_cloud_check');

        $input = $this->payload($request);
        $ctx = $this->idShop();
        $connection = $this->cloudConnection($this->state->build($ctx, 'cloud'), (string) ($input['conn'] ?? ''));

        $errors = WizardFlow::checkCredentials(
            (string) ($input['client_id'] ?? ''),
            (string) ($input['client_secret'] ?? ''),
            (string) ($input['api_key'] ?? ''),
            (bool) ($input['psi'] ?? false),
            $connection !== null && $connection->hasSecret,
            $connection !== null && $connection->hasApiKey,
        );

        if ($errors !== []) {
            return $this->fail($this->t((string) reset($errors)), 400, ['field' => (string) array_key_first($errors)]);
        }

        return $this->ok();
    }

    /**
     * "Sign in with Google" / "Grant missing permissions" / "Grant permission".
     * Formularz POST -> przekierowanie do Google; powrot na krok z `qi_oauth=...`.
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function connectStartAction(Request $request): RedirectResponse
    {
        $return = (string) $request->request->get('return', 'connect');

        if (!$this->csrfValid($request, 'quince_insight_connect_start')) {
            $this->addFlash('error', $this->t('Invalid security token, please try again.'));

            return $this->redirectToRoute('quince_insight_wizard');
        }

        $ctx = $this->idShop();
        $connection = $this->connections->forShop($ctx);

        if ($connection === null || !$connection->hasCredentials()) {
            $this->addFlash('error', $this->t('Enter the Google Cloud project details first.'));

            return $this->redirectToRoute('quince_insight_wizard', ['step' => 'cloud']);
        }

        $keys = $this->services->enabledKeys($ctx);
        $service = WizardFlow::serviceOf($return);

        if ($service !== '' && !in_array($service, $keys, true)) {
            $keys[] = $service;
        }

        if ($return === 'settings') {
            $returnUrl = $this->generateUrl('quince_insight_settings', [], UrlGeneratorInterface::ABSOLUTE_URL);
        } else {
            $step = isset(WizardFlow::STEPS[$return]) ? $return : 'connect';
            $returnUrl = $this->generateUrl('quince_insight_wizard', ['step' => $step], UrlGeneratorInterface::ABSOLUTE_URL);
        }

        try {
            $url = $this->flow->authUrl(
                $connection->id,
                $ctx,
                (int) $this->getEmployeeContext()->getEmployee()?->getId(),
                Scopes::forServices($keys),
                $returnUrl
            );
        } catch (NotConnectedException $e) {
            $this->addFlash('error', $this->t('Enter the Google Cloud project details first.'));

            return $this->redirectToRoute('quince_insight_wizard', ['step' => 'cloud']);
        }

        return $this->redirect($url);
    }

    /** "Disconnect" (Settings): cofa dostep w Google i usuwa tokeny; ustawienia zostaja. */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function connectDisconnectAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_connect_disconnect');

        $input = $this->payload($request);
        $id = (int) ($input['id'] ?? 0);
        $connection = $id > 0 ? $this->connections->find($id) : $this->connections->forShop($this->idShop());

        if ($connection === null) {
            return $this->fail($this->t('There is no Google connection to disconnect.'), 404);
        }

        // Polaczenie wspolne kilku sklepow: tylko gdy uzywa go sklep z kontekstu pracownika (R-07).
        if (!$this->connectionInContext($connection->id)) {
            return $this->fail($this->t('You do not have permission to change this.'), 403);
        }

        $this->connections->disconnect($connection->id);
        Log::write($this->idShop(), 'core', Log::INFO, 'Google account disconnected.', ['id_connection' => $connection->id]);

        return $this->ok(['message' => $this->t('Google account disconnected. Settings are kept.')]);
    }

    /** Sprawdzenie weryfikacji (wejscie w krok, "Check again"). */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function verifyCheckAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_verify_check');

        $input = $this->payload($request);
        $idShop = $this->verifyShop($input);
        $manual = ($input['mode'] ?? '') === 'manual';

        try {
            $result = $this->verifier->check($idShop);
        } catch (\Throwable $e) {
            return $this->fail($this->googleError($e));
        }

        $state = match ($result['state']) {
            VerificationState::VERIFIED, VerificationState::MANUAL => 'verified',
            default => $manual ? 'still' : 'unverified',
        };

        return $this->ok(['state' => $state, 'method' => $result['state'] === VerificationState::MANUAL ? 'manual' : 'meta']);
    }

    /** "Add tag and verify": wstawia tag (po zgodzie) i prosi Google o weryfikacje. */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function verifyStartAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_verify_start');

        $input = $this->payload($request);

        if (empty($input['confirm'])) {
            return $this->fail($this->t('Switch on "Add this tag to the shop" first.'));
        }

        $idShop = $this->verifyShop($input);

        try {
            $result = $this->verifier->start($idShop);
        } catch (\Throwable $e) {
            return $this->fail($this->googleError($e));
        }

        if ($result['state'] === VerificationState::VERIFIED) {
            $this->shops->setStepState($idShop, 'verify', 'done');
        }

        return $this->ok(['state' => $result['state'] === VerificationState::VERIFIED ? 'verified' : 'error']);
    }

    /** "Remove": tag znika ze strony sklepu, potem wlasnosc w Google. */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function verifyRemoveAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_verify_remove');

        $idShop = $this->verifyShop($this->payload($request));
        $result = $this->verifier->remove($idShop);
        $this->shops->setStepState($idShop, 'verify', 'todo');

        return $this->ok([
            'state' => 'unverified',
            'message' => $result['google']
                ? $this->t('The tag was removed from the shop and the verification from Google.')
                : $this->t('The tag was removed from the shop. Google still lists the site as verified — remove it in Search Console if you want to.'),
        ]);
    }

    // ------------------------------------------------------------------
    // Zapis krokow
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $w
     * @param array<string,mixed> $input
     *
     * @return array<string,string>
     */
    private function saveServices(array $w, array $input): array
    {
        $picked = array_keys(array_filter((array) ($input['svc'] ?? []), static fn ($v): bool => (string) $v === '1'));
        $picked = array_values(array_intersect(Services::KEYS, $picked));

        if ($picked === []) {
            return ['svc' => $this->t('Pick at least one service.')];
        }

        foreach ($w['runShops'] as $idShop) {
            foreach (Services::KEYS as $key) {
                $was = $this->services->enabled($idShop, $key);
                $on = in_array($key, $picked, true);

                if ($was && !$on) {
                    $this->services->setEnabled($idShop, $key, false);
                    $this->disableModules($idShop, $key);
                }
            }

            // Wlaczenie po wylaczeniach: zaleznosci (ads -> gtm, gcr -> gmc) wygrywaja.
            foreach ($picked as $key) {
                $this->services->setEnabled($idShop, $key, true);
            }
        }

        $from = (int) ($input['copy_from'] ?? 0);

        if ($w['adding'] && $from > 0 && in_array($from, $w['otherDone'], true)) {
            foreach ($w['runShops'] as $idShop) {
                $this->copier->copy($from, $idShop);
            }
        }

        return [];
    }

    /** Usluga wylaczona w sklepie: sprzatanie po stronie uslug, ktore od niej zaleza (bez Google). */
    private function disableModules(int $idShop, string $key): void
    {
        foreach ($this->registry->all() as $module) {
            if ($module->requires() !== $key) {
                continue;
            }

            try {
                $module->disable($idShop);
            } catch (\Throwable $e) {
                Log::write($idShop, $module->key(), Log::ERROR, 'disable() failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * @param array<string,mixed> $w
     * @param array<string,mixed> $input
     *
     * @return array<string,string>
     */
    private function saveCloud(array $w, array $input): array
    {
        $choice = (string) ($input['conn'] ?? '');
        $run = $w['run'];

        // Kolejny sklep na istniejacym polaczeniu: nic do wpisania w Google Cloud.
        if ($w['adding'] && ctype_digit($choice) && $choice !== '0') {
            $connection = $this->connections->find((int) $choice);

            // Numer z formularza: bez sprawdzenia pracownik podpialby sklep pod tokeny cudzego sklepu.
            if ($connection === null || !$this->connectionUsable($connection->id)) {
                return ['conn' => $this->t('Choose a connection.')];
            }

            foreach ($w['runShops'] as $idShop) {
                $this->connections->assignShop($idShop, $connection->id);
            }

            $run['conn'] = (string) $connection->id;
            $this->runs->save($run);

            // Psi w tym sklepie potrzebuje klucza API polaczenia.
            if (in_array('psi', $w['services'], true) && !$connection->hasApiKey) {
                return ['api_key' => $this->t('This connection has no API key. Page speed needs one — connect another Google Cloud project or turn Page speed off.')];
            }

            return [];
        }

        $connection = $this->cloudConnection($w, $choice);
        $clientId = trim((string) ($input['client_id'] ?? ''));
        $secret = trim((string) ($input['client_secret'] ?? ''));
        $apiKey = trim((string) ($input['api_key'] ?? ''));
        $needsKey = in_array('psi', $w['services'], true);

        $errors = WizardFlow::checkCredentials($clientId, $secret, $apiKey, $needsKey, $connection !== null && $connection->hasSecret, $connection !== null && $connection->hasApiKey);

        if ($errors !== []) {
            return array_map(fn (string $m): string => $this->t($m), $errors);
        }

        $id = $this->connections->saveCredentials($connection?->id, $connection?->label ?? '', $clientId, $secret, $apiKey);
        $this->connections->setProductionConfirmed($id, ((string) ($input['production'] ?? '0')) === '1');

        foreach ($w['runShops'] as $idShop) {
            $this->connections->assignShop($idShop, $id);
        }

        if ($w['adding']) {
            $run['conn'] = 'new';
            $this->runs->save($run);
        }

        return [];
    }

    /**
     * Polaczenie, ktore edytuje formularz Google Cloud. Kolejny sklep z "Connect another Google
     * Cloud project" dostaje nowe polaczenie, chyba ze juz ma wlasne (niewspoldzielone).
     *
     * @param array<string,mixed> $w
     */
    private function cloudConnection(array $w, string $choice): ?Connection
    {
        $ctx = (int) $w['ctx'];
        $connection = $this->connections->forShop($ctx);

        if ($w['adding']) {
            if ($connection !== null && array_diff($this->shops->shopsOfConnection($connection->id), $w['runShops']) === []) {
                return $connection;
            }

            return null;
        }

        if ($connection !== null) {
            return $connection;
        }

        // Pierwsza konfiguracja po odlaczeniu albo polaczenie bez sklepu: to samo polaczenie,
        // o ile pracownik moze je ruszac; inaczej formularz zalozy nowe.
        $first = $this->connections->all()[0] ?? null;

        return $first !== null && $this->connectionUsable($first->id) ? $first : null;
    }

    /**
     * Czy kreator moze podpiac sklep pod to polaczenie: uzywa go sklep z kontekstu albo sklep,
     * ktorym pracownik zarzadza. Polaczenie bez sklepow tylko dla pracownika wszystkich sklepow.
     * Sam `connectionInContext()` nie wystarcza: w kontekscie jednego sklepu odrzucilby
     * polaczenie innego sklepu tego samego pracownika, a na tym stoi dodawanie sklepu.
     */
    private function connectionUsable(int $idConnection): bool
    {
        if ($this->connectionInContext($idConnection)) {
            return true;
        }

        $users = $this->shops->shopsOfConnection($idConnection);

        if ($users === []) {
            $users = array_keys(ShopContext::shops());

            return array_filter($users, static fn (int $id): bool => !ShopContext::employeeMayManage($id)) === [];
        }

        foreach ($users as $idShop) {
            if (ShopContext::employeeMayManage((int) $idShop)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $w
     *
     * @return array<string,string>
     */
    private function saveConnect(array $w): array
    {
        $connection = $this->connections->forShop((int) $w['ctx']);

        if ($connection === null || !$connection->isConnected()) {
            return ['connect' => $this->t('Sign in with Google first.')];
        }

        foreach ($w['runShops'] as $idShop) {
            $this->connections->assignShop($idShop, $connection->id);
        }

        return [];
    }

    /**
     * @param array<string,mixed> $w
     * @param int[] $shopIds
     * @param array<string,mixed> $input
     *
     * @return array<string,string>
     */
    private function saveServiceStep(array $w, string $step, array $shopIds, array $input): array
    {
        if ($this->needsGrant((int) $w['formShop'], $step)) {
            return ['_grant' => $this->t('Permission not granted.')];
        }

        $owner = $this->registry->wizardStep($step);

        if ($owner === null) {
            return [];
        }

        return array_map('strval', $owner->save($shopIds, $input));
    }

    /** @param array<string,string> $query dodatkowe parametry adresu (np. `setShopContext`) */
    private function goNext(int $ctx, string $step, array $query = []): RedirectResponse
    {
        $w = $this->state->build($ctx, $step);
        $next = WizardFlow::next($step, $w['active']) ?? 'summary';
        $run = $w['run'];
        $run['furthest'] = WizardFlow::further($run['furthest'], $next);
        $this->runs->save($run);

        foreach ($w['runShops'] as $idShop) {
            $this->shops->setWizardStep($idShop, $next);
        }

        return $this->redirectToRoute('quince_insight_wizard', ['step' => $next] + $query);
    }

    /** Kreator otwarty bez jednego sklepu w kontekscie ("wszystkie sklepy" albo grupa). */
    private function allShopsPick(): bool
    {
        return ShopContext::isMultistore() && ShopContext::current() === null;
    }

    /**
     * Stan kreatora w kontekscie "wszystkie sklepy": tylko krok Shops, zaden inny krok nie
     * jest jeszcze dostepny, zaden sklep nie jest zaznaczony.
     *
     * @param array<string,mixed> $w
     *
     * @return array<string,mixed>
     */
    private function allShopsState(array $w): array
    {
        $w['current'] = 'shops';
        $w['single'] = '';
        $w['adding'] = false;
        $w['services'] = [];
        $w['active'] = WizardFlow::activeSteps([], true, false, false);
        $w['states'] = array_fill_keys(array_keys(WizardFlow::STEPS), 'todo');
        $w['furthest'] = '';
        $w['runShops'] = [];
        $w['run']['mode'] = 'one';
        $w['allShops'] = true;

        return $w;
    }

    /**
     * Krok Shops wyslany w kontekscie "wszystkie sklepy": przejscie zaczyna sie od pierwszego
     * wybranego sklepu, a kreator przelacza kontekst PrestaShop na ten sklep.
     */
    private function shopsFromAllShops(Request $request, string $mode): RedirectResponse
    {
        $candidates = array_values(array_filter(
            $this->contextShopIds(),
            static fn (int $id): bool => ShopContext::exists($id) && ShopContext::employeeMayManage($id)
        ));

        $picked = WizardFlow::pickedShops($mode, (int) $request->request->get('one', 0), (array) $request->request->all('pick'), $candidates);

        if ($picked === []) {
            $this->addFlash('error', $mode === 'several' ? $this->t('Choose the shops to set up.') : $this->t('Choose the shop to set up.'));

            return $this->redirectToRoute('quince_insight_wizard');
        }

        if (!WizardFlow::sameProject(array_map(fn (int $id): int => $this->shops->connectionId($id), $picked))) {
            $this->addFlash('error', $this->t('These shops use different Google Cloud projects. Set them up separately.'));

            return $this->redirectToRoute('quince_insight_wizard');
        }

        $first = $picked[0];
        $run = $this->runs->withShops($this->runs->load($first), $picked, $mode);
        $run['furthest'] = WizardFlow::further($run['furthest'], 'shops');
        $this->runs->save($run);

        foreach ($run['shops'] as $idShop) {
            $this->shops->setStepState($idShop, 'welcome', 'done');
            $this->shops->setStepState($idShop, 'shops', 'done');
        }

        return $this->goNext($first, 'shops', ['setShopContext' => 's-' . $first]);
    }

    /** @param array<string,string> $errors */
    private function backWithErrors(Request $request, string $step, int $shop, array $errors): RedirectResponse
    {
        $request->getSession()->set('qi_wizard_errors', ['step' => $step, 'errors' => $errors]);

        foreach (array_unique(array_values($errors)) as $message) {
            $this->addFlash('error', $message);
        }

        return $this->redirectToRoute('quince_insight_wizard', ['step' => $step, 'shop' => $shop]);
    }

    /** @return array<string,string> */
    private function takeErrors(Request $request): array
    {
        if (!$request->hasSession()) {
            return [];
        }

        $stored = $request->getSession()->remove('qi_wizard_errors');

        return is_array($stored) && is_array($stored['errors'] ?? null) ? $stored['errors'] : [];
    }

    /** @return array<string,mixed> pola formularza bez pol powloki (`_csrf_token`, `_step`, ...) */
    private function stepInput(Request $request): array
    {
        $input = $this->payload($request);

        foreach (array_keys($input) as $key) {
            if (str_starts_with((string) $key, '_')) {
                unset($input[$key]);
            }
        }

        return $input;
    }

    /** Sklep akcji weryfikacji: z przejscia kreatora albo biezacy. @param array<string,mixed> $input */
    private function verifyShop(array $input): int
    {
        $ctx = $this->idShop();
        $id = (int) ($input['shop'] ?? 0);
        $run = $this->runs->load($ctx);

        return $id > 0 && in_array($id, $run['shops'], true) ? $id : $ctx;
    }

    /** Sklep moze dolaczyc do przejscia "several": bez innego projektu Google Cloud. */
    private function pickable(int $ctx, int $idShop): bool
    {
        $own = $this->shops->connectionId($idShop);
        $base = $this->shops->connectionId($ctx);

        return $own === 0 || $base === 0 || $own === $base;
    }

    private function needsGrant(int $idShop, string $step): bool
    {
        if (!in_array($step, WizardFlow::GRANT_STEPS, true)) {
            return false;
        }

        $connection = $this->connections->forShop($idShop);

        return $connection === null || !$connection->isConnected()
            || $this->connections->missingFor($connection->id, [$step]) !== [];
    }

    // ------------------------------------------------------------------
    // Dane widoku
    // ------------------------------------------------------------------

    /**
     * Pasek krokow, przelacznik sklepu, przyciski — wszystko, co powloka rysuje wokol kroku.
     *
     * @param array<string,mixed> $w
     *
     * @return array<string,mixed>
     */
    private function shell(array $w): array
    {
        $current = $w['current'];
        $active = $w['navActive'] ?? $w['active'];
        $steps = [];
        // Linia laczaca kroki: odcinek w kolorze akcentu prowadzi od zrobionego kroku do nastepnego.
        $prevDone = false;

        foreach (WizardFlow::STEPS as $key => $label) {
            $isActive = in_array($key, $active, true);
            $isDone = $isActive && $key !== $current && ($w['states'][$key] ?? '') === 'done';
            $lineIn = $prevDone;
            $lineOut = $isDone || (!$isActive && $prevDone);

            if ($isActive) {
                $prevDone = $isDone;
            }

            $steps[] = [
                'key' => $key,
                'label' => $label,
                'active' => $isActive,
                'num' => WizardFlow::number($key, $active),
                // "wszystkie sklepy": uslugi jeszcze niewybrane — bez znacznika "Off".
                'state' => !$isActive ? (empty($w['allShops']) ? 'off' : 'todo') : ($key === $current ? 'current' : $w['states'][$key]),
                // Przy zmianie jednej uslugi pasek tylko pokazuje stan kreatora — przejscie do
                // innego kroku konczy sie w Settings ("Change"/"Connect"), nie tutaj.
                'canGo' => $w['single'] === '' && $key !== $current && empty($w['allShops']) && WizardFlow::canGo($key, $active, $w['furthest'], $w['states']),
                'current' => $key === $current,
                'lineIn' => $lineIn,
                'lineOut' => $lineOut,
            ];
        }

        $runShops = [];

        foreach ($w['runShops'] as $idShop) {
            $runShops[] = [
                'id' => $idShop,
                'name' => ShopContext::name($idShop),
                'url' => ShopContext::baseUrl($idShop),
                'state' => $w['perShop'][$current][$idShop] ?? 'todo',
            ];
        }

        $label = 'Setup';

        if ($w['multistore'] && $runShops !== []) {
            $label = count($runShops) === 1
                ? $this->t('Setup · %name%', ['%name%' => $runShops[0]['name']])
                : $this->t('Setup · %count% shops', ['%count%' => count($runShops)]);
        }

        $single = $w['single'] !== '';

        return [
            'steps' => $steps,
            'current' => $current,
            'number' => WizardFlow::number($current, $active),
            'total' => count($active),
            // "wszystkie sklepy": przed wyborem sklepow liczba krokow nie jest znana — licznik bez lacznej liczby.
            'allShops' => !empty($w['allShops']),
            'label' => $label,
            'single' => $single,
            'adding' => $w['adding'],
            'runShops' => $runShops,
            'formShop' => $w['formShop'],
            'formShopUrl' => ShopContext::baseUrl($w['formShop']),
            'switcher' => count($runShops) > 1 && in_array($current, WizardFlow::SWITCHER_STEPS, true),
            'same' => !in_array($current, WizardFlow::NO_SAME, true),
            'optional' => in_array($current, WizardFlow::OPTIONAL, true) && !$single,
            'prev' => $single || !empty($w['allShops']) ? null : WizardFlow::prev($current, $active),
            'cancelRoute' => $single ? (self::RETURN_ROUTES[$current] ?? 'quince_insight_settings') : '',
            'needsGrant' => $this->needsGrant($w['formShop'], $current),
            'serviceStep' => in_array($current, WizardFlow::SERVICE_STEPS, true),
        ];
    }

    /**
     * Dane biezacego kroku.
     *
     * @param array<string,mixed> $w
     *
     * @return array<string,mixed>
     */
    private function stepData(array $w, string $step): array
    {
        $ctx = (int) $w['ctx'];

        switch ($step) {
            case 'welcome':
                return [];

            case 'shops':
                $shops = [];
                $doneShops = $this->shops->setUpShops();
                $all = !empty($w['allShops']);
                $context = $this->contextShopIds();

                foreach (ShopContext::shops() as $id => $shop) {
                    if (($all && !in_array($id, $context, true)) || !ShopContext::employeeMayManage($id)) {
                        continue;
                    }

                    $shops[] = [
                        'id' => $id,
                        'name' => $shop['name'],
                        'url' => $shop['base_url'],
                        'current' => !$all && $id === $ctx,
                        'picked' => !$all && ($id === $ctx || in_array($id, $w['runShops'], true)),
                        'done' => in_array($id, $doneShops, true),
                        'https' => ShopContext::isHttps($id),
                        // Bez sklepu bazowego wspolny projekt Google Cloud sprawdza zapis (shopsFromAllShops).
                        'pickable' => $all || $this->pickable($ctx, $id),
                    ];
                }

                return [
                    'shopsStep' => [
                        'allShops' => $all,
                        'mode' => $w['run']['mode'],
                        'shops' => $shops,
                        'thisShop' => ['name' => ShopContext::name($ctx), 'url' => ShopContext::baseUrl($ctx)],
                    ],
                ];

            case 'services':
                $copyFrom = [];

                foreach ($w['otherDone'] as $id) {
                    $copyFrom[] = ['id' => $id, 'name' => ShopContext::name($id)];
                }

                $selected = [];

                foreach (Services::KEYS as $key) {
                    $selected[$key] = in_array($key, $w['services'], true);
                }

                // Pierwsza konfiguracja: zaznaczenie startowe jak w prototypie (bez Ads i opinii).
                if (!array_filter($selected) && !$w['run']['started'] && !$w['done']) {
                    $selected = ['ga4' => true, 'ads' => false, 'gtm' => true, 'gmc' => true, 'gcr' => false, 'gsc' => true, 'psi' => true];
                }

                return [
                    'servicesStep' => [
                        'groups' => WizardFlow::GROUPS,
                        'services' => WizardFlow::SERVICES,
                        'selected' => $selected,
                        'copyFrom' => $w['adding'] ? $copyFrom : [],
                    ],
                ];

            case 'cloud':
                return ['cloudStep' => $this->cloudData($w)];

            case 'connect':
                return ['connectStep' => $this->connectData($w)];

            case 'summary':
                return ['summaryStep' => $this->summaryData($w)];
        }

        return ['serviceStepData' => $this->ownerData($step, (int) $w['formShop'])];
    }

    /** @param array<string,mixed> $w @return array<string,mixed> */
    private function cloudData(array $w): array
    {
        $connection = $this->cloudConnection($w, '');
        $existing = [];

        if ($w['adding']) {
            foreach ($this->connections->all() as $conn) {
                // Na liscie tylko polaczenia, ktore zapis kroku przyjmie (saveCloud).
                if (!$this->connectionUsable($conn->id)) {
                    continue;
                }

                $users = [];

                foreach ($this->shops->shopsOfConnection($conn->id) as $id) {
                    if ($this->shops->wizardDone($id)) {
                        $users[] = ShopContext::name($id);
                    }
                }

                if ($users !== []) {
                    $existing[] = ['id' => $conn->id, 'email' => $conn->googleEmail, 'label' => $conn->label, 'shops' => implode(', ', $users)];
                }
            }
        }

        $choice = (string) $w['run']['conn'];

        if ($choice === '' && $existing !== []) {
            $choice = (string) $existing[0]['id'];
        }

        $cloudSteps = [];

        foreach (WizardFlow::cloudSteps($w['services']) as $n) {
            $cloudSteps[] = ['n' => $n] + WizardFlow::CLOUD_STEPS[$n];
        }

        return [
            'clientId' => $connection?->clientId ?? '',
            'hasSecret' => $connection !== null && $connection->hasSecret,
            'hasApiKey' => $connection !== null && $connection->hasApiKey,
            'production' => $connection !== null && $connection->productionConfirmed,
            'psi' => in_array('psi', $w['services'], true),
            'apis' => WizardFlow::apisNeeded($w['services']),
            'redirectUri' => $this->flow->redirectUri(),
            'steps' => $cloudSteps,
            'existing' => $existing,
            'choice' => $choice === '' ? 'new' : $choice,
            'addingName' => ShopContext::name((int) $w['ctx']),
        ];
    }

    /** @param array<string,mixed> $w @return array<string,mixed> */
    private function connectData(array $w): array
    {
        $connection = $this->connections->forShop((int) $w['ctx']);
        $connected = $connection !== null && $connection->isConnected();
        $scopes = [];
        $missing = [];

        foreach (WizardFlow::scopeKeys($w['services']) as $key) {
            $granted = $connected && ($key === 'email' || Scopes::missing($connection->scopes, [$key]) === []);

            $scopes[] = ['key' => $key, 'granted' => $granted] + WizardFlow::SCOPES[$key];

            if ($connected && !$granted) {
                $missing[] = WizardFlow::SCOPES[$key]['svc'];
            }
        }

        return [
            'connected' => $connected,
            'email' => $connection?->googleEmail ?? '',
            'status' => $connection?->status ?? '',
            'lastError' => $connection?->lastError ?? '',
            'redirectUri' => $this->flow->redirectUri(),
            'scopes' => $scopes,
            'missing' => $missing,
        ];
    }

    /** @param array<string,mixed> $w @return array<string,mixed> */
    private function summaryData(array $w): array
    {
        $idShop = (int) $w['formShop'];
        $enabled = $this->services->enabledKeys($idShop);
        $rows = [];

        foreach (WizardFlow::SERVICES as $key => $service) {
            if (!in_array($key, $enabled, true)) {
                continue;
            }

            $step = WizardFlow::stepFor($key);

            if ($key === 'psi') {
                $connection = $this->connections->forShop($idShop);
                $ok = $connection !== null && $connection->hasApiKey;
                $rows[] = ['name' => $service['name'], 'ok' => $ok, 'text' => $ok ? 'API key saved · first check tonight' : 'Not finished', 'params' => [], 'step' => 'cloud'];

                continue;
            }

            $state = $this->state->shopStepState($idShop, $step);

            // Pominiety krok opcjonalny (Ads, Customer Reviews): "Skipped" z szara kropka jak w
            // prototypie — takze gdy usluga ma ustawienia z wczesniejszego przejscia.
            if (in_array($step, WizardFlow::OPTIONAL, true) && ($this->shops->wizardState($idShop)[$step] ?? '') === 'skipped') {
                $rows[] = ['name' => $service['name'], 'ok' => false, 'text' => 'Skipped', 'params' => [], 'step' => $step];

                continue;
            }

            $ownerRows = [];

            try {
                $ownerRows = $this->registry->wizardStep($step)?->summary($idShop) ?? [];
            } catch (\Throwable $e) {
                Log::write($idShop, 'core', Log::WARNING, 'Wizard step ' . $step . ' summary failed: ' . $e->getMessage());
            }

            if ($ownerRows === []) {
                $rows[] = [
                    'name' => $service['name'],
                    'ok' => $state === 'done',
                    'text' => match ($state) {
                        'done' => 'Done',
                        'skipped' => 'Skipped',
                        default => 'Not finished',
                    },
                    'params' => [],
                    'step' => $step,
                ];

                continue;
            }

            foreach ($ownerRows as $row) {
                $rows[] = [
                    'name' => (string) ($row['label'] ?? $service['name']),
                    'ok' => ($row['state'] ?? '') === 'done',
                    'text' => (string) ($row['value'] ?? ''),
                    'params' => array_map('strval', (array) ($row['params'] ?? [])),
                    'step' => $step,
                ];
            }
        }

        return [
            'rows' => $rows,
            'gmcDraft' => in_array('gmc', $enabled, true) && $this->state->shopStepState($idShop, 'gmc') === 'done',
            'cron' => CronBlock::translated($this->cron->data($idShop), fn (string $m, array $p): string => $this->t($m, $p)),
            'shopName' => ShopContext::name($idShop),
        ];
    }

    /**
     * Dane kroku uslugi od wlasciciela. Krok z bledem nie wywraca kreatora —
     * pokazuje komunikat, a reszta przejscia dziala.
     *
     * @return array{ok:bool,data:array<string,mixed>,error:string}
     */
    private function ownerData(string $step, int $idShop): array
    {
        try {
            $owner = $this->registry->wizardStep($step);
            $data = $owner === null ? [] : $owner->data([$idShop]);

            return ['ok' => true, 'data' => is_array($data) ? $data : [], 'error' => ''];
        } catch (\Throwable $e) {
            Log::write($idShop, 'core', Log::ERROR, 'Wizard step ' . $step . ' failed to load: ' . $e->getMessage(), [
                'file' => basename($e->getFile()) . ':' . $e->getLine(),
            ]);

            return ['ok' => false, 'data' => [], 'error' => $this->googleError($e)];
        }
    }
}
