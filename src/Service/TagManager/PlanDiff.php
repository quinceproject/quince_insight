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

use Quince\Insight\Service\TagManager\Dto\Plan;

/**
 * Porownanie planu ze stanem kontenera. Czysta funkcja.
 *
 * Dopasowanie encji: najpierw po identyfikatorze GTM zapamietanym w `gtm_entity`, potem po
 * nazwie (encje z poprzedniej instalacji albo utracony wpis). Encja jest „bez zmian”, gdy
 * nasz hash definicji sie zgadza ORAZ fingerprint GTM jest ten sam co po naszym ostatnim
 * zapisie — reczna zmiana w GTM daje wiec „Update”. Encje spoza modulu zostaja nietkniete;
 * usuwane sa tylko encje, ktore modul sam utworzyl, a ktorych nie ma juz w planie.
 */
final class PlanDiff
{
    public const CREATE = 'create';
    public const UPDATE = 'update';
    public const DELETE = 'delete';
    public const KEEP = 'keep';
    /** Wpis w `gtm_entity` bez encji w kontenerze i bez miejsca w planie — tylko usuniecie wiersza. */
    public const FORGET = 'forget';
    /**
     * Tag Google spoza modulu dla tego samego identyfikatora (G-… / AW-…) juz jest w kontenerze:
     * modul nie tworzy drugiego (Tag Manager odrzuca wersje z konfliktem tagow Google),
     * tylko dopisuje do istniejacego `send_page_view: false` (decyzja #9).
     */
    public const ADOPT = 'adopt';
    /** Jak ADOPT, ale istniejacy tag Google ma juz `send_page_view: false` — bez zapisu. */
    public const ADOPTED = 'adopted';

    public const ID_FIELDS = ['tag' => 'tagId', 'trigger' => 'triggerId', 'variable' => 'variableId', 'folder' => 'folderId'];

