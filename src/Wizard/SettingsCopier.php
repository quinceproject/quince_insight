<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Wizard;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Db;
use Quince\Insight\Core\Log;
use Quince\Insight\Core\Options;
use Quince\Insight\Core\Schema;
use Quince\Insight\Service\Events\EventsOptions;
use Quince\Insight\Service\MerchantTools\MerchantToolsOptions;
use Quince\Insight\Service\MerchantTools\Schema as ToolsSchema;

/**
 * "Copy settings from another shop" w kroku Services kolejnego sklepu (prototyp `ms.adding`).
 *
 * Kopiuje to, co nie zalezy od kont Google: ustawienia zdarzen i zgod, zrodla
 * identyfikatorow produktu, mapowanie kategorii i atrybutow oraz reguly wspolne dla
 * wszystkich feedow. Konta (Analytics, Tag Manager, Merchant Center) wybiera sie dla
 * sklepu osobno. Filtry feedu nie maja jeszcze dokad trafic (sklep nie ma feedu) —
 * sklep-zrodlo zostaje zapisany w `WizardOptions::COPY_FROM` dla kroku Merchant Center.
 */
final class SettingsCopier
{
    /**
     * Klucze ustawien do skopiowania: wszystkie `QUINCE_INSIGHT_EV_*` (zdarzenia, zgody,
     * wysylka z serwera, dziennik) i zrodla identyfikatorow Merchant Center.
     *
     * @return string[]
     */
    public static function optionKeys(): array
    {
        $out = [];

        // Stan sklepu, nie ustawienie: kiedy strona ostatnio wypisala snippet i czy wykryto
        // drugi kontener GTM. Skopiowany udawalby, ze nowy sklep juz dziala (agent I, prosba A).
        $state = [EventsOptions::EV_SNIPPET_SEEN, EventsOptions::EV_OTHER_GTM];

        foreach ((new \ReflectionClass(EventsOptions::class))->getConstants() as $value) {
            if (is_string($value) && str_starts_with($value, 'QUINCE_INSIGHT_EV_') && !in_array($value, $state, true)) {
                $out[] = $value;
            }
        }

        foreach ([MerchantToolsOptions::ID_GTIN, MerchantToolsOptions::ID_MPN, MerchantToolsOptions::ID_BRAND, MerchantToolsOptions::ID_EXISTS_AUTO] as $key) {
            $out[] = $key;
        }

        return $out;
    }

    /**
     * Kopiuje ustawienia z $from do $to. Ustawienia, ktorych zrodlo nie ma, zostaja w $to bez zmian.
     *
     * @return int liczba skopiowanych ustawien i wierszy
     */
    public function copy(int $from, int $to): int
    {
        if ($from <= 0 || $to <= 0 || $from === $to) {
            return 0;
        }

        $count = $this->copyOptions($from, $to);

        try {
            $count += $this->copyRows(ToolsSchema::CATEGORY_MAP, ['id_category', 'google_id'], $from, $to);
            $count += $this->copyRows(ToolsSchema::ATTRIBUTE_MAP, ['target', 'source_type', 'source_id', 'fixed_value'], $from, $to);
            $count += $this->copyRules($from, $to);
        } catch (\Throwable $e) {
            // Tabela usluga moze jeszcze nie istniec — ustawienia zdarzen i tak juz sa skopiowane.
            Log::write($to, 'core', Log::WARNING, 'Copying settings from another shop was incomplete: ' . $e->getMessage());
        }

        Options::set(WizardOptions::COPY_FROM, $from, $to);

        return $count;
    }

    public function copyOptions(int $from, int $to): int
    {
        $count = 0;

        foreach (self::optionKeys() as $key) {
            if (Options::has($key, $from)) {
                Options::set($key, Options::str($key, '', $from), $to);
                ++$count;
            }
        }

        return $count;
    }

    /** @param string[] $columns kolumny poza `id_shop` */
    private function copyRows(string $table, array $columns, int $from, int $to): int
    {
        $name = '`' . Schema::table($table) . '`';
        $list = '`' . implode('`, `', $columns) . '`';

        Db::getInstance()->delete(Schema::name($table), '`id_shop` = ' . $to);

        Db::getInstance()->execute(
            'INSERT INTO ' . $name . ' (`id_shop`, ' . $list . ')
             SELECT ' . $to . ', ' . $list . ' FROM ' . $name . ' WHERE `id_shop` = ' . $from
        );

        return (int) Db::getInstance()->Affected_Rows();
    }

    /** Reguly wspolne dla wszystkich feedow (`id_feed = 0`); reguly konkretnego feedu zostaja przy nim. */
    private function copyRules(int $from, int $to): int
    {
        $name = '`' . Schema::table(ToolsSchema::RULE) . '`';
        $columns = '`type`, `name`, `conditions`, `actions`, `position`, `active`';

        Db::getInstance()->delete(Schema::name(ToolsSchema::RULE), '`id_shop` = ' . $to . ' AND `id_feed` = 0');

        Db::getInstance()->execute(
            'INSERT INTO ' . $name . ' (`id_shop`, `id_feed`, ' . $columns . ')
             SELECT ' . $to . ', 0, ' . $columns . ' FROM ' . $name . ' WHERE `id_shop` = ' . $from . ' AND `id_feed` = 0'
        );

        return (int) Db::getInstance()->Affected_Rows();
    }
}
