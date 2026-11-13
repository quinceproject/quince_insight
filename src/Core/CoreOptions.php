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

/**
 * Klucze ustawien rdzenia (per sklep). Odczyt i zapis przez `Options`.
 */
final class CoreOptions
{
    /** Prefiks wlacznika uslugi: `QUINCE_INSIGHT_SVC_GA4` itd. Uzywac `Services`, nie tych kluczy wprost. */
    public const SVC_PREFIX = 'QUINCE_INSIGHT_SVC_';

    /** Wersja tokenu crona sklepu; "New token" ja podnosi i stare adresy przestaja dzialac. */
    public const CRON_TOKEN_VER = 'QUINCE_INSIGHT_CRON_TOKEN_VER';

    /** Ile dni trzymac `api_log`. */
    public const LOG_KEEP_DAYS = 'QUINCE_INSIGHT_LOG_KEEP_DAYS';

    public const LOG_KEEP_DAYS_DEFAULT = 30;
}
