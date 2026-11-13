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

use Quince\Insight\Core\Cache;
use Quince\Insight\Core\Google\GoogleApiException;
use Quince\Insight\Core\Options;
use Quince\Insight\Core\Services;
use Quince\Insight\Service\TagManager\GtmOptions;

/**
 * Odczyt ustawien Analytics dla innych uslug (kontrakt ARCHITEKTURA §7.3, wiazacy).
 *
 * Zdarzenia kluczowe zyja w GA4 (`properties.keyEvents`), nie w module: odczyt idzie do
 * Google (pamiec podreczna 10 minut), zmiana od razu do Google i czysci pamiec.
 *
 * Usluga `ga4` wylaczona w sklepie: identyfikator pomiaru i sekret MP pochodza z recznych pol
 * kroku `gtm` ("GA4 without the Analytics step", agent D — `GtmOptions::GA4_MANUAL_*`).
 * Usluga wlaczona: wygrywaja wartosci z kroku `ga4`.
 */
final class Ga4Settings
{
    public const CACHE_KEY_EVENTS = 'ga4.state.keyevents';
    private const STATE_TTL = 600;

    public function __construct(
        private readonly Ga4Api $api,
        private readonly Cache $cache,
        private readonly Services $services,
    ) {
    }

    /** '' gdy brak */
    public function measurementId(int $idShop): string
    {
        if (!$this->services->enabled($idShop, 'ga4')) {
            return Options::str(GtmOptions::GA4_MANUAL_ID, '', $idShop);
        }

        return Options::str(AnalyticsOptions::MEASUREMENT_ID, '', $idShop);
    }

    /** '' gdy brak. Sekret — nigdy do HTML ani odpowiedzi JSON. */
    public function mpSecret(int $idShop): string
    {
        if (!$this->services->enabled($idShop, 'ga4')) {
            return Options::secret(GtmOptions::GA4_MANUAL_SECRET, $idShop);
        }

        return Options::secret(AnalyticsOptions::MP_SECRET, $idShop);
    }

    public function property(int $idShop): string
    {
        return Options::str(AnalyticsOptions::PROPERTY, '', $idShop);
    }

    /**
     * Zdarzenia kluczowe uslugi GA4 sklepu (Events -> Event list).
     * Bez uslugi: []. Blad Google przechodzi dalej (wolajacy pokazuje komunikat), chyba ze
     * jest ostatnia znana lista — wtedy ta.
     *
     * @return string[] np. ['purchase']
     */
    public function keyEvents(int $idShop): array
    {
        $property = $this->property($idShop);

        if ($property === '') {
            return [];
        }

        $key = self::CACHE_KEY_EVENTS . '.' . self::id($property);

        try {
            $data = $this->cache->remember($idShop, $key, self::STATE_TTL, fn (): array => [
                'events' => self::names($this->api->keyEvents($idShop, $property)),
            ]);
        } catch (GoogleApiException $e) {
            $stale = $this->cache->stale($idShop, $key);

            if ($stale === null) {
                throw $e;
            }

            $data = $stale;
        }

        return array_values(array_map('strval', (array) ($data['events'] ?? [])));
    }

    /**
     * Dodaje zdarzenie kluczowe w GA4 (`keyEvents.create`, `countingMethod` = ONCE_PER_EVENT —
     * pole wymagane, research/05 §B).
     *
     * Decyzja #5: modul NIGDY nie usuwa zdarzen kluczowych w GA4 — usuwa je wlasciciel
     * w GA4 (support.google.com/analytics/answer/13128484). `$on = false` konczy sie
     * \DomainException bez zadnego wywolania Google. Zdarzenie juz kluczowe = nic do zrobienia.
     * Rzuca GoogleApiException, NotConnectedException, \DomainException.
     */
    public function setKeyEvent(int $idShop, string $event, bool $on): void
    {
        if (!$on) {
            throw new \DomainException('The module does not remove key events. Remove it in GA4.');
        }

        $property = $this->property($idShop);

        if ($property === '') {
            throw new \DomainException('Analytics is not set up for this shop yet.');
        }

        if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,39}$/', $event) !== 1) {
            throw new \InvalidArgumentException('Invalid event name.');
        }

        try {
            if (in_array($event, self::names($this->api->keyEvents($idShop, $property)), true)) {
                return;
            }

            try {
                $this->api->createKeyEvent($idShop, $property, $event);
            } catch (GoogleApiException $e) {
                if ($e->httpCode() !== 409) {
                    throw $e;
                }
            }
        } finally {
            $this->cache->forget($idShop, self::CACHE_KEY_EVENTS);
        }
    }

    /**
     * @param array<int,array<string,mixed>> $keyEvents
     *
     * @return string[]
     */
    public static function names(array $keyEvents): array
    {
        $names = [];

        foreach ($keyEvents as $keyEvent) {
            $name = (string) ($keyEvent['eventName'] ?? '');

            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /** `properties/123` -> `123` (do kluczy pamieci podrecznej). */
    public static function id(string $property): string
    {
        return (string) preg_replace('#^properties/#', '', $property);
    }
}
