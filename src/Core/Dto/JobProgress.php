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
 * Wynik jednego kroku zadania w tle.
 */
final class JobProgress
{
    /**
     * @param int $done ile jednostek pracy wykonano lacznie
     * @param int $total ile jest wszystkich (0 = jeszcze nie wiadomo)
     * @param string $message co sie wlasnie dzieje — pokazywane pod paskiem postepu
     * @param bool $finished true konczy zadanie (status `done`)
     * @param int $waitSeconds ile odczekac przed nastepnym krokiem (limity Google)
     * @param array<mixed> $state stan dla nastepnego kroku. Jedyny klucz widoczny w przegladarce
     *                            to `items` — lista `{text, st: wait|run|ok|err, note?}` pokazywana
     *                            pod paskiem postepu (angielskie teksty, juz gotowe do wyswietlenia);
     *                            reszta stanu nie wychodzi poza serwer
     * @param array<mixed> $result wynik koncowy — zapisywany przy `finished = true`
     */
    public function __construct(
        public int $done,
        public int $total,
        public string $message = '',
        public bool $finished = false,
        public int $waitSeconds = 0,
        public array $state = [],
        public array $result = [],
    ) {
    }
}
