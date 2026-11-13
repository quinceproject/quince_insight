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

        foreach ($plan->entities as $entity) {
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
            if (isset($planKeys[$key])) {
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
        return count(array_filter($ops, static fn (array $op): bool => in_array($op['op'], [self::CREATE, self::UPDATE, self::DELETE], true)));
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
