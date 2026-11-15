<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\MerchantTools;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Klucze ustawien uslugi (per sklep) — narzedzia Merchant Center. Odczyt i zapis przez `Core\Options`.
 *
 * Lista z ARCHITEKTURA §7.7. Wartosci domyslne przy odczycie (`MappingValues::DEFAULTS`).
 */
final class MerchantToolsOptions
{
    /** Zrodlo GTIN: ean13 | upc | isbn | none (domyslnie ean13). */
    public const ID_GTIN = 'QUINCE_INSIGHT_GMC_ID_GTIN';

    /** Zrodlo MPN: reference | mpn | supplier_reference | none (domyslnie reference). */
    public const ID_MPN = 'QUINCE_INSIGHT_GMC_ID_MPN';

    /** Zrodlo marki: manufacturer | shop | none (domyslnie manufacturer). */
    public const ID_BRAND = 'QUINCE_INSIGHT_GMC_ID_BRAND';

    /** identifier_exists: auto | yes | no (domyslnie auto — "no", gdy brak GTIN i MPN). */
    public const ID_EXISTS_AUTO = 'QUINCE_INSIGHT_GMC_ID_EXISTS_AUTO';

    /** Kiedy modul ostatnio wyslal ustawienia wysylki (DATETIME). */
    public const SHIPPING_SENT_AT = 'QUINCE_INSIGHT_GMC_SHIPPING_SENT_AT';

    /** etag ustawien wysylki po ostatniej wysylce z modulu (rozpoznanie "set manually"). */
    public const SHIPPING_ETAG = 'QUINCE_INSIGHT_GMC_SHIPPING_ETAG';

    /** Uslugi wyslane ostatnio do Merchant Center (JSON) — dla JSON-LD agenta H (`ShippingSettings`). */
    public const SHIPPING_SENT = 'QUINCE_INSIGHT_GMC_SHIPPING_SENT';

    /** Zrodla danych promocji: JSON "jezyk~KRAJ" => `accounts/x/dataSources/y`. */
    public const PROMO_DATASOURCE = 'QUINCE_INSIGHT_GMC_PROMO_DATASOURCE';
}
