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
use Quince\Insight\Core\CoreOptions;
use Quince\Insight\Core\Google\ConnectionRepository;
use Quince\Insight\Core\Google\Dto\Connection;
use Quince\Insight\Core\Google\OAuthFlow;
use Quince\Insight\Core\Log;
use Quince\Insight\Core\Options;
use Quince\Insight\Core\PanelAccess;
use Quince\Insight\Core\Scheduler;
use Quince\Insight\Core\ServiceRegistry;
use Quince\Insight\Core\Services;
use Quince\Insight\Core\ShopContext;
use Quince\Insight\Core\ShopRepository;
use Quince\Insight\Service\Verification\VerificationState;
use Quince\Insight\Service\Verification\Verifier;
use Quince\Insight\Wizard\CronBlock;
use Quince\Insight\Wizard\TurnOff;
use Quince\Insight\Wizard\WizardFlow;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Settings — 1:1 z prototypu `pg==='settings'`: polaczenie Google, sklepy (multistore),
 * uslugi, crony, kreator od nowa. Dodatek z paczki A: retencja dziennika modulu.
 *
 * W kontekscie "wszystkie sklepy" tylko polaczenia i sklepy — reszta nalezy do sklepu.
 */
final class SettingsController extends PanelController
{
    /**
     * Co przestaje dzialac po "Disconnect" — tylko to, co wola Google przez polaczenie OAuth.
     * Kod na stronach sklepu (Tag Manager, zdarzenia), zakupy z serwera (sekret MP) i Page speed
     * (klucz API) dzialaja dalej.
     */
    private const DISCONNECT_STOPS = [
        'ga4' => 'Analytics reports',
        'gtm' => 'Tag Manager container updates',
        'gmc' => 'Merchant Center',
        'gsc' => 'Search Console',
    ];

