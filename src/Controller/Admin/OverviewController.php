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
use Quince\Insight\Core\CoreAttention;
use Quince\Insight\Core\Dto\AttentionItem;
use Quince\Insight\Core\Dto\ServiceHealth;
use Quince\Insight\Core\Google\ConnectionRepository;
use Quince\Insight\Core\Google\Dto\Connection;
use Quince\Insight\Core\Google\Scopes;
use Quince\Insight\Core\Log;
use Quince\Insight\Core\NoticeRepository;
use Quince\Insight\Core\Scheduler;
use Quince\Insight\Core\ServiceRegistry;
use Quince\Insight\Core\Services;
use Quince\Insight\Core\ShopContext;
use Quince\Insight\Core\ShopRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Overview — 1:1 z prototypu `pg==='overview'` i `pg==='overview-all'` (`js/overview.js`,
 * `js/multistore.js` `msCard`): "Needs attention" ze wszystkich uslug i rdzenia, karta na
 * usluge z `health()`, sekcja Connection, informacje z tabeli `notice` z "Dismiss".
 * W kontekscie "wszystkie sklepy" karta kazdego sklepu z "Open" / "Set up".
 *
 * Bez statystyk i okresow — wszystko z biezacego stanu uslug. Teksty z uslug sa
 * angielskimi zrodlami tlumaczen z parametrami; tlumaczy je szablon.
 */
final class OverviewController extends PanelController
{
    /**
     * Karty w kolejnosci bocznego paska (prototyp `ovCards`). `svc` = usluga z kreatora
     * (null = karta zawsze, jak Structured data), `modules` = uslugi, ktorych `health()`
     * sklada karte (Events = kontener Tag Managera + zdarzenia), `step` = krok "Connect".
     */
    private const CARDS = [
        ['key' => 'analytics', 'title' => 'Analytics', 'svc' => 'ga4', 'modules' => ['analytics'], 'route' => 'quince_insight_analytics', 'step' => 'ga4'],
        ['key' => 'events', 'title' => 'Events', 'svc' => 'gtm', 'modules' => ['tagmanager', 'events'], 'route' => 'quince_insight_events', 'step' => 'gtm'],
        ['key' => 'merchant', 'title' => 'Merchant Center', 'svc' => 'gmc', 'modules' => ['merchant'], 'route' => 'quince_insight_merchant', 'step' => 'gmc'],
        ['key' => 'gsc', 'title' => 'Search Console', 'svc' => 'gsc', 'modules' => ['searchconsole'], 'route' => 'quince_insight_gsc', 'step' => 'gsc'],
        ['key' => 'psi', 'title' => 'Page speed', 'svc' => 'psi', 'modules' => ['pagespeed'], 'route' => 'quince_insight_psi', 'step' => 'cloud'],
        ['key' => 'schema', 'title' => 'Structured data', 'svc' => null, 'modules' => ['structureddata'], 'route' => 'quince_insight_schema', 'step' => ''],
    ];

    /** Nazwa przycisku pozycji "Needs attention" po trasie i zakladce (prototyp: "Settings", "Products", "Sitemap"...). */
    private const ACTIONS = [
        'quince_insight_settings' => 'Settings',
        'quince_insight_wizard' => 'Set up',
        'quince_insight_analytics' => 'Analytics',
        'quince_insight_events' => 'Events',
        'quince_insight_merchant' => 'Merchant Center',
        'quince_insight_gsc' => 'Search Console',
        'quince_insight_psi' => 'Page speed',
        'quince_insight_schema' => 'Structured data',
        'quince_insight_support' => 'Support',
        // Poza modulem (decyzja #8): pozycje hookow PrestaShop i Consent Mode w Quince CMP.
        'admin_modules_positions' => 'Positions',
        'quince_cmp_scripts' => 'Quince CMP',
    ];

    private const TAB_ACTIONS = [
        'quince_insight_merchant' => ['products' => 'Products', 'mapping' => 'Mapping', 'promotions' => 'Promotions', 'shipping' => 'Shipping', 'overview' => 'Review and activate'],
        'quince_insight_events' => ['orders' => 'Order log', 'missing' => 'Missing orders', 'consent' => 'Consent', 'server' => 'Server-side'],
        'quince_insight_gsc' => ['sitemap' => 'Sitemap'],
        'quince_insight_analytics' => ['property' => 'Property'],
    ];