    /**
     * @param array<string,array<int,array<string,mixed>>> $live rodzaj (tag|trigger|variable|folder) => encje z GTM
     * @param array<string,array<string,string>> $tracked klucz => wiersz `gtm_entity`
     *
     * @return array<int,array{op:string,kind:string,key:string,name:string,group:string,gtmId:string,gtmFingerprint:string}>
     */
    public function diff(Plan $plan, array $live, array $tracked): array
    {
        $byId = [];
        $byName = [];

        foreach (self::ID_FIELDS as $kind => $field) {
            foreach ($live[$kind] ?? [] as $entity) {
                $id = (string) ($entity[$field] ?? '');
                $byId[$kind][$id] = $entity;
                $byName[$kind][(string) ($entity['name'] ?? '')] ??= $entity;
            }
        }

        $ops = [];
        $used = [];

        // Tag Google z innego zrodla (np. dodany przez Tag Manager do uslugi) dla tego samego
        // identyfikatora: korzystamy z niego, a wlasny — jesli juz powstal — usuwamy.
        $google = $plan->find('tag.google');
        $foreign = $google !== null ? self::foreignGoogleTag($google, $live['tag'] ?? [], $tracked['tag.google']['gtm_id'] ?? '') : null;

        if ($google !== null && $foreign !== null) {
            $ownId = '';
            $row = $tracked['tag.google'] ?? null;

            if ($row !== null && isset($byId['tag'][$row['gtm_id']])) {
                $ownId = (string) $row['gtm_id'];
            } elseif (isset($byName['tag'][$google->name])) {
                $ownId = (string) ($byName['tag'][$google->name]['tagId'] ?? '');
            }

            if ($ownId !== '' && $ownId !== (string) $foreign['tagId']) {
                $ops[] = $this->op(self::DELETE, 'tag', 'tag.google', $google->name, $google->group, $ownId, '');
                $used['tag'][$ownId] = true;
            }

            $used['tag'][(string) $foreign['tagId']] = true;
            $ops[] = $this->op(self::googleTagInfo($foreign)['noPageView'] ? self::ADOPTED : self::ADOPT, 'tag', 'tag.google', (string) ($foreign['name'] ?? ''), $google->group, (string) $foreign['tagId'], (string) ($foreign['fingerprint'] ?? ''));
        }

        foreach ($plan->entities as $entity) {
            if ($entity->key === 'tag.google' && $foreign !== null) {
                continue;
            }

            $field = self::ID_FIELDS[$entity->kind];
            $row = $tracked[$entity->key] ?? null;
            $match = null;

            if ($row !== null && $row['kind'] === $entity->kind && isset($byId[$entity->kind][$row['gtm_id']])) {
                $match = $byId[$entity->kind][$row['gtm_id']];
            } elseif (isset($byName[$entity->kind][$entity->name])) {
                $candidate = $byName[$entity->kind][$entity->name];

                if (!isset($used[$entity->kind][(string) $candidate[$field]])) {
                    $match = $candidate;
                }
            }

            if ($match === null) {
                $ops[] = $this->op(self::CREATE, $entity->kind, $entity->key, $entity->name, $entity->group, '', '');

                continue;
            }

            $id = (string) $match[$field];
            $used[$entity->kind][$id] = true;
            $liveFp = (string) ($match['fingerprint'] ?? '');
            $same = $row !== null
                && $row['gtm_id'] === $id
                && $row['fingerprint'] === $entity->fingerprint
                && $row['gtm_fingerprint'] === $liveFp;

            $ops[] = $this->op($same ? self::KEEP : self::UPDATE, $entity->kind, $entity->key, $entity->name, $entity->group, $id, $liveFp);
        }

        // Encje modulu spoza planu: najpierw tagi, potem wyzwalacze, zmienne, folder (zaleznosci).
        $planKeys = array_flip($plan->keys());
        $removals = [];

        foreach ($tracked as $key => $row) {
            if (isset($planKeys[$key]) || ($key === 'tag.google' && $foreign !== null)) {
                continue;
            }

            $kind = (string) $row['kind'];
            $exists = isset($byId[$kind][$row['gtm_id']]) && !isset($used[$kind][$row['gtm_id']]);
            $removals[] = $this->op($exists ? self::DELETE : self::FORGET, $kind, (string) $key, (string) $row['name'], self::groupOf((string) $key), (string) $row['gtm_id'], '');
        }

        $order = ['tag' => 0, 'trigger' => 1, 'variable' => 2, 'folder' => 3];
        usort($removals, static fn (array $a, array $b): int => [$order[$a['kind']] ?? 9, $a['key']] <=> [$order[$b['kind']] ?? 9, $b['key']]);

        return array_merge($ops, $removals);
    }

    /** Liczba wywolan zapisujacych potrzebnych do wykonania roznic. */
    public static function writes(array $ops): int
    {
        return count(array_filter($ops, static fn (array $op): bool => in_array($op['op'], [self::CREATE, self::UPDATE, self::DELETE, self::ADOPT], true)));
    }

    /**
     * Tag Google spoza modulu dla identyfikatora z planu albo null.
     *
     * @param array<int,array<string,mixed>> $tags tagi z kontenera (pelne albo `slim`)
     *
     * @return array<string,mixed>|null
     */
    public static function foreignGoogleTag(\Quince\Insight\Service\TagManager\Dto\PlanEntity $google, array $tags, string $ownTrackedId): ?array
    {
        $want = strtoupper(trim((string) self::paramValue($google->body['parameter'] ?? [], 'tagId')));

        if ($want === '') {
            return null;
        }

        foreach ($tags as $tag) {
            $info = self::googleTagInfo($tag);

            if ($info['type'] !== 'googtag' || strtoupper($info['tagId']) !== $want) {
                continue;
            }

            $id = (string) ($tag['tagId'] ?? '');

            if ($id === '' || $id === $ownTrackedId || (string) ($tag['name'] ?? '') === $google->name) {
                continue;
            }

            return $tag;
        }

        return null;
    }

