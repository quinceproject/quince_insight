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
 * Stan zadania w tle do pokazania w panelu i w odpowiedzi JSON.
 */
final class JobStatus
{
    public const PENDING = 'pending';
    public const RUNNING = 'running';
    public const DONE = 'done';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';

    /**
     * @param array<mixed> $result
     * @param array<int,array{text:string,st:string,note?:string}> $items lista krokow do pokazania pod paskiem
     *        (z `JobProgress::$state['items']`; `st`: wait | run | ok | err) — jak w prototypie
     */
    public function __construct(
        public int $id,
        public int $idShop,
        public string $type,
        public string $status,
        public int $done,
        public int $total,
        public string $message,
        public string $error,
        public array $result,
        public int $waitSeconds = 0,
        public array $items = [],
    ) {
    }

    /** Zadanie jeszcze trwa (czeka na krok albo jest w trakcie). */
    public function isActive(): bool
    {
        return $this->status === self::PENDING || $this->status === self::RUNNING;
    }

    public function percent(): int
    {
        if ($this->status === self::DONE) {
            return 100;
        }

        return $this->total > 0 ? (int) min(100, floor($this->done * 100 / $this->total)) : 0;
    }

    /**
     * Postac do `x-data` i odpowiedzi JSON. Bez `payload` i `state` — moga zawierac
     * dane, ktorych nie pokazujemy w przegladarce.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'done' => $this->done,
            'total' => $this->total,
            'percent' => $this->percent(),
            'message' => $this->message,
            'error' => $this->error,
            'result' => $this->result,
            'wait' => $this->waitSeconds,
            'active' => $this->isActive(),
            'items' => $this->items,
        ];
    }
}
