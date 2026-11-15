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

if (!defined('_PS_VERSION_')) {
    exit;
}

use Quince\Insight\Core\Cache;
use Quince\Insight\Core\Dto\AttentionItem;
use Quince\Insight\Core\Dto\ServiceHealth;
use Quince\Insight\Core\Google\ConnectionRepository;
use Quince\Insight\Core\Options;
use Quince\Insight\Core\ServiceModule;

/**
 * Usluga `analytics` — Google Analytics 4: usluga, strumien, raporty.
 *
 * Bez hookow i cronow: wszystko dzieje sie w panelu na zadanie (raporty z pamieci
 * podrecznej, `Cache`); jedyne zadanie w tle to `analytics.setup` z kroku kreatora.
 * Dane GA4 do frontu daje `Ga4Settings` (uslugi Events i Tag Manager).
 */
final class Module implements ServiceModule
{
    public function __construct(
        private readonly ConnectionRepository $connections,
        private readonly Cache $cache,
        private readonly Ga4Api $api,
    ) {
    }

    public function key(): string
    {
        return 'analytics';
    }

    public function requires(): ?string
    {
        return 'ga4';
    }

    public function hooks(): array
    {
        return [];
    }

    public function cronTasks(): array
    {
        return [];
    }

    public function jobs(): array
    {
        return [SetupJob::TYPE => SetupJob::class];
    }

    public function tables(): array
    {
        return [];
    }

    public function health(int $idShop): ?ServiceHealth
    {
        $problem = $this->problems($idShop)[0] ?? null;

        if ($problem !== null) {
            return new ServiceHealth(
                $problem->level === AttentionItem::ERROR ? ServiceHealth::ERROR : ServiceHealth::ATTENTION,
                $problem->message,
                Options::str(AnalyticsOptions::MEASUREMENT_ID, '', $idShop),
                $problem->params
            );
        }

        $id = Options::str(AnalyticsOptions::MEASUREMENT_ID, '', $idShop);
        $realtime = $this->realtime($idShop);

        if ($realtime === null) {
            return new ServiceHealth(ServiceHealth::OK, 'Connected to GA4', $id);
        }

        if ($realtime < 0) {
            return new ServiceHealth(ServiceHealth::OK, 'No events in the last 30 minutes', $id);
        }

        return $realtime === 0
            ? new ServiceHealth(ServiceHealth::OK, 'Receiving data · last event less than a minute ago', $id)
            : new ServiceHealth(ServiceHealth::OK, 'Receiving data · last event %minutes% min ago', $id, ['%minutes%' => $realtime]);
    }

    public function attention(int $idShop): array
    {
        return $this->problems($idShop);
    }

    public function disable(int $idShop): void
    {
        // Wybor uslugi zostaje (ponowne wlaczenie nie wymaga kreatora); znika tylko pamiec raportow.
        $this->cache->forget($idShop, 'ga4.');
    }

    public function purge(int $idShop): void
    {
        // Wszystko lokalne siedzi w `setting` i `cache` — rdzen czysci je po id_shop.
    }

    public function leftInGoogle(int $idShop): array
    {
        $property = Options::str(AnalyticsOptions::PROPERTY_NAME, '', $idShop) ?: Options::str(AnalyticsOptions::PROPERTY, '', $idShop);

        if ($property === '') {
            return [];
        }

        $out = ['Analytics property ' . $property . ' with its web data stream ' . Options::str(AnalyticsOptions::MEASUREMENT_ID, '', $idShop)];

        if (Options::str(AnalyticsOptions::MP_SECRET_NAME, '', $idShop) !== '') {
            $out[] = 'Measurement Protocol secret "' . AnalyticsOptions::SECRET_DISPLAY_NAME . '" in that stream';
        }

        return $out;
    }

    /**
     * Problemy w kolejnosci wagi: brak uprawnien, brak uslugi / strumienia, brak sekretu.
     *
     * @return AttentionItem[]
     */
    public function problems(int $idShop): array
    {
        $connection = $this->connections->forShop($idShop);

        if ($connection !== null && $connection->isConnected() && $this->connections->missingFor($connection->id, ['ga4']) !== []) {
            return [new AttentionItem(AttentionItem::ERROR, 'Google did not grant access to Analytics.', 'quince_insight_settings')];
        }

        if (Options::str(AnalyticsOptions::PROPERTY, '', $idShop) === '') {
            return [new AttentionItem(AttentionItem::WARNING, 'No GA4 property is selected — Analytics is not set up.', 'quince_insight_wizard', ['step' => 'ga4'])];
        }

        if (Options::str(AnalyticsOptions::STREAM, '', $idShop) === '' || Options::str(AnalyticsOptions::MEASUREMENT_ID, '', $idShop) === '') {
            return [new AttentionItem(AttentionItem::WARNING, 'The GA4 property has no web data stream for this shop.', 'quince_insight_wizard', ['step' => 'ga4'])];
        }

        if (Options::bool(AnalyticsOptions::MP_ON, true, $idShop) && Options::secret(AnalyticsOptions::MP_SECRET, $idShop) === '') {
            return [new AttentionItem(AttentionItem::WARNING, 'The Measurement Protocol secret is missing — purchases and refunds cannot be sent from the server.', 'quince_insight_analytics', ['tab' => 'property'])];
        }

        return [];
    }

    /**
     * Minuty od ostatniego zdarzenia z raportu czasu rzeczywistego (30 minut, pamiec 10 minut);
     * -1 = brak zdarzen, null = nie udalo sie sprawdzic.
     */
    private function realtime(int $idShop): ?int
    {
        $property = Options::str(AnalyticsOptions::PROPERTY, '', $idShop);

        try {
            $data = $this->cache->remember($idShop, 'ga4.state.realtime.' . Ga4Settings::id($property), 600, function () use ($idShop, $property): array {
                $min = -1;

                foreach (ReportMath::rows($this->api->runRealtimeReport($idShop, $property, ReportQueries::realtime())) as $row) {
                    if (($row['metrics']['eventCount'] ?? 0) > 0) {
                        $ago = (int) ($row['dims']['minutesAgo'] ?? 0);
                        $min = $min < 0 ? $ago : min($min, $ago);
                    }
                }

                return ['minutes' => $min];
            });
        } catch (\Throwable $e) {
            return null;
        }

        return (int) ($data['minutes'] ?? -1);
    }
}
