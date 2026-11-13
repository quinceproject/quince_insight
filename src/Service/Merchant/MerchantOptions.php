<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\Merchant;

/**
 * Klucze ustawien uslugi (per sklep) — Merchant Center. Odczyt i zapis przez `Core\Options`.
 *
 * Lista z ARCHITEKTURA §7.6 plus rozszerzenia agenta E (oznaczone nizej).
 */
final class MerchantOptions
{
    /** Id konta Merchant Center sklepu. */
    public const ACCOUNT = 'QUINCE_INSIGHT_GMC_ACCOUNT';

    public const ACCOUNT_NAME = 'QUINCE_INSIGHT_GMC_ACCOUNT_NAME';

    /** Konto rejestracji projektu w Merchant API. */
    public const REG_ACCOUNT = 'QUINCE_INSIGHT_GMC_REG_ACCOUNT';

    public const REG_AT = 'QUINCE_INSIGHT_GMC_REG_AT';

    /** unclaimed | claimed | other */
    public const CLAIM_STATE = 'QUINCE_INSIGHT_GMC_CLAIM_STATE';

    /** cron | manual */
    public const SYNC_MODE = 'QUINCE_INSIGHT_GMC_SYNC_MODE';

    /** Domyslnie 100. */
    public const BATCH = 'QUINCE_INSIGHT_GMC_BATCH';

    /* ---- rozszerzenia agenta E ---- */

    /** Wynik dobowej kontroli konta rejestracji: '' | ok | not_registered | no_contact | error. */
    public const REG_STATE = 'QUINCE_INSIGHT_GMC_REG_STATE';

    /** Kiedy ostatnio sprawdzono konto rejestracji (`getAccountForGcpRegistration`). */
    public const REG_CHECKED_AT = 'QUINCE_INSIGHT_GMC_REG_CHECKED_AT';

    /** Adres witryny przejety na koncie (do pokazania obok stanu przejecia). */
    public const CLAIM_URI = 'QUINCE_INSIGHT_GMC_CLAIM_URI';

    /** JSON: rola zalogowanego uzytkownika na koncie (`accessRights`). */
    public const ACCESS_RIGHTS = 'QUINCE_INSIGHT_GMC_ACCESS_RIGHTS';

    /** JSON: problemy konta z ostatniego odswiezenia (`accounts.issues`). */
    public const ACCOUNT_ISSUES = 'QUINCE_INSIGHT_GMC_ACCOUNT_ISSUES';

    public const ISSUES_AT = 'QUINCE_INSIGHT_GMC_ISSUES_AT';

    /** Token strony listy produktow Google, gdy odswiezanie statusow nie zmiescilo sie w jednym cronie. */
    public const STATUS_PAGE_TOKEN = 'QUINCE_INSIGHT_GMC_STATUS_TOKEN';

    /** Id feedu w trakcie usuwania (`FeedDeleteJob`); 0 = zaden. Feed jest wtedy wstrzymany. */
    public const DELETING_FEED = 'QUINCE_INSIGHT_GMC_DELETING_FEED';

    /** Domyslna wielkosc paczki kolejki (wywolan Google na jedno uruchomienie). */
    public const DEFAULT_BATCH = 100;
}
