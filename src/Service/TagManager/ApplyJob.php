<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\TagManager;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Quince\Insight\Core\Clock;
use Quince\Insight\Core\Dto\Job;
use Quince\Insight\Core\Dto\JobProgress;
use Quince\Insight\Core\Google\GoogleApiException;
use Quince\Insight\Core\Google\GoogleAuthException;
use Quince\Insight\Core\JobHandler;
use Quince\Insight\Core\Log;
use Quince\Insight\Core\Options;
use Quince\Insight\Service\TagManager\Dto\Plan;
use Quince\Insight\Service\TagManager\Dto\PlanEntity;

/**
 * Zadanie `gtm.apply`: zastosowanie planu kontenera i publikacja (tryb `auto`)
 * albo sprawdzenie wlasnego kontenera usera (tryb `own`).
 *
 * Przebieg trybu `auto` (kazda faza to jeden lub kilka wywolan; stan w `job.state`):
 *   workspace  — lista workspace'ow; nasz „Quince Insight” albo domyslny do odczytu
 *   read       — odczyt folderow, zmiennych, wyzwalaczy, tagow (po jednym rodzaju na krok)
 *                i porownanie z planem; brak roznic = koniec bez ani jednego zapisu
 *   createWs   — utworzenie workspace'u „Quince Insight” (dopiero gdy sa zmiany)
 *   bulk       — proba `bulk_update` jednym wywolaniem (ARCHITEKTURA §12 pkt 8),
 *                przy bledzie powrot do pojedynczych wywolan
 *   single     — pojedyncze zapisy w kolejnosci zaleznosci
 *   version    — `create_version` (usuwa workspace — sciezki nie zapisujemy w ustawieniach)
 *   publish    — `versions.publish`
 *
 * Tempo: najwyzej 24 wywolania w oknie 100 s (limit Google: 25 / 100 s na projekt —
 * SPEC §9 pkt 1). Po bledzie limitu krok konczy sie z `waitSeconds`, stan zostaje.
 * Idempotencja: kazdy udany zapis od razu trafia do `gtm_entity` (zanim zapisze sie stan
 * zadania), a encje sa dopasowywane po identyfikatorze i nazwie — wznowienie nie tworzy
 * duplikatow.
 */
final class ApplyJob implements JobHandler
{
    public const TYPE = 'gtm.apply';

    public const WORKSPACE = 'Quince Insight';

    /** Okno i limit wywolan (z zapasem jednego wywolania na panel). */
    private const WINDOW = 100;
    private const LIMIT = 24;

    /** Najwyzej tyle wywolan i sekund w jednym kroku zadania. */
    private const STEP_CALLS = 6;
    private const STEP_SECONDS = 6.0;

    /** Odczekanie po bledzie limitu, gdy Google nie podal czasu. */
    private const QUOTA_WAIT = 30;

    private const READ_ORDER = ['folder', 'variable', 'trigger', 'tag'];

    public const QUOTA_NOTE = 'Tag Manager allows 25 requests per 100 seconds — waiting and continuing…';

    public function __construct(
        private readonly PlanSettings $settings,
        private readonly EntityStore $store,
        private readonly GtmApi $api,
        private readonly PlanDiff $differ,
    ) {
    }

