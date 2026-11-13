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
 * Informacje globalne na pulpicie (tabela `notice`), zamykane przyciskiem "Dismiss".
 *
 * Nie naleza do sklepu: najwazniejsza z nich mowi o sklepie, ktorego juz nie ma
 * (co zostalo po nim w Google).
 */
final class NoticeRepository
{
    public const KIND_SHOP_DELETED = 'shop_deleted';

    /**
     * @param string[] $items pozycje listy (zwykly tekst)
     */
    public function add(string $kind, string $title, array $items): int
    {
        Db::getInstance()->insert(Schema::name(Schema::NOTICE), [
            'kind' => pSQL(substr($kind, 0, 30)),
            'title' => pSQL(mb_substr($title, 0, 255), true),
            'body' => pSQL((string) json_encode(array_values($items), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true),
            'dismissed' => 0,
            'date_add' => pSQL(Clock::now()),
        ]);

        return (int) Db::getInstance()->Insert_ID();
    }

    /**
     * Informacje jeszcze niezamkniete, od najnowszej.
     *
     * @return array<int,array{id_notice:int,kind:string,title:string,items:string[],date_add:string}>
     */
    public function open(): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT * FROM `' . Schema::table(Schema::NOTICE) . '` WHERE `dismissed` = 0 ORDER BY `id_notice` DESC'
        ) ?: [];

        $out = [];

        foreach ($rows as $row) {
            $items = json_decode((string) $row['body'], true);

            $out[] = [
                'id_notice' => (int) $row['id_notice'],
                'kind' => (string) $row['kind'],
                'title' => (string) $row['title'],
                'items' => is_array($items) ? array_map('strval', $items) : [],
                'date_add' => (string) $row['date_add'],
            ];
        }

        return $out;
    }

    public function dismiss(int $idNotice): void
    {
        Db::getInstance()->update(Schema::name(Schema::NOTICE), ['dismissed' => 1], '`id_notice` = ' . $idNotice);
    }
}
