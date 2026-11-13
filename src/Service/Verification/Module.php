<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\Verification;

use Context;
use Quince\Insight\Core\Dto\AttentionItem;
use Quince\Insight\Core\Dto\ServiceHealth;
use Quince\Insight\Core\Options;
use Quince\Insight\Core\ServiceModule;
use Quince\Insight\Core\Services;

/**
 * Usluga `verification` — weryfikacja witryny w Google (tag meta w naglowku strony).
 *
 * Jedyne, co usluga wstawia na strone sklepu, to `<meta name="google-site-verification">`
 * — i tylko po "Add tag and verify" w kreatorze (zgoda usera, ARCHITEKTURA §11).
 * Hook nie wola Google; czyta wylacznie ustawienia sklepu.
 */
final class Module implements ServiceModule
{
    public function __construct(private readonly Services $services)
    {
    }

    public function key(): string
    {
        return 'verification';
    }

    public function requires(): ?string
    {
        return null;
    }

    public function hooks(): array
    {
        return ['displayHeader' => 'onHeader'];
    }

    public function cronTasks(): array
    {
        return [];
    }

    public function jobs(): array
    {
        return [];
    }

    public function tables(): array
    {
        return [];
    }

    /** @param array<string,mixed> $params */
    public function onHeader(array $params): string
    {
        $idShop = (int) (Context::getContext()->shop->id ?? 0);

        return self::headerFor($idShop);
    }

    /** Tag dla sklepu; '' gdy user go nie wlaczyl albo weryfikacja zostala usunieta. */
    public static function headerFor(int $idShop): string
    {
        if ($idShop <= 0) {
            return '';
        }

        $meta = Options::str(VerificationOptions::META, '', $idShop);
        $state = Options::str(VerificationOptions::STATE, VerificationState::NONE, $idShop);

        return VerificationState::tagOn($meta, $state) ? VerificationState::metaTag($meta) : '';
    }

    public function health(int $idShop): ?ServiceHealth
    {
        // Weryfikacja nie ma wlasnej karty na pulpicie — jej stan widac przy uslugach,
        // ktore jej potrzebuja (Merchant Center, Search Console) i w "Needs attention".
        return null;
    }

    public function attention(int $idShop): array
    {
        if (!$this->services->enabled($idShop, 'gsc') && !$this->services->enabled($idShop, 'gmc')) {
            return [];
        }

        $state = Options::str(VerificationOptions::STATE, VerificationState::NONE, $idShop);

        if (VerificationState::isVerified($state)) {
            return [];
        }

        return [new AttentionItem(
            AttentionItem::WARNING,
            'The shop address is not verified with Google. Merchant Center and Search Console need it.',
            'quince_insight_wizard',
            ['step' => 'verify']
        )];
    }

    public function disable(int $idShop): void
    {
        // Weryfikacja nie jest usluga do wylaczenia — zostaje, dopoki user jej nie usunie.
    }

    public function purge(int $idShop): void
    {
        // Rdzen usuwa ustawienia sklepu; plikow ani tabel usluga nie ma.
    }

    public function leftInGoogle(int $idShop): array
    {
        $state = Options::str(VerificationOptions::STATE, VerificationState::NONE, $idShop);
        $site = Options::str(VerificationOptions::SITE, '', $idShop);

        if ($state !== VerificationState::VERIFIED || $site === '') {
            return [];
        }

        return ['Site verification of ' . $site . ' (the Google account stays a verified owner)'];
    }
}