    public function __construct(
        private readonly ConnectionRepository $connections,
        private readonly ShopRepository $shops,
        private readonly Services $services,
        private readonly Scheduler $scheduler,
        private readonly CronBlock $cron,
        private readonly OAuthFlow $flow,
        private readonly ServiceRegistry $registry,
        private readonly Verifier $verifier,
    ) {
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", message: 'You do not have permission to view this.')]
    public function indexAction(Request $request): Response
    {
        $allShops = ShopContext::current() === null;
        $idShop = $this->idShop();
        $multistore = ShopContext::isMultistore();

        $connections = [];

        if ($allShops) {
            foreach ($this->connections->all() as $connection) {
                if ($this->connectionInContext($connection->id)) {
                    $connections[] = $this->connectionView($connection);
                }
            }
        } else {
            $own = $this->connections->forShop($idShop);
            $connections = $own === null ? [] : [$this->connectionView($own)];
        }

        $shopRows = [];

        if ($multistore) {
            $all = $this->connections->all();

            $context = $this->contextShopIds();

            // Lista jak w prototypie: wszystkie sklepy. Wybor polaczenia tylko przy sklepach
            // z kontekstu pracownika — ten sam warunek co w zapisie (R-07).
            foreach (ShopContext::shops() as $id => $shop) {
                $conn = $this->connections->forShop($id);

                $shopRows[] = [
                    'id' => $id,
                    'name' => $shop['name'],
                    'url' => $shop['base_url'],
                    'done' => $this->shops->wizardDone($id),
                    'active' => $shop['active'],
                    'connection' => $conn?->googleEmail ?: ($conn?->label ?? ''),
                    'idConnection' => $conn?->id ?? 0,
                    'editable' => PanelAccess::shopAllowed($id, $context),
                ];
            }

            $choices = [];

            foreach ($all as $connection) {
                if ($this->connectionInContext($connection->id)) {
                    $choices[$connection->id] = $connection->googleEmail ?: $connection->label;
                }
            }
        }

        $services = [];
        $enabled = $this->services->enabledKeys($idShop);

        foreach (WizardFlow::SERVICES as $key => $service) {
            $on = in_array($key, $enabled, true);

            $services[] = [
                'key' => $key,
                'name' => $service['name'],
                'on' => $on,
                'step' => WizardFlow::stepFor($key),
                'off' => $on ? $this->turnOffView($key, $enabled) : null,
            ];
        }

        $shopUrl = ShopContext::baseUrl($idShop);

        return $this->renderPanel('settings/index.html.twig', 'settings', [
            'allShopsView' => $allShops,
            'connections' => $connections,
            'shopRows' => $shopRows,
            'connectionChoices' => $choices ?? [],
            'servicesList' => $services,
            'cron' => $allShops ? null : CronBlock::translated($this->cron->data($idShop), fn (string $m, array $p): string => $this->t($m, $p)),
            'logDays' => Options::int(CoreOptions::LOG_KEEP_DAYS, CoreOptions::LOG_KEEP_DAYS_DEFAULT, $idShop),
            'shopLine' => ShopContext::name($idShop) . ' · ' . rtrim((string) preg_replace('#^https?://#', '', $shopUrl), '/'),
            'shopHost' => rtrim((string) preg_replace('#^https?://#', '', $shopUrl), '/'),
            'redirectUri' => $this->flow->redirectUri(),
            'verification' => $allShops ? null : $this->verificationView($idShop),
        ]);
    }

    /** Retencja dziennika modulu (formularz). */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function saveAction(Request $request): Response
    {
        if (!$this->csrfValid($request, 'quince_insight_settings_save')) {
            $this->addFlash('error', $this->t('Invalid security token, please try again.'));

            return $this->redirectToRoute('quince_insight_settings');
        }

        $days = (int) $request->request->get('log_keep_days', CoreOptions::LOG_KEEP_DAYS_DEFAULT);

        if ($days < 1 || $days > 365) {
            $this->addFlash('error', $this->t('Keep the log between 1 and 365 days.'));

            return $this->redirectToRoute('quince_insight_settings');
        }

        Options::set(CoreOptions::LOG_KEEP_DAYS, $days, $this->idShop());
        $this->addFlash('success', $this->t('Saved'));

        return $this->redirectToRoute('quince_insight_settings');
    }

    /**
     * Wlaczenie uslugi z Settings → Services ("Connect"): usluga wlaczona, przegladarka idzie
     * do jej kroku kreatora (tryb jednej uslugi, z prosba o brakujace uprawnienie).
     * Wylaczenie (`on: false`) sprzata po stronie uslug zaleznych, bez zmian w Google.
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function serviceToggleAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_settings_service_toggle');

        $input = $this->payload($request);
        $key = (string) ($input['key'] ?? '');
        $on = (bool) ($input['on'] ?? true);
        $idShop = $this->idShop();

        if (!in_array($key, Services::KEYS, true)) {
            return $this->fail($this->t('Unknown service.'));
        }

        if (ShopContext::current() === null) {
            return $this->fail($this->t('Choose a shop first.'));
        }

        // Wylaczenie ("Turn off", decyzja #24): usluga i zalezne od niej (gtm → ads, gmc → gcr).
        $affected = $on ? [$key] : TurnOff::affected($key, $this->services->enabledKeys($idShop));

        $this->services->setEnabled($idShop, $key, $on);

        if (!$on) {
            foreach ($this->registry->all() as $module) {
                if (in_array($module->requires(), $affected, true)) {
                    try {
                        $module->disable($idShop);
                    } catch (\Throwable $e) {
                        Log::write($idShop, $module->key(), Log::ERROR, 'disable() failed: ' . $e->getMessage());
                    }
                }
            }

            Log::write($idShop, 'core', Log::INFO, 'Service turned off in Settings.', ['services' => $affected]);

            $names = array_map(fn (string $k): string => $this->t(WizardFlow::SERVICES[$k]['name']), $affected);
            $this->addFlash('success', $this->t('Turned off: %services%. Nothing was deleted in Google.', ['%services%' => implode(', ', $names)]));

            return $this->ok(['enabled' => $this->services->enabledKeys($idShop)]);
        }

        return $this->ok([
            'enabled' => $this->services->enabledKeys($idShop),
            'redirect' => $this->generateUrl('quince_insight_wizard', ['step' => WizardFlow::stepFor($key)]),
        ]);
    }

    /** Settings → Shops: sklep -> polaczenie Google (gdy polaczen jest kilka). */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function shopsSaveAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_settings_shops_save');

        $input = $this->payload($request);
        $idShop = (int) ($input['shop'] ?? 0);
        $idConnection = (int) ($input['connection'] ?? 0);

        if (!ShopContext::exists($idShop) || $this->connections->find($idConnection) === null) {
            return $this->fail($this->t('Choose a shop and a connection.'));
        }

        // Tylko sklep z kontekstu pracownika i polaczenie, ktorego uzywa ktorys z tych sklepow (R-07).
        if (!PanelAccess::shopAllowed($idShop, $this->contextShopIds()) || !$this->connectionInContext($idConnection)) {
            return $this->fail($this->t('You do not have permission to change this.'), 403);
        }

        $this->connections->assignShop($idShop, $idConnection);
        Log::write($idShop, 'core', Log::INFO, 'Shop assigned to another Google connection.', ['id_connection' => $idConnection]);

        return $this->ok(['message' => $this->t('Saved')]);
    }

