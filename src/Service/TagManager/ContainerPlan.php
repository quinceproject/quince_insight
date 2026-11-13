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

use Quince\Insight\Service\TagManager\Dto\Plan;
use Quince\Insight\Service\TagManager\Dto\PlanEntity;
use Quince\Insight\Service\TagManager\Dto\PlanInput;

/**
 * Deklaratywny plan kontenera: z ustawien sklepu (`PlanInput`) powstaje lista zmiennych,
 * wyzwalaczy i tagow, jakie modul chce miec w GTM. Czysta funkcja — bez bazy i bez Google.
 *
 * Punkt wyjscia: kontener TagPilota (research/02 §3.4 „GTM container created by
 * autoConfigureContainer()”) — te same typy i klucze parametrow, z poprawkami z SPEC §5.3:
 * Conversion Linker, rozszerzone konwersje w tagu Ads.
 * Fala 3 (decyzje #8, #9): wszystkie tagi Google bez dodatkowego warunku zgody („Not set”,
 * `consentStatus: notSet`) — zgod pilnuje Consent Mode; Google tag z `send_page_view: false`,
 * odslone wysyla tylko tag zdarzenia `page_view`. Zmiana hashy encji = „Update” w planie,
 * wiec istniejace kontenery dostaja poprawke przez zwykle „Apply changes”.
 * Klucze bez oficjalnego zrodla (research/05 §E.4 [U]) sa wypisane w raporcie D, sekcja 5.
 */
final class ContainerPlan
{
    /** Prefiks nazw encji modulu — po nim dopasowujemy encje w kontenerze. */
    public const PREFIX = 'QI - ';

    public const FOLDER_NAME = 'Quince Insight';

    /** Wbudowany wyzwalacz „All Pages” (research/05 §E.4 [V-LOCAL]). */
    public const ALL_PAGES = '2147479553';

    /** 15 zdarzen w kolejnosci prototypu (`EVENTS`). */
    public const EVENTS = [
        'page_view', 'view_item', 'view_item_list', 'select_item', 'add_to_cart', 'remove_from_cart', 'view_cart',
        'begin_checkout', 'add_shipping_info', 'add_payment_info', 'purchase', 'refund', 'login', 'sign_up', 'search',
    ];

    /** Zdarzenia bez bloku `ecommerce` w dataLayer — tag bez „Send Ecommerce data” (uwaga z kodu TagPilota). */
    private const NO_ECOMMERCE = ['page_view', 'login', 'sign_up', 'search'];

    /** Zmienne warstwy danych: nazwa po prefiksie => sciezka w dataLayer (lista TagPilota doslownie). */
    public const DATA_LAYER_VARIABLES = [
        'DLV - ecommerce.transaction_id' => 'ecommerce.transaction_id',
        'DLV - ecommerce.value' => 'ecommerce.value',
        'DLV - ecommerce.currency' => 'ecommerce.currency',
        'DLV - ecommerce.tax' => 'ecommerce.tax',
        'DLV - ecommerce.shipping' => 'ecommerce.shipping',
        'DLV - ecommerce.items' => 'ecommerce.items',
        'DLV - search_term' => 'search_term',
        'DLV - pageCategory' => 'pageCategory',
        'DLV - user_id' => 'user_id',
        'DLV - method' => 'method',
        'DLV - user_data.email' => 'user_data.email',
        'DLV - user_data.phone_number' => 'user_data.phone_number',
        'DLV - user_data.address.first_name' => 'user_data.address.first_name',
        'DLV - user_data.address.last_name' => 'user_data.address.last_name',
        'DLV - user_data.address.postal_code' => 'user_data.address.postal_code',
        'DLV - user_data.address.country' => 'user_data.address.country',
    ];

    /** Pola zmiennej „User-Provided Data” (typ `awec`, tryb MANUAL) => zmienna warstwy danych. */
    private const USER_DATA_FIELDS = [
        'email' => 'DLV - user_data.email',
        'phone_number' => 'DLV - user_data.phone_number',
        'first_name' => 'DLV - user_data.address.first_name',
        'last_name' => 'DLV - user_data.address.last_name',
        'postal_code' => 'DLV - user_data.address.postal_code',
        'country' => 'DLV - user_data.address.country',
    ];