    public function step(Job $job): JobProgress
    {
        $s = $job->state;

        if (!isset($s['phase'])) {
            $s = $this->init($job);
        }

        if (($s['mode'] ?? 'auto') === GtmOptions::MODE_OWN) {
            return $this->stepOwn($job, $s);
        }

        $api = $this->api->forShop($job->idShop);
        $started = microtime(true);
        $before = $api->calls();

        while ($s['phase'] !== 'finished') {
            $s['calls'] = $this->window($s['calls'] ?? []);

            if (count($s['calls']) >= self::LIMIT) {
                $wait = max(1, (int) $s['calls'][0] + self::WINDOW - Clock::time() + 1);

                return $this->progress($s, $wait);
            }

            if ($api->calls() - $before >= self::STEP_CALLS || microtime(true) - $started > self::STEP_SECONDS) {
                break;
            }

            $callsBefore = $api->calls();

            try {
                $this->advance($job, $s, $api);
                $s['note'] = '';
            } catch (GoogleAuthException $e) {
                throw $e;
            } catch (GoogleApiException $e) {
                $this->countCalls($s, $api->calls() - $callsBefore);

                if ($e->isQuota() && !$e->isDailyQuota()) {
                    $s['note'] = self::QUOTA_NOTE;
                    $s['quotaHits'] = (int) ($s['quotaHits'] ?? 0) + 1;

                    return $this->progress($s, max(5, $e->retryAfterSeconds() ?? self::QUOTA_WAIT));
                }

                throw new \RuntimeException($this->explain($e, (string) $s['phase']), 0, $e);
            }

            $this->countCalls($s, $api->calls() - $callsBefore);
        }

        return $this->progress($s, 0);
    }

    /** @return array<string,mixed> */
    private function init(Job $job): array
    {
        $idShop = $job->idShop;
        $mode = (string) ($job->payload['mode'] ?? Options::str(GtmOptions::GTM_MODE, GtmOptions::MODE_AUTO, $idShop));
        $container = (string) ($job->payload['container'] ?? Options::str(GtmOptions::GTM_CONTAINER, '', $idShop));

        if ($container === '') {
            throw new \RuntimeException('Choose a Tag Manager container first.');
        }

        if ($mode === GtmOptions::MODE_SHARED) {
            throw new \RuntimeException('This container is managed by another shop. Update it from that shop.');
        }

        if ($mode === GtmOptions::MODE_OWN) {
            return ['phase' => 'own', 'mode' => GtmOptions::MODE_OWN, 'container' => $container];
        }

        $plan = $this->settings->plan($idShop);

        return [
            'phase' => 'workspace',
            'mode' => GtmOptions::MODE_AUTO,
            'container' => $container,
            'plan' => $plan->toArray(),
            'hash' => $plan->hash,
            'counts' => ['variable' => $plan->count('variable'), 'trigger' => $plan->count('trigger'), 'tag' => $plan->count('tag')],
            'calls' => [],
            'ws' => '',
            'wsOwn' => false,
            'wsTried' => false,
            'readQueue' => self::READ_ORDER,
            'live' => [],
            'ops' => [],
            'cursor' => 0,
            'opsTotal' => 0,
            'ids' => [],
            'bulk' => '',
            'bulkKeys' => [],
            'changed' => false,
            'versionName' => self::WORKSPACE . ' ' . date('Y-m-d H:i', Clock::time()) . ' #' . $job->id,
            'versionPath' => '',
            'versionId' => '',
            'syncTried' => false,
            'note' => '',
            'result' => '',
        ];
    }

