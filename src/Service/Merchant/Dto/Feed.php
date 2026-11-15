<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\Merchant\Dto;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Feed Merchant Center — wiersz tabeli `feed` (ARCHITEKTURA §7.6).
 *
 * `descriptionSource`: description_short | description | both (wartosci z prototypu,
 * tak jak w FeedForge). `idSource`: id | reference | ean.
 */
final class Feed
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';

    /**
     * @param array<string,mixed> $filters wszystkie grupy filtrow z zakladki "What is sent"
     */
    public function __construct(
        public int $id,
        public int $idShop,
        public string $name,
        public string $country,
        public int $idLang,
        public string $contentLanguage,
        public string $feedLabel,
        public int $idCurrency,
        public bool $withTax,
        public string $titleTemplate,
        public string $descriptionSource,
        public string $offerPrefix,
        public string $idSource,
        public string $dataSource,
        public string $status,
        public bool $onlyReady,
        public array $filters = [],
        public ?string $lastFullSync = null,
        public ?string $lastStatusSync = null,
        public string $currencyIso = '',
    ) {
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isPaused(): bool
    {
        return $this->status === self::STATUS_PAUSED;
    }
}
