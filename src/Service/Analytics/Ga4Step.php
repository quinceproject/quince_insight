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

use Quince\Insight\Core\Google\ConnectionRepository;
use Quince\Insight\Core\Google\GoogleApiException;
use Quince\Insight\Core\Google\GoogleAuthException;
use Quince\Insight\Core\Google\NotConnectedException;
use Quince\Insight\Core\JobRunner;
use Quince\Insight\Core\Log;
use Quince\Insight\Core\Options;
use Quince\Insight\Core\ShopContext;
use Quince\Insight\Core\WizardStep;

/**
 * Krok kreatora `ga4` (prototyp `cur==='ga4'`).
 *
 * Szablon: `views/templates/admin/wizard/steps/ga4.html.twig` — dostaje wynik `data([$idShop])`
 * jako zmienna `step`. Pola formularza (nazwy jak klucze `save()`): account, property
 * (`properties/…` albo `new`), name, timezone, currency, stream (`properties/…/dataStreams/…`
 * albo `new`), mp (0/1), key_purchase (0/1), ack (0/1).
 *
 * `save()` robi wszystko w Google od razu (najwyzej kilka wywolan) i idempotentnie:
 * powtorzenie z tym samym wyborem nie tworzy drugiej uslugi ani strumienia.
 * Przy kilku sklepach pierwszy sklep z listy dostaje wybor dokladnie, kolejne — to samo
 * konto i te sama usluge; strumien ten sam, gdy pasuje do domeny sklepu, inaczej strumien
 * dla domeny tego sklepu (ARCHITEKTURA §6 "Podpowiedzi z adresu").
 *
 * Bledy `save()`: klucz pola => komunikat; `ack` = potrzebne potwierdzenie zbierania danych
 * (szablon pokazuje wtedy przelacznik), `_` = blad Google.
 */
final class Ga4Step implements WizardStep
{
    public function __construct(
        private readonly PropertySetup $setup,
        private readonly AccountBrowser $browser,
        private readonly Ga4Settings $settings,
        private readonly ShopDefaults $defaults,
        private readonly ConnectionRepository $connections,
        private readonly PropertyInfo $info,
        private readonly JobRunner $jobs,
    ) {
    }

    public function key(): string
    {
        return 'ga4';
    }

    public function data(array $shopIds): array
    {
        $idShop = (int) ($shopIds[0] ?? ShopContext::currentOrDefault());
        $origin = $this->defaults->origin($idShop);
        $saved = [
            'account' => Options::str(AnalyticsOptions::ACCOUNT, '', $idShop),
            'property' => Options::str(AnalyticsOptions::PROPERTY, '', $idShop),
            'stream' => Options::str(AnalyticsOptions::STREAM, '', $idShop),
        ];

        $data = [
            'idShop' => $idShop,
            'shopUrl' => $origin,
            'granted' => $this->granted($idShop),
            'defaults' => [
                'name' => $this->defaults->name($idShop),
                'timezone' => $this->defaults->timeZone($idShop),
                'currency' => $this->defaults->currency($idShop),
            ],
            'timeZones' => $this->defaults->timeZones(),
            'currencies' => $this->defaults->currencies($idShop),
            'accounts' => [],
            'properties' => [],
            'error' => '',
            'form' => [
                'account' => $saved['account'],
                'property' => $saved['property'] !== '' ? $saved['property'] : 'existing',
                'stream' => $saved['stream'] !== '' ? $saved['stream'] : 'new',
                'mp' => Options::bool(AnalyticsOptions::MP_ON, true, $idShop),
                'keyPurchase' => true,
                'ack' => false,
            ],
            'ackNeeded' => Options::bool(AnalyticsOptions::ACK_NEEDED, false, $idShop),
            'keyLocked' => false,
            'savedProperty' => $saved['property'],
            'setupError' => Options::str(AnalyticsOptions::SETUP_ERROR, '', $idShop),
            'status' => $this->status($idShop),
        ];

        // Zadanie "Set up Analytics" (karta postepu): '' | running | done | error.
        $job = $this->jobs->last($idShop, SetupJob::TYPE);
        $data['job'] = $job?->toArray();
        $data['task'] = $job === null ? '' : ($job->isActive() ? 'running'
            : ($job->status === 'done' && $data['status'] === 'done' ? 'done' : ($job->status === 'failed' ? 'error' : '')));

        if (!$data['granted']) {
            return $data;
        }

        try {
            $data['accounts'] = $this->browser->accounts($idShop);

            if ($data['form']['account'] === '' && $data['accounts'] !== []) {
                $data['form']['account'] = $data['accounts'][0]['name'];
            }

            if ($data['form']['account'] !== '') {
                $data['properties'] = $this->browser->properties($idShop, $data['form']['account'], $origin);
            }

            if ($saved['property'] !== '') {
                // Decyzja #5: juz kluczowe w GA4 -> przelacznik pokazuje stan z GA4 i jest zablokowany
                // (dla zapisanej uslugi; po wyborze innej uslugi w kroku znow dziala).
                $data['keyLocked'] = in_array('purchase', $this->settings->keyEvents($idShop), true);
                $data['form']['keyPurchase'] = $data['keyLocked'];
            }
        } catch (\Throwable $e) {
            $data['error'] = self::message($e);
        }

        $data['form'] = self::preselect($data['form'], $data['properties'], $saved);

        return $data;
    }