    public function build(PlanInput $input): Plan
    {
        $entities = [];
        $notes = [];
        $events = array_values(array_filter(self::EVENTS, static fn (string $e): bool => in_array($e, $input->events, true)));
        $mid = strtoupper(trim($input->measurementId));
        $adsId = self::adsDigits($input->adsConversionId);
        $adsLabel = trim($input->adsConversionLabel);
        $conversion = $input->ads && $adsId !== '' && $adsLabel !== '';

        if ($mid === '') {
            $notes[] = 'There is no Measurement ID, so the plan has no Analytics tags.';
        }

        if ($input->ads && $adsId === '') {
            $notes[] = 'Google Ads has no conversion ID, so the plan has no Google Ads tags.';
        } elseif ($input->ads && $adsLabel === '') {
            $notes[] = 'Google Ads has no conversion label, so the plan has no conversion tag.';
        }

        $entities[] = $this->entity('folder', 'folder.main', self::FOLDER_NAME, 'folder', ['name' => self::FOLDER_NAME]);

        foreach (self::DATA_LAYER_VARIABLES as $name => $path) {
            $entities[] = $this->entity('variable', 'variable.dlv.' . $path, self::PREFIX . $name, 'dlv', [
                'name' => self::PREFIX . $name,
                'type' => 'v',
                'parameter' => [
                    self::param('integer', 'dataLayerVersion', '2'),
                    self::param('boolean', 'setDefaultValue', 'false'),
                    self::param('template', 'name', $path),
                ],
                'parentFolderId' => '@folder.main',
            ]);
        }

        $userData = [self::param('template', 'mode', 'MANUAL')];

        foreach (self::USER_DATA_FIELDS as $field => $variable) {
            $userData[] = self::param('template', $field, self::ref($variable));
        }

        $entities[] = $this->entity('variable', 'variable.user_data', self::PREFIX . 'User-Provided Data', 'upd', [
            'name' => self::PREFIX . 'User-Provided Data',
            'type' => 'awec',
            'parameter' => $userData,
            'parentFolderId' => '@folder.main',
        ]);

        // Wyzwalacz zakupu jest potrzebny tagowi konwersji Ads takze wtedy, gdy zdarzenie
        // GA4 purchase jest wylaczone.
        $triggerEvents = $events;

        if ($conversion && !in_array('purchase', $triggerEvents, true)) {
            $triggerEvents[] = 'purchase';
            $triggerEvents = array_values(array_filter(self::EVENTS, static fn (string $e): bool => in_array($e, $triggerEvents, true)));
        }

        foreach ($triggerEvents as $event) {
            $entities[] = $this->entity('trigger', 'trigger.' . $event, self::PREFIX . 'CE - ' . $event, 'triggers', [
                'name' => self::PREFIX . 'CE - ' . $event,
                'type' => 'customEvent',
                'customEventFilter' => [[
                    'type' => 'equals',
                    'parameter' => [
                        self::param('template', 'arg0', '{{_event}}'),
                        self::param('template', 'arg1', $event),
                    ],
                ]],
                'parentFolderId' => '@folder.main',
            ]);
        }

        if ($mid !== '') {
            $entities[] = $this->entity('tag', 'tag.google', self::PREFIX . 'Google tag', 'google_tag', [
                'name' => self::PREFIX . 'Google tag',
                'type' => 'googtag',
                'parameter' => [
                    self::param('template', 'tagId', $mid),
                    // Decyzja #9: bez automatycznej odslony — inaczej `page_view` szloby dwa razy
                    // (Google tag + tag zdarzenia). developers.google.com/analytics/devguides/collection/ga4/views;
                    // klucz `configSettingsTable` — research/05 §E.4 [U].
                    ['type' => 'list', 'key' => 'configSettingsTable', 'list' => [
                        ['type' => 'map', 'map' => [
                            self::param('template', 'parameter', 'send_page_view'),
                            self::param('template', 'parameterValue', 'false'),
                        ]],
                    ]],
                ],
                'firingTriggerId' => [self::ALL_PAGES],
                // Tryb zaawansowany zgod: tag laduje sie przy `denied` i wysyla pingi bez ciasteczek
                // (podstawa modelowania); Google tag ma wbudowane kontrole zgod (research/05 §F).
                'consentSettings' => self::googleConsent(),
                'parentFolderId' => '@folder.main',
            ]);

            foreach ($events as $event) {
                $params = [
                    self::param('template', 'eventName', $event),
                    self::param('template', 'measurementIdOverride', $mid),
                ];

                if (!in_array($event, self::NO_ECOMMERCE, true)) {
                    $params[] = self::param('boolean', 'sendEcommerceData', 'true');
                    $params[] = self::param('template', 'getEcommerceDataFrom', 'dataLayer');
                } else {
                    $params[] = self::param('boolean', 'sendEcommerceData', 'false');
                }

                $extra = self::eventParameters($event);

                if ($extra !== []) {
                    $rows = [];

                    foreach ($extra as $name => $value) {
                        $rows[] = ['type' => 'map', 'map' => [
                            self::param('template', 'parameter', $name),
                            self::param('template', 'parameterValue', $value),
                        ]];
                    }

                    $params[] = ['type' => 'list', 'key' => 'eventSettingsTable', 'list' => $rows];
                }

                $entities[] = $this->entity('tag', 'tag.ga4.' . $event, self::PREFIX . 'GA4 - ' . $event, 'ga4_events', [
                    'name' => self::PREFIX . 'GA4 - ' . $event,
                    'type' => 'gaawe',
                    'parameter' => $params,
                    'firingTriggerId' => ['@trigger.' . $event],
                    'tagFiringOption' => 'oncePerEvent',
                    'consentSettings' => self::googleConsent(),
                    'parentFolderId' => '@folder.main',
                ]);
            }
        }

        if ($input->ads && $adsId !== '') {
            if ($conversion) {
                $params = [
                    self::param('template', 'conversionId', $adsId),
                    self::param('template', 'conversionLabel', $adsLabel),
                    self::param('template', 'conversionValue', self::ref('DLV - ecommerce.value')),
                    self::param('template', 'currencyCode', self::ref('DLV - ecommerce.currency')),
                    self::param('template', 'orderId', self::ref('DLV - ecommerce.transaction_id')),
                ];

                if ($input->adsEnhanced) {
                    // Rozszerzone konwersje — klucze [U] (research/05 §E.4), do sprawdzenia przez tags.get.
                    $params[] = self::param('boolean', 'enableEnhancedConversion', 'true');
                    $params[] = self::param('template', 'cssProvidedEnhancedConversionValue', self::ref('User-Provided Data'));
                }

                $entities[] = $this->entity('tag', 'tag.ads.conversion', self::PREFIX . 'Google Ads conversion', 'ads_conversion', [
                    'name' => self::PREFIX . 'Google Ads conversion',
                    'type' => 'awct',
                    'parameter' => $params,
                    'firingTriggerId' => ['@trigger.purchase'],
                    'consentSettings' => self::googleConsent(),
                    'parentFolderId' => '@folder.main',
                ]);
            }

            $entities[] = $this->entity('tag', 'tag.ads.linker', self::PREFIX . 'Conversion Linker', 'ads_linker', [
                'name' => self::PREFIX . 'Conversion Linker',
                'type' => 'gclidw',
                'firingTriggerId' => [self::ALL_PAGES],
                'consentSettings' => self::googleConsent(),
                'parentFolderId' => '@folder.main',
            ]);

            if ($input->adsRemarketing) {
                $entities[] = $this->entity('tag', 'tag.ads.remarketing', self::PREFIX . 'Google Ads remarketing', 'ads_remarketing', [
                    'name' => self::PREFIX . 'Google Ads remarketing',
                    'type' => 'sp',
                    'parameter' => [self::param('template', 'conversionId', $adsId)],
                    'firingTriggerId' => [self::ALL_PAGES],
                    'consentSettings' => self::googleConsent(),
                    'parentFolderId' => '@folder.main',
                ]);
            }
        }

        $hashSource = '';

        foreach ($entities as $entity) {
            $hashSource .= $entity->key . ':' . $entity->fingerprint . "\n";
        }

        return new Plan($entities, sha1($hashSource), $notes);
    }

