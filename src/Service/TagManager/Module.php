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

if (!defined('_PS_VERSION_')) {
    exit;
}

use Quince\Insight\Core\Dto\AttentionItem;
use Quince\Insight\Core\Dto\ServiceHealth;
use Quince\Insight\Core\ServiceModule;
use Quince\Insight\Core\ShopContext;

/**
 * Usluga `tagmanager` — Tag Manager: kontener, plan kontenera, Google Ads w kontenerze.
 *
 * Bez hookow i cronow: snippet i dataLayer wstawia usluga Events (agent C) na podstawie
 * `GtmSettings`. Zadanie w tle `gtm.apply` dokancza cron (grupy `orders`/`products`/`all`),
 * gdy karta panelu zostanie zamknieta.
 */
final class Module implements ServiceModule
{
    public function __construct(
        private readonly GtmSettings $gtm,
        private readonly ContainerSetup $setup,
    ) {
    }

    public function key(): string
    {
        return 'tagmanager';
    }

    public function requires(): ?string
    {
        return 'gtm';
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
        return [ApplyJob::TYPE => ApplyJob::class];
    }

    public function tables(): array
    {
        return Schema::tables();
    }

    public function health(int $idShop): ?ServiceHealth
    {
        if (!$this->gtm->isConfigured($idShop)) {
            return new ServiceHealth(ServiceHealth::OFF, 'No container chosen yet');
        }

        $id = $this->gtm->publicId($idShop);
        $problem = $this->attention($idShop)[0] ?? null;

        if ($problem !== null) {
            return new ServiceHealth($problem->level === AttentionItem::ERROR ? ServiceHealth::ERROR : ServiceHealth::ATTENTION, $problem->message, $id, $problem->params);
        }

        return match ($this->gtm->mode($idShop)) {
            GtmOptions::MODE_OWN => new ServiceHealth(ServiceHealth::OK, 'You manage the container yourself', $id),
            GtmOptions::MODE_SHARED => new ServiceHealth(ServiceHealth::OK, 'Container managed from %shop%', $id, ['%shop%' => ShopContext::name($this->gtm->ownerShop($idShop))]),
            default => new ServiceHealth(ServiceHealth::OK, 'Container published and up to date', $id),
        };
    }

    public function attention(int $idShop): array
    {
        if (!$this->gtm->isConfigured($idShop) || $this->gtm->mode($idShop) === GtmOptions::MODE_OWN) {
            return [];
        }

        $items = [];
        $job = $this->setup->lastJob($idShop);

        if ($job !== null && $job->status === 'failed' && $job->error !== '') {
            $items[] = new AttentionItem(AttentionItem::ERROR, 'Updating the Tag Manager container failed: %error%', 'quince_insight_events', ['tab' => 'overview'], ['%error%' => $job->error]);
        }

        if (($job === null || !$job->isActive()) && !$this->gtm->upToDate($idShop)) {
            $items[] = $this->gtm->mode($idShop) === GtmOptions::MODE_SHARED
                ? new AttentionItem(AttentionItem::WARNING, 'The shared Tag Manager container does not match the event settings yet. Update it from %shop%.', 'quince_insight_events', ['tab' => 'overview'], ['%shop%' => ShopContext::name($this->gtm->ownerShop($idShop))])
                : new AttentionItem(AttentionItem::WARNING, 'The Tag Manager container does not match the event settings yet. Update the container.', 'quince_insight_events', ['tab' => 'overview']);
        }

        return $items;
    }

    public function disable(int $idShop): void
    {
        // Nic w Google: kontener i tagi zostaja (ARCHITEKTURA §4 — disable nie usuwa nic w Google).
    }

    public function purge(int $idShop): void
    {
        // Wiersze `gtm_entity` usuwa rdzen po id_shop; plikow usluga nie ma.
    }

    public function leftInGoogle(int $idShop): array
    {
        if (!$this->gtm->isConfigured($idShop)) {
            return [];
        }

        $id = $this->gtm->publicId($idShop);

        return $this->gtm->mode($idShop) === GtmOptions::MODE_AUTO
            ? ['Tag Manager container ' . $id . ' with the tags, triggers and variables in the folder “Quince Insight”']
            : ['Tag Manager container ' . $id];
    }
}