    /**
     * Typ tagu, identyfikator docelowy tagu Google i czy ma `send_page_view: false`.
     * Dziala na pelnej encji z API i na wersji `slim` z zadania.
     *
     * @param array<string,mixed> $tag
     *
     * @return array{type:string,tagId:string,noPageView:bool}
     */
    public static function googleTagInfo(array $tag): array
    {
        if (isset($tag['googtagId'])) {
            return ['type' => (string) ($tag['type'] ?? ''), 'tagId' => (string) $tag['googtagId'], 'noPageView' => ($tag['noPageView'] ?? '') === '1'];
        }

        $params = is_array($tag['parameter'] ?? null) ? $tag['parameter'] : [];

        return [
            'type' => (string) ($tag['type'] ?? ''),
            'tagId' => (string) self::paramValue($params, 'tagId'),
            'noPageView' => self::sendPageViewOff($params),
        ];
    }

    /** @param array<int,array<string,mixed>> $params */
    public static function sendPageViewOff(array $params): bool
    {
        foreach ($params as $param) {
            if (($param['key'] ?? '') !== 'configSettingsTable' || !is_array($param['list'] ?? null)) {
                continue;
            }

            foreach ($param['list'] as $row) {
                $map = is_array($row['map'] ?? null) ? $row['map'] : [];

                if (self::paramValue($map, 'parameter') === 'send_page_view') {
                    return strtolower(trim((string) self::paramValue($map, 'parameterValue'))) === 'false';
                }
            }
        }

        return false;
    }

    /**
     * Parametry tagu Google z `send_page_view: false` — reszta konfiguracji bez zmian.
     *
     * @param array<int,array<string,mixed>> $params
     *
     * @return array<int,array<string,mixed>>
     */
    public static function withSendPageViewOff(array $params): array
    {
        $row = ['type' => 'map', 'map' => [
            ['type' => 'template', 'key' => 'parameter', 'value' => 'send_page_view'],
            ['type' => 'template', 'key' => 'parameterValue', 'value' => 'false'],
        ]];

        foreach ($params as $i => $param) {
            if (($param['key'] ?? '') !== 'configSettingsTable') {
                continue;
            }

            $list = is_array($param['list'] ?? null) ? $param['list'] : [];

            foreach ($list as $j => $existing) {
                $map = is_array($existing['map'] ?? null) ? $existing['map'] : [];

                if (self::paramValue($map, 'parameter') === 'send_page_view') {
                    $list[$j] = $row;
                    $params[$i]['list'] = $list;

                    return $params;
                }
            }

            $list[] = $row;
            $params[$i]['type'] = 'list';
            $params[$i]['list'] = $list;

            return $params;
        }

        $params[] = ['type' => 'list', 'key' => 'configSettingsTable', 'list' => [$row]];

        return $params;
    }

    /** @param array<int,array<string,mixed>> $params */
    private static function paramValue(array $params, string $key): ?string
    {
        foreach ($params as $param) {
            if (($param['key'] ?? '') === $key) {
                return isset($param['value']) ? (string) $param['value'] : null;
            }
        }

        return null;
    }

    /** Grupa podgladu z samego klucza (dla encji usuwanych, ktorych nie ma w planie). */
    public static function groupOf(string $key): string
    {
        return match (true) {
            $key === 'folder.main' => 'folder',
            $key === 'variable.user_data' => 'upd',
            str_starts_with($key, 'variable.') => 'dlv',
            str_starts_with($key, 'trigger.') => 'triggers',
            $key === 'tag.google' => 'google_tag',
            str_starts_with($key, 'tag.ga4.') => 'ga4_events',
            $key === 'tag.ads.conversion' => 'ads_conversion',
            $key === 'tag.ads.linker' => 'ads_linker',
            $key === 'tag.ads.remarketing' => 'ads_remarketing',
            default => 'other',
        };
    }

    /** @return array{op:string,kind:string,key:string,name:string,group:string,gtmId:string,gtmFingerprint:string} */
    private function op(string $op, string $kind, string $key, string $name, string $group, string $gtmId, string $gtmFingerprint): array
    {
        return ['op' => $op, 'kind' => $kind, 'key' => $key, 'name' => $name, 'group' => $group, 'gtmId' => $gtmId, 'gtmFingerprint' => $gtmFingerprint];
    }
}
