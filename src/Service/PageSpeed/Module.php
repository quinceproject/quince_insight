<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\PageSpeed;

use Quince\Insight\Core\Dto\AttentionItem;
use Quince\Insight\Core\Dto\CronTask;
use Quince\Insight\Core\Dto\ServiceHealth;
use Quince\Insight\Core\Google\ConnectionRepository;
use Quince\Insight\Core\Options;
use Quince\Insight\Core\Scheduler;
use Quince\Insight\Core\ServiceModule;

/**
 * Usluga `pagespeed` — Page speed: CrUX (dane prawdziwych wizyt) i PageSpeed Insights
 * (test laboratoryjny wybranych stron). Nic nie wstawia na strone sklepu.
 */
final class Module implements ServiceModule
{
    public function __construct(
        private readonly ConnectionRepository $connections,
        private readonly PsiRepository $repository,
    ) {
    }

    public function key(): string
    {
        return 'pagespeed';
    }

    public function requires(): ?string
    {
        return 'psi';
    }

    public function hooks(): array
    {
        return [];
    }

    public function cronTasks(): array
    {
        // Raz na godzine wystarczy: zadanie samo pilnuje harmonogramu (tydzien / doba).
        return [new CronTask('pagespeed.schedule', Scheduler::GROUP_MAINTENANCE, ScheduleTask::class, 60, 45)];
    }

    public function jobs(): array
    {
        return [CheckJob::TYPE => CheckJob::class];
    }

    public function tables(): array
    {
        return Schema::tables();
    }

    public function health(int $idShop): ?ServiceHealth
    {
        if (!$this->hasKey($idShop)) {
            return new ServiceHealth(ServiceHealth::ATTENTION, 'Page speed needs a Google API key.');
        }

        $field = PageSpeedScreen::field($this->repository->results($idShop)[0][PsiRepository::FIELD]['mobile'] ?? []);

        if ($field['state'] === 'none') {
            return new ServiceHealth(ServiceHealth::OK, 'Not checked yet.');
        }

        if ($field['state'] === 'nodata') {
            return new ServiceHealth(ServiceHealth::OK, 'Not enough Chrome visitors for real-visitor data yet.');
        }

        return $field['passed']
            ? new ServiceHealth(ServiceHealth::OK, 'Core Web Vitals passed on mobile.')
            : new ServiceHealth(ServiceHealth::ATTENTION, 'Core Web Vitals not passed on mobile.');
    }

    public function attention(int $idShop): array
    {
        if (!$this->hasKey($idShop)) {
            // R-12: klucz API wpisuje sie w kroku kreatora „Google Cloud project”, nie w Settings.
            return [new AttentionItem(AttentionItem::WARNING, 'Page speed needs a Google API key. Add it in the Google Cloud project step of the wizard.', 'quince_insight_wizard', ['step' => 'cloud'])];
        }

        return [];
    }

    public function disable(int $idShop): void
    {
        // Wyniki zostaja (do porownania po ponownym wlaczeniu); harmonogram nie rusza, bo
        // zadania cron uslugi wylaczonej nie sa uruchamiane.
    }

    public function purge(int $idShop): void
    {
        // Tabele `psi_*` i ustawienia czysci rdzen po `id_shop`; plikow usluga nie ma.
    }

    public function leftInGoogle(int $idShop): array
    {
        return [];
    }

    private function hasKey(int $idShop): bool
    {
        $connection = $this->connections->forShop($idShop);

        return $connection !== null && $connection->hasApiKey;
    }

    /** Ostatnie sprawdzenie (do pulpitu). */
    public static function lastRun(int $idShop): string
    {
        return Options::str(PageSpeedOptions::LAST_RUN, '', $idShop);
    }
}
