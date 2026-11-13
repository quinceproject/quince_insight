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

use Quince\Insight\Core\Dto\JobStatus;
use Quince\Insight\Core\Google\GoogleApiException;
use Quince\Insight\Core\Google\NotConnectedException;
use Quince\Insight\Core\JobRunner;
use Quince\Insight\Core\Options;

/**
 * „Apply and publish” (kreator i Events -> Overview): w razie potrzeby tworzy wybrany nowy
 * kontener, potem startuje zadanie `gtm.apply`. Drugie klikniecie w trakcie oddaje trwajace zadanie.
 */
final class ContainerSetup
{
    public function __construct(
        private readonly JobRunner $jobs,
        private readonly ContainerService $containers,
        private readonly GtmSettings $gtm,
    ) {
    }

    public function start(int $idShop): JobStatus
    {
        $active = $this->jobs->active($idShop, ApplyJob::TYPE);

        if ($active !== null) {
            return $active;
        }

        $mode = $this->gtm->mode($idShop);

        if ($mode === GtmOptions::MODE_SHARED) {
            throw new \RuntimeException('This container is managed by another shop. Update it from that shop.');
        }

        if (Options::bool(GtmOptions::GTM_NEW_CONTAINER, false, $idShop)) {
            $account = Options::str(GtmOptions::GTM_ACCOUNT, '', $idShop);
            $created = $this->containers->create($idShop, $account);

            if ($created['path'] === '') {
                throw new \RuntimeException('Tag Manager did not create the container. Try again.');
            }

            $mode = $this->containers->select($idShop, $mode, $created['path'], $created['publicId'], $created['name'], $account);
            Options::delete(GtmOptions::GTM_NEW_CONTAINER, $idShop);
        }

        if ($this->gtm->container($idShop) === '') {
            throw new \RuntimeException('Choose a Tag Manager container first.');
        }

        $id = $this->jobs->start($idShop, ApplyJob::TYPE, ['mode' => $mode, 'container' => $this->gtm->container($idShop)]);

        return $this->jobs->status($id);
    }

    /** Ostatnie zadanie (trwajace albo zakonczone) do pokazania paska postepu. */
    public function lastJob(int $idShop): ?JobStatus
    {
        return $this->jobs->active($idShop, ApplyJob::TYPE) ?? $this->jobs->last($idShop, ApplyJob::TYPE);
    }

    /** Komunikat dla usera z bledu Google (bez sekretow). */
    public static function errorText(\Throwable $e): string
    {
        if ($e instanceof NotConnectedException) {
            return 'This shop is not connected to Google yet. Connect a Google account in Settings.';
        }

        if ($e instanceof GoogleApiException) {
            if ($e->isApiDisabled()) {
                return 'The Tag Manager API is not enabled in your Google Cloud project. Enable it in the API library and try again.';
            }

            if ($e->isQuota()) {
                return 'Google is limiting requests right now. Wait a moment and try again.';
            }

            if ($e->isAuth()) {
                return 'Google refused access. Check that the connected account has permission to this Tag Manager account.';
            }

            return 'Tag Manager returned an error: ' . $e->getMessage();
        }

        return $e instanceof \RuntimeException ? $e->getMessage() : 'Something went wrong. Details are in the module log.';
    }
}
