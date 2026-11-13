<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\TagManager\Dto;

/**
 * Plan kontenera: uporzadkowana lista encji (folder, zmienne, wyzwalacze, tagi) i jej hash.
 */
final class Plan
{
    /**
     * @param PlanEntity[] $entities w kolejnosci tworzenia (zaleznosci najpierw)
     * @param string[] $notes uwagi dla usera, np. brak etykiety konwersji
     */
    public function __construct(
        public array $entities,
        public string $hash,
        public array $notes = [],
    ) {
    }

    public function find(string $key): ?PlanEntity
    {
        foreach ($this->entities as $entity) {
            if ($entity->key === $key) {
                return $entity;
            }
        }

        return null;
    }

    /** @return string[] */
    public function keys(): array
    {
        return array_map(static fn (PlanEntity $e): string => $e->key, $this->entities);
    }

    public function count(string $kind): int
    {
        return count(array_filter($this->entities, static fn (PlanEntity $e): bool => $e->kind === $kind));
    }

    /** @return array<int,array<string,mixed>> */
    public function toArray(): array
    {
        return array_map(static fn (PlanEntity $e): array => $e->toArray(), $this->entities);
    }

    /** @param array<int,array<string,mixed>> $rows */
    public static function fromArray(array $rows, string $hash): self
    {
        return new self(array_map(static fn (array $r): PlanEntity => PlanEntity::fromArray($r), $rows), $hash);
    }
}
