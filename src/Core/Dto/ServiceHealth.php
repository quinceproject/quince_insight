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
 * Stan uslugi do karty na pulpicie.
 *
 * Teksty sa angielskimi zrodlami tlumaczen: Twig robi `summary|trans(params)`.
 * Liczb i nazw nie sklejac w PHP — ida w `$params` jako `%nazwa%`.
 */
final class ServiceHealth
{
    public const OK = 'ok';
    public const ATTENTION = 'attention';
    public const ERROR = 'error';
    public const OFF = 'off';

    /**
     * @param string $state ok | attention | error | off
     * @param string $summary jedno zdanie (klucz tlumaczenia + parametry w $params)
     * @param string $fact jedna kluczowa informacja
     * @param array<string,string|int|float> $params
     */
    public function __construct(
        public string $state,
        public string $summary,
        public string $fact = '',
        public array $params = [],
    ) {
    }
}
