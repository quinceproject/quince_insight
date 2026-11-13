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

use Db;
use Quince\Insight\Core\Dto\Job;
use Quince\Insight\Core\Dto\JobStatus;
use Quince\Insight\Core\Google\GoogleApiException;
use Quince\Insight\Core\Google\GoogleAuthException;

/**
 * Zadania w tle (tabela `job`) — wszystko, co trwa dluzej niz jedno zadanie HTTP.
 *
 * Zadanie to ciag malych krokow. Panel popycha je zapytaniami `job_tick` co sekunde;
 * gdy karta przegladarki zniknie, kroki dokancza cron. Stan miedzy krokami lezy w bazie,
 * wiec zadanie da sie wznowic po przerwaniu i po limicie Google.
 */
final class JobRunner
{
    /** Na ile sekund krok blokuje zadanie. Dluzszy krok = przejecie przez kolejne wywolanie. */
    private const LOCK_SECONDS = 60;

    /** Odczekanie po limicie Google, gdy odpowiedz nie mowi, ile czekac. */
    private const QUOTA_WAIT = 60;

    public function __construct(private readonly ServiceRegistry $registry)
    {
    }

    /**
     * Tworzy zadanie; $type musi byc zarejestrowany przez usluge. Jedno aktywne zadanie
     * danego typu na sklep — drugie wywolanie oddaje identyfikator juz trwajacego.
     *
     * @param array<mixed> $payload
     */
    public function start(int $idShop, string $type, array $payload = []): int
    {
        if (!isset($this->registry->jobs()[$type])) {
            throw new \InvalidArgumentException('Quince Insight: unknown job type "' . $type . '".');
        }

        $active = $this->active($idShop, $type);

        if ($active !== null) {
            return $active->id;
        }

        $now = pSQL(Clock::now());

        Db::getInstance()->insert(Schema::name(Schema::JOB), [
            'id_shop' => $idShop,
            'type' => pSQL($type),
            'status' => JobStatus::PENDING,
            'done' => 0,
            'total' => 0,
            'message' => '',
            'payload' => pSQL((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true),
            'state' => '[]',
            'result' => '',
            'error' => '',
            'date_add' => $now,
            'date_upd' => $now,
        ]);

        return (int) Db::getInstance()->Insert_ID();
    }

    /**
     * Wykonuje krok, jesli zadanie nie jest zablokowane i minal czas oczekiwania.
     * W kazdym innym przypadku tylko oddaje biezacy stan.
     */
    public function tick(int $idJob): JobStatus
    {
        $row = $this->row($idJob);

        if ($row === null) {
            throw new \InvalidArgumentException('Quince Insight: job ' . $idJob . ' not found.');
        }

        $status = $this->hydrate($row);

        if (!$status->isActive() || $status->waitSeconds > 0) {
            return $status;
        }

        if (!$this->lock($idJob)) {
            return $status;
        }

        $handlerClass = $this->registry->jobs()[$status->type] ?? null;

        if ($handlerClass === null) {
            $this->finish($idJob, JobStatus::FAILED, ['error' => 'Unknown job type.']);

            return $this->status($idJob);
        }

        try {
            $handler = Container::get($handlerClass);

            if (!$handler instanceof JobHandler) {
                throw new \LogicException($handlerClass . ' does not implement JobHandler.');
            }

            $state = json_decode((string) $row['state'], true);
            $payload = json_decode((string) $row['payload'], true);

            $progress = ShopContext::run($status->idShop, static fn () => $handler->step(new Job(
                $status->id,
                $status->idShop,
                $status->type,
                is_array($payload) ? $payload : [],
                is_array($state) ? $state : [],
                $status->done,
                $status->total,
            )));

            $fields = [
                'status' => $progress->finished ? JobStatus::DONE : JobStatus::RUNNING,
                'done' => max(0, $progress->done),
                'total' => max(0, $progress->total),
                'message' => pSQL(mb_substr($progress->message, 0, 255), true),
                'state' => pSQL((string) json_encode($progress->state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true),
                'not_before' => $progress->waitSeconds > 0 ? pSQL(Clock::at($progress->waitSeconds)) : null,
                'error' => '',
            ];

            if ($progress->finished) {
                $fields['result'] = pSQL((string) json_encode($progress->result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true);
                $fields['not_before'] = null;
            }

            $this->save($idJob, $fields);
        } catch (GoogleAuthException $e) {
            // Logowanie przestalo dzialac — czekanie nic nie da, zadanie konczy sie bledem.
            $this->finish($idJob, JobStatus::FAILED, ['error' => pSQL(mb_substr($e->getMessage(), 0, 1000), true)]);
        } catch (GoogleApiException $e) {
            if ($e->isQuota() && !$e->isDailyQuota()) {
                // Limit Google: zadanie zostaje aktywne i wraca do pracy po odczekaniu.
                $wait = max(1, $e->retryAfterSeconds() ?? self::QUOTA_WAIT);

                $this->save($idJob, [
                    'status' => JobStatus::RUNNING,
                    'message' => pSQL('Waiting for Google limits', true),
                    'not_before' => pSQL(Clock::at($wait)),
                ]);
            } else {
                $this->finish($idJob, JobStatus::FAILED, ['error' => pSQL(mb_substr($e->getMessage(), 0, 1000), true)]);
            }
        } catch (\Throwable $e) {
            Log::write($status->idShop, 'core', Log::ERROR, 'Job ' . $status->type . ' failed: ' . $e->getMessage(), [
                'id_job' => $idJob,
                'exception' => get_class($e),
                'file' => basename($e->getFile()) . ':' . $e->getLine(),
            ]);

            $this->finish($idJob, JobStatus::FAILED, ['error' => pSQL(mb_substr($e->getMessage(), 0, 1000), true)]);
        }

        return $this->status($idJob);
    }

    public function status(int $idJob): JobStatus
    {
        $row = $this->row($idJob);

        if ($row === null) {
            throw new \InvalidArgumentException('Quince Insight: job ' . $idJob . ' not found.');
        }

        return $this->hydrate($row);
    }

    public function find(int $idJob): ?JobStatus
    {
        $row = $this->row($idJob);

        return $row === null ? null : $this->hydrate($row);
    }

    public function active(int $idShop, string $type): ?JobStatus
    {
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . Schema::table(Schema::JOB) . '`
             WHERE `id_shop` = ' . $idShop . ' AND `type` = "' . pSQL($type) . '"
               AND `status` IN ("' . JobStatus::PENDING . '", "' . JobStatus::RUNNING . '")
             ORDER BY `id_job` ASC',
            false
        );

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** Ostatnie zadanie danego typu w sklepie, takze zakonczone (do pokazania wyniku). */
    public function last(int $idShop, string $type): ?JobStatus
    {
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . Schema::table(Schema::JOB) . '`
             WHERE `id_shop` = ' . $idShop . ' AND `type` = "' . pSQL($type) . '"
             ORDER BY `id_job` DESC',
            false
        );

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function cancel(int $idJob): void
    {
        $null = ['type' => 'sql', 'value' => 'NULL'];

        Db::getInstance()->update(
            Schema::name(Schema::JOB),
            ['status' => JobStatus::CANCELLED, 'locked_until' => $null, 'not_before' => $null, 'date_upd' => pSQL(Clock::now())],
            '`id_job` = ' . $idJob . ' AND `status` IN ("' . JobStatus::PENDING . '", "' . JobStatus::RUNNING . '")'
        );
    }

    /**
     * Popycha niedokonczone zadania sklepu (cron). Kazde zadanie dostaje kolejne kroki,
     * dopoki nie skonczy, nie kaze czekac albo nie minie termin.
     *
     * @param float $deadline chwila (microtime), po ktorej nie zaczynamy nowego kroku
     *
     * @return int liczba wykonanych krokow
     */
    public function resumeDue(int $idShop, float $deadline): int
    {
        $rows = Db::getInstance()->executeS(
            'SELECT `id_job` FROM `' . Schema::table(Schema::JOB) . '`
             WHERE `id_shop` = ' . $idShop . '
               AND `status` IN ("' . JobStatus::PENDING . '", "' . JobStatus::RUNNING . '")
             ORDER BY `id_job` ASC'
        ) ?: [];

        $steps = 0;

        foreach ($rows as $row) {
            $idJob = (int) $row['id_job'];

            while (microtime(true) < $deadline) {
                $before = $this->find($idJob);

                if ($before === null || !$before->isActive() || $before->waitSeconds > 0) {
                    break;
                }

                $after = $this->tick($idJob);
                ++$steps;

                // Krok niczego nie zmienil: zadanie trzyma ktos inny (otwarta karta panelu).
                if ($after->isActive() && $after->done === $before->done && $after->message === $before->message && $after->waitSeconds === 0) {
                    break;
                }
            }
        }

        return $steps;
    }

    /**
     * Blokada kroku: jedno zapytanie UPDATE, ktore udaje sie tylko jednemu wywolaniu.
     * Czas z PHP po obu stronach porownania — patrz `Clock`.
     */
    private function lock(int $idJob): bool
    {
        $now = pSQL(Clock::now());

        Db::getInstance()->execute(
            'UPDATE `' . Schema::table(Schema::JOB) . '`
             SET `locked_until` = "' . pSQL(Clock::at(self::LOCK_SECONDS)) . '", `date_upd` = "' . $now . '"
             WHERE `id_job` = ' . $idJob . '
               AND `status` IN ("' . JobStatus::PENDING . '", "' . JobStatus::RUNNING . '")
               AND (`locked_until` IS NULL OR `locked_until` < "' . $now . '")'
        );

        return (int) Db::getInstance()->Affected_Rows() === 1;
    }

    /** @param array<string,mixed> $fields wartosci juz zabezpieczone; zapis zwalnia blokade */
    private function save(int $idJob, array $fields): void
    {
        $fields['locked_until'] = null;
        $fields['date_upd'] = pSQL(Clock::now());

        // Nie przez `$null_values = true` w Db::update(): ten tryb zamienia na NULL takze
        // puste napisy, a kolumny tekstowe sa tu NOT NULL.
        foreach ($fields as $name => $value) {
            if ($value === null) {
                $fields[$name] = ['type' => 'sql', 'value' => 'NULL'];
            }
        }

        // Zadanie anulowane w trakcie kroku zostaje anulowane.
        Db::getInstance()->update(
            Schema::name(Schema::JOB),
            $fields,
            '`id_job` = ' . $idJob . ' AND `status` <> "' . JobStatus::CANCELLED . '"'
        );
    }

    /** @param array<string,mixed> $fields */
    private function finish(int $idJob, string $status, array $fields): void
    {
        $fields['status'] = $status;
        $fields['not_before'] = null;

        $this->save($idJob, $fields);
    }

    /** @return array<string,mixed>|null */
    private function row(int $idJob): ?array
    {
        if ($idJob <= 0) {
            return null;
        }

        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . Schema::table(Schema::JOB) . '` WHERE `id_job` = ' . $idJob,
            false
        );

        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): JobStatus
    {
        $result = json_decode((string) $row['result'], true);
        $state = json_decode((string) $row['state'], true);
        $items = [];
        $wait = 0;

        // Ze stanu do przegladarki idzie wylacznie publiczna lista krokow (`items`).
        $failed = in_array((string) $row['status'], [JobStatus::FAILED, JobStatus::CANCELLED], true);

        foreach ((is_array($state) && is_array($state['items'] ?? null)) ? $state['items'] : [] as $item) {
            if (is_array($item) && isset($item['text'])) {
                $st = in_array($item['st'] ?? '', ['wait', 'run', 'ok', 'err'], true) ? (string) $item['st'] : 'wait';
                $public = [
                    'text' => (string) $item['text'],
                    // Zadanie przerwane bledem: krok w toku konczy sie bledem (prototyp `it.st = 'err'`).
                    'st' => $failed && $st === 'run' ? 'err' : $st,
                    'note' => (string) ($item['note'] ?? ''),
                ];

                // Parametry tlumaczenia tekstu (np. nazwa zrodla danych) — tlumaczy je panel.
                if (is_array($item['params'] ?? null) && $item['params'] !== []) {
                    $public['params'] = array_map('strval', $item['params']);
                }

                $items[] = $public;
            }
        }

        if (!empty($row['not_before'])) {
            $wait = max(0, strtotime((string) $row['not_before']) - Clock::time());
        }

        return new JobStatus(
            (int) $row['id_job'],
            (int) $row['id_shop'],
            (string) $row['type'],
            (string) $row['status'],
            (int) $row['done'],
            (int) $row['total'],
            (string) $row['message'],
            (string) $row['error'],
            is_array($result) ? $result : [],
            $wait,
            $items,
        );
    }
}