    /** @param array<string,mixed> $s */
    private function advance(Job $job, array &$s, GtmApi $api): void
    {
        $idShop = $job->idShop;
        $container = (string) $s['container'];

        switch ($s['phase']) {
            case 'workspace':
                $list = $api->workspaces($container);
                $ours = null;
                $default = $list[0] ?? null;

                foreach ($list as $ws) {
                    if (($ws['name'] ?? '') === self::WORKSPACE) {
                        $ours = $ws;
                    } elseif (($ws['name'] ?? '') === 'Default Workspace') {
                        $default = $ws;
                    }
                }

                if ($ours !== null) {
                    $s['ws'] = (string) $ours['path'];
                    $s['wsOwn'] = true;
                    $s['phase'] = 'read';
                } elseif ($default !== null) {
                    $s['ws'] = (string) $default['path'];
                    $s['wsOwn'] = false;
                    $s['phase'] = 'read';
                } else {
                    $s['phase'] = 'createWs';
                }

                $s['readQueue'] = self::READ_ORDER;
                $s['live'] = [];

                return;

            case 'read':
                $kind = $s['readQueue'][0] ?? null;

                if ($kind !== null) {
                    // Kolejka skraca sie dopiero po udanym odczycie — limit w trakcie nie gubi rodzaju.
                    $s['live'][$kind] = $this->slim($api->entities((string) $s['ws'], $kind), $kind);
                    array_shift($s['readQueue']);

                    if ($s['readQueue'] !== []) {
                        return;
                    }
                }

                $this->compare($idShop, $s);

                return;

            case 'createWs':
                try {
                    $ws = $api->createWorkspace($container, self::WORKSPACE, 'Changes made by the Quince Insight module for PrestaShop.');
                    $s['ws'] = (string) ($ws['path'] ?? '');
                    $s['wsOwn'] = true;
                    $s['wsTried'] = true;
                    $s['readQueue'] = self::READ_ORDER;
                    $s['live'] = [];
                    $s['phase'] = 'read';
                } catch (GoogleApiException $e) {
                    if ($e->isQuota() || $e->isAuth() || (string) $s['ws'] === '') {
                        throw $e;
                    }

                    // Darmowe konto ma limit workspace'ow: pracujemy w domyslnym (jak TagPilot).
                    Log::write($idShop, 'tagmanager', Log::WARNING, 'Could not create the Quince Insight workspace, using the default one.', ['reason' => $e->reason()]);
                    $s['wsTried'] = true;
                    $s['wsOwn'] = true;
                    $s['phase'] = $s['bulk'] === '' ? 'bulk' : 'single';
                }

                return;

            case 'status':
                $status = $api->workspaceStatus((string) $s['ws']);
                $s['phase'] = empty($status['workspaceChange']) ? 'done' : 'version';

                if ($s['phase'] === 'done') {
                    $this->finish($idShop, $s);
                }

                return;

            case 'bulk':
                $this->bulk($idShop, $s, $api);

                return;

            case 'single':
                $this->single($idShop, $s, $api);

                return;

            case 'version':
                $this->version($idShop, $s, $api);

                return;

            case 'findVersion':
                $header = $api->latestVersionHeader($container);

                if (($header['name'] ?? '') !== $s['versionName'] || empty($header['path'])) {
                    throw new \RuntimeException('The workspace was removed before the version was created. Run the update again.');
                }

                $s['versionPath'] = (string) $header['path'];
                $s['versionId'] = (string) ($header['containerVersionId'] ?? '');
                $s['phase'] = 'publish';

                return;

            case 'publish':
                $result = $api->publish((string) $s['versionPath']);

                if (!empty($result['compilerError'])) {
                    throw new \RuntimeException('Tag Manager could not compile the container version. Open it in Tag Manager to see the problem.');
                }

                Options::set(GtmOptions::GTM_VERSION, (string) $s['versionId'], $idShop);
                Options::set(GtmOptions::GTM_VERSION_NAME, (string) $s['versionName'], $idShop);
                Options::set(GtmOptions::GTM_PUBLISHED_AT, Clock::now(), $idShop);
                $s['result'] = 'published';
                $this->finish($idShop, $s);

                return;

            case 'done':
                $this->finish($idShop, $s);

                return;
        }

        throw new \LogicException('Unknown phase ' . (string) $s['phase']);
    }

