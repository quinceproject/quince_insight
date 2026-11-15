<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Core\Task;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Db;
use Quince\Insight\Core\Clock;
use Quince\Insight\Core\CoreOptions;
use Quince\Insight\Core\Dto\JobStatus;
use Quince\Insight\Core\Options;
use Quince\Insight\Core\Schema;

/**
 * Zadanie `core.retention` (grupa `maintenance`): usuwa stare wpisy rdzenia.
 *
 * Dziennik `api_log` po ustawionej liczbie dni (domyslnie 30), zakonczone zadania w tle
 * po 30 dniach, pamiec podreczna 30 dni po wygasnieciu, zuzyte wpisy `state` logowania.
 * Tabele uslug sprzataja ich wlasne zadania.
 */
final class RetentionTask
{
    /** Usuwanie porcjami, zeby duze kasowanie nie blokowalo tabeli na caly przebieg. */
    private const CHUNK = 5000;

    private const KEEP_FINISHED_DAYS = 30;

    public function run(int $idShop): string
    {
        $db = Db::getInstance();
        $days = max(1, Options::int(CoreOptions::LOG_KEEP_DAYS, CoreOptions::LOG_KEEP_DAYS_DEFAULT, $idShop));

        $db->execute(
            'DELETE FROM `' . Schema::table(Schema::API_LOG) . '`
             WHERE `id_shop` = ' . $idShop . ' AND `date_add` < "' . pSQL(Clock::at(-$days * 86400)) . '"
             LIMIT ' . self::CHUNK
        );
        $logs = (int) $db->Affected_Rows();

        $old = pSQL(Clock::at(-self::KEEP_FINISHED_DAYS * 86400));

        $db->execute(
            'DELETE FROM `' . Schema::table(Schema::JOB) . '`
             WHERE `id_shop` = ' . $idShop . '
               AND `status` IN ("' . JobStatus::DONE . '", "' . JobStatus::FAILED . '", "' . JobStatus::CANCELLED . '")
               AND `date_upd` < "' . $old . '"
             LIMIT ' . self::CHUNK
        );
        $jobs = (int) $db->Affected_Rows();

        $db->execute(
            'DELETE FROM `' . Schema::table(Schema::CACHE) . '`
             WHERE `id_shop` = ' . $idShop . ' AND `expires_at` < "' . $old . '"
             LIMIT ' . self::CHUNK
        );
        $cache = (int) $db->Affected_Rows();

        $db->execute(
            'DELETE FROM `' . Schema::table(Schema::OAUTH_STATE) . '` WHERE `expires_at` < "' . pSQL(Clock::now()) . '"'
        );

        return 'Removed: ' . $logs . ' log entries, ' . $jobs . ' finished jobs, ' . $cache . ' cache entries.';
    }
}
