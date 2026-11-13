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

use Quince\Insight\Service\Merchant\ProductData;

/**
 * Oferta zbudowana dla feedu: dane po mapowaniu i regulach, gotowa tresc dla Google,
 * hashe i problemy wykryte lokalnie.
 */
final class BuiltOffer
{
    /**
     * @param array<string,mixed> $input tresc `ProductInput` (PayloadBuilder::productInput)
     * @param array<int,array{level:string,code:string,message:string}> $issues
     */
    public function __construct(
        public ProductData $product,
        public bool $excluded,
        public string $reason,
        public array $input,
        public string $hash,
        public string $coreHash,
        public array $issues,
    ) {
    }

    public function offerId(): string
    {
        return (string) ($this->input['offerId'] ?? '');
    }

    public function title(): string
    {
        return (string) ($this->product->fields['title'] ?? '');
    }
}