    /** Porownanie planu z odczytanym stanem i wybor nastepnej fazy. @param array<string,mixed> $s */
    private function compare(int $idShop, array &$s): void
    {
        $container = (string) $s['container'];
        $plan = $this->plan($s);
        $tracked = $this->store->all($idShop, $container);
        $ops = $this->differ->diff($plan, $s['live'], $tracked);
        $bulkKeys = array_flip((array) $s['bulkKeys']);
        $todo = [];
        $ids = [];

        foreach ($ops as $op) {
            if ($op['op'] === PlanDiff::FORGET) {
                $this->store->remove($idShop, $container, $op['kind'], $op['key']);

                continue;
            }

            if ($op['gtmId'] !== '') {
                $ids[$op['key']] = $op['gtmId'];
            }

            // Po udanym bulk_update encje sa juz w kontenerze — zapamietujemy je bez kolejnego zapisu.
            if ($op['op'] === PlanDiff::UPDATE && isset($bulkKeys[$op['key']])) {
                $entity = $plan->find($op['key']);

                if ($entity !== null) {
                    $this->store->save($idShop, $container, $entity->kind, $entity->key, $op['gtmId'], $entity->name, $entity->fingerprint, $op['gtmFingerprint']);
                }

                continue;
            }

            if ($op['op'] !== PlanDiff::KEEP) {
                // Identyfikator z `gtm_entity` w chwili planowania: inny przy wznowieniu = zapis juz sie udal.
                $op['trackedId'] = (string) ($tracked[$op['key']]['gtm_id'] ?? '');
                $todo[] = $op;
            }
        }

        $s['ids'] = $ids;
        $s['ops'] = $todo;
        $s['cursor'] = 0;
        $s['opsTotal'] = max((int) $s['opsTotal'], count($todo));
        $s['live'] = [];

        if ($todo === []) {
            if ($s['changed']) {
                $s['phase'] = 'version';
            } elseif ($s['wsOwn']) {
                // Nasz workspace z poprzedniego, przerwanego przebiegu moze miec niezapisane zmiany.
                $s['phase'] = 'status';
            } else {
                $s['phase'] = 'done';
            }

            return;
        }

        if (!$s['wsOwn'] && !$s['wsTried']) {
            $s['phase'] = 'createWs';

            return;
        }

        $s['phase'] = $s['bulk'] === '' ? 'bulk' : 'single';
    }

    /** @param array<string,mixed> $s */
    private function bulk(int $idShop, array &$s, GtmApi $api): void
    {
        $plan = $this->plan($s);
        $ids = (array) $s['ids'];
        $changes = [];
        $keys = [];
        $n = 0;

        foreach ($s['ops'] as $op) {
            $kind = $op['kind'];
            $field = PlanDiff::ID_FIELDS[$kind];

            if ($op['op'] === PlanDiff::DELETE) {
                $changes[] = [$kind => [$field => $op['gtmId'], 'path' => $s['ws'] . '/' . GtmApi::COLLECTIONS[$kind] . '/' . $op['gtmId']], 'changeStatus' => 'deleted'];

                continue;
            }

            $entity = $plan->find($op['key']);

            if ($entity === null) {
                continue;
            }

            if ($op['op'] === PlanDiff::CREATE) {
                $ids[$entity->key] = 'new_' . (++$n);
                $body = $this->resolve($entity, $ids);
                $body[$field] = $ids[$entity->key];
                $changes[] = [$kind => $body, 'changeStatus' => 'added'];
            } else {
                $body = $this->resolve($entity, $ids);
                $body[$field] = $op['gtmId'];
                $body['fingerprint'] = $op['gtmFingerprint'];
                $changes[] = [$kind => $body, 'changeStatus' => 'updated'];
            }

            $keys[] = $entity->key;
        }

        $s['bulk'] = 'tried';

        try {
            $api->bulkUpdate((string) $s['ws'], $changes);
            $s['bulk'] = 'ok';
            $s['bulkKeys'] = $keys;
            $s['changed'] = true;
        } catch (GoogleApiException $e) {
            if ($e->isQuota() || $e instanceof GoogleAuthException) {
                $s['bulk'] = '';

                throw $e;
            }

            $s['bulk'] = 'failed';
            Log::write($idShop, 'tagmanager', Log::WARNING, 'bulk_update failed, switching to single calls.', ['reason' => $e->reason(), 'http' => $e->httpCode()]);
        }

        // Po bulk (udanym albo nie) czytamy kontener od nowa: dopasowanie po nazwie
        // wychwyci to, co zdazylo powstac, wiec pojedyncze wywolania nie zrobia duplikatow.
        $s['readQueue'] = self::READ_ORDER;
        $s['live'] = [];
        $s['phase'] = 'read';
    }

