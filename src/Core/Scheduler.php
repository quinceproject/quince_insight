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

use Db;
use Quince\Insight\Core\Dto\CronTask;

/**
 * Harmonogram: piec grup zadan cyklicznych o roznej czestotliwosci plus adres zbiorczy.
 *
 * PrestaShop nie ma wlasnego zegara — przebieg uruchamia cron serwera, wolajac adres
 * kontrolera `cron` osobno dla kazdego sklepu. Czestotliwosc ustawia user na serwerze;
 * modul podaje rekomendacje i pilnuje, zeby zbyt czesty cron nie robil pracy na darmo.
 */
final class Scheduler
{
    public const GROUP_ORDERS = 'orders';
    public const GROUP_PRODUCTS = 'products';
    public const GROUP_STATUSES = 'statuses';
    public const GROUP_SITEMAP = 'sitemap';
    public const GROUP_MAINTENANCE = 'maintenance';
    public const GROUP_ALL = 'all';

    /** Rekomendowana czestotliwosc grup w minutach. */
    public const GROUPS = ['orders' => 10, 'products' => 15, 'statuses' => 180, 'sitemap' => 1440, 'maintenance' => 1440];

    /** Rekomendacja dla adresu zbiorczego `group=all`. */
    public const ALL_MINUTES = 5;

    /** Grupy potrzebne tylko przy wybranych uslugach kreatora (dodatkowo do zadan uslug). */
    private const GROUP_NEEDS = ['orders' => ['gtm', 'ga4']];

    /** Budzet jednego wywolania crona w sekundach; reszta zadan idzie przy nastepnym. */
    private const BUDGET_SECONDS = 50;

    /** Ile z budzetu moga zajac niedokonczone zadania w tle, zanim rusza zadania cykliczne. */
    private const JOBS_SECONDS = 25;

    /** Tolerancja dla `group=all`: cron odpalony o kilka sekund za wczesnie nie moze przeskoczyc tury. */
    private const DUE_TOLERANCE = 90;

    /** Grupy, ktorych wywolanie dokancza takze zadania w tle sklepu. */
    private const GROUPS_WITH_JOBS = [self::GROUP_ORDERS, self::GROUP_PRODUCTS, self::GROUP_ALL];

    public function __construct(
        private readonly ServiceRegistry $registry,
        private readonly Services $services,
        private readonly JobRunner $jobs,
        private readonly ShopRepository $shops,
    ) {
    }

    /**
     * Uruchamia zadania grupy (albo wszystkich przy 'all'). Zwraca raport do odpowiedzi crona.
     *
     * @param string $source cron | manual ("Run now" w Settings — pomija minimalne odstepy)
     *
     * @return array<string,mixed>
     */
    public function run(int $idShop, string $group, string $source = 'cron'): array
    {
        $report = ['shop' => $idShop, 'group' => $group, 'ran' => [], 'jobs' => 0, 'skipped' => []];

        if ($group !== self::GROUP_ALL && !isset(self::GROUPS[$group])) {
            $report['error'] = 'Unknown group.';

            return $report;
        }

        if (!ShopContext::exists($idShop)) {
            $report['error'] = 'Unknown shop.';

            return $report;
        }

        // Sklep wylaczony: crony i wysylka wstrzymane, konfiguracja zostaje.
        if (!ShopContext::isActive($idShop)) {
            $report['paused'] = true;

            return $report;
        }

        // Sklep bez ukonczonego kreatora: nic z modulu w nim nie dziala.
        if (!$this->shops->isSetUp($idShop)) {
            $report['skipped'][] = 'not_set_up';

            return $report;
        }

        $lock = 'qi_cron_' . _DB_PREFIX_ . $idShop;

        // Jeden przebieg na sklep naraz. Blokada nazwana MySQL zwalnia sie sama, gdy
        // proces padnie — nie zostawia po sobie zatrzasnietego pliku ani wiersza.
        if ((int) Db::getInstance()->getValue('SELECT GET_LOCK("' . pSQL($lock) . '", 0)', false) !== 1) {
            $report['skipped'][] = 'busy';

            return $report;
        }

        $started = microtime(true);
        $deadline = $started + self::BUDGET_SECONDS;
        $source = $source === 'manual' ? 'manual' : 'cron';

        try {
            $report = ShopContext::run($idShop, function () use ($idShop, $group, $source, $started, $deadline, $report): array {
                if (in_array($group, self::GROUPS_WITH_JOBS, true)) {
                    $report['jobs'] = $this->jobs->resumeDue($idShop, min($deadline, $started + self::JOBS_SECONDS));
                }

                $groups = $group === self::GROUP_ALL ? $this->dueGroups($idShop) : [$group];

                foreach ($groups as $key) {
                    $groupStarted = microtime(true);
                    $complete = true;
                    $results = $this->runGroup($idShop, $key, $source, $deadline, $complete);
                    $status = 'ok';

                    foreach ($results as $result) {
                        $report['ran'][] = $result;

                        if ($result['status'] === 'error') {
                            $status = 'error';
                        }
                    }

                    if (!$complete) {
                        $report['skipped'][] = 'budget:' . $key;
                    }

                    if (self::marksGroupRun($group, $complete)) {
                        $this->saveGroup($idShop, $key, $status, (int) round((microtime(true) - $groupStarted) * 1000), $source);
                    }
                }

                // Adres zbiorczy ma wlasny slad ostatniego uruchomienia — do ekranu Cron.
                if ($group === self::GROUP_ALL) {
                    $this->saveGroup($idShop, self::GROUP_ALL, 'ok', (int) round((microtime(true) - $started) * 1000), $source);
                }

                return $report;
            });
        } finally {
            Db::getInstance()->getValue('SELECT RELEASE_LOCK("' . pSQL($lock) . '")', false);
        }

        $report['ms'] = (int) round((microtime(true) - $started) * 1000);

        return $report;
    }

