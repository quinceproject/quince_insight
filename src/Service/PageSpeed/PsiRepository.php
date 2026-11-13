<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\PageSpeed;

use Db;
use Quince\Insight\Core\Clock;
use Quince\Insight\Core\Options;
use Quince\Insight\Core\Schema as CoreSchema;

/**
 * Strony do testu (`psi_page`) i wyniki (`psi_result`, tylko biezacy i poprzedni).
 *
 * Domyslna lista stron (glowna, kategoria, produkt, wpis bloga) powstaje w bazie dopiero
 * przy pierwszej akcji usera albo pierwszym sprawdzeniu — widok (GET) niczego nie zapisuje,
 * tylko pokazuje strony domyslne z ujemnymi identyfikatorami (-pozycja).
 */
final class PsiRepository
{
    public const MAX_PAGES = 5;

    public const FIELD = 'field';
    public const LAB = 'lab';

    public const CURRENT = 'current';
    public const PREVIOUS = 'previous';

    public const STRATEGIES = ['mobile', 'desktop'];

    public function __construct(private readonly SamplePages $samples)
    {
    }

    /**
     * Strony sklepu do testu, w kolejnosci.
     *
     * @return array<int,array{id:int,label:string,url:string,position:int}>
     */
    public function pages(int $idShop): array
    {
        if (!Options::bool(PageSpeedOptions::PAGES_READY, false, $idShop)) {
            $out = [];
            $position = 0;

            foreach ($this->samples->forShop($idShop) as $page) {
                ++$position;
                $out[] = ['id' => -$position, 'label' => $page['label'], 'url' => $page['url'], 'position' => $position];
            }

            return array_slice($out, 0, self::MAX_PAGES);
        }

        $rows = Db::getInstance()->executeS(
            'SELECT `id_psi_page`, `label`, `url`, `position` FROM `' . CoreSchema::table(Schema::PSI_PAGE) . '`
             WHERE `id_shop` = ' . $idShop . ' ORDER BY `position` ASC, `id_psi_page` ASC'
        ) ?: [];

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id_psi_page'],
            'label' => (string) $row['label'],
            'url' => (string) $row['url'],
            'position' => (int) $row['position'],
        ], $rows);
    }

    /** Zapisuje domyslna liste stron w bazie (raz na sklep). */
    public function materialize(int $idShop): void
    {
        if (Options::bool(PageSpeedOptions::PAGES_READY, false, $idShop)) {
            return;
        }

        foreach ($this->pages($idShop) as $page) {
            Db::getInstance()->insert(CoreSchema::name(Schema::PSI_PAGE), [
                'id_shop' => $idShop,
                'label' => pSQL(mb_substr($page['label'], 0, 60)),
                'url' => pSQL(mb_substr($page['url'], 0, 500)),
                'position' => $page['position'],
            ]);
        }

        Options::set(PageSpeedOptions::PAGES_READY, 1, $idShop);
    }

    /** Dodaje strone na koncu listy. Zwraca id albo 0, gdy lista jest pelna. */
    public function add(int $idShop, string $label, string $url): int
    {
        $this->materialize($idShop);

        $pages = $this->pages($idShop);

        if (count($pages) >= self::MAX_PAGES) {
            return 0;
        }

        $position = $pages ? max(array_column($pages, 'position')) + 1 : 1;

        Db::getInstance()->insert(CoreSchema::name(Schema::PSI_PAGE), [
            'id_shop' => $idShop,
            'label' => pSQL(mb_substr($label, 0, 60)),
            'url' => pSQL(mb_substr($url, 0, 500)),
            'position' => $position,
        ]);

        return (int) Db::getInstance()->Insert_ID();
    }

    /**
     * Usuwa strone razem z jej wynikami. Ujemne id = strona domyslna przed zapisem listy
     * (pozycja), wiec najpierw lista trafia do bazy.
     */
    public function delete(int $idShop, int $id): bool
    {
        if ($id < 0) {
            $position = -$id;
            $this->materialize($idShop);
            $id = (int) Db::getInstance()->getValue(
                'SELECT `id_psi_page` FROM `' . CoreSchema::table(Schema::PSI_PAGE) . '`
                 WHERE `id_shop` = ' . $idShop . ' AND `position` = ' . $position,
                false
            );
        }

        if ($id <= 0) {
            return false;
        }

        $where = '`id_shop` = ' . $idShop . ' AND `id_psi_page` = ' . $id;
        Db::getInstance()->delete(CoreSchema::name(Schema::PSI_PAGE), $where);
        $deleted = (int) Db::getInstance()->Affected_Rows() > 0;
        Db::getInstance()->delete(CoreSchema::name(Schema::PSI_RESULT), $where);

        return $deleted;
    }

    /**
     * Zapisuje nowy wynik jako biezacy; dotychczasowy biezacy staje sie poprzednim,
     * a starszy znika — w bazie nigdy nie ma wiecej niz dwa wyniki na strone i urzadzenie.
     *
     * @param array<mixed> $metrics
     */
    public function saveResult(int $idShop, int $idPage, string $kind, string $strategy, ?int $score, array $metrics): void
    {
        $db = Db::getInstance();
        $where = '`id_shop` = ' . $idShop . ' AND `id_psi_page` = ' . $idPage
            . ' AND `kind` = "' . pSQL($kind) . '" AND `strategy` = "' . pSQL($strategy) . '"';

        $db->delete(CoreSchema::name(Schema::PSI_RESULT), $where . ' AND `slot` = "' . self::PREVIOUS . '"');
        $db->update(CoreSchema::name(Schema::PSI_RESULT), ['slot' => self::PREVIOUS], $where . ' AND `slot` = "' . self::CURRENT . '"');

        $db->insert(CoreSchema::name(Schema::PSI_RESULT), [
            'id_shop' => $idShop,
            'id_psi_page' => $idPage,
            'kind' => pSQL($kind),
            'strategy' => pSQL($strategy),
            'slot' => self::CURRENT,
            'score' => $score === null ? ['type' => 'sql', 'value' => 'NULL'] : $score,
            'metrics' => pSQL((string) json_encode($metrics, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true),
            'checked_at' => pSQL(Clock::now()),
        ]);
    }

    /**
     * Wszystkie wyniki sklepu: [id strony][kind][strategy][slot] => {score, metrics, checked_at}.
     *
     * @return array<int,array<string,array<string,array<string,array{score:?int,metrics:array<mixed>,checked_at:string}>>>>
     */
    public function results(int $idShop): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT * FROM `' . CoreSchema::table(Schema::PSI_RESULT) . '` WHERE `id_shop` = ' . $idShop
        ) ?: [];

        $out = [];

        foreach ($rows as $row) {
            $metrics = json_decode((string) $row['metrics'], true);
            $out[(int) $row['id_psi_page']][(string) $row['kind']][(string) $row['strategy']][(string) $row['slot']] = [
                'score' => $row['score'] === null ? null : (int) $row['score'],
                'metrics' => is_array($metrics) ? $metrics : [],
                'checked_at' => (string) $row['checked_at'],
            ];
        }

        return $out;
    }

    /** Sprzatanie przy usunieciu sklepu / wylaczeniu uslugi nie jest potrzebne — rdzen czysci po id_shop. */
    public function clear(int $idShop): void
    {
        Db::getInstance()->delete(CoreSchema::name(Schema::PSI_RESULT), '`id_shop` = ' . $idShop);
    }
}
