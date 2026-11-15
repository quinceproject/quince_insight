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

use Quince\Insight\Core\Dto\Job;
use Quince\Insight\Core\Dto\JobProgress;
use Quince\Insight\Core\Google\GoogleApiException;
use Quince\Insight\Core\Google\GoogleAuthException;
use Quince\Insight\Core\JobHandler;
use Quince\Insight\Core\Log;
use Quince\Insight\Core\Options;

/**
 * Zadanie `analytics.setup` — „Set up Analytics” w kroku kreatora `ga4` (prototyp
 * `taskLines('ga4')` i wspolna karta postepu). Pasek postepu = wspolny `job_progress`.
 *
 * Wiersze tylko dla tego, co faktycznie sie dzieje (jak prototyp — `null` odpada):
 *  - „Creating property “…”” — gdy wybrana nowa usluga,
 *  - „Creating web data stream for …” — gdy nowa usluga albo nowy strumien,
 *  - „Creating Measurement Protocol secret” — gdy sekret wlaczony (z potwierdzeniem zbierania
 *    danych, gdy user je zaznaczyl),
 *  - „Marking purchase as a key event” — gdy przelacznik wlaczony. Decyzja #5: modul tylko
 *    dodaje zdarzenie kluczowe, nigdy go nie usuwa.
 *
 * Kazdy krok jest idempotentny (`PropertySetup::ensure*` szuka przed `create`), wiec
 * wznowienie po limicie Google albo ponowienie („Try again”) nie tworzy duplikatow.
 *
 * Payload: pola formularza kroku (account, property, name, timezone, currency, stream, mp,
 * key_purchase, ack) — sprawdzone `Ga4Step::validate()` przed startem.
 */
final class SetupJob implements JobHandler
{
    public const TYPE = 'analytics.setup';

    /** Fazy w kolejnosci wykonania. */
    private const PHASES = ['property', 'stream', 'secret', 'key'];

    public function __construct(
        private readonly PropertySetup $setup,
        private readonly Ga4Settings $settings,
        private readonly ShopDefaults $defaults,
        private readonly PropertyInfo $info,
    ) {
    }

    /**
     * Wiersze karty postepu dla wyboru z formularza (bez wykonywania czegokolwiek).
     *
     * @param array<string,mixed> $input
     *
     * @return array<int,array{key:string,text:string,params:array<string,string>,st:string,note:string}>
     */
    public static function plan(array $input, string $origin): array
    {
        $new = (string) ($input['property'] ?? '') === 'new';
        $items = [];

        if ($new) {
            $items[] = ['key' => 'property', 'text' => 'Creating property “%name%”', 'params' => ['%name%' => trim((string) ($input['name'] ?? ''))], 'st' => 'wait', 'note' => ''];
        }

        if ($new || (string) ($input['stream'] ?? 'new') === 'new') {
            $items[] = ['key' => 'stream', 'text' => 'Creating web data stream for %url%', 'params' => ['%url%' => $origin], 'st' => 'wait', 'note' => ''];
        }

        if (self::flag($input['mp'] ?? true)) {
            $items[] = ['key' => 'secret', 'text' => 'Creating Measurement Protocol secret', 'params' => [], 'st' => 'wait', 'note' => ''];
        }

        if (self::flag($input['key_purchase'] ?? true)) {
            $items[] = ['key' => 'key', 'text' => 'Marking purchase as a key event', 'params' => [], 'st' => 'wait', 'note' => ''];
        }

        return $items;
    }

