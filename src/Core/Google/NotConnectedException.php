<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Core\Google;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Sklep nie ma polaczenia z Google, polaczenie wymaga ponownego logowania albo
 * brakuje klucza API. To stan do pokazania ("Connect"), nie awaria.
 */
final class NotConnectedException extends \RuntimeException
{
}
