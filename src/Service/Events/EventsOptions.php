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

/**
 * Klucze ustawien uslugi (per sklep) — zdarzenia, zgody, serwer, Customer Reviews. Odczyt i zapis przez `Core\Options`.
 *
 * Lista z ARCHITEKTURA §7; od fali 1 plik nalezy do agenta C.
 */
final class EventsOptions
{
    /** JSON: zdarzenie => 0/1 (15 zdarzen z prototypu `EVENTS`). */
    public const EV_ENABLED = 'QUINCE_INSIGHT_EV_ENABLED';

    /** id | reference | ean */
    public const EV_PRODUCT_ID = 'QUINCE_INSIGHT_EV_PRODUCT_ID';

    /** 0/1 */
    public const EV_PRICE_TAX = 'QUINCE_INSIGHT_EV_PRICE_TAX';

    /** 0/1 */
    public const EV_CATEGORY_PATH = 'QUINCE_INSIGHT_EV_CATEGORY_PATH';

    /** never | purchase | always */
    public const EV_USER_DATA = 'QUINCE_INSIGHT_EV_USER_DATA';

    /** qcmp | other | none */
    public const EV_CONSENT_SOURCE = 'QUINCE_INSIGHT_EV_CONSENT_SOURCE';

    public const EV_CONSENT_COOKIE = 'QUINCE_INSIGHT_EV_CONSENT_COOKIE';

    /** JSON: kategoria => sygnal. */
    public const EV_CONSENT_MAP = 'QUINCE_INSIGHT_EV_CONSENT_MAP';

    /** JSON 4 sygnalow. */
    public const EV_CONSENT_DEFAULT = 'QUINCE_INSIGHT_EV_CONSENT_DEFAULT';

    /** Milisekundy. */
    public const EV_CONSENT_WAIT = 'QUINCE_INSIGHT_EV_CONSENT_WAIT';

    public const EV_SERVER_PURCHASE = 'QUINCE_INSIGHT_EV_SERVER_PURCHASE';

    public const EV_SERVER_REFUND = 'QUINCE_INSIGHT_EV_SERVER_REFUND';

    public const EV_SERVER_USER_DATA = 'QUINCE_INSIGHT_EV_SERVER_USER_DATA';

    public const EV_LOG_ON = 'QUINCE_INSIGHT_EV_LOG_ON';

    /** Domyslnie 14. */
    public const EV_LOG_KEEP_DAYS = 'QUINCE_INSIGHT_EV_LOG_KEEP_DAYS';

    /** 0/1, domyslnie 1 — cron sam dosyla brakujace zamowienia. */
    public const EV_AUTO_MISSING = 'QUINCE_INSIGHT_EV_AUTO_MISSING';

    /** Minuty od zlozenia zamowienia, domyslnie 120. */
    public const EV_AUTO_MISSING_AFTER = 'QUINCE_INSIGHT_EV_AUTO_MISSING_AFTER';

    /** User potwierdzil wlaczenie programu w Merchant Center — bez tego okienko Google nie jest wstawiane. */
    public const GCR_CONFIRMED = 'QUINCE_INSIGHT_GCR_CONFIRMED';

    /** Wartosc domyslna liczby dni dostawy. */
    public const GCR_DELIVERY_DAYS = 'QUINCE_INSIGHT_GCR_DELIVERY_DAYS';

    /** JSON: `id_reference` przewoznika => liczba dni. */
    public const GCR_CARRIER_DAYS = 'QUINCE_INSIGHT_GCR_CARRIER_DAYS';

    /** `opt_in_style` */
    public const GCR_STYLE = 'QUINCE_INSIGHT_GCR_STYLE';

    /** 0/1, domyslnie 1 — "Ask customers after an order" (prototyp `gcr.survey`). Rozszerzenie listy z ARCHITEKTURA §7.4. */
    public const GCR_SURVEY = 'QUINCE_INSIGHT_GCR_SURVEY';

    /** Kiedy front ostatnio wypisal snippet GTM (kontrola na Events -> Overview). Zapis najwyzej co 5 minut. */
    public const EV_SNIPPET_SEEN = 'QUINCE_INSIGHT_EV_SNIPPET_SEEN';

    /** JSON {ids: [...], at: data} — inne kontenery GTM wykryte w przegladarce (drugi snippet w motywie). */
    public const EV_OTHER_GTM = 'QUINCE_INSIGHT_EV_OTHER_GTM';
}