    public function save(array $shopIds, array $input): array
    {
        $shopIds = array_values(array_filter(array_map('intval', $shopIds), static fn (int $id): bool => $id > 0));

        if ($shopIds === []) {
            return ['_' => 'No shop selected.'];
        }

        // Sklep, na ktorego formularzu user wybieral, idzie pierwszy — dostaje wybor doslownie.
        $formShop = (int) ($input['id_shop'] ?? 0);

        if (in_array($formShop, $shopIds, true)) {
            $shopIds = array_values(array_unique(array_merge([$formShop], $shopIds)));
        }

        // "Continue" po udanym zadaniu "Set up Analytics" (`task_job` = jego id): wszystko jest
        // juz w GA4 i w sklepie — bez drugiego przejscia przez Google.
        if (count($shopIds) === 1 && (int) ($input['task_job'] ?? 0) > 0) {
            $last = $this->jobs->last($shopIds[0], SetupJob::TYPE);

            if ($last !== null && $last->id === (int) $input['task_job'] && $last->status === 'done' && $this->status($shopIds[0]) === 'done') {
                return [];
            }
        }

        $account = trim((string) ($input['account'] ?? ''));
        $propertyChoice = trim((string) ($input['property'] ?? ''));
        $streamChoice = trim((string) ($input['stream'] ?? 'new'));
        $mp = self::flag($input['mp'] ?? true);
        $keyPurchase = self::flag($input['key_purchase'] ?? true);
        $ack = self::flag($input['ack'] ?? false);

        $errors = self::validate($account, $propertyChoice, (string) ($input['name'] ?? ''), (string) ($input['timezone'] ?? ''), (string) ($input['currency'] ?? ''));

        if ($errors !== []) {
            return $errors;
        }

        $property = null;
        $firstStream = null;

        foreach ($shopIds as $n => $idShop) {
            try {
                if (!$this->granted($idShop)) {
                    return ['_' => 'Google did not grant access to Analytics. Sign in again in the Google account step.'];
                }

                $property ??= $this->setup->ensureProperty(
                    $idShop,
                    $account,
                    $propertyChoice,
                    (string) ($input['name'] ?? ''),
                    (string) ($input['timezone'] ?? ''),
                    strtoupper((string) ($input['currency'] ?? ''))
                );

                $origin = $this->defaults->origin($idShop);
                $choice = $propertyChoice === 'new' ? 'new' : $streamChoice;

                if ($n > 0) {
                    // Kolejny sklep: ten sam strumien tylko w tej samej domenie.
                    $same = $firstStream !== null && PropertySetup::host((string) ($firstStream['webStreamData']['defaultUri'] ?? '')) === PropertySetup::host($origin);
                    $choice = $same ? (string) $firstStream['name'] : 'new';
                }

                $stream = $this->setup->ensureStream($idShop, (string) $property['name'], $choice, $origin);
                $firstStream ??= $stream;

                $this->setup->remember($idShop, $account, $property, $stream);
                Options::set(AnalyticsOptions::MP_ON, $mp ? 1 : 0, $idShop);

                if ($mp) {
                    $this->setup->ensureSecret($idShop, (string) $property['name'], (string) $stream['name'], $ack);
                }

                $this->keyPurchase($idShop, $keyPurchase);

                Options::delete(AnalyticsOptions::SETUP_ERROR, $idShop);
                $this->info->forget($idShop);
            } catch (AckRequiredException $e) {
                Options::set(AnalyticsOptions::SETUP_ERROR, $e->getMessage(), $idShop);

                return ['ack' => $e->getMessage()];
            } catch (\DomainException|\InvalidArgumentException $e) {
                return ['_' => $e->getMessage()];
            } catch (\Throwable $e) {
                $message = self::message($e);
                Options::set(AnalyticsOptions::SETUP_ERROR, $message, $idShop);
                Log::write($idShop, 'analytics', Log::ERROR, 'GA4 setup failed: ' . $e->getMessage());

                return ['_' => $message];
            }
        }

        return [];
    }

