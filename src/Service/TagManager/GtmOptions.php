<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\TagManager;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Klucze ustawien uslugi (per sklep) — Tag Manager i Google Ads w kontenerze. Odczyt i zapis przez `Core\Options`.
 *
 * Lista z ARCHITEKTURA §7; od fali 1 plik nalezy do agenta D.
 */
final class GtmOptions
{
    /** auto | own | shared */
    public const GTM_MODE = 'QUINCE_INSIGHT_GTM_MODE';

    /** Sklep zarzadzajacy kontenerem przy `shared`. */
    public const GTM_OWNER_SHOP = 'QUINCE_INSIGHT_GTM_OWNER_SHOP';

    public const GTM_ACCOUNT = 'QUINCE_INSIGHT_GTM_ACCOUNT';

    /** Sciezka `accounts/x/containers/y`. Sciezki workspace NIE zapisywac. */
    public const GTM_CONTAINER = 'QUINCE_INSIGHT_GTM_CONTAINER';

    /** `GTM-...` */
    public const GTM_PUBLIC_ID = 'QUINCE_INSIGHT_GTM_PUBLIC_ID';

    public const GTM_VERSION = 'QUINCE_INSIGHT_GTM_VERSION';

    public const GTM_VERSION_NAME = 'QUINCE_INSIGHT_GTM_VERSION_NAME';

    public const GTM_PUBLISHED_AT = 'QUINCE_INSIGHT_GTM_PUBLISHED_AT';

    /** Hash planu ostatnio zastosowanego. */
    public const GTM_PLAN_HASH = 'QUINCE_INSIGHT_GTM_PLAN_HASH';

    /** `AW-...` */
    public const ADS_CONVERSION_ID = 'QUINCE_INSIGHT_ADS_CONVERSION_ID';

    public const ADS_CONVERSION_LABEL = 'QUINCE_INSIGHT_ADS_CONVERSION_LABEL';

    /** 0/1 */
    public const ADS_REMARKETING = 'QUINCE_INSIGHT_ADS_REMARKETING';

    /** 0/1 */
    public const ADS_ENHANCED = 'QUINCE_INSIGHT_ADS_ENHANCED';

    /** Nazwa kontenera (do ekranow, bez wywolania Google). */
    public const GTM_CONTAINER_NAME = 'QUINCE_INSIGHT_GTM_CONTAINER_NAME';

    /**
     * Identyfikator pomiaru wpisany recznie w kroku `gtm`, gdy usluga Analytics jest wylaczona
     * ("GA4 without the Analytics step"). Przy wlaczonej usludze liczy sie `Ga4Settings`.
     */
    public const GA4_MANUAL_ID = 'QUINCE_INSIGHT_GTM_GA4_MANUAL_ID';

    /** Sekret Measurement Protocol wpisany recznie (zaszyfrowany, `Options::setSecret`). */
    public const GA4_MANUAL_SECRET = 'QUINCE_INSIGHT_GTM_GA4_MANUAL_SECRET';

    /**
     * 1 = user wybral „Create a container” w koncie `GTM_ACCOUNT`; kontener powstaje dopiero
     * przy „Apply and publish” (podglad zmian niczego w Google nie tworzy).
     */
    public const GTM_NEW_CONTAINER = 'QUINCE_INSIGHT_GTM_NEW_CONTAINER';

    /** Krok `ads` zapisany przez usera (Summary: done zamiast todo). */
    public const ADS_SAVED = 'QUINCE_INSIGHT_ADS_SAVED';

    // Krotkie nazwy uzywane przez rdzen (przekazanie wspolnego kontenera po usunieciu sklepu).
    public const MODE = self::GTM_MODE;
    public const OWNER_SHOP = self::GTM_OWNER_SHOP;
    public const CONTAINER = self::GTM_CONTAINER;

    public const MODE_AUTO = 'auto';
    public const MODE_OWN = 'own';
    public const MODE_SHARED = 'shared';
}