    /** Stan karty -> stan kropki (`ovDot`); `error` dostaje kolor bledu. */
    private const STATES = [
        ServiceHealth::OK => 'ok',
        ServiceHealth::ATTENTION => 'warn',
        ServiceHealth::ERROR => 'error',
        ServiceHealth::OFF => 'idle',
    ];

    public function __construct(
        private readonly ServiceRegistry $registry,
        private readonly Services $services,
        private readonly CoreAttention $coreAttention,
        private readonly NoticeRepository $notices,
        private readonly ShopRepository $shops,
        private readonly ConnectionRepository $connections,
        private readonly Scheduler $scheduler,
    ) {
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", message: 'You do not have permission to view this.')]
    public function indexAction(Request $request): Response
    {
        $allShops = ShopContext::current() === null && ShopContext::isMultistore();
        $notices = $this->notices->open();

        if ($allShops) {
            return $this->renderPanel('overview/all.html.twig', 'overview', [
                'notices' => $notices,
                'shopCards' => $this->shopCards(),
                'connection' => $this->allShopsConnection(),
                'version' => $this->moduleVersion(),
            ]);
        }

        $idShop = $this->idShop();

        return $this->renderPanel('overview/index.html.twig', 'overview', [
            'notices' => $notices,
            'attention' => $this->attention($idShop, true),
            'cards' => $this->cards($idShop),
            'connection' => $this->connection($idShop),
            'version' => $this->moduleVersion(),
        ]);
    }

    /** "Dismiss" informacji z tabeli `notice` (np. co zostalo w Google po usunietym sklepie). */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function dismissAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_overview_dismiss');

        $id = (int) ($this->payload($request)['id'] ?? 0);

        if ($id <= 0) {
            return $this->fail($this->t('Unknown notice.'));
        }

        $this->notices->dismiss($id);

        return $this->ok();
    }

    /**
     * Pozycje "Needs attention": rdzen (polaczenie, uprawnienia, crony), uslugi wlaczone
     * w sklepie, a przy kilku sklepach — sklepy bez konfiguracji. Bledy przed ostrzezeniami.
     *
     * @return array<int,array{level:string,message:string,params:array<string,mixed>,url:string,action:string,shop:bool}>
     */
    private function attention(int $idShop, bool $withShops): array
    {
        $items = [];

        try {
            foreach ($this->coreAttention->items($idShop) as $item) {
                $items[] = $this->item($item);
            }
        } catch (\Throwable $e) {
            Log::write($idShop, 'core', Log::ERROR, 'Overview: core attention failed: ' . $e->getMessage());
        }

        if ($withShops && ShopContext::isMultistore()) {
            foreach (ShopContext::shops() as $id => $shop) {
                if ($id !== $idShop && !$this->shops->wizardDone($id)) {
                    $items[] = [
                        'level' => AttentionItem::WARNING,
                        'message' => '%shop% is not set up — the module does nothing in this shop.',
                        'params' => ['%shop%' => $shop['name']],
                        'url' => $this->generateUrl('quince_insight_wizard', ['setShopContext' => 's-' . $id]),
                        'action' => 'Set up',
                        'shop' => true,
                    ];
                }
            }
        }

        foreach ($this->liveModules($idShop) as $key => $module) {
            try {
                foreach ($module->attention($idShop) as $item) {
                    if ($item instanceof AttentionItem) {
                        $items[] = $this->item($item);
                    }
                }
            } catch (\Throwable $e) {
                // Jedna usluga nie moze wywrocic pulpitu.
                Log::write($idShop, $key, Log::ERROR, 'Overview: attention() failed: ' . $e->getMessage());
            }
        }

        // Stabilne sortowanie: bledy przed ostrzezeniami, poza tym kolejnosc zrodel.
        $order = array_keys($items);
        array_multisort(array_map(static fn (array $i): int => $i['level'] === AttentionItem::ERROR ? 0 : 1, $items), $order, $items);

        return $items;
    }