    public function status(int $idShop): string
    {
        $configured = Options::str(AnalyticsOptions::PROPERTY, '', $idShop) !== ''
            && Options::str(AnalyticsOptions::STREAM, '', $idShop) !== ''
            && Options::str(AnalyticsOptions::MEASUREMENT_ID, '', $idShop) !== '';
        $secretOk = !Options::bool(AnalyticsOptions::MP_ON, true, $idShop)
            || Options::secret(AnalyticsOptions::MP_SECRET, $idShop) !== '';

        if ($configured && $secretOk) {
            return 'done';
        }

        return Options::str(AnalyticsOptions::SETUP_ERROR, '', $idShop) !== '' ? 'error' : 'todo';
    }

    public function summary(int $idShop): array
    {
        if ($this->status($idShop) !== 'done') {
            return [['label' => 'Analytics', 'value' => 'Not finished', 'state' => 'todo']];
        }

        $value = Options::str(AnalyticsOptions::MEASUREMENT_ID, '', $idShop);

        if (Options::secret(AnalyticsOptions::MP_SECRET, $idShop) !== '') {
            $value .= ' · server-side events on';
        }

        return [['label' => 'Analytics', 'value' => $value, 'state' => 'done']];
    }

    /**
     * Bledy formularza przed wywolaniem Google.
     *
     * @return array<string,string>
     */
    public static function validate(string $account, string $property, string $name, string $timezone, string $currency): array
    {
        $errors = [];

        if (preg_match('#^accounts/\d+$#', $account) !== 1) {
            $errors['account'] = 'Choose an Analytics account.';
        }

        if ($property !== 'new' && preg_match('#^properties/\d+$#', $property) !== 1) {
            $errors['property'] = 'Choose a property or create a new one.';
        }

        if ($property === 'new') {
            if (trim($name) === '') {
                $errors['name'] = 'Enter a name for the new property.';
            }

            if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
                $errors['timezone'] = 'Choose a time zone.';
            }

            if (preg_match('/^[A-Z]{3}$/', strtoupper($currency)) !== 1) {
                $errors['currency'] = 'Choose a currency.';
            }
        }

        return $errors;
    }

    /**
     * Wybor wstepny: zapisany, inaczej usluga i strumien pasujace do sklepu, inaczej nowe.
     *
     * @param array<string,mixed> $form
     * @param array<int,array<string,mixed>> $properties
     * @param array<string,string> $saved
     *
     * @return array<string,mixed>
     */
    public static function preselect(array $form, array $properties, array $saved): array
    {
        if ($saved['property'] !== '') {
            return $form;
        }

        foreach ($properties as $property) {
            if (!empty($property['matches']) && !empty($property['canEdit'])) {
                $form['property'] = $property['name'];

                foreach ($property['streams'] as $stream) {
                    if (!empty($stream['matches'])) {
                        $form['stream'] = $stream['name'];
                    }
                }

                return $form;
            }
        }

        $form['property'] = 'new';
        $form['stream'] = 'new';

        return $form;
    }

    /** Komunikat bledu dla usera (angielskie zrodlo; szczegoly w dzienniku). */
    public static function message(\Throwable $e): string
    {
        if ($e instanceof NotConnectedException) {
            return 'This shop is not connected to Google yet. Connect a Google account in Settings.';
        }

        if ($e instanceof GoogleAuthException) {
            return 'The Google account needs to be connected again. Open Settings and sign in with Google.';
        }

        if ($e instanceof GoogleApiException) {
            if ($e->isApiDisabled()) {
                return 'The Google Analytics Admin API or Data API is not enabled in your Google Cloud project. Enable it in the API library and try again.';
            }

            if ($e->isQuota()) {
                return 'Google is limiting requests right now. Wait a moment and try again.';
            }

            if ($e->isAuth()) {
                return 'Google refused access. Check that the connected account can edit this Analytics property.';
            }

            if ($e->httpCode() === 0 || $e->httpCode() >= 500) {
                return 'Google did not respond. Try again in a moment.';
            }

            return 'Google returned an error: ' . $e->getMessage();
        }

        if ($e instanceof \DomainException || $e instanceof \InvalidArgumentException) {
            return $e->getMessage();
        }

        return 'Something went wrong. Details are in the module log.';
    }

    /**
     * Decyzja #5: przelacznik tylko dodaje `purchase` jako kluczowe. Wylaczony = nic nie
     * robimy w GA4 (nigdy nie usuwamy zdarzenia kluczowego).
     */
    private function keyPurchase(int $idShop, bool $on): void
    {
        if ($on) {
            $this->settings->setKeyEvent($idShop, 'purchase', true);
        }
    }

    private function granted(int $idShop): bool
    {
        $connection = $this->connections->forShop($idShop);

        return $connection !== null && $connection->isConnected()
            && $this->connections->missingFor($connection->id, ['ga4']) === [];
    }

    private static function flag(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'on', 'true'], true);
    }
}
