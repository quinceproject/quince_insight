<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\Analytics;

/**
 * Klucze ustawien uslugi (per sklep) — Analytics. Odczyt i zapis przez `Core\Options`.
 *
 * Lista z ARCHITEKTURA §7; od fali 1 plik nalezy do agenta B.
 */
final class AnalyticsOptions
{
    /** `accounts/123` */
    public const ACCOUNT = 'QUINCE_INSIGHT_GA4_ACCOUNT';

    /** `properties/456` */
    public const PROPERTY = 'QUINCE_INSIGHT_GA4_PROPERTY';

    public const PROPERTY_NAME = 'QUINCE_INSIGHT_GA4_PROPERTY_NAME';

    /** Pelna nazwa zasobu strumienia. */
    public const STREAM = 'QUINCE_INSIGHT_GA4_STREAM';

    /** `G-...` */
    public const MEASUREMENT_ID = 'QUINCE_INSIGHT_GA4_MEASUREMENT_ID';

    /** Sekret Measurement Protocol — zaszyfrowany (`Options::setSecret`). */
    public const MP_SECRET = 'QUINCE_INSIGHT_GA4_MP_SECRET';

    public const MP_SECRET_NAME = 'QUINCE_INSIGHT_GA4_MP_SECRET_NAME';

    /** Data potwierdzenia zbierania danych. */
    public const ACK_AT = 'QUINCE_INSIGHT_GA4_ACK_AT';

    /** Id klienta Ads z powiazania — tylko odczyt. */
    public const ADS_LINK = 'QUINCE_INSIGHT_GA4_ADS_LINK';

    /*
     * Rozszerzenie listy z ARCHITEKTURA §7.3 (agent B) — tylko wybory z kreatora,
     * potrzebne do stanu kroku i do "Needs attention".
     */

    /** 0/1 — user chcial sekretu Measurement Protocol (przelacznik w kreatorze; domyslnie 1). */
    public const MP_ON = 'QUINCE_INSIGHT_GA4_MP_ON';

    /** 1 = ostatnia proba utworzenia sekretu odbila sie od braku potwierdzenia zbierania danych. */
    public const ACK_NEEDED = 'QUINCE_INSIGHT_GA4_ACK_NEEDED';

    /** Ostatni blad zapisu kroku kreatora (komunikat dla usera; '' = brak). */
    public const SETUP_ERROR = 'QUINCE_INSIGHT_GA4_SETUP_ERROR';

    /** Nazwa sekretu MP tworzonego przez modul (displayName w GA4). */
    public const SECRET_DISPLAY_NAME = 'Quince Insight';
}
