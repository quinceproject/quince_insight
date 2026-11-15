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
 * Zamiana zgod na 4 sygnaly Consent Mode v2. Czysta logika (bez PrestaShop).
 *
 * Trzy zrodla (SPEC §5.3 „adapter zgod”):
 *  - Quince CMP — sygnaly podaje sam modul (`consentState()['signals']`), nic do mapowania;
 *  - inne narzedzie — ciasteczko o podanej nazwie + mapowanie kategoria -> sygnal;
 *  - brak — stan domyslny z ustawien.
 *
 * Format ciasteczka obcego narzedzia nie jest znany z gory, wiec parser przyjmuje
 * najczestsze postacie: JSON obiektu (`{"analytics":true,...}`), JSON listy
 * (`["analytics","marketing"]`), pary `klucz:wartosc` / `klucz=wartosc` rozdzielone
 * `,` `;` `&` `|` oraz sama lista nazw kategorii. Wartosci w adresie (%7B…) sa dekodowane.
 */
final class ConsentMapper
{
    /** Popularne inne nazwy tych samych kategorii (np. Cookiebot: statistics). */
    private const ALIASES = [
        'statistics' => 'analytics',
        'statistic' => 'analytics',
        'performance' => 'analytics',
        'analytical' => 'analytics',
        'advertising' => 'marketing',
        'ads' => 'marketing',
        'targeting' => 'marketing',
        'functional' => 'preferences',
        'functionality' => 'preferences',
        'preference' => 'preferences',
    ];

    /** Fragmenty nazw kluczy, pod ktorymi narzedzia trzymaja kategorie odrzucone. */
    private const NEGATIVE_KEYS = ['reject', 'denied', 'deny', 'declin', 'refus', 'disallow', 'optout', 'notaccepted', 'notallowed'];

    private const TRUE_VALUES =['1', 'true', 'yes', 'granted', 'allow', 'allowed', 'accepted', 'on'];

    /**
     * Kategorie, na ktore odwiedzajacy sie zgodzil; null = ciasteczka brak (nie zdecydowal).
     *
     * @return string[]|null
     */
    public static function parseCookie(?string $raw): ?array
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return null;
        }

        if (str_contains($raw, '%')) {
            $raw = rawurldecode($raw);
        }

        $granted = [];
        $decoded = json_decode($raw, true);

        if (is_array($decoded)) {
            self::collect($decoded, $granted);
        } elseif (preg_match_all('/([A-Za-z_][A-Za-z0-9_\-]*)\s*[:=]\s*([A-Za-z0-9"\']+)/', $raw, $pairs, PREG_SET_ORDER)) {
            foreach ($pairs as $pair) {
                if (self::truthy(trim($pair[2], '"\''))) {
                    $granted[] = self::category($pair[1]);
                }
            }
        } else {
            foreach (preg_split('/[\s,;|&+]+/', $raw) ?: [] as $token) {
                if ($token !== '') {
                    $granted[] = self::category($token);
                }
            }
        }

        return array_values(array_unique(array_filter($granted, static fn (string $c): bool => $c !== '')));
    }

    /**
     * Sygnaly z kategorii: sygnal przyznany, gdy jego kategoria jest wsrod zgod.
     * Bez decyzji (null) — stan domyslny.
     *
     * @param string[]|null $categories
     * @param array<string,string> $map sygnal => kategoria
     * @param array<string,string> $defaults sygnal => granted|denied
     *
     * @return array<string,string>
     */
    public static function signalsFromCategories(?array $categories, array $map, array $defaults): array
    {
        if ($categories === null) {
            return self::normalize($defaults);
        }

        $out = [];

        foreach (EventsSettings::SIGNALS as $signal) {
            $category = $map[$signal] ?? (EventsSettings::DEFAULT_MAP[$signal] ?? '');
            $out[$signal] = in_array($category, $categories, true) ? 'granted' : 'denied';
        }

        return $out;
    }

    /**
     * Zawsze komplet 4 sygnalow z wartosciami granted|denied (brakujacy = denied).
     *
     * @param array<string,mixed> $signals
     *
     * @return array<string,string>
     */
    public static function normalize(array $signals): array
    {
        $out = [];

        foreach (EventsSettings::SIGNALS as $signal) {
            $value = $signals[$signal] ?? 'denied';
            $out[$signal] = ($value === 'granted' || $value === true) ? 'granted' : 'denied';
        }

        return $out;
    }

    /**
     * Pole `consent` Measurement Protocol (research/05 §D: tylko ad_user_data i ad_personalization).
     *
     * @param array<string,string> $signals
     *
     * @return array{ad_user_data:string, ad_personalization:string}
     */
    public static function forMeasurementProtocol(array $signals): array
    {
        return [
            'ad_user_data' => ($signals['ad_user_data'] ?? '') === 'granted' ? 'GRANTED' : 'DENIED',
            'ad_personalization' => ($signals['ad_personalization'] ?? '') === 'granted' ? 'GRANTED' : 'DENIED',
        ];
    }

    /** @param array<string,string> $signals */
    public static function granted(array $signals, string $signal): bool
    {
        return ($signals[$signal] ?? '') === 'granted';
    }

    /**
     * @param array<mixed> $data
     * @param string[] $granted
     */
    private static function collect(array $data, array &$granted): void
    {
        $isList = array_is_list($data);

        foreach ($data as $key => $value) {
            if ($isList && is_string($value)) {
                $granted[] = self::category($value);
                continue;
            }

            if (is_array($value)) {
                // Galaz z odmowa ({"rejected": ["analytics"]}) nie jest lista zgod.
                // Bez tego kategorie odrzucone liczylyby sie jak przyznane.
                if (is_string($key) && self::negative($key)) {
                    continue;
                }

                // Zagniezdzenie typu {"categories": {...}} albo {"consent": [...]}.
                self::collect($value, $granted);
                continue;
            }

            if (is_string($key) && self::truthy($value)) {
                $granted[] = self::category($key);
            }
        }
    }

    /** Klucz galezi z kategoriami odrzuconymi (`rejected`, `deniedCategories`, `opt_out`...). */
    private static function negative(string $key): bool
    {
        $key = (string) preg_replace('/[^a-z]/', '', strtolower($key));

        foreach (self::NEGATIVE_KEYS as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value > 0;
        }

        return is_string($value) && in_array(strtolower(trim($value)), self::TRUE_VALUES, true);
    }

    private static function category(string $name): string
    {
        $name = strtolower(trim($name));

        if (in_array($name, EventsSettings::CATEGORIES, true)) {
            return $name;
        }

        return self::ALIASES[$name] ?? '';
    }
}
