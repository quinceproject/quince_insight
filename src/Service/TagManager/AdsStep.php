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
use Quince\Insight\Core\WizardStep;

/**
 * Krok kreatora `ads` — 1:1 z prototypu (`cur==='ads'`): ID i etykieta konwersji,
 * remarketing, rozszerzone konwersje. Tagi trafiaja do kontenera w kroku `gtm`
 * (ARCHITEKTURA §12 pkt 17: z Ads nic nie czytamy).
 *
 * Pola formularza: conversion_id, conversion_label, remarketing (0/1), enhanced (0/1).
 */
final class AdsStep implements WizardStep
{
    public function __construct(private readonly GtmSettings $gtm)
    {
    }

    public function key(): string
    {
        return 'ads';
    }

    public function data(array $shopIds): array
    {
        $idShop = (int) ($shopIds[0] ?? ShopContext::currentOrDefault());

        return [
            'idShop' => $idShop,
            'form' => [
                'convId' => Options::str(GtmOptions::ADS_CONVERSION_ID, '', $idShop),
                'label' => Options::str(GtmOptions::ADS_CONVERSION_LABEL, '', $idShop),
                // Domyslnie wlaczone, jak w prototypie (`ads: {remarketing: true, enhanced: true}`).
                'remarketing' => Options::bool(GtmOptions::ADS_REMARKETING, true, $idShop),
                'enhanced' => Options::bool(GtmOptions::ADS_ENHANCED, true, $idShop),
            ],
        ];
    }

    public function save(array $shopIds, array $input): array
    {
        $id = strtoupper(trim((string) ($input['conversion_id'] ?? '')));
        $label = trim((string) ($input['conversion_label'] ?? ''));

        if ($id !== '' && preg_match('/^\d{5,15}$/', $id)) {
            $id = 'AW-' . $id;
        }

        if ($id !== '' && !preg_match('/^AW-\d{5,15}$/', $id)) {
            return ['conversion_id' => 'The conversion ID looks like AW-123456789.'];
        }

        if ($label !== '' && !preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $label)) {
            return ['conversion_label' => 'The conversion label has only letters, digits, - and _.'];
        }

        foreach ($shopIds as $idShop) {
            $idShop = (int) $idShop;
            Options::set(GtmOptions::ADS_CONVERSION_ID, $id, $idShop);
            Options::set(GtmOptions::ADS_CONVERSION_LABEL, $label, $idShop);
            Options::set(GtmOptions::ADS_REMARKETING, self::bool($input['remarketing'] ?? '0'), $idShop);
            Options::set(GtmOptions::ADS_ENHANCED, self::bool($input['enhanced'] ?? '0'), $idShop);
            Options::set(GtmOptions::ADS_SAVED, true, $idShop);
        }

        return [];
    }

    public function status(int $idShop): string
    {
        return Options::bool(GtmOptions::ADS_SAVED, false, $idShop) && Options::str(GtmOptions::ADS_CONVERSION_ID, '', $idShop) !== '' ? 'done' : 'todo';
    }

    public function summary(int $idShop): array
    {
        if ($this->status($idShop) !== 'done') {
            return [['label' => 'Google Ads', 'value' => 'Not finished', 'state' => 'todo']];
        }

        $id = Options::str(GtmOptions::ADS_CONVERSION_ID, '', $idShop);
        $own = $this->gtm->mode($idShop) === GtmOptions::MODE_OWN;

        return [['label' => 'Google Ads', 'value' => $id . ($own ? ' · add the tag in your container' : ' · tags published with the container'), 'state' => 'done']];
    }

    private static function bool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true' || $value === 'on';
    }
}