    /** `AW-123456789` -> `123456789` (tag `awct` / `sp` przyjmuje same cyfry — [U]). */
    public static function adsDigits(string $id): string
    {
        $id = strtoupper(trim($id));

        if (strpos($id, 'AW-') === 0) {
            $id = substr($id, 3);
        }

        return preg_match('/^\d{5,15}$/', $id) ? $id : '';
    }

    /** Odwolanie do zmiennej modulu: `{{QI - DLV - ecommerce.value}}`. */
    public static function ref(string $variable): string
    {
        return '{{' . self::PREFIX . $variable . '}}';
    }

    /** Hash definicji encji: rodzaj + nazwa + tresc z odwolaniami `@klucz` (bez identyfikatorow GTM). */
    public static function fingerprint(string $kind, array $body): string
    {
        return sha1($kind . '|' . (string) json_encode(self::canonical($body), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string,string> parametr zdarzenia => wartosc (TagPilot: eventSettingsTable) */
    private static function eventParameters(string $event): array
    {
        return match ($event) {
            'page_view' => ['page_category' => self::ref('DLV - pageCategory')],
            'login', 'sign_up' => ['method' => self::ref('DLV - method')],
            'search' => ['search_term' => self::ref('DLV - search_term')],
            'purchase' => ['user_data' => self::ref('User-Provided Data')],
            default => [],
        };
    }

    /**
     * Ustawienie zgod kazdego tagu Google w planie (Google tag, GA4, Ads conversion, Conversion
     * Linker, remarketing): „Not set” — bez dodatkowego warunku zgody (decyzja #8). Tagi Google
     * maja wbudowane kontrole zgod i same reaguja na Consent Mode
     * (support.google.com/tagmanager/answer/10718549, /12962079; research/05 §E.4: `notSet`
     * = domyslnie, bez dodatkowych kontroli). Dawny warunek `ad_storage` na tagach Ads usuniety.
     *
     * @return array{consentStatus:string}
     */
    public static function googleConsent(): array
    {
        return ['consentStatus' => 'notSet'];
    }

    /** @return array{type:string,key:string,value:string} */
    private static function param(string $type, string $key, string $value): array
    {
        return ['type' => $type, 'key' => $key, 'value' => $value];
    }

    /** @param array<string,mixed> $body */
    private function entity(string $kind, string $key, string $name, string $group, array $body): PlanEntity
    {
        return new PlanEntity($kind, $key, $name, $group, $body, self::fingerprint($kind, $body));
    }

    /** Tablice asocjacyjne z kluczami posortowanymi — ten sam hash niezaleznie od kolejnosci pol. */
    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $out = [];

        foreach ($value as $k => $v) {
            $out[$k] = self::canonical($v);
        }

        if (!array_is_list($out)) {
            ksort($out);
        }

        return $out;
    }
}
