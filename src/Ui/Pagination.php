<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Ui;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Symfony\Component\HttpFoundation\Request;

/**
 * Stronicowanie list po stronie serwera (parametry `page` i `per`).
 *
 * Wynik `resolve()` idzie do `_partials/listfoot.html.twig`; `offset` i `per` do `LIMIT`.
 * Stopka pokazuje sie tylko wtedy, gdy lista nie miesci sie na jednej stronie.
 */
final class Pagination
{
    public const PER_OPTIONS = [25, 50, 100];

    /**
     * @param int $total liczba wszystkich pozycji po zastosowaniu filtrow
     * @param int[] $perOptions dozwolone rozmiary strony; pierwszy jest domyslny
     *
     * @return array{page:int,per:int,pages:int,total:int,offset:int,from:int,to:int,numbers:array<int,int|null>,visible:bool,perOptions:int[]}
     */
    public function resolve(Request $request, int $total, array $perOptions = self::PER_OPTIONS): array
    {
        return self::build(
            (int) $request->query->get('page', 1),
            (int) $request->query->get('per', 0),
            $total,
            $perOptions
        );
    }

    /**
     * To samo bez obiektu zadania (testy, listy stronicowane parametrami o innych nazwach).
     *
     * @param int[] $perOptions
     *
     * @return array{page:int,per:int,pages:int,total:int,offset:int,from:int,to:int,numbers:array<int,int|null>,visible:bool,perOptions:int[]}
     */
    public static function build(int $page, int $per, int $total, array $perOptions = self::PER_OPTIONS): array
    {
        $perOptions = array_values(array_filter(array_map('intval', $perOptions), static fn (int $n): bool => $n > 0)) ?: self::PER_OPTIONS;
        $per = in_array($per, $perOptions, true) ? $per : $perOptions[0];
        $total = max(0, $total);
        $pages = max(1, (int) ceil($total / $per));

        // Strona spoza zakresu (stary odnosnik po zmianie filtra) laduje na ostatniej, nie na pustej.
        $page = min($pages, max(1, $page));
        $offset = ($page - 1) * $per;

        return [
            'page' => $page,
            'per' => $per,
            'pages' => $pages,
            'total' => $total,
            'offset' => $offset,
            'from' => $total === 0 ? 0 : $offset + 1,
            'to' => min($total, $offset + $per),
            'numbers' => self::numbers($page, $pages),
            // Stopka tylko gdy lista nie miesci sie na stronie o najmniejszym rozmiarze.
            'visible' => $total > $perOptions[0],
            'perOptions' => $perOptions,
        ];
    }

    /**
     * Numery stron z wielokropkiem: 1 … 4 5 6 … 12 (null = wielokropek) — jak w prototypie.
     *
     * @return array<int,int|null>
     */
    public static function numbers(int $page, int $pages): array
    {
        $out = [];

        for ($n = 1; $n <= $pages; ++$n) {
            if ($n === 1 || $n === $pages || abs($n - $page) <= 1) {
                $out[] = $n;
            } elseif (end($out) !== null) {
                $out[] = null;
            }
        }

        return $out;
    }
}
