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

use Quince\Insight\Core\Google\ConnectionRepository;
use Quince\Insight\Core\Options;
use Quince\Insight\Core\Services;
use Quince\Insight\Core\ShopContext;
use Quince\Insight\Core\WizardStep;
use Quince\Insight\Service\Events\EventsSettings;

/**
 * Krok kreatora `gtm` — 1:1 z prototypu (`cur==='gtm'`).
 *
 * Szablon: `views/templates/admin/wizard/steps/gtm.html.twig`. Zapis ustawien zdarzen,
 * zgod i wysylki z serwera idzie przez `EventsSettings` (wlasciciel: agent C) — ten krok
 * ich nie zapisuje bezposrednio. „Review changes” i „Apply and publish” to trasy JSON
 * `gtm_save` / `gtm_plan` / `gtm_apply` (zadanie `gtm.apply` z paskiem postepu).
 *
 * Pola formularza: mode (auto|own), container (sciezka albo `new`), account (dla `new`),
 * ga4_mid, ga4_secret (tylko gdy Analytics jest wylaczone), consent (qcmp|other|none),
 * cookie, ev_<zdarzenie> (0/1), server_purchase, server_refund.
 */
final class GtmStep implements WizardStep
{
    public function __construct(
        private readonly GtmSettings $gtm,
        private readonly ContainerService $containers,
        private readonly ContainerSetup $setup,
        private readonly EventsSettings $events,
        private readonly Services $services,
        private readonly ConnectionRepository $connections,
        private readonly PlanSettings $plans,
    ) {
    }

    public function key(): string
    {
        return 'gtm';
    }

    public function data(array $shopIds): array
    {
        $idShop = (int) ($shopIds[0] ?? ShopContext::currentOrDefault());
        $accounts = [];
        $error = '';
        $granted = $this->granted($idShop);

        if ($granted) {
            try {
                $accounts = $this->containers->accounts($idShop);
            } catch (\Throwable $e) {
                $error = ContainerSetup::errorText($e);
            }
        }

        $container = $this->gtm->container($idShop);
        $new = Options::bool(GtmOptions::GTM_NEW_CONTAINER, false, $idShop);
        $first = $accounts[0]['containers'][0]['path'] ?? '';
        $job = $this->setup->lastJob($idShop);
        $events = $this->events->events($idShop);
        $consent = $this->events->consentSource($idShop);

        return [
            'idShop' => $idShop,
            'granted' => $granted,
            'error' => $error,
            'accounts' => $accounts,
            'newName' => $this->containers->newName($idShop),
            'ga4' => $this->services->enabled($idShop, 'ga4'),
            'ads' => $this->services->enabled($idShop, 'ads'),
            'adsId' => Options::str(GtmOptions::ADS_CONVERSION_ID, '', $idShop),
            'qcmp' => self::quinceCmp(),
            'form' => [
                'mode' => $this->gtm->mode($idShop) === GtmOptions::MODE_OWN ? GtmOptions::MODE_OWN : GtmOptions::MODE_AUTO,
                'container' => $new ? 'new' : ($container !== '' ? $container : $first),
                'account' => Options::str(GtmOptions::GTM_ACCOUNT, (string) ($accounts[0]['path'] ?? ''), $idShop),
                'mid' => Options::str(GtmOptions::GA4_MANUAL_ID, '', $idShop),
                'apiSecret' => '',
                'consent' => $consent,
                'cookie' => $this->events->consentCookie($idShop),
                'events' => $events,
                'serverPurchase' => $this->events->serverPurchase($idShop),
                'serverRefund' => $this->events->serverRefund($idShop),
            ],
            'hasSecret' => Options::secret(GtmOptions::GA4_MANUAL_SECRET, $idShop) !== '',
            'mode' => $this->gtm->mode($idShop),
            'owner' => $this->gtm->mode($idShop) === GtmOptions::MODE_SHARED ? ShopContext::name($this->gtm->ownerShop($idShop)) : '',
            'publicId' => $this->gtm->publicId($idShop),
            'upToDate' => $container !== '' && !$new && $this->gtm->upToDate($idShop),
            'job' => $job?->toArray(),
            'eventList' => ContainerPlan::EVENTS,
        ];
    }

