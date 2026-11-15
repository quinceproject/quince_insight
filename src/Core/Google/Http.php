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

use Quince\Insight\Core\Dev;

/**
 * Wybor transportu: siec albo atrapy.
 *
 * Tryb atrap wlacza wylacznie plik `config/dev.php`, ktorego nie ma w paczce wydania.
 */
final class Http
{
    private static ?HttpTransport $override = null;

    /**
     * @param string $service klucz uslugi = katalog atrap (`core`, `analytics`, `tagmanager`, `merchant`, ...)
     */
    public static function transport(string $service = 'core'): HttpTransport
    {
        if (self::$override !== null) {
            return self::$override;
        }

        return Dev::fakeGoogle() ? new FakeTransport($service) : new CurlTransport();
    }

    /** Tylko testy: jeden transport dla wszystkich wywolan (null przywraca domyslny). */
    public static function override(?HttpTransport $transport): void
    {
        self::$override = $transport;
    }
}