    /** Jeden zapis z kolejki. @param array<string,mixed> $s */
    private function single(int $idShop, array &$s, GtmApi $api): void
    {
        $ops = (array) $s['ops'];
        $cursor = (int) $s['cursor'];

        if (!isset($ops[$cursor])) {
            $s['changed'] = $s['changed'] || $ops !== [];
            $s['phase'] = 'version';

            return;
        }

        $op = $ops[$cursor];
        $kind = (string) $op['kind'];
        $field = PlanDiff::ID_FIELDS[$kind];
        $container = (string) $s['container'];
        $ws = (string) $s['ws'];

        if ($op['op'] === PlanDiff::DELETE) {
            try {
                $api->delete($ws, $kind, (string) $op['gtmId']);
            } catch (GoogleApiException $e) {
                if ($e->httpCode() !== 404) {
                    throw $e;
                }
            }

            $this->store->remove($idShop, $container, $kind, (string) $op['key']);
        } else {
            $entity = $this->plan($s)->find((string) $op['key']);

            if ($entity === null) {
                throw new \LogicException('Plan entity ' . $op['key'] . ' is missing.');
            }

            $tracked = $this->store->all($idShop, $container)[$entity->key] ?? null;
            $body = $this->resolve($entity, (array) $s['ids']);

            if ($op['op'] === PlanDiff::CREATE && $tracked !== null && $tracked['gtm_id'] !== '' && $tracked['gtm_id'] !== (string) ($op['trackedId'] ?? '')) {
                // Zapis z przerwanego kroku juz sie udal (wiersz zapisany przed stanem zadania).
                $s['ids'][$entity->key] = $tracked['gtm_id'];
                ++$s['cursor'];
                $s['changed'] = true;

                return;
            }

            try {
                $saved = $op['op'] === PlanDiff::CREATE
                    ? $api->create($ws, $kind, $body)
                    : $api->update($ws, $kind, (string) $op['gtmId'], $body, (string) $op['gtmFingerprint']);
            } catch (GoogleApiException $e) {
                if ($op['op'] === PlanDiff::UPDATE && $e->httpCode() === 404) {
                    // Encje usunieto w GTM w miedzyczasie — tworzymy ja od nowa.
                    $s['ops'][$cursor]['op'] = PlanDiff::CREATE;

                    return;
                }

                if (($e->httpCode() === 400 || $e->httpCode() === 409) && empty($s['resynced'][$entity->key])) {
                    // Duplikat nazwy albo nieaktualny fingerprint: czytamy kontener jeszcze raz.
                    $s['resynced'][$entity->key] = true;
                    $s['readQueue'] = self::READ_ORDER;
                    $s['live'] = [];
                    $s['phase'] = 'read';

                    return;
                }

                throw $e;
            }

            $id = (string) ($saved[$field] ?? $op['gtmId']);

            if ($id === '') {
                throw new \RuntimeException('Tag Manager did not return an ID for ' . $entity->name . '.');
            }

            $this->store->save($idShop, $container, $kind, $entity->key, $id, $entity->name, $entity->fingerprint, (string) ($saved['fingerprint'] ?? ''));
            $s['ids'][$entity->key] = $id;
        }

        ++$s['cursor'];
        $s['changed'] = true;
    }

    /**
     * Krotki opis odpowiedzi `create_version`: flagi i lista pol, bez tresci encji.
     *
     * @param array<string,mixed> $result
     */
    public static function versionResponseSummary(array $result): string
    {
        $flag = static fn (mixed $v): string => $v === null ? 'missing' : (!empty($v) ? 'true' : 'false');
        $sync = is_array($result['syncStatus'] ?? null) ? $result['syncStatus'] : [];
        $version = is_array($result['containerVersion'] ?? null) ? $result['containerVersion'] : null;

        $parts = [
            'compilerError=' . $flag($result['compilerError'] ?? null),
            'mergeConflict=' . $flag($sync['mergeConflict'] ?? null),
            'syncError=' . $flag($sync['syncError'] ?? null),
            'containerVersion=' . ($version === null ? 'missing' : 'present (fields: ' . implode(', ', array_slice(array_keys($version), 0, 12)) . ')'),
            'newWorkspacePath=' . (!empty($result['newWorkspacePath']) ? 'present' : 'missing'),
            'response fields: ' . ($result === [] ? 'none' : implode(', ', array_keys($result))),
        ];

        return implode('; ', $parts);
    }

