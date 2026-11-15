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
 * Dziennik zdarzen (`event_log`): co strona wypisala do dataLayer, co skrypt frontu
 * zglosil po zdarzeniu w przegladarce i co poszlo z serwera. Wlacznik i retencja w ustawieniach.
 *
 * Bez danych osobowych: `user_data` (hasze) i `user_id` sa usuwane przed zapisem.
 */
final class EventLog
{
    public function __construct(private readonly EventsSettings $settings)
    {
    }

    /**
     * @param array<int,array{event:string, payload:array<string,mixed>}> $events
     */
    public function write(int $idShop, string $source, string $page, array $events, ?int $idOrder = null): void
    {
        if ($events === [] || !$this->settings->logOn($idShop)) {
            return;
        }

        $values = [];
        $now = pSQL(Clock::now());
        $page = pSQL(substr(self::cleanPage($page), 0, 255));

        foreach ($events as $event) {
            $payload = self::strip($event['payload']);
            $values[] = '(' . $idShop . ', "' . pSQL(substr($event['event'], 0, 40)) . '", "' . pSQL($source) . '", '
                . ($idOrder ? (int) $idOrder : 'NULL') . ', "' . $page . '", "'
                . pSQL(substr(Json::encode($payload), 0, 60000), true) . '", "' . $now . '")';
        }

        Db::getInstance()->execute(
            'INSERT INTO `' . CoreSchema::table(Schema::EVENT_LOG) . '` (`id_shop`, `event`, `source`, `id_order`, `page`, `payload`, `date_add`) VALUES ' . implode(', ', $values)
        );
    }

    /**
     * @return array{rows:array<int,array<string,mixed>>, total:int}
     */
    public function page(int $idShop, string $event, int $limit, int $offset): array
    {
        $where = '`id_shop` = ' . $idShop . ($event !== '' && $event !== 'all' ? ' AND `event` = "' . pSQL($event) . '"' : '');
        $table = '`' . CoreSchema::table(Schema::EVENT_LOG) . '`';
        $total = (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where, false);
        $rows = Db::getInstance()->executeS(
            'SELECT * FROM ' . $table . ' WHERE ' . $where . ' ORDER BY `id_event_log` DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
            true,
            false
        );

        return ['rows' => is_array($rows) ? $rows : [], 'total' => $total];
    }

    public function count(int $idShop, string $event): int
    {
        return (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . CoreSchema::table(Schema::EVENT_LOG) . '` WHERE `id_shop` = ' . $idShop . ($event !== '' && $event !== 'all' ? ' AND `event` = "' . pSQL($event) . '"' : ''), false);
    }

    /** @return array<string,int> zdarzenie => liczba z ostatnich $hours godzin */
    public function counts(int $idShop, int $hours = 24): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT `event`, COUNT(*) AS n FROM `' . CoreSchema::table(Schema::EVENT_LOG) . '`
             WHERE `id_shop` = ' . $idShop . ' AND `date_add` >= "' . pSQL(Clock::at(-$hours * 3600)) . '"
             GROUP BY `event`',
            true,
            false
        );
        $out = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[(string) $row['event']] = (int) $row['n'];
        }

        return $out;
    }

    /** Ostatnie zdarzenie z przegladarki (kontrola „Events reach the dataLayer”). */
    public function lastBrowserEvent(int $idShop): ?string
    {
        $value = Db::getInstance()->getValue(
            'SELECT MAX(`date_add`) FROM `' . CoreSchema::table(Schema::EVENT_LOG) . '` WHERE `id_shop` = ' . $idShop . ' AND `source` = "browser"',
            false
        );

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** Retencja: usuwa wpisy starsze niz ustawienie. Zwraca liczbe usunietych. */
    public function purgeOld(int $idShop): int
    {
        $db = Db::getInstance();
        $db->execute(
            'DELETE FROM `' . CoreSchema::table(Schema::EVENT_LOG) . '`
             WHERE `id_shop` = ' . $idShop . ' AND `date_add` < "' . pSQL(Clock::at(-$this->settings->logKeepDays($idShop) * 86400)) . '"'
        );

        return (int) $db->Affected_Rows();
    }

    public function clear(int $idShop): void
    {
        Db::getInstance()->delete(CoreSchema::name(Schema::EVENT_LOG), '`id_shop` = ' . $idShop);
    }

    /**
     * Usuwa dane klienta przed zapisem w dzienniku (ARCHITEKTURA §11).
     *
     * @param array<string,mixed> $payload
     *
     * @return array<string,mixed>
     */
    public static function strip(array $payload): array
    {
        unset($payload['user_id']);

        if (array_key_exists('user_data', $payload)) {
            $payload['user_data'] = '(hashed customer data)';
        }

        return $payload;
    }

    /** Parametry adresu, ktore otwieraja cudze dane (np. `key` na potwierdzeniu zamowienia). */
    private const SECRET_PARAMS = ['key', 'token', 'reset_token', 'id_customer', 'secure_key', 'password'];

    /**
     * Adres strony do dziennika bez parametrow z sekretami. Czyscimy tutaj, bo ten sam adres
     * przychodzi z serwera (`REQUEST_URI`) i z przegladarki (zgloszenie skryptu frontu).
     */
    public static function cleanPage(string $page): string
    {
        $page = explode('#', $page, 2)[0];
        $parts = explode('?', $page, 2);

        if (!isset($parts[1]) || $parts[1] === '') {
            return $parts[0];
        }

        $kept = [];

        foreach (explode('&', $parts[1]) as $pair) {
            $name = strtolower(urldecode(explode('=', $pair, 2)[0]));
            $name = (string) preg_replace('/\[.*$/', '', $name);

            if ($pair !== '' && !in_array($name, self::SECRET_PARAMS, true)) {
                $kept[] = $pair;
            }
        }

        return $kept === [] ? $parts[0] : $parts[0] . '?' . implode('&', $kept);
    }
}
