<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Core\Dto;

/**
 * Zadanie w tle w postaci, jaka dostaje `JobHandler::step()`.
 */
final class Job
{
    /**
     * @param array<mixed> $payload dane podane przy `JobRunner::start()` — nie zmieniaja sie
     * @param array<mixed> $state stan zwrocony przez poprzedni krok ([] przy pierwszym)
     */
    public function __construct(
        public int $id,
        public int $idShop,
        public string $type,
        public array $payload,
        public array $state,
        public int $done,
        public int $total,
    ) {
    }
}