    /** @param array<string,mixed> $s */
    private function version(int $idShop, array &$s, GtmApi $api): void
    {
        try {
            $result = $api->createVersion((string) $s['ws'], (string) $s['versionName'], 'Published by the Quince Insight module for PrestaShop.');
        } catch (GoogleApiException $e) {
            if ($e->httpCode() === 404) {
                // Wersja mogla powstac w przerwanym kroku (create_version usuwa workspace).
                $s['phase'] = 'findVersion';

                return;
            }

            throw $e;
        }

        if (!empty($result['syncStatus']['mergeConflict'])) {
            if ($s['syncTried']) {
                throw new \RuntimeException('The workspace “Quince Insight” has conflicts with the latest container version. Resolve them in Tag Manager and run the update again.');
            }

            $s['syncTried'] = true;
            $api->syncWorkspace((string) $s['ws']);

            return;
        }

        $version = is_array($result['containerVersion'] ?? null) ? $result['containerVersion'] : [];
        $versionId = (string) ($version['containerVersionId'] ?? '');
        $path = (string) ($version['path'] ?? '');

        // Wersja jest, ale bez `path` w odpowiedzi — sciezke da sie zlozyc z kontenera i numeru.
        if ($path === '' && $versionId !== '' && empty($result['compilerError'])) {
            $path = rtrim((string) $s['container'], '/') . '/versions/' . $versionId;
        }

        // Bez bledu kompilacji i bez wersji w odpowiedzi, ale z nowym obszarem roboczym:
        // `create_version` usunal obszar, wiec wersja najpewniej powstala — szukamy jej po nazwie.
        if ($path === '' && empty($result['compilerError']) && !empty($result['newWorkspacePath'])) {
            $s['phase'] = 'findVersion';

            return;
        }

        if (!empty($result['compilerError']) || $path === '') {
            // Odpowiedz Google (bez danych osobowych i sekretow) — zeby bylo widac prawdziwa przyczyne.
            $details = self::versionResponseSummary($result);
            Log::write($idShop, 'tagmanager', Log::ERROR, 'create_version did not return a version', ['response' => $details]);

            throw new \RuntimeException('Tag Manager could not create the container version. Open the workspace “Quince Insight” in Tag Manager to see the problem. — Google: ' . $details);
        }

        $s['versionPath'] = $path;
        $s['versionId'] = $versionId;
        $s['ws'] = '';
        $s['phase'] = 'publish';
    }

    /** @param array<string,mixed> $s */
    private function finish(int $idShop, array &$s): void
    {
        Options::set(GtmOptions::GTM_PLAN_HASH, (string) $s['hash'], $idShop);

        if ($s['result'] === '') {
            $s['result'] = 'unchanged';
        }

        $s['phase'] = 'finished';
    }

    /** Tryb „I manage the container myself”: tylko sprawdzenie dostepu i zapis. @param array<string,mixed> $s */
    private function stepOwn(Job $job, array $s): JobProgress
    {
        $items = [
            ['text' => 'Saving container ID', 'st' => 'run'],
            ['text' => 'Adding the GTM snippet and the dataLayer to the shop', 'st' => 'wait'],
        ];

        if ($s['phase'] === 'own') {
            $container = $this->api->forShop($job->idShop)->container((string) $s['container']);

            if (!empty($container['publicId'])) {
                Options::set(GtmOptions::GTM_PUBLIC_ID, (string) $container['publicId'], $job->idShop);
                Options::set(GtmOptions::GTM_CONTAINER_NAME, (string) ($container['name'] ?? ''), $job->idShop);
            }

            $s['phase'] = 'ownDone';
            $items[0]['st'] = 'ok';
            $items[1]['st'] = 'run';

            return new JobProgress(1, 2, 'Saving container ID', false, 0, $s + ['items' => $items]);
        }

        $items[0]['st'] = 'ok';
        $items[1]['st'] = 'ok';
        unset($s['items']);

        return new JobProgress(2, 2, 'Done', true, 0, $s + ['items' => $items], ['mode' => GtmOptions::MODE_OWN]);
    }

