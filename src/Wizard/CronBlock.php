<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Wizard;

use Quince\Insight\Core\Clock;
use Quince\Insight\Core\Scheduler;
use Quince\Insight\Core\Services;

/**
 * Blok "Scheduled tasks (cron)" — Settings i Summary kreatora (prototyp `CRON_GROUPS`).
 *
 * Wiersz na kazda grupe potrzebna wlaczonym uslugom sklepu: nazwa, rekomendacja,
 * opis, adres (gotowa linie curl / wget sklada przegladarka), ostatnie uruchomienie.
 * Adresy sa osobne dla kazdego sklepu, w jego domenie.
 */
final class CronBlock
{
    /**
     * Opis grup 1:1 z prototypu. `needs` = uslugi, przy ktorych grupa jest potrzebna
     * (null = zawsze) — do sumy z grupami zgloszonymi przez uslugi w `Scheduler::groupsFor()`,
     * zeby ekran byl wlasciwy takze wtedy, gdy usluga nie dopiela jeszcze zadan.
     */
    public const GROUPS = [
        'orders' => ['name' => 'Orders', 'every' => 'every 10 minutes', 'expr' => '*/10 * * * *', 'needs' => ['gtm', 'ga4'],
            'desc' => 'Sends purchases and refunds to Analytics, including orders the browser missed.'],
        'products' => ['name' => 'Products', 'every' => 'every 15 minutes', 'expr' => '*/15 * * * *', 'needs' => ['gmc'],
            'desc' => 'Sends changed products to Merchant Center — prices, stock, new and removed products.'],
        'statuses' => ['name' => 'Statuses', 'every' => 'every 3 hours', 'expr' => '0 */3 * * *', 'needs' => ['gmc'],
            'desc' => 'Reads product statuses and account issues from Merchant Center, updates promotions.'],
        'sitemap' => ['name' => 'Sitemap', 'every' => 'once a day, at night', 'expr' => '30 2 * * *', 'needs' => ['gsc'],
            'desc' => 'Rebuilds the sitemap and submits it to Search Console.'],
        'maintenance' => ['name' => 'Maintenance', 'every' => 'once a day', 'expr' => '0 4 * * *', 'needs' => null,
            'desc' => 'Search Console data, page speed checks, Google connection check, old logs clean-up.'],
    ];

    /** Adres zbiorczy: co 5 minut (Scheduler::ALL_MINUTES). */
    public const ALL_EXPR = '*/5 * * * *';

    public function __construct(
        private readonly Scheduler $scheduler,
        private readonly Services $services,
    ) {
    }

    /**
     * Grupy potrzebne sklepowi, w kolejnosci ekranu.
     *
     * @param string[] $serviceKeys wlaczone uslugi
     * @param string[] $fromTasks grupy zgloszone przez zadania uslug
     *
     * @return string[]
     */
    public static function neededGroups(array $serviceKeys, array $fromTasks): array
    {
        $out = [];

        foreach (self::GROUPS as $key => $group) {
            // Grupa z lista uslug pokazuje sie tylko przy tych uslugach (zadanie zarejestrowane
            // przy innych uslugach i tak nic by nie robilo — raport C). Grupa bez listy:
            // zawsze albo gdy zglosilo ja zadanie uslugi.
            $show = $group['needs'] === null
                ? true
                : array_intersect($group['needs'], $serviceKeys) !== [];

            if ($show || ($group['needs'] === null && in_array($key, $fromTasks, true))) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /**
     * Dane bloku dla sklepu.
     *
     * @param string[]|null $serviceKeys uslugi (null = wlaczone w sklepie); kreator podaje wybrane w przejsciu
     *
     * @return array{rows:array<int,array<string,mixed>>,all:array<string,mixed>}
     */
    public function data(int $idShop, ?array $serviceKeys = null): array
    {
        $keys = $serviceKeys ?? $this->services->enabledKeys($idShop);
        $rows = [];

        foreach (self::neededGroups($keys, $this->scheduler->groupsFor($idShop)) as $key) {
            $rows[] = $this->row($idShop, $key);
        }

        $all = $this->scheduler->groupState($idShop, Scheduler::GROUP_ALL);

        return [
            'rows' => $rows,
            'all' => [
                'key' => Scheduler::GROUP_ALL,
                'expr' => self::ALL_EXPR,
                'url' => $this->scheduler->url($idShop, Scheduler::GROUP_ALL),
                'lastRun' => $all['last_run'],
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function row(int $idShop, string $key): array
    {
        $group = self::GROUPS[$key];
        $state = $this->scheduler->groupState($idShop, $key);

        return [
            'key' => $key,
            'name' => $group['name'],
            'every' => $group['every'],
            'expr' => $group['expr'],
            'desc' => $group['desc'],
            'url' => $this->scheduler->url($idShop, $key),
            'lastRun' => $state['last_run'],
            'lastStatus' => $state['last_status'],
            'late' => $state['overdue'],
        ];
    }

    /**
     * Dane bloku z tekstami gotowymi do ekranu: nazwa, opis i rekomendacja przetlumaczone,
     * `when` = czas ostatniego uruchomienia ("4 min ago"), '' gdy grupa nie biegla nigdy.
     *
     * @param callable(string,array<string,string>):string $t tlumaczenie w domenie modulu
     * @param array{rows:array<int,array<string,mixed>>,all:array<string,mixed>} $data
     *
     * @return array{rows:array<int,array<string,mixed>>,all:array<string,mixed>}
     */
    public static function translated(array $data, callable $t): array
    {
        foreach ($data['rows'] as $i => $row) {
            [$text, $params] = self::relative($row['lastRun'] ?? null);

            $data['rows'][$i]['name'] = $t((string) $row['name'], []);
            $data['rows'][$i]['desc'] = $t((string) $row['desc'], []);
            $data['rows'][$i]['every'] = $t((string) $row['every'], []);
            $data['rows'][$i]['when'] = $text === '' ? '' : $t($text, $params);
        }

        return $data;
    }

    /**
     * Czas ostatniego uruchomienia jako tekst do wiersza ("4 min ago", "today 02:30").
     * Zwraca [szablon tlumaczenia, parametry]; '' gdy grupa nie biegla nigdy.
     *
     * @return array{0:string,1:array<string,string>}
     */
    public static function relative(?string $datetime, ?int $now = null): array
    {
        if ($datetime === null || $datetime === '') {
            return ['', []];
        }

        $ts = strtotime($datetime);
        $now ??= Clock::time();

        if ($ts === false) {
            return ['', []];
        }

        $seconds = max(0, $now - $ts);

        if ($seconds < 60) {
            return ['just now', []];
        }

        if ($seconds < 3600) {
            return ['%n% min ago', ['%n%' => (string) intdiv($seconds, 60)]];
        }

        if ($seconds < 6 * 3600) {
            $hours = intdiv($seconds, 3600);

            return [$hours === 1 ? '1 hour ago' : '%n% hours ago', ['%n%' => (string) $hours]];
        }

        if (date('Y-m-d', $ts) === date('Y-m-d', $now)) {
            return ['today %time%', ['%time%' => date('H:i', $ts)]];
        }

        return ['%date%', ['%date%' => date('Y-m-d H:i', $ts)]];
    }
}
