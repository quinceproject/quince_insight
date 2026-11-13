<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\SearchConsole\Sitemap;

/**
 * Powody pominiecia adresu przy przebudowie mapy i ich opis na ekranie Sitemap.
 *
 * Modul sam sprawdza adresy, ktore wpisuje do mapy (SPEC §5.1) — bez zapytan do Google.
 * Liczby sa liczone na encje (produkt, strona) w kazdym jezyku sklepu osobno, tak jak
 * w mapie kazdy jezyk ma wlasny adres.
 */
final class SkippedReasons
{
    /** Produkt nieaktywny albo niewidoczny w sklepie. */
    public const INACTIVE = 'inactive';

    /** Produkt nieaktywny z przekierowaniem 301/302 — w mapie jest tylko adres docelowy. */
    public const REDIRECT = 'redirect';

    /** Strona prosi o nieindeksowanie (CMS bez indeksowania, wpis bloga z noindex). */
    public const NOINDEX = 'noindex';

    /** Adres odpowiada 404/403: kategoria wylaczona, brak dostepu dla gosci, strona marek wylaczona. */
    public const NOT_FOUND = 'notfound';

    /**
     * Wiersze ekranu: etykieta i wyjasnienie 1:1 z prototypu (`scDropped`). Recznych
     * wykluczen nie ma (decyzja #20) — tylko automatyczne pomijanie adresow nieindeksowalnych.
     *
     * @return array<string,array{label:string,why:string}>
     */
    public static function rows(): array
    {
        return [
            self::INACTIVE => ['label' => 'Inactive or deleted products', 'why' => 'Not visible in the shop'],
            self::REDIRECT => ['label' => 'Addresses that redirect', 'why' => 'Only the target address is listed'],
            self::NOINDEX => ['label' => 'Pages with noindex', 'why' => 'Products, CMS pages and blog posts marked not to index'],
            self::NOT_FOUND => ['label' => 'Not found (404)', 'why' => 'Category or page removed, link still in the shop'],
        ];
    }

    /**
     * Podsumowanie do ekranu: wiersze z liczbami i suma.
     *
     * @param array<string,int> $counts powod => liczba (z `_SM_SKIPPED`)
     *
     * @return array{rows:array<int,array{key:string,label:string,why:string,n:int}>,total:int}
     */
    public static function summary(array $counts): array
    {
        $rows = [];
        $total = 0;

        foreach (self::rows() as $key => $row) {
            $n = max(0, (int) ($counts[$key] ?? 0));

            $rows[] = ['key' => $key, 'label' => $row['label'], 'why' => $row['why'], 'n' => $n];
            $total += $n;
        }

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Powod pominiecia produktu z jego danych sklepowych albo null = do mapy.
     *
     * @param array{active:int|string,visibility:string,redirect_type:string} $row
     * @param string $defaultRedirect `PS_PRODUCT_REDIRECTION_DEFAULT` (dla `redirect_type = default`)
     */
    public static function forProduct(array $row, string $defaultRedirect = '404'): ?string
    {
        if ((int) $row['active'] === 1) {
            return (string) $row['visibility'] === 'none' ? self::INACTIVE : null;
        }

        $type = (string) $row['redirect_type'];

        if ($type === '' || $type === 'default') {
            $type = $defaultRedirect;
        }

        // Nieaktywny produkt z przekierowaniem 301/302 odsyla na inny adres (kategoria albo produkt);
        // z 404/410 albo 200 z informacja — nie ma czego indeksowac.
        return str_starts_with($type, '301') || str_starts_with($type, '302') ? self::REDIRECT : self::INACTIVE;
    }
}