    /** Postep do paska: kroki 1:1 z prototypu (`taskLines('gtm')`). @param array<string,mixed> $s */
    private function progress(array $s, int $wait): JobProgress
    {
        $phase = (string) $s['phase'];
        $finished = $phase === 'finished';
        $ops = (array) $s['ops'];
        $cursor = (int) $s['cursor'];
        $writes = (int) $s['opsTotal'];
        $counts = (array) $s['counts'];
        $order = ['workspace' => 0, 'read' => 0, 'createWs' => 0, 'status' => 0, 'bulk' => 1, 'single' => 1, 'version' => 2, 'findVersion' => 2, 'publish' => 3, 'done' => 4, 'finished' => 4];
        $at = $order[$phase] ?? 0;
        $unchanged = $finished && $s['result'] === 'unchanged';

        // Stan wierszy zmiennych / wyzwalaczy / tagow: wedlug rodzaju biezacej operacji.
        $kindState = static function (string $kind) use ($at, $phase, $ops, $cursor): string {
            if ($at > 1) {
                return 'ok';
            }

            if ($at < 1) {
                return 'wait';
            }

            if ($phase === 'bulk') {
                return 'run';
            }

            $left = array_filter(array_slice($ops, $cursor), static fn (array $op): bool => $op['kind'] === $kind || ($kind === 'variable' && $op['kind'] === 'folder'));

            if ($left === []) {
                return 'ok';
            }

            $current = $ops[$cursor]['kind'] ?? '';

            return $current === $kind || ($kind === 'variable' && $current === 'folder') ? 'run' : 'wait';
        };

        $minutes = (int) ceil(max(0, $writes + 10 - self::LIMIT) * (self::WINDOW / 25) / 60);
        $prepare = 'Preparing workspace “' . self::WORKSPACE . '”' . ($minutes >= 2 ? ' — about ' . $minutes . '–' . ($minutes + 1) . ' minutes, Tag Manager limits the pace' : '');
        $note = (string) ($s['note'] ?? '');

        $items = [
            ['text' => $prepare, 'st' => $at > 0 || $finished ? 'ok' : 'run', 'note' => $at === 0 ? $note : ''],
            ['text' => 'Data layer variables — ' . (int) ($counts['variable'] ?? 0), 'st' => $finished ? 'ok' : $kindState('variable')],
            ['text' => 'Custom event triggers — ' . (int) ($counts['trigger'] ?? 0), 'st' => $finished ? 'ok' : $kindState('trigger')],
            ['text' => 'Tags — ' . (int) ($counts['tag'] ?? 0), 'st' => $finished ? 'ok' : $kindState('tag'), 'note' => $at === 1 ? $note : ''],
            ['text' => 'Creating container version', 'st' => $finished ? 'ok' :($at === 2 ? 'run' : ($at > 2 ? 'ok' : 'wait')), 'note' => $at === 2 ? $note : ''],
            ['text' => 'Publishing', 'st' => $finished ? 'ok' : ($at === 3 ? 'run' : 'wait'), 'note' => $at === 3 ? $note : ''],
        ];

        $doneWrites = $phase === 'single' ? $cursor : ($at > 1 ? $writes : 0);
        $total = 2 + $writes + 2;
        $done = min($total, ($at > 0 ? 2 : ($phase === 'read' ? 1 : 0)) + $doneWrites + ($at > 2 ? 1 : 0) + ($finished ? 1 : 0));

        if ($finished) {
            $done = $total;
        }

        $message = match (true) {
            $unchanged => 'The container is already up to date.',
            $finished => 'Container published',
            $at === 0 => 'Preparing workspace',
            $at === 1 => 'Updating tags, triggers and variables…',
            $at === 2 => 'Creating container version',
            default => 'Publishing',
        };

        $state = $s;
        $state['items'] = $items;

        return new JobProgress(
            $done,
            $total,
            $message,
            $finished,
            $finished ? 0 : $wait,
            $state,
            $finished ? [
                'result' => $s['result'],
                'version' => (string) $s['versionId'],
                'versionName' => $s['result'] === 'published' ? (string) $s['versionName'] : '',
                'writes' => $writes,
                'bulk' => (string) $s['bulk'],
                'hash' => (string) $s['hash'],
            ] : [],
        );
    }

