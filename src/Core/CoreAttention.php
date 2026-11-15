<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Core;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Quince\Insight\Core\Dto\AttentionItem;
use Quince\Insight\Core\Google\ConnectionRepository;
use Quince\Insight\Core\Google\Dto\Connection;
use Quince\Insight\Core\Google\OAuthFlow;

/**
 * Pozycje "Needs attention" zglaszane przez sam rdzen: logowanie do Google,
 * brakujace uprawnienia, zmieniony adres zwrotny, spoznione crony.
 *
 * Pulpit laczy je z pozycjami uslug (`ServiceModule::attention()`).
 */
final class CoreAttention
{
    /** Nazwy grup cron jak na ekranie Settings -> Cron. */
    private const GROUP_NAMES = [
        Scheduler::GROUP_ORDERS => 'Orders',
        Scheduler::GROUP_PRODUCTS => 'Products',
        Scheduler::GROUP_STATUSES => 'Statuses',
        Scheduler::GROUP_SITEMAP => 'Sitemap',
        Scheduler::GROUP_MAINTENANCE => 'Maintenance',
    ];

    /** Nazwy uslug w pozycji o brakujacych uprawnieniach (jak w Settings). */
    private const SERVICE_NAMES = [
        'ga4' => 'Analytics',
        'gtm' => 'Tag Manager',
        'gmc' => 'Merchant Center',
        'gsc' => 'Search Console',
        'verify' => 'Site verification',
    ];

    /** Spozniona grupa: co przez to nie dziala (teksty jak w prototypie dla `orders`). */
    private const LATE = [
        Scheduler::GROUP_ORDERS => 'The %name% cron has not run since %date% — purchases are not being sent to Analytics.',
        Scheduler::GROUP_PRODUCTS => 'The %name% cron has not run since %date% — product changes are not being sent to Merchant Center.',
        Scheduler::GROUP_STATUSES => 'The %name% cron has not run since %date% — product statuses from Merchant Center are not being updated.',
        Scheduler::GROUP_SITEMAP => 'The %name% cron has not run since %date% — the sitemap is not being rebuilt.',
        Scheduler::GROUP_MAINTENANCE => 'The %name% cron has not run since %date% — Search Console data and the Google connection are not being checked.',
    ];

    private const NEVER_RAN = [
        Scheduler::GROUP_ORDERS => 'The %name% cron has never run — purchases are not being sent to Analytics.',
        Scheduler::GROUP_PRODUCTS => 'The %name% cron has never run — product changes are not being sent to Merchant Center.',
        Scheduler::GROUP_STATUSES => 'The %name% cron has never run — product statuses from Merchant Center are not being updated.',
        Scheduler::GROUP_SITEMAP => 'The %name% cron has never run — the sitemap is not being rebuilt.',
        Scheduler::GROUP_MAINTENANCE => 'The %name% cron has never run — Search Console data and the Google connection are not being checked.',
    ];

    public function __construct(
        private readonly Scheduler $scheduler,
        private readonly ConnectionRepository $connections,
        private readonly OAuthFlow $flow,
        private readonly Services $services,
        private readonly ShopRepository $shops,
    ) {
    }