    /** "New token": stare adresy crona sklepu przestaja dzialac od razu. */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function cronTokenAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_settings_cron_token');

        if (ShopContext::current() === null) {
            return $this->fail($this->t('Choose a shop first.'));
        }

        $idShop = $this->idShop();
        $this->scheduler->rotateToken($idShop);
        Log::write($idShop, 'cron', Log::INFO, 'Cron token changed.');

        $urls = [];

        foreach (array_merge(array_keys(Scheduler::GROUPS), [Scheduler::GROUP_ALL]) as $group) {
            $urls[$group] = $this->scheduler->url($idShop, $group);
        }

        return $this->ok(['urls' => $urls]);
    }

    /** "Run now": jedna grupa zadan od razu, bez czekania na cron serwera. */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function cronRunAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_settings_cron_run');

        $group = (string) ($this->payload($request)['group'] ?? '');

        if (!isset(Scheduler::GROUPS[$group])) {
            return $this->fail($this->t('Unknown cron group.'));
        }

        if (ShopContext::current() === null) {
            return $this->fail($this->t('Choose a shop first.'));
        }

        $idShop = $this->idShop();
        $report = $this->scheduler->run($idShop, $group, 'manual');

        if (!empty($report['error'])) {
            return $this->fail($this->t((string) $report['error']));
        }

        if (!empty($report['paused'])) {
            return $this->fail($this->t('This shop is disabled, so its scheduled tasks are paused.'));
        }

        if (in_array('busy', $report['skipped'] ?? [], true)) {
            return $this->fail($this->t('A cron for this shop is running right now. Try again in a minute.'), 409);
        }

        if (in_array('not_set_up', $report['skipped'] ?? [], true)) {
            return $this->fail($this->t('This shop is not set up yet, so nothing runs here.'));
        }

        $failed = false;

        foreach ($report['ran'] ?? [] as $task) {
            if (($task['status'] ?? '') === 'error') {
                $failed = true;
            }
        }

        $row = CronBlock::translated(['rows' => [$this->cron->row($idShop, $group)], 'all' => []], fn (string $m, array $p): string => $this->t($m, $p))['rows'][0];

        return $this->ok(['row' => $row, 'failed' => $failed, 'ran' => count($report['ran'] ?? [])]);
    }

    /**
     * Krok potwierdzenia "Turn off": co przestanie dzialac, co wylaczy sie razem z usluga,
     * ktore crony przestana byc potrzebne. Teksty juz przetlumaczone.
     *
     * @param string[] $enabled
     *
     * @return array{title:string,lines:string[],button:string}
     */
    private function turnOffView(string $key, array $enabled): array
    {
        $name = fn (string $k): string => $this->t(WizardFlow::SERVICES[$k]['name']);
        $affected = TurnOff::affected($key, $enabled);
        $lines = [];

        foreach ($affected as $k) {
            foreach (TurnOff::EFFECTS[$k] ?? [] as $effect) {
                $lines[] = $this->t($effect);
            }
        }

        foreach (array_slice($affected, 1) as $dependent) {
            $lines[] = $this->t('%service% is turned off too — it works through %base%.', ['%service%' => $name($dependent), '%base%' => $name($key)]);
        }

        $crons = TurnOff::lostCronGroups($enabled, TurnOff::enabledAfter($key, $enabled));

        if ($crons !== []) {
            $lines[] = $this->t('Crons no longer needed: %crons%.', ['%crons%' => implode(', ', array_map(
                fn (string $g): string => $this->t(CronBlock::GROUPS[$g]['name']),
                $crons
            ))]);
        }

        $lines[] = $this->t('Nothing is deleted in Google. The settings are kept — turning the service on again brings them back.');

        return [
            'title' => $this->t('Turn off %service%?', ['%service%' => $name($key)]),
            'lines' => $lines,
            'button' => $this->t('Turn off'),
        ];
    }

    /**
     * Weryfikacja witryny w karcie polaczenia Google (decyzja #25): stan i krok potwierdzenia
     * "Remove" z uslugami, ktore przestana dzialac. Null, gdy sklep nie ma wlasnej weryfikacji.
     *
     * @return array<string,mixed>|null
     */
    private function verificationView(int $idShop): ?array
    {
        $state = $this->verifier->state($idShop);

        if (!VerificationState::isVerified($state['state']) || $state['site'] === '') {
            return null;
        }

        $sharing = $this->verifier->sharingShops($idShop, $state['site']);
        $using = [];

        foreach (array_merge([$idShop], $sharing) as $id) {
            foreach (['gmc', 'gsc'] as $key) {
                if ($this->services->enabled($id, $key)) {
                    $using[$key] = $this->t(WizardFlow::SERVICES[$key]['name']);
                }
            }
        }

        $site = (string) preg_replace('#^sc-domain:#', '', $state['site']);
        $lines = [];

        $lines[] = $state['meta']
            ? $this->t('The tag is removed from the shop pages first, then the verification is removed in Google.')
            : $this->t('The module did not add a tag for this verification, so it stays in Google. Only the module stops using it.');

        if ($using !== []) {
            $lines[] = $this->t('These services stop working until the site is verified again: %services%.', ['%services%' => implode(', ', $using)]);
        }

        if ($sharing !== []) {
            $lines[] = $this->t('Shops on the same domain lose it too: %shops%.', ['%shops%' => implode(', ', array_map([ShopContext::class, 'name'], $sharing))]);
        }

        return [
            'site' => $site,
            'meta' => $state['meta'],
            'title' => $this->t('Remove the site verification of %site%?', ['%site%' => $site]),
            'lines' => $lines,
        ];
    }

    /** @return array<string,mixed> polaczenie bez sekretow (do szablonu) */
    private function connectionView(Connection $connection): array
    {
        $users = [];

        foreach ($this->shops->shopsOfConnection($connection->id) as $id) {
            $users[] = ShopContext::name($id);
        }

        return [
            'id' => $connection->id,
            'email' => $connection->googleEmail,
            'label' => $connection->label,
            'clientId' => $connection->clientId,
            'status' => $connection->status,
            'connected' => $connection->isConnected(),
            'needsReauth' => $connection->status === Connection::STATUS_NEEDS_REAUTH,
            'hasCredentials' => $connection->hasCredentials(),
            'redirectChanged' => $this->flow->redirectUriChanged($connection->id),
            'shops' => implode(', ', $users),
            'disconnect' => $connection->isConnected() ? $this->disconnectView($connection) : null,
        ];
    }

    /**
     * Krok potwierdzenia "Disconnect" (jak "Remove" weryfikacji, ARCHITEKTURA §5.1 — bez
     * natywnego `confirm`): sklepy na tym polaczeniu i uslugi, ktore przestaja dzialac.
     *
     * @return array{title:string,lines:string[]}
     */
    private function disconnectView(Connection $connection): array
    {
        $shops = [];
        $using = [];

        foreach ($this->shops->shopsOfConnection($connection->id) as $id) {
            $shops[] = ShopContext::name($id);

            foreach (self::DISCONNECT_STOPS as $key => $label) {
                if ($this->services->enabled($id, $key)) {
                    $using[$key] = $this->t($label);
                }
            }
        }

        $lines = [];

        if ($shops !== []) {
            $lines[] = $this->t('Shops using this connection: %shops%.', ['%shops%' => implode(', ', $shops)]);
        }

        if ($using !== []) {
            $lines[] = $this->t('These stop working until you sign in again: %services%.', ['%services%' => implode(', ', $using)]);
        }

        $lines[] = $this->t('Access is revoked in Google — nothing is deleted there. Settings are kept.');

        return [
            'title' => $connection->googleEmail !== ''
                ? $this->t('Disconnect the Google account %email%?', ['%email%' => $connection->googleEmail])
                : $this->t('Disconnect the Google account?'),
            'lines' => $lines,
        ];
    }
}
