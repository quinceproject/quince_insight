<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Core;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Opcjonalne rozszerzenie `ServiceModule`: wlasne kroki instalacji i odinstalowania uslugi.
 *
 * Dla rzeczy, ktorych nie zalatwia sama definicja tabel — zaladowanie danych
 * slownikowych, usuniecie plikow. Usluga, ktora tego nie potrzebuje, interfejsu
 * nie implementuje. Wywoluje `Core\Installer`, nie plik glowny modulu.
 */
interface Installable
{
    /** Po zalozeniu tabel. False przerywa instalacje modulu. */
    public function install(): bool;

    /** Przed usunieciem tabel (nazwy plikow bywaja znane tylko z tabeli). Wynik nie przerywa odinstalowania. */
    public function uninstall(): void;
}