    /**
     * Karty uslug (prototyp `ovCards`). Usluga niewlaczona = karta wyszarzona z "Connect".
     *
     * @return array<int,array<string,mixed>>
     */
    private function cards(int $idShop): array
    {
        $out = [];
        $modules = $this->registry->all();

        foreach (self::CARDS as $card) {
            $view = [
                'key' => $card['key'],
                'title' => $card['title'],
                'url' => $this->generateUrl($card['route']),
                'connect' => false,
                'state' => 'ok',
                'lines' => [],
                'facts' => [],
            ];

            if ($card['svc'] !== null && !$this->services->enabled($idShop, $card['svc'])) {
                $view['state'] = 'off';
                $view['connect'] = true;
                $view['url'] = $this->generateUrl('quince_insight_wizard', ['step' => $card['step']]);
                $view['lines'][] = ['text' => 'Not connected', 'params' => []];
                $out[] = $view;

                continue;
            }

            $states = [];

            foreach ($card['modules'] as $key) {
                $module = $modules[$key] ?? null;

                if ($module === null) {
                    continue;
                }

                try {
                    $health = $module->health($idShop);
                } catch (\Throwable $e) {
                    Log::write($idShop, $key, Log::ERROR, 'Overview: health() failed: ' . $e->getMessage());
                    $health = new ServiceHealth(ServiceHealth::ERROR, 'Could not read the state of this service. Details are in the module log.');
                }

                if (!$health instanceof ServiceHealth) {
                    continue;
                }

                $state = self::STATES[$health->state] ?? 'warn';

                // "off" uslugi wlaczonej w kreatorze to brak konfiguracji, nie stan spoczynku.
                if ($state === 'idle' && $card['svc'] !== null) {
                    $state = 'warn';
                }

                $states[] = $state;
                $view['lines'][] = ['text' => $health->summary, 'params' => $health->params];

                if ($health->fact !== '') {
                    $view['facts'][] = ['text' => $health->fact, 'params' => $health->params];
                }
            }

            $view['state'] = self::worst($states === [] ? ['idle'] : $states);
            $out[] = $view;
        }

        return $out;
    }

    /**
     * Sekcja Connection sklepu: konto Google, uprawnienia, crony, wersja.
     *
     * @return array<string,mixed>
     */
    private function connection(int $idShop): array
    {
        $connection = $this->connections->forShop($idShop);
        $keys = $this->services->enabledKeys($idShop);

        // Grupy uprawnien jak w prototypie (`scopesNeeded`): e-mail, weryfikacja (gdy GMC/GSC)
        // i kazda wlaczona usluga z wlasnymi uprawnieniami.
        $groups = ['email'];

        if (array_intersect(['gmc', 'gsc'], $keys) !== []) {
            $groups[] = 'verify';
        }

        foreach ($keys as $key) {
            if (Scopes::ofService($key) !== [] && !in_array($key, $groups, true)) {
                $groups[] = $key;
            }
        }

        $granted = $connection?->scopes ?? [];
        $missing = $connection !== null && $connection->isConnected() ? count(Scopes::missing($granted, array_diff($groups, ['email']))) : count($groups);

        $cronGroups = $this->scheduler->groupsFor($idShop);
        $last = null;

        foreach ($cronGroups as $group) {
            $run = $this->scheduler->groupState($idShop, $group)['last_run'];

            if ($run !== null && ($last === null || $run > $last)) {
                $last = $run;
            }
        }

        return [
            'email' => $connection?->googleEmail ?? '',
            'state' => self::connectionState($connection),
            'granted' => count($groups) - $missing,
            'needed' => count($groups),
            'crons' => count($cronGroups),
            'lastRun' => $last === null ? '' : substr($last, 0, 16),
        ];
    }

    /** @return array<string,mixed> */
    private function allShopsConnection(): array
    {
        $emails = [];
        $state = 'none';

        foreach ($this->connections->all() as $connection) {
            if ($connection->googleEmail !== '') {
                $emails[] = $connection->googleEmail;
            }

            $own = self::connectionState($connection);
            $state = $state === 'none' || $own === 'error' ? $own : $state;
        }

        $shops = ShopContext::shops();

        return [
            'emails' => $emails,
            'state' => $state,
            'used' => count(array_filter(array_keys($shops), fn (int $id): bool => $this->shops->wizardDone($id))),
            'total' => count($shops),
        ];
    }

