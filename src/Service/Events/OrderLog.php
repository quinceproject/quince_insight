<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\Events;

use Db;
use Quince\Insight\Core\Clock;
use Quince\Insight\Core\Schema as CoreSchema;

/**
 * Tabele `order_log` i `refund_log` (ARCHITEKTURA §7.4). Jedno zamowienie = jeden wiersz.
 */
final class OrderLog
{
    /** Filtry listy Order log (prototyp): all | missing | blocked | refunds. */
    public const FILTERS = ['all', 'missing', 'blocked', 'refunds'];

    /** @return array<string,mixed>|null */
    public function find(int $idShop, int $idOrder): ?array
    {
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . CoreSchema::table(Schema::ORDER_LOG) . '`
             WHERE `id_shop` = ' . $idShop . ' AND `id_order` = ' . $idOrder,
            false
        );

        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function findById(int $idShop, int $idOrderLog): ?array
    {
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . CoreSchema::table(Schema::ORDER_LOG) . '`
             WHERE `id_shop` = ' . $idShop . ' AND `id_order_log` = ' . $idOrderLog,
            false
        );

        return is_array($row) ? $row : null;
    }

    /**
     * Wiersz przy skladaniu zamowienia. Drugi zapis tego samego zamowienia (podzial koszyka,
     * powtorzony hook) niczego nie nadpisuje.
     *
     * @param array<string,mixed> $data
     */
    public function create(int $idShop, int $idOrder, array $data): void
    {
        Db::getInstance()->execute(
            'INSERT IGNORE INTO `' . CoreSchema::table(Schema::ORDER_LOG) . '`
             (`id_shop`, `id_order`, `reference`, `total`, `currency`, `ga_client_id`, `ga_session_id`, `consent`, `source`, `server_status`, `date_add`)
             VALUES (' . $idShop . ', ' . $idOrder . ', "' . pSQL(substr((string) $data['reference'], 0, 32)) . '", '
            . (float) $data['total'] . ', "' . pSQL(substr((string) $data['currency'], 0, 3)) . '", "'
            . pSQL(substr((string) $data['ga_client_id'], 0, 64)) . '", "' . pSQL(substr((string) $data['ga_session_id'], 0, 64)) . '", "'
            . pSQL(substr((string) json_encode($data['consent']), 0, 255)) . '", "'
            . pSQL(substr(Json::encode($data['source']), 0, 500)) . '", "none", "'
            . pSQL((string) ($data['date_add'] ?? Clock::now())) . '")'
        );
    }

    /** @param array<string,mixed> $fields */
    public function update(int $idShop, int $idOrder, array $fields): void
    {
        $set = [];

        foreach ($fields as $column => $value) {
            if ($value === null) {
                $set[$column] = null;
            } elseif (is_array($value)) {
                $set[$column] = pSQL(Json::encode($value), true);
            } else {
                $set[$column] = pSQL((string) $value, true);
            }
        }

        Db::getInstance()->update(CoreSchema::name(Schema::ORDER_LOG), $set, '`id_shop` = ' . $idShop . ' AND `id_order` = ' . $idOrder, 0, true);
    }

    /**
     * Ochrona przed odswiezeniem potwierdzenia: tylko pierwsze wywolanie dostaje `true`.
     * Atomowo (UPDATE ... WHERE browser_at IS NULL), wiec dwie karty naraz tez nie powtorza.
     */
    public function claimBrowser(int $idShop, int $idOrder): bool
    {
        $db = Db::getInstance();
        $db->execute(
            'UPDATE `' . CoreSchema::table(Schema::ORDER_LOG) . '` SET `browser_at` = "' . pSQL(Clock::now()) . '"
             WHERE `id_shop` = ' . $idShop . ' AND `id_order` = ' . $idOrder . ' AND `browser_at` IS NULL'
        );

        return $db->Affected_Rows() > 0;
    }

    public function count(int $idShop, string $filter): int
    {
        return (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . CoreSchema::table(Schema::ORDER_LOG) . '` ol WHERE ' . $this->filterSql($idShop, $filter), false);
    }

    /**
     * Lista do ekranu Order log.
     *
     * @return array{rows:array<int,array<string,mixed>>, total:int}
     */
    public function page(int $idShop, string $filter, int $limit, int $offset): array
    {
        $where = $this->filterSql($idShop, $filter);
        $total = (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . CoreSchema::table(Schema::ORDER_LOG) . '` ol WHERE ' . $where, false);
        $rows = Db::getInstance()->executeS(
            'SELECT ol.*, o.`payment`, o.`total_paid_tax_incl`,
                (SELECT COUNT(*) FROM `' . CoreSchema::table(Schema::REFUND_LOG) . '` r WHERE r.`id_shop` = ol.`id_shop` AND r.`id_order` = ol.`id_order`) AS `refunds`,
                (SELECT COALESCE(SUM(r.`amount`), 0) FROM `' . CoreSchema::table(Schema::REFUND_LOG) . '` r WHERE r.`id_shop` = ol.`id_shop` AND r.`id_order` = ol.`id_order`) AS `refunded`
             FROM `' . CoreSchema::table(Schema::ORDER_LOG) . '` ol
             LEFT JOIN `' . _DB_PREFIX_ . 'orders` o ON o.`id_order` = ol.`id_order`
             WHERE ' . $where . '
             ORDER BY ol.`date_add` DESC, ol.`id_order_log` DESC
             LIMIT ' . $limit . ' OFFSET ' . $offset,
            true,
            false
        );

        return ['rows' => is_array($rows) ? $rows : [], 'total' => $total];
    }

    /**
     * Zamowienia z ostatnich $hours godzin.
     *
     * @return array<int,array<string,mixed>>
     */
    public function since(int $idShop, int $hours): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT ol.*, o.`payment` FROM `' . CoreSchema::table(Schema::ORDER_LOG) . '` ol
             LEFT JOIN `' . _DB_PREFIX_ . 'orders` o ON o.`id_order` = ol.`id_order`
             WHERE ol.`id_shop` = ' . $idShop . ' AND ol.`date_add` >= "' . pSQL(Clock::at(-$hours * 3600)) . '"
             ORDER BY ol.`date_add` DESC',
            true,
            false
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * Zamowienia do ponowienia przez cron: bledy sieci i brakujace (decyduje `SendRules`).
     *
     * @return array<int,array<string,mixed>>
     */
    public function pending(int $idShop, int $limit = 50): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT * FROM `' . CoreSchema::table(Schema::ORDER_LOG) . '`
             WHERE `id_shop` = ' . $idShop . ' AND `server_status` IN ("none", "failed", "skipped")
               AND `browser_at` IS NULL AND `ga_client_id` <> ""
               AND `date_add` >= "' . pSQL(Clock::at(-MpPayload::MAX_AGE_SECONDS)) . '"
             ORDER BY `date_add` ASC LIMIT ' . $limit,
            true,
            false
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * Liczby do Events -> Overview (zakupy z 7 dni), z tych samych regul co filtry Order log.
     *
     * @return array<string,int>
     */
    public function stats(int $idShop, int $days = 7): array
    {
        $row = Db::getInstance()->getRow(
            'SELECT COUNT(*) AS `all`,
                SUM(`browser_at` IS NOT NULL AND `server_status` = "sent") AS `both`,
                SUM(`browser_at` IS NULL AND `server_status` = "sent") AS `server_only`,
                SUM(`browser_at` IS NOT NULL AND `server_status` <> "sent") AS `browser_only`,
                SUM(`browser_at` IS NULL AND `server_status` <> "sent") AS `none`,
                SUM(`server_status` = "skipped" AND `server_reason` = "no_consent") AS `no_consent`,
                SUM(`server_status` = "skipped" AND `server_reason` = "no_session") AS `no_session`
             FROM `' . CoreSchema::table(Schema::ORDER_LOG) . '`
             WHERE `id_shop` = ' . $idShop . ' AND `date_add` >= "' . pSQL(Clock::at(-$days * 86400)) . '"',
            false
        );

        return array_map('intval', is_array($row) ? $row : []);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function refunds(int $idShop, int $idOrder): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT * FROM `' . CoreSchema::table(Schema::REFUND_LOG) . '`
             WHERE `id_shop` = ' . $idShop . ' AND `id_order` = ' . $idOrder . ' ORDER BY `id_refund_log` ASC',
            true,
            false
        );

        return is_array($rows) ? $rows : [];
    }

    /** @return array<string,mixed>|null */
    public function refund(int $idShop, int $idOrder, int $idOrderSlip): ?array
    {
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . CoreSchema::table(Schema::REFUND_LOG) . '`
             WHERE `id_shop` = ' . $idShop . ' AND `id_order` = ' . $idOrder . ' AND `id_order_slip` = ' . $idOrderSlip,
            false
        );

        return is_array($row) ? $row : null;
    }

    /**
     * Wiersz zwrotu; `false`, gdy taki zwrot juz jest (dwa wywolania hooka dla jednego dokumentu).
     *
     * @param array<string,mixed> $data
     */
    public function createRefund(int $idShop, int $idOrder, int $idOrderSlip, array $data): bool
    {
        $db = Db::getInstance();
        $db->execute(
            'INSERT IGNORE INTO `' . CoreSchema::table(Schema::REFUND_LOG) . '`
             (`id_shop`, `id_order`, `id_order_slip`, `amount`, `currency`, `items`, `status`, `reason`, `date_add`)
             VALUES (' . $idShop . ', ' . $idOrder . ', ' . $idOrderSlip . ', ' . (float) $data['amount'] . ', "'
            . pSQL(substr((string) $data['currency'], 0, 3)) . '", "'
            . pSQL(Json::encode($data['items']), true) . '", "none", "", "'
            . pSQL(Clock::now()) . '")'
        );

        return $db->Affected_Rows() > 0;
    }

    /** @param array<string,mixed> $fields */
    public function updateRefund(int $idShop, int $idOrder, int $idOrderSlip, array $fields): void
    {
        $set = [];

        foreach ($fields as $column => $value) {
            $set[$column] = $value === null ? null : pSQL(is_array($value) ? Json::encode($value) : (string) $value, true);
        }

        Db::getInstance()->update(CoreSchema::name(Schema::REFUND_LOG), $set, '`id_shop` = ' . $idShop . ' AND `id_order` = ' . $idOrder . ' AND `id_order_slip` = ' . $idOrderSlip, 0, true);
    }

    /**
     * Zwroty do ponowienia przez cron.
     *
     * @return array<int,array<string,mixed>>
     */
    public function pendingRefunds(int $idShop, int $limit = 30): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT * FROM `' . CoreSchema::table(Schema::REFUND_LOG) . '`
             WHERE `id_shop` = ' . $idShop . ' AND `status` = "failed" AND `attempts` < 5
               AND (`reason` = "timeout" OR `reason` LIKE "http_5%" OR `reason` = "http_429")
             ORDER BY `id_refund_log` ASC LIMIT ' . $limit,
            true,
            false
        );

        return is_array($rows) ? $rows : [];
    }

    /** Retencja tresci wysylek (hasze danych klienta). Zwraca liczbe wyczyszczonych wierszy. */
    public function clearPayloads(int $idShop, int $days): int
    {
        $db = Db::getInstance();
        $before = pSQL(Clock::at(-$days * 86400));
        $db->execute('UPDATE `' . CoreSchema::table(Schema::ORDER_LOG) . '` SET `payload` = NULL WHERE `id_shop` = ' . $idShop . ' AND `payload` IS NOT NULL AND `date_add` < "' . $before . '"');
        $count = (int) $db->Affected_Rows();
        $db->execute('UPDATE `' . CoreSchema::table(Schema::REFUND_LOG) . '` SET `payload` = NULL WHERE `id_shop` = ' . $idShop . ' AND `payload` IS NOT NULL AND `date_add` < "' . $before . '"');

        return $count + (int) $db->Affected_Rows();
    }

    private function filterSql(int $idShop, string $filter): string
    {
        $where = 'ol.`id_shop` = ' . $idShop;

        return match ($filter) {
            'missing' => $where . ' AND ol.`browser_at` IS NULL',
            'blocked' => $where . ' AND ol.`server_status` <> "sent"',
            'refunds' => $where . ' AND EXISTS (SELECT 1 FROM `' . CoreSchema::table(Schema::REFUND_LOG) . '` r WHERE r.`id_shop` = ol.`id_shop` AND r.`id_order` = ol.`id_order`)',
            default => $where,
        };
    }
}