    /**
     * Grupy potrzebne wlaczonym uslugom sklepu. `maintenance` jest zawsze (zadania rdzenia).
     *
     * @return string[]
     */
    public function groupsFor(int $idShop): array
    {
        $needed = [];

        foreach ($this->tasksFor($idShop) as $task) {
            $needed[$task->group] = true;
        }

        // Usluga Events ma `requires() = null` (sama sprawdza gtm/ga4), wiec jej zadania
        // wpadalyby do kazdego sklepu. Grupa `orders` ma sens tylko przy Tag Managerze albo
        // Analytics (prototyp `CRON_GROUPS.needs`, prosba agenta C) — inaczej pulpit
        // zglaszalby "spozniony" cron, ktorego user nie musi ustawiac.
        foreach (self::GROUP_NEEDS as $group => $keys) {
            if (isset($needed[$group]) && array_intersect($keys, $this->services->enabledKeys($idShop)) === []) {
                unset($needed[$group]);
            }
        }

        return array_values(array_filter(array_keys(self::GROUPS), static fn (string $key): bool => isset($needed[$key])));
    }

    /**
     * Stan grupy: last_run, last_status, overdue(bool) oraz czas trwania i zrodlo.
     *
     * Grupa jest spozniona, gdy nie bylo jej przez 3x rekomendacje (minimum 1 h).
     * Dla grupy, ktora nie biegla nigdy, czas liczy sie od zalozenia wiersza sklepu.
     *
     * @return array{last_run:?string,last_status:string,last_duration_ms:int,source:string,overdue:bool,recommended_minutes:int}
     */
    public function groupState(int $idShop, string $group): array
    {
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . Schema::table(Schema::CRON_GROUP) . '`
             WHERE `id_shop` = ' . $idShop . ' AND `group_key` = "' . pSQL($group) . '"',
            false
        );

        $lastRun = is_array($row) && !empty($row['last_run']) ? (string) $row['last_run'] : null;
        $recommended = $group === self::GROUP_ALL ? self::ALL_MINUTES : (self::GROUPS[$group] ?? 0);
        $since = Clock::secondsSince($lastRun ?? ($this->shops->find($idShop)['date_add'] ?? null));

