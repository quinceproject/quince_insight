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

use Db;

/**
 * Stan modulu w sklepie: polaczenie Google i postep kreatora (tabela `shop`).
 *
 * Sklep bez ukonczonego kreatora jest dla modulu martwy — hooki nic w nim nie robia,
 * crony nic nie uruchamiaja. To jest bezpiecznik dla sklepu dodanego pozniej.
 */
final class ShopRepository
{
    /** @var array<int,array<string,mixed>|null> */
    private static array $rows = [];

    /**
     * @return array{id_shop:int,id_connection:int,wizard_done:bool,wizard_step:string,wizard_state:array<string,string>,date_add:string}|null
     */
    public function find(int $idShop): ?array
    {
        if (array_key_exists($idShop, self::$rows)) {
            return self::$rows[$idShop];
        }

        try {
            $row = Db::getInstance()->getRow(
                'SELECT * FROM `' . Schema::table(Schema::SHOP) . '` WHERE `id_shop` = ' . $idShop
            );
        } catch (\Throwable $e) {
            // Tabeli moze nie byc w trakcie instalacji i odinstalowania.
            $row = false;
        }

        if (!is_array($row)) {
            return self::$rows[$idShop] = null;
        }

        $state = json_decode((string) $row['wizard_state'], true);

        return self::$rows[$idShop] = [
            'id_shop' => (int) $row['id_shop'],
            'id_connection' => (int) $row['id_connection'],
            'wizard_done' => (bool) $row['wizard_done'],
            'wizard_step' => (string) $row['wizard_step'],
            'wizard_state' => is_array($state) ? $state : [],
            'date_add' => (string) $row['date_add'],
        ];
    }

    /** Zaklada wiersz sklepu, jesli go nie ma (kreator nieukonczony, brak polaczenia). */
    public function ensure(int $idShop): void
    {
        $now = pSQL(Clock::now());

        Db::getInstance()->execute(
            'INSERT IGNORE INTO `' . Schema::table(Schema::SHOP) . '`
             (`id_shop`, `id_connection`, `wizard_done`, `wizard_step`, `wizard_state`, `date_add`, `date_upd`)
             VALUES (' . $idShop . ', 0, 0, "", "{}", "' . $now . '", "' . $now . '")'
        );

        unset(self::$rows[$idShop]);
    }

    /**
     * Czy modul ma w tym sklepie dzialac: kreator ukonczony.
     *
     * Obejscie z `config/dev.php` liczy sie jak ukonczony kreator, zeby dalo sie
     * pracowac nad ekranami i hookami, zanim kreator bedzie gotowy.
     */
    public function isSetUp(int $idShop): bool
    {
        if (Dev::skipWizard()) {
            return true;
        }

        return $this->wizardDone($idShop);
    }

    /**
     * Czy hooki modulu maja w tym sklepie cokolwiek robic: kreator ukonczony.
     *
     * Obejscie kreatora z `config/dev.php` tu sie NIE liczy — wpuszcza do hookow dopiero
     * osobny przelacznik `QUINCE_INSIGHT_DEV_HOOKS`, zeby samo istnienie pliku dev nie
     * zmienialo strony sklepu.
     */
    public function isLive(int $idShop): bool
    {
        if (Dev::hooksWithoutWizard()) {
            return true;
        }

        return $this->wizardDone($idShop);
    }

    public function wizardDone(int $idShop): bool
    {
        return (bool) ($this->find($idShop)['wizard_done'] ?? false);
    }

    public function setWizardDone(int $idShop, bool $done): void
    {
        $this->update($idShop, ['wizard_done' => (int) $done]);
    }

    public function wizardStep(int $idShop): string
    {
        return (string) ($this->find($idShop)['wizard_step'] ?? '');
    }

    public function setWizardStep(int $idShop, string $step): void
    {
        $this->update($idShop, ['wizard_step' => pSQL(substr($step, 0, 30))]);
    }

    /** @return array<string,string> krok => done | skipped | error | todo */
    public function wizardState(int $idShop): array
    {
        return $this->find($idShop)['wizard_state'] ?? [];
    }

    /** @param array<string,string> $state */
    public function setWizardState(int $idShop, array $state): void
    {
        $this->update($idShop, ['wizard_state' => pSQL((string) json_encode($state), true)]);
    }

    /** Ustawia stan jednego kroku, reszte zostawia. */
    public function setStepState(int $idShop, string $step, string $state): void
    {
        $all = $this->wizardState($idShop);
        $all[$step] = $state;

        $this->setWizardState($idShop, $all);
    }

    /** Identyfikator polaczenia Google sklepu; 0 = brak. */
    public function connectionId(int $idShop): int
    {
        return (int) ($this->find($idShop)['id_connection'] ?? 0);
    }

    public function setConnection(int $idShop, int $idConnection): void
    {
        $this->update($idShop, ['id_connection' => $idConnection]);
    }

    /** Sklepy przypisane do polaczenia. @return int[] */
    public function shopsOfConnection(int $idConnection): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT `id_shop` FROM `' . Schema::table(Schema::SHOP) . '`
             WHERE `id_connection` = ' . $idConnection . ' ORDER BY `id_shop` ASC'
        ) ?: [];

        return array_map(static fn (array $row): int => (int) $row['id_shop'], $rows);
    }

    /** Sklepy z ukonczonym kreatorem. @return int[] */
    public function setUpShops(): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT `id_shop` FROM `' . Schema::table(Schema::SHOP) . '` WHERE `wizard_done` = 1 ORDER BY `id_shop` ASC'
        ) ?: [];

        return array_map(static fn (array $row): int => (int) $row['id_shop'], $rows);
    }

    public function delete(int $idShop): void
    {
        Db::getInstance()->delete(Schema::name(Schema::SHOP), '`id_shop` = ' . $idShop);
        unset(self::$rows[$idShop]);
    }

    /** @param array<string,mixed> $fields wartosci juz zabezpieczone (liczby albo pSQL) */
    private function update(int $idShop, array $fields): void
    {
        $this->ensure($idShop);

        $fields['date_upd'] = pSQL(Clock::now());

        Db::getInstance()->update(Schema::name(Schema::SHOP), $fields, '`id_shop` = ' . $idShop);
        unset(self::$rows[$idShop]);
    }
}
