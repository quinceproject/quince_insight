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

use Quince\Insight\Core\ShopContext;
use Quince\Insight\Core\WizardStep;

/**
 * Krok kreatora `verify` — weryfikacja adresu sklepu (prototyp `cur==='verify'`).
 *
 * Szablon: `views/templates/admin/wizard/steps/verify.html.twig`. Akcje Google
 * (sprawdzenie, tag i weryfikacja, usuniecie) ida trasami `verify_check|start|remove`.
 * Krok jest per sklep: adres sklepu w domenie juz zweryfikowanego sklepu jest gotowy
 * od razu (prototyp `msVerifiedBy`).
 */
final class VerifyStep implements WizardStep
{
    public function __construct(private readonly Verifier $verifier)
    {
    }

    public function key(): string
    {
        return 'verify';
    }

    public function data(array $shopIds): array
    {
        $idShop = (int) ($shopIds[0] ?? ShopContext::currentOrDefault());
        $state = $this->verifier->state($idShop);
        $by = VerificationState::isVerified($state['state']) && $state['meta'] ? null : $this->verifier->coveredBy($idShop);
        $url = $this->verifier->siteUrl($idShop);

        return [
            'url' => $url,
            'name' => ShopContext::name($idShop),
            'domain' => (string) (ShopContext::shops()[$idShop]['domain'] ?? ''),
            'state' => $state['state'],
            'method' => $state['method'],
            'meta' => $state['meta'],
            'site' => $state['site'],
            'verifiedBy' => $by === null ? null : ['id' => $by, 'name' => ShopContext::name($by)],
            // Stan startowy ekranu: zweryfikowany lokalnie nie potrzebuje pytania do Google.
            'initial' => $by !== null || VerificationState::isVerified($state['state']) ? 'verified' : 'idle',
        ];
    }

    public function save(array $shopIds, array $input): array
    {
        foreach ($shopIds as $idShop) {
            $idShop = (int) $idShop;
            $by = $this->verifier->coveredBy($idShop);

            if ($by !== null && !VerificationState::isVerified($this->verifier->state($idShop)['state'])) {
                $this->verifier->markShared($idShop, $by);
            }

            if (!$this->verifier->isVerified($idShop)) {
                return ['verify' => 'Verify the site first.'];
            }
        }

        return [];
    }

    public function status(int $idShop): string
    {
        return $this->verifier->isVerified($idShop) ? 'done' : 'todo';
    }

    public function summary(int $idShop): array
    {
        $state = $this->verifier->state($idShop);

        if (!$this->verifier->isVerified($idShop)) {
            return [['label' => 'Site verification', 'value' => 'Not finished', 'state' => 'todo']];
        }

        $site = $state['site'] !== '' ? $state['site'] : $this->verifier->siteUrl($idShop);

        return [['label' => 'Site verification', 'value' => $site . ($state['meta'] ? ' · verification tag' : ''), 'state' => 'done']];
    }
}