    /**
     * @return AttentionItem[]
     */
    public function items(int $idShop): array
    {
        if (!$this->shops->isSetUp($idShop)) {
            return [];
        }

        $out = [];
        $connection = $this->connections->forShop($idShop);

        if ($connection !== null) {
            if (in_array($connection->status, [Connection::STATUS_NEEDS_REAUTH, Connection::STATUS_REVOKED], true)) {
                $out[] = new AttentionItem(
                    AttentionItem::ERROR,
                    'The Google account needs to be connected again. Nothing is sent to Google until then.',
                    'quince_insight_settings'
                );
            } elseif ($connection->isConnected()) {
                $missing = $this->connections->missingFor($connection->id, $this->services->enabledKeys($idShop));

                if ($missing !== []) {
                    // Prototyp (`ovAttention`): blad z lista uslug, ktorym Google nie dal dostepu.
                    $out[] = new AttentionItem(
                        AttentionItem::ERROR,
                        'Google did not grant access to %services%.',
                        'quince_insight_settings',
                        [],
                        ['%services%' => implode(', ', array_map(static fn (string $k): string => self::SERVICE_NAMES[$k] ?? $k, $missing))]
                    );
                }
            }

            if ($this->flow->redirectUriChanged($connection->id)) {
                $out[] = new AttentionItem(
                    AttentionItem::WARNING,
                    'The shop address has changed. Add the new redirect address in Google Cloud: %url%',
                    'quince_insight_settings',
                    [],
                    ['%url%' => $this->flow->redirectUri()]
                );
            }
        }

        // Prototyp: "The Orders cron has not run for 2 hours — purchases are not being sent…".
        // Czas jako data ostatniego uruchomienia (bez odmiany liczebnikow w tlumaczeniu).
        foreach ($this->overdueGroups($idShop) as $group) {
            $last = $this->scheduler->groupState($idShop, $group)['last_run'];

            $out[] = new AttentionItem(
                AttentionItem::ERROR,
                $last === null ? self::NEVER_RAN[$group] ?? self::NEVER_RAN['maintenance'] : self::LATE[$group] ?? self::LATE['maintenance'],
                'quince_insight_settings',
                [],
                ['%name%' => self::GROUP_NAMES[$group] ?? $group, '%date%' => $last === null ? '' : substr($last, 0, 16)]
            );
        }

        foreach ($this->consentItems($idShop) as $item) {
            $out[] = $item;
        }

        return $out;
    }

    /**
     * Ostrzezenia o wspolpracy z Quince CMP w `<head>` (decyzja #8 a/b).
     *
     * @return AttentionItem[]
     */
    private function consentItems(int $idShop): array
    {
        if (!$this->services->enabled($idShop, 'gtm')) {
            return [];
        }

        try {
            $cmp = Container::get(QuinceCmp::class);

            if (!$cmp->enabledIn($idShop)) {
                return [];
            }

            $source = Container::get(\Quince\Insight\Service\Events\EventsSettings::class)->consentSource($idShop);
            $keys = self::consentWarnings(
                true,
                true,
                $cmp->headerOrderWrong($idShop),
                $source,
                $source === 'qcmp' ? $cmp->declaresConsent($idShop) : null
            );
        } catch (\Throwable $e) {
            Log::write($idShop, 'core', Log::WARNING, 'Quince CMP check failed: ' . $e->getMessage());

            return [];
        }

        $out = [];

        if (in_array('order', $keys, true)) {
            $out[] = new AttentionItem(
                AttentionItem::WARNING,
                'The Tag Manager container comes before the Quince CMP consent declaration in the page head. In Design → Positions, move Quince Insight below Quince CMP in the displayHeader hook.',
                'admin_modules_positions'
            );
        }

        if (in_array('declaration', $keys, true)) {
            $out[] = new AttentionItem(
                AttentionItem::WARNING,
                'Quince CMP is the consent source, but it adds no consent declaration to the shop pages, so Google tags get no consent signals. Map the cookie categories to Consent Mode in Quince CMP.',
                'quince_cmp_scripts'
            );
        }

        return $out;
    }

    /**
     * Czysta regula ostrzezen Quince CMP: `order` — nasz `displayHeader` przed Quince CMP,
     * `declaration` — zrodlo zgod = Quince CMP, a ten nie wypisuje deklaracji
     * (`$declares === null` = nie da sie ustalic, bez ostrzezenia).
     *
     * @return string[]
     */
    public static function consentWarnings(bool $gtmOn, bool $cmpOn, bool $orderWrong, string $source, ?bool $declares): array
    {
        if (!$gtmOn || !$cmpOn) {
            return [];
        }

        $out = [];

        if ($orderWrong) {
            $out[] = 'order';
        }

        if ($source === 'qcmp' && $declares === false) {
            $out[] = 'declaration';
        }

        return $out;
    }

    /**
     * Grupy cron potrzebne sklepowi, ktore sa spoznione.
     *
     * Gdy user korzysta z adresu zbiorczego, poszczegolne grupy i tak maja swieze
     * `last_run` (zapisuje je kazdy przebieg), wiec osobnej reguly dla `all` nie trzeba.
     *
     * @return string[]
     */
    public function overdueGroups(int $idShop): array
    {
        $out = [];

        foreach ($this->scheduler->groupsFor($idShop) as $group) {
            if ($this->scheduler->groupState($idShop, $group)['overdue']) {
                $out[] = $group;
            }
        }

        return $out;
    }
}
