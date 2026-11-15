<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Core;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Quince\Insight\Core\Dto\CronTask;

/**
 * Stala lista uslug modulu i tego, co kazda z nich dopina do rdzenia.
 *
 * Usluga nie rejestruje sie sama — jest wpisana tutaj. Dzieki temu kolejnosc
 * (tabel, hookow, kart na pulpicie) jest jedna i przewidywalna.
 */
final class ServiceRegistry
{
    /** Kolejnosc = kolejnosc wywolywania hookow i kart na pulpicie. */
    public const MODULES = [
        \Quince\Insight\Service\Verification\Module::class,
        \Quince\Insight\Service\Analytics\Module::class,
        \Quince\Insight\Service\TagManager\Module::class,
        \Quince\Insight\Service\Events\Module::class,
        \Quince\Insight\Service\Merchant\Module::class,
        \Quince\Insight\Service\MerchantTools\Module::class,
        \Quince\Insight\Service\SearchConsole\Module::class,
        \Quince\Insight\Service\PageSpeed\Module::class,
        \Quince\Insight\Service\StructuredData\Module::class,
    ];

    /** Kroki kreatora dostarczane przez uslugi: klucz kroku => klasa `WizardStep`. Kolejnosc jak w prototypie. */
    public const WIZARD_STEPS = [
        'ga4' => \Quince\Insight\Service\Analytics\Ga4Step::class,
        'ads' => \Quince\Insight\Service\TagManager\AdsStep::class,
        'gtm' => \Quince\Insight\Service\TagManager\GtmStep::class,
        'verify' => \Quince\Insight\Service\Verification\VerifyStep::class,
        'gmc' => \Quince\Insight\Service\Merchant\GmcStep::class,
        'gcr' => \Quince\Insight\Service\Events\GcrStep::class,
        'gsc' => \Quince\Insight\Service\SearchConsole\GscStep::class,
    ];

    /**
     * Zadania w tle rdzenia tylko dla instalacji deweloperskiej (`config/dev.php`):
     * typ => klasa `JobHandler`. Paczka wydania ich nie rejestruje (R-16).
     */
    private const DEV_JOBS = [
        'core.demo' => Job\DemoJob::class,
    ];

    /** @var array<string,ServiceModule>|null */
    private ?array $modules = null;

    /**
     * @return array<string,ServiceModule> klucz uslugi => obiekt
     */
    public function all(): array
    {
        if ($this->modules !== null) {
            return $this->modules;
        }

        $out = [];

        foreach (self::MODULES as $class) {
            $module = Container::get($class);

            if ($module instanceof ServiceModule) {
                $out[$module->key()] = $module;
            }
        }

        return $this->modules = $out;
    }

    public function get(string $key): ?ServiceModule
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * Wszystkie typy zadan w tle: typ => klasa `JobHandler`.
     *
     * @return array<string,string>
     */
    public function jobs(): array
    {
        $out = Dev::enabled() ? self::DEV_JOBS : [];

        foreach ($this->all() as $module) {
            foreach ($module->jobs() as $type => $class) {
                $out[(string) $type] = (string) $class;
            }
        }

        return $out;
    }

    /**
     * Zadania cykliczne rdzenia (grupa `maintenance`).
     *
     * @return CronTask[]
     */
    public function coreCronTasks(): array
    {
        return [
            new CronTask('core.connection', Scheduler::GROUP_MAINTENANCE, Task\ConnectionTask::class, 60, 15),
            new CronTask('core.retention', Scheduler::GROUP_MAINTENANCE, Task\RetentionTask::class, 60, 15),
        ];
    }

    /**
     * Krok kreatora uslugi; null gdy klucz nie nalezy do zadnej uslugi (kroki rdzenia).
     */
    public function wizardStep(string $key): ?WizardStep
    {
        $class = self::WIZARD_STEPS[$key] ?? null;

        if ($class === null) {
            return null;
        }

        $step = Container::get($class);

        return $step instanceof WizardStep ? $step : null;
    }
}