    /**
     * Karty sklepow w kontekscie "wszystkie sklepy" (prototyp `msCard`).
     *
     * @return array<int,array<string,mixed>>
     */
    private function shopCards(): array
    {
        $out = [];

        foreach (ShopContext::shops() as $id => $shop) {
            $done = $this->shops->wizardDone($id);
            $card = [
                'id' => $id,
                'name' => $shop['name'],
                'url' => $shop['base_url'],
                'done' => $done,
                'link' => $this->generateUrl($done ? 'quince_insight_overview' : 'quince_insight_wizard', ['setShopContext' => 's-' . $id]),
            ];

            if (!$done) {
                $out[] = $card + [
                    'state' => 'off',
                    'line' => ['text' => 'Not set up', 'params' => []],
                    'fact' => ['text' => 'The module does nothing in this shop — no code on its pages, nothing sent to Google.', 'params' => []],
                ];

                continue;
            }

            if (!$shop['active']) {
                $out[] = $card + [
                    'state' => 'off',
                    'line' => ['text' => 'Paused — the shop is disabled', 'params' => []],
                    'fact' => ['text' => 'Scheduled tasks and sending to Google wait until the shop is enabled again.', 'params' => []],
                ];

                continue;
            }

            $count = count($this->attention($id, false));
            $services = count($this->services->enabledKeys($id));

            $out[] = $card + [
                'state' => $count > 0 ? 'warn' : 'ok',
                'line' => ['text' => $services === 1 ? '1 service connected' : '%count% services connected', 'params' => ['%count%' => $services]],
                'fact' => $count === 0
                    ? ['text' => 'Nothing to fix right now', 'params' => []]
                    : ['text' => $count === 1 ? '1 thing needs attention' : '%count% things need attention', 'params' => ['%count%' => $count]],
            ];
        }

        return $out;
    }

    /**
     * Uslugi, ktore w sklepie dzialaja: `requires()` wlaczone albo brak zaleznosci.
     * Wylaczona usluga nie zglasza "Needs attention" (np. Analytics bez wybranej uslugi GA4).
     *
     * @return array<string,\Quince\Insight\Core\ServiceModule>
     */
    private function liveModules(int $idShop): array
    {
        return array_filter(
            $this->registry->all(),
            fn ($module): bool => $module->requires() === null || $this->services->enabled($idShop, (string) $module->requires())
        );
    }

    /** @return array{level:string,message:string,params:array<string,mixed>,url:string,action:string,shop:bool} */
    private function item(AttentionItem $item): array
    {
        $tab = (string) ($item->routeParams['tab'] ?? '');

        try {
            $url = $this->generateUrl($item->route, $item->routeParams);
        } catch (\Throwable $e) {
            $url = $this->generateUrl('quince_insight_overview');
        }

        return [
            'level' => $item->level === AttentionItem::ERROR ? AttentionItem::ERROR : AttentionItem::WARNING,
            'message' => $item->message,
            'params' => $item->params,
            'url' => $url,
            'action' => self::TAB_ACTIONS[$item->route][$tab] ?? self::ACTIONS[$item->route] ?? 'Open',
            'shop' => false,
        ];
    }

    private static function connectionState(?Connection $connection): string
    {
        if ($connection === null) {
            return 'none';
        }

        if (in_array($connection->status, [Connection::STATUS_NEEDS_REAUTH, Connection::STATUS_REVOKED], true)) {
            return 'error';
        }

        return $connection->isConnected() ? 'ok' : 'none';
    }

    /** @param string[] $states */
    private static function worst(array $states): string
    {
        foreach (['error', 'warn', 'ok', 'idle'] as $state) {
            if (in_array($state, $states, true)) {
                return $state;
            }
        }

        return 'idle';
    }

    private function moduleVersion(): string
    {
        $module = \Module::getInstanceByName('quince_insight');

        return $module instanceof \Module ? (string) $module->version : '';
    }
}