    public function step(Job $job): JobProgress
    {
        $idShop = $job->idShop;
        $input = $job->payload;
        $state = $job->state + ['phase' => 0, 'property' => null, 'stream' => null];

        $total = count(self::PHASES);

        // Pierwszy krok tylko rozpisuje wiersze karty — przerwanie w pierwszej fazie ma juz
        // liste, na ktorej widac, ktory wiersz sie nie udal.
        if (!isset($state['items'])) {
            $state['items'] = self::plan($input, $this->defaults->origin($idShop));

            if ($state['items'] !== []) {
                $state['items'][0]['st'] = 'run';
            }

            return new JobProgress(0, $total, 'Setting up Analytics', false, 0, $state);
        }

        // Faza bez wiersza (np. istniejaca usluga) idzie od razu dalej — jeden krok zadania
        // konczy sie dopiero na fazie widocznej w karcie albo na koncu zadania.
        while ($state['phase'] < $total) {
            $phase = self::PHASES[$state['phase']];
            $state['items'] = self::mark($state['items'], $phase, 'run');

            try {
                $state = $this->run($phase, $idShop, $input, $state);
            } catch (AckRequiredException $e) {
                Options::set(AnalyticsOptions::SETUP_ERROR, $e->getMessage(), $idShop);

                throw new \DomainException($e->getMessage(), 0, $e);
            } catch (GoogleApiException $e) {
                // Limit Google: zadanie czeka i wraca (JobRunner); inne bledy — komunikat dla usera.
                if ($e->isQuota() && !$e->isDailyQuota()) {
                    throw $e;
                }

                throw $this->failure($idShop, $e);
            } catch (GoogleAuthException $e) {
                throw $this->failure($idShop, $e);
            } catch (\DomainException|\InvalidArgumentException $e) {
                throw $e;
            } catch (\Throwable $e) {
                throw $this->failure($idShop, $e);
            }

            $state['items'] = self::mark($state['items'], $phase, 'ok');
            ++$state['phase'];

            if (self::has($state['items'], $phase) && $state['phase'] < $total) {
                $next = self::PHASES[$state['phase']];
                $state['items'] = self::mark($state['items'], $next, 'run');

                return new JobProgress($state['phase'], $total, 'Setting up Analytics', false, 0, $state);
            }
        }

        Options::delete(AnalyticsOptions::SETUP_ERROR, $idShop);
        $this->info->forget($idShop);

        return new JobProgress($total, $total, 'Done', true, 0, $state, [
            'measurementId' => Options::str(AnalyticsOptions::MEASUREMENT_ID, '', $idShop),
        ]);
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $state
     *
     * @return array<string,mixed>
     */
    private function run(string $phase, int $idShop, array $input, array $state): array
    {
        $account = trim((string) ($input['account'] ?? ''));

        switch ($phase) {
            case 'property':
                $state['property'] = $this->setup->ensureProperty(
                    $idShop,
                    $account,
                    trim((string) ($input['property'] ?? '')),
                    (string) ($input['name'] ?? ''),
                    (string) ($input['timezone'] ?? ''),
                    strtoupper((string) ($input['currency'] ?? ''))
                );
                break;

            case 'stream':
                $property = (array) $state['property'];
                $choice = (string) ($input['property'] ?? '') === 'new' ? 'new' : trim((string) ($input['stream'] ?? 'new'));
                $state['stream'] = $this->setup->ensureStream($idShop, (string) ($property['name'] ?? ''), $choice, $this->defaults->origin($idShop));
                $this->setup->remember($idShop, $account, $property, (array) $state['stream']);
                Options::set(AnalyticsOptions::MP_ON, self::flag($input['mp'] ?? true) ? 1 : 0, $idShop);
                break;

            case 'secret':
                if (self::flag($input['mp'] ?? true)) {
                    $this->setup->ensureSecret($idShop, (string) (((array) $state['property'])['name'] ?? ''), (string) (((array) $state['stream'])['name'] ?? ''), self::flag($input['ack'] ?? false));
                }
                break;

            case 'key':
                // Decyzja #5: tylko dodanie; wylaczony przelacznik = nic w GA4.
                if (self::flag($input['key_purchase'] ?? true)) {
                    $this->settings->setKeyEvent($idShop, 'purchase', true);
                }
                break;
        }

        return $state;
    }

    private function failure(int $idShop, \Throwable $e): \RuntimeException
    {
        $message = Ga4Step::message($e);
        Options::set(AnalyticsOptions::SETUP_ERROR, $message, $idShop);
        Log::write($idShop, 'analytics', Log::ERROR, 'GA4 setup failed: ' . $e->getMessage());

        return new \RuntimeException($message, 0, $e);
    }

    /**
     * @param array<int,array<string,mixed>> $items
     *
     * @return array<int,array<string,mixed>>
     */
    private static function mark(array $items, string $key, string $st): array
    {
        foreach ($items as $i => $item) {
            if (($item['key'] ?? '') === $key) {
                $items[$i]['st'] = $st;
            }
        }

        return $items;
    }

    /** @param array<int,array<string,mixed>> $items */
    private static function has(array $items, string $key): bool
    {
        foreach ($items as $item) {
            if (($item['key'] ?? '') === $key) {
                return true;
            }
        }

        return false;
    }

    private static function flag(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'on', 'true'], true);
    }
}
