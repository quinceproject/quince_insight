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

use Quince\Insight\Core\Options;
use Quince\Insight\Core\ShopContext;

/**
 * Dane karty kontenera na ekranie Events -> Overview (kontrakt ARCHITEKTURA §9).
 *
 * Agent C dolacza szablon karty:
 *   {% include '@Modules/quince_insight/views/templates/admin/events/_container.html.twig'
 *       with {card: containerCard, checks: checks} only %}
 * gdzie `containerCard` = `ContainerCard::data($idShop)`, a `checks` (opcjonalnie) to wiersze
 * kontroli dzialania agenta C: lista `{on: bool, text: string, note: string}` pokazywana pod
 * naglowkiem karty, gdy nie trwa podglad zmian ani publikacja (prototyp `pg==='tracking'`).
 *
 * Bez wywolan Google: dane z ustawien sklepu. Lista zmian laduje sie po „Update container”
 * (trasa `gtm_plan`), publikacja przez `gtm_apply` + pasek postepu zadania `gtm.apply`.
 */
final class ContainerCard
{
    public function __construct(
        private readonly GtmSettings $gtm,
        private readonly ContainerSetup $setup,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function data(int $idShop): array
    {
        $mode = $this->gtm->mode($idShop);
        $owner = $this->gtm->ownerShop($idShop);
        $configured = $this->gtm->isConfigured($idShop);
        $job = $this->setup->lastJob($idShop);
        $version = Options::str(GtmOptions::GTM_VERSION, '', $owner);
        $published = Options::str(GtmOptions::GTM_PUBLISHED_AT, '', $owner);

        return [
            'idShop' => $idShop,
            'configured' => $configured,
            'mode' => $mode,
            'publicId' => $this->gtm->publicId($idShop),
            'containerName' => $this->gtm->containerName($idShop),
            'shopName' => ShopContext::name($idShop),
            'shopDomain' => (string) (ShopContext::shops()[$idShop]['domain'] ?? ''),
            'ownerName' => $mode === GtmOptions::MODE_SHARED ? ShopContext::name($owner) : '',
            'version' => $version,
            'publishedAt' => $published !== '' ? substr($published, 0, 16) : '',
            'upToDate' => $configured && $this->gtm->upToDate($idShop),
            'canUpdate' => $configured && $mode === GtmOptions::MODE_AUTO,
            'job' => $job !== null && $job->isActive() ? $job->toArray() : null,
            'lastError' => $job !== null && $job->status === 'failed' ? $job->error : '',
        ];
    }
}