    public function save(array $shopIds, array $input): array
    {
        $mode = (string) ($input['mode'] ?? GtmOptions::MODE_AUTO);
        $choice = trim((string) ($input['container'] ?? ''));
        $account = trim((string) ($input['account'] ?? ''));

        if (!in_array($mode, [GtmOptions::MODE_AUTO, GtmOptions::MODE_OWN], true)) {
            return ['mode' => 'Choose one of the options.'];
        }

        if ($choice === '' || ($choice === 'new' && $mode === GtmOptions::MODE_OWN)) {
            return ['container' => 'Choose a container.'];
        }

        $mid = strtoupper(trim((string) ($input['ga4_mid'] ?? '')));

        if ($mid !== '' && !preg_match('/^G-[A-Z0-9]{4,16}$/', $mid)) {
            return ['ga4_mid' => 'The Measurement ID looks like G-XXXXXXXXXX.'];
        }

        $errors = [];

        foreach ($shopIds as $idShop) {
            $idShop = (int) $idShop;

            // Reczny identyfikator GA4 przed wyborem kontenera — od niego zalezy, czy kontener
            // innego sklepu moze byc wspolny (decyzja #7).
            if (!$this->services->enabled($idShop, 'ga4')) {
                Options::set(GtmOptions::GA4_MANUAL_ID, $mid, $idShop);
                $secret = trim((string) ($input['ga4_secret'] ?? ''));

                if ($secret !== '') {
                    Options::setSecret(GtmOptions::GA4_MANUAL_SECRET, $secret, $idShop);
                }
            }

            if ($choice === 'new') {
                if (!preg_match('#^accounts/\d+$#', $account)) {
                    return ['account' => 'Choose a Tag Manager account.'];
                }

                Options::set(GtmOptions::GTM_MODE, GtmOptions::MODE_AUTO, $idShop);
                Options::set(GtmOptions::GTM_ACCOUNT, $account, $idShop);
                Options::set(GtmOptions::GTM_NEW_CONTAINER, true, $idShop);
            } else {
                $found = $this->find($idShop, $choice);

                if ($found === null) {
                    return ['container' => 'This container is not available for the connected Google account.'];
                }

                try {
                    $this->containers->select($idShop, $mode, $found['path'], $found['publicId'], $found['name'], $found['account']);
                } catch (\RuntimeException $e) {
                    return ['container' => $e->getMessage()];
                }

                Options::delete(GtmOptions::GTM_NEW_CONTAINER, $idShop);
            }

            // Ustawienia zdarzen naleza do uslugi Events — zapis przez jej klase (walidacja tam).
            foreach (ContainerPlan::EVENTS as $event) {
                if (array_key_exists('ev_' . $event, $input)) {
                    $this->events->setEvent($idShop, $event, self::bool($input['ev_' . $event]));
                }
            }

            foreach (['consent' => 'consentSource', 'cookie' => 'consentCookie', 'server_purchase' => 'serverPurchase', 'server_refund' => 'serverRefund'] as $field => $key) {
                if (!array_key_exists($field, $input)) {
                    continue;
                }

                if ($field === 'cookie' && (string) ($input['consent'] ?? '') !== 'other') {
                    continue;
                }

                $message = $this->events->save($idShop, $key, $input[$field]);

                if ($message !== '') {
                    $errors[$field] = $message;
                }
            }
        }

        return $errors;
    }

    public function status(int $idShop): string
    {
        if (!$this->gtm->isConfigured($idShop) || Options::bool(GtmOptions::GTM_NEW_CONTAINER, false, $idShop)) {
            return 'todo';
        }

        return match ($this->gtm->mode($idShop)) {
            GtmOptions::MODE_OWN, GtmOptions::MODE_SHARED => 'done',
            default => Options::str(GtmOptions::GTM_PLAN_HASH, '', $idShop) !== '' ? 'done' : 'todo',
        };
    }

    public function summary(int $idShop): array
    {
        if ($this->status($idShop) !== 'done') {
            return [['label' => 'Tag Manager', 'value' => 'Not finished', 'state' => 'todo']];
        }

        $id = $this->gtm->publicId($idShop);
        $value = match ($this->gtm->mode($idShop)) {
            GtmOptions::MODE_OWN => $id . ' · your own container',
            GtmOptions::MODE_SHARED => $id . ' · managed from ' . ShopContext::name($this->gtm->ownerShop($idShop)),
            default => $id . ' · published, ' . count($this->plans->input($idShop)->events) . ' events',
        };

        return [['label' => 'Tag Manager', 'value' => $value, 'state' => 'done']];
    }

    /** @return array{path:string,publicId:string,name:string,account:string}|null */
    private function find(int $idShop, string $path): ?array
    {
        foreach ([false, true] as $refresh) {
            foreach ($this->containers->accounts($idShop, $refresh) as $account) {
                foreach ($account['containers'] as $container) {
                    if ($container['path'] === $path) {
                        return ['path' => $path, 'publicId' => (string) $container['publicId'], 'name' => (string) $container['name'], 'account' => (string) $account['path']];
                    }
                }
            }
        }

        return null;
    }

    private function granted(int $idShop): bool
    {
        $connection = $this->connections->forShop($idShop);

        return $connection !== null && $connection->isConnected() && $this->connections->missingFor($connection->id, ['gtm']) === [];
    }

    private static function quinceCmp(): bool
    {
        try {
            return class_exists('Module') && \Module::isEnabled('quince_cmp');
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function bool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true' || $value === 'on';
    }
}
