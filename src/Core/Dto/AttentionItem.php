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
 * Pozycja listy "Needs attention" na pulpicie.
 */
final class AttentionItem
{
    public const ERROR = 'error';
    public const WARNING = 'warning';

    /**
     * @param string $level error | warning
     * @param string $message angielskie zrodlo tlumaczenia z parametrami `%nazwa%`
     * @param string $route trasa panelu, gdzie sie to naprawia
     * @param array<string,string|int> $routeParams
     * @param array<string,string|int|float> $params parametry tlumaczenia
     */
    public function __construct(
        public string $level,
        public string $message,
        public string $route,
        public array $routeParams = [],
        public array $params = [],
    ) {
    }
}