    /** Odwolania `@klucz` -> identyfikatory GTM. @param array<string,string> $ids */
    private function resolve(PlanEntity $entity, array $ids): array
    {
        $body = $entity->body;

        if (isset($body['parentFolderId']) && str_starts_with((string) $body['parentFolderId'], '@')) {
            $ref = substr((string) $body['parentFolderId'], 1);

            if (isset($ids[$ref])) {
                $body['parentFolderId'] = $ids[$ref];
            } else {
                unset($body['parentFolderId']);
            }
        }

        foreach (['firingTriggerId', 'blockingTriggerId'] as $field) {
            if (!isset($body[$field])) {
                continue;
            }

            $body[$field] = array_map(static function ($id) use ($ids, $entity): string {
                $id = (string) $id;

                if (!str_starts_with($id, '@')) {
                    return $id;
                }

                $ref = substr($id, 1);

                if (!isset($ids[$ref])) {
                    throw new \RuntimeException('Missing trigger for ' . $entity->name . '.');
                }

                return $ids[$ref];
            }, (array) $body[$field]);
        }

        return $body;
    }

    /**
     * Z odczytu zostawiamy tylko to, czego potrzebuje porownanie (stan zadania lezy w bazie).
     *
     * @param array<int,array<string,mixed>> $list
     *
     * @return array<int,array<string,string>>
     */
    private function slim(array $list, string $kind): array
    {
        $field = PlanDiff::ID_FIELDS[$kind];
        $out = [];

        foreach ($list as $entity) {
            $out[] = [
                $field => (string) ($entity[$field] ?? ''),
                'name' => (string) ($entity['name'] ?? ''),
                'fingerprint' => (string) ($entity['fingerprint'] ?? ''),
            ];
        }

        return $out;
    }

    /** @param array<string,mixed> $s */
    private function plan(array $s): Plan
    {
        return Plan::fromArray((array) $s['plan'], (string) $s['hash']);
    }

    /** @param array<string,mixed> $s */
    private function countCalls(array &$s, int $n): void
    {
        for ($i = 0; $i < $n; ++$i) {
            $s['calls'][] = Clock::time();
        }
    }

    /** @param array<int,int> $calls @return array<int,int> wywolania z ostatnich 100 s */
    private function window(array $calls): array
    {
        $from = Clock::time() - self::WINDOW;

        return array_values(array_filter(array_map('intval', $calls), static fn (int $t): bool => $t > $from));
    }

    private function explain(GoogleApiException $e, string $phase): string
    {
        if ($e->isApiDisabled()) {
            return 'The Tag Manager API is not enabled in your Google Cloud project. Enable it in the API library and run the update again.';
        }

        if ($e->isDailyQuota()) {
            return 'The daily Tag Manager limit has been reached. The update can continue tomorrow.';
        }

        if ($e->isAuth()) {
            return match ($phase) {
                'publish' => 'The Google account cannot publish this container. In Tag Manager, give it the Publish permission and run the update again.',
                'version' => 'The Google account cannot create container versions. In Tag Manager, give it the Approve or Publish permission and run the update again.',
                default => 'The Google account cannot edit this container. In Tag Manager, give it the Edit permission and run the update again.',
            };
        }

        return 'Tag Manager returned an error: ' . $e->getMessage();
    }
}
