<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\Analytics;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Google nie utworzyl sekretu Measurement Protocol, bo usluga nie ma potwierdzenia
 * zbierania danych (`acknowledgeUserDataCollection`). Potwierdzenie musi dac user.
 */
final class AckRequiredException extends \RuntimeException
{
}