        return [
            'last_run' => $lastRun,
            'last_status' => is_array($row) ? (string) $row['last_status'] : '',
            'last_duration_ms' => is_array($row) ? (int) $row['last_duration_ms'] : 0,
            'source' => is_array($row) ? (string) $row['source'] : '',
            'overdue' => self::isOverdue($since, $recommended),
            'recommended_minutes' => $recommended,
        ];
    }

    /**
     * Czy zapisac grupe jako uruchomiona. Przy adresie zbiorczym `all` tylko wtedy, gdy
     * wykonaly sie wszystkie jej zadania — grupa przerwana na budzecie zostaje nalezna
     * i idzie przy nastepnym wywolaniu (R-03). Grupa wywolana wlasnym adresem zapisuje
     * sie zawsze (ma wlasny harmonogram, a zadania od najdawniej uruchomionego).
     */
    public static function marksGroupRun(string $calledGroup, bool $complete): bool
    {
        return $calledGroup !== self::GROUP_ALL || $complete;
    }

    /** Czy grupa jest spozniona: brak uruchomienia przez 3x rekomendacje, minimum 1 h. */
    public static function isOverdue(?int $secondsSinceLastRun, int $recommendedMinutes): bool
    {
        if ($secondsSinceLastRun === null || $recommendedMinutes <= 0) {
            return false;
        }

        return $secondsSinceLastRun > max(3600, 3 * $recommendedMinutes * 60);
    }

    /**
     * Czy grupa wywolana adresem zbiorczym ma teraz isc: nie czesciej niz jej rekomendacja
     * (inaczej mapa witryny budowalaby sie co 5 minut).
     */
    public static function isDue(?int $secondsSinceLastRun, int $recommendedMinutes): bool
    {
        return $secondsSinceLastRun === null
            || $secondsSinceLastRun >= $recommendedMinutes * 60 - self::DUE_TOLERANCE;
    }

    /** Adres crona grupy (albo `all`) dla sklepu — w domenie tego sklepu. */
    public function url(int $idShop, string $group): string
    {
        return ShopContext::baseUrl($idShop) . 'index.php?' . http_build_query([
            'fc' => 'module',
            'module' => 'quince_insight',
            'controller' => 'cron',
            'group' => $group,
            'token' => self::token($idShop),
        ]);
    }

    /**
     * Token crona sklepu. Wyprowadzony, nie zapisany: z klucza sklepu, id sklepu i wersji
     * tokenu. "New token" podnosi wersje i stare adresy przestaja dzialac.
     */
    public static function token(int $idShop): string
    {
        $version = Options::int(CoreOptions::CRON_TOKEN_VER, 1, $idShop);

        return substr(hash_hmac('sha256', 'cron.' . $idShop . '.' . $version, (string) _COOKIE_KEY_), 0, 32);
    }

    public static function checkToken(int $idShop, string $token): bool
    {
        return $token !== '' && hash_equals(self::token($idShop), $token);
    }

    /** Uniewaznia dotychczasowe adresy crona sklepu. */
    public function rotateToken(int $idShop): void
    {
        Options::set(CoreOptions::CRON_TOKEN_VER, Options::int(CoreOptions::CRON_TOKEN_VER, 1, $idShop) + 1, $idShop);
    }

    /**
     * Zadania cykliczne sklepu: rdzen plus uslugi wlaczone w tym sklepie.
     *
     * @return CronTask[]
     */
    public function tasksFor(int $idShop): array
    {
        $out = $this->registry->coreCronTasks();

        foreach ($this->registry->all() as $service) {
            $requires = $service->requires();

            if ($requires !== null && !$this->services->enabled($idShop, $requires)) {
                continue;
            }

            foreach ($service->cronTasks() as $task) {
                if ($task instanceof CronTask && isset(self::GROUPS[$task->group])) {
                    $out[] = $task;
                }
            }
        }

        return $out;
    }

    /**
     * Grupy, ktore przy wywolaniu `group=all` maja teraz isc.
     *
     * @return string[]
     */
    private function dueGroups(int $idShop): array
    {
        $out = [];

        foreach ($this->groupsFor($idShop) as $group) {
            $state = $this->groupState($idShop, $group);

            if (self::isDue(Clock::secondsSince($state['last_run']), self::GROUPS[$group])) {
                $out[] = $group;
            }
        }

        return $out;
    }

    /**
     * @return array<int,array{task:string,status:string,message:string,ms:int}>
     */
    private function runGroup(int $idShop, string $group, string $source, float $deadline, bool &$complete = true): array
    {
        $complete = true;

        $tasks = array_values(array_filter(
            $this->tasksFor($idShop),
            static fn (CronTask $task): bool => $task->group === $group
        ));

        if ($tasks === []) {
            return [];
        }

        $states = $this->taskStates($idShop);

        // Od najdawniej uruchomionego (nigdy nieuruchomione na poczatku) — zadanie,
        // ktore nie zmiescilo sie w budzecie, przy nastepnym wywolaniu idzie pierwsze.
        usort($tasks, static fn (CronTask $a, CronTask $b): int => strcmp(
            (string) ($states[$a->key]['last_run'] ?? ''),
            (string) ($states[$b->key]['last_run'] ?? '')
        ));

        $results = [];

        foreach ($tasks as $task) {
            $since = Clock::secondsSince($states[$task->key]['last_run'] ?? null);

            if ($source !== 'manual' && $task->minMinutes > 0 && $since !== null && $since < $task->minMinutes * 60) {
                continue;
            }

            if (microtime(true) + min($task->maxSeconds, 5) > $deadline) {
                $complete = false;

                break;
            }

            $taskStarted = microtime(true);

            try {
                $handler = Container::get($task->handlerClass);

                if (!method_exists($handler, 'run')) {
                    throw new \LogicException($task->handlerClass . ' has no run() method.');
                }

                $message = (string) $handler->run($idShop);
                $status = 'ok';
            } catch (\Throwable $e) {
                $message = $e->getMessage();
                $status = 'error';

                Log::write($idShop, 'cron', Log::ERROR, 'Task ' . $task->key . ' failed: ' . $message, [
                    'exception' => get_class($e),
                    'file' => basename($e->getFile()) . ':' . $e->getLine(),
                ]);
            }

            $ms = (int) round((microtime(true) - $taskStarted) * 1000);
            $this->saveTask($idShop, $task->key, $status, $message, $ms);

            $results[] = ['task' => $task->key, 'status' => $status, 'message' => mb_substr($message, 0, 200), 'ms' => $ms];
        }

        return $results;
    }

    /** @return array<string,array{last_run:?string}> */
    private function taskStates(int $idShop): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT `task_key`, `last_run` FROM `' . Schema::table(Schema::TASK) . '` WHERE `id_shop` = ' . $idShop
        ) ?: [];

        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row['task_key']] = ['last_run' => !empty($row['last_run']) ? (string) $row['last_run'] : null];
        }

        return $out;
    }

    private function saveTask(int $idShop, string $key, string $status, string $message, int $ms): void
    {
        Db::getInstance()->execute(
            'INSERT INTO `' . Schema::table(Schema::TASK) . '`
             (`id_shop`, `task_key`, `last_run`, `last_status`, `last_message`, `last_duration_ms`)
             VALUES (' . $idShop . ', "' . pSQL($key) . '", "' . pSQL(Clock::now()) . '", "' . pSQL($status) . '", "'
            . pSQL(mb_substr($message, 0, 500), true) . '", ' . $ms . ')
             ON DUPLICATE KEY UPDATE `last_run` = VALUES(`last_run`), `last_status` = VALUES(`last_status`),
                 `last_message` = VALUES(`last_message`), `last_duration_ms` = VALUES(`last_duration_ms`)'
        );
    }

    private function saveGroup(int $idShop, string $group, string $status, int $ms, string $source): void
    {
        Db::getInstance()->execute(
            'INSERT INTO `' . Schema::table(Schema::CRON_GROUP) . '`
             (`id_shop`, `group_key`, `last_run`, `last_status`, `last_duration_ms`, `source`)
             VALUES (' . $idShop . ', "' . pSQL($group) . '", "' . pSQL(Clock::now()) . '", "' . pSQL($status) . '", '
            . $ms . ', "' . pSQL($source) . '")
             ON DUPLICATE KEY UPDATE `last_run` = VALUES(`last_run`), `last_status` = VALUES(`last_status`),
                 `last_duration_ms` = VALUES(`last_duration_ms`), `source` = VALUES(`source`)'
        );
    }
}
