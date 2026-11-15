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
 * Logowanie do Google przestalo dzialac: token odswiezania wygasl albo zostal cofniety,
 * albo token dostepu jest odrzucany takze po odswiezeniu. Wymaga ponownego logowania.
 */
final class GoogleAuthException extends GoogleApiException
{
}
