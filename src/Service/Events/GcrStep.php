<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\Events;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Context;
use Quince\Insight\Core\Options;
use Quince\Insight\Core\WizardStep;

/**
 * Krok kreatora `gcr` — Google Customer Reviews (prototyp `cur==='gcr'`).
 *
 * Programu nie da sie wlaczyc przez API (research/04 §15) — krok daje link do Merchant Center
 * i wymaga potwierdzenia usera; dni dostawy per przewoznik z wartoscia domyslna.
 * Szablon: `views/templates/admin/wizard/steps/gcr.html.twig`.
 */
final class GcrStep implements WizardStep
{
    public function __construct(private readonly CustomerReviews $reviews)
    {
    }

    public function key(): string
    {
        return 'gcr';
    }

    public function data(array $shopIds): array
    {
        $idShop = (int) ($shopIds[0] ?? 0);
        $idLang = (int) (Context::getContext()->language->id ?? 1);

        return [
            'survey' => $this->reviews->survey($idShop),
            'confirmed' => $this->reviews->confirmed($idShop),
            'days' => $this->reviews->defaultDays($idShop),
            'carriers' => $this->reviews->carriers($idShop, $idLang),
            'merchantId' => $this->reviews->merchantId($idShop),
        ];
    }

    public function save(array $shopIds, array $input): array
    {
        $fields = [
            'survey' => $input['survey'] ?? '0',
            'confirmed' => $input['confirmed'] ?? '0',
            'days' => $input['days'] ?? CustomerReviews::DEFAULT_DAYS,
            'carrier' => is_array($input['carrier'] ?? null) ? $input['carrier'] : [],
        ];

        foreach ($shopIds as $idShop) {
            $errors = $this->reviews->save((int) $idShop, $fields);

            if ($errors !== []) {
                return $errors;
            }
        }

        return [];
    }

    public function status(int $idShop): string
    {
        return Options::has(EventsOptions::GCR_SURVEY, $idShop) ? 'done' : 'todo';
    }

    public function summary(int $idShop): array
    {
        if (!$this->reviews->survey($idShop)) {
            return [['label' => 'Customer Reviews', 'value' => 'Survey off', 'state' => 'done']];
        }

        if (!$this->reviews->confirmed($idShop)) {
            return [['label' => 'Customer Reviews', 'value' => 'Waiting — enable the programme in Merchant Center', 'state' => 'todo']];
        }

        return [['label' => 'Customer Reviews', 'value' => 'Survey on · delivery days set per carrier', 'state' => 'done']];
    }
}
