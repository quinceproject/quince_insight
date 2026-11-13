<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Controller\Admin;

use PrestaShopBundle\Security\Attribute\AdminSecurity;
use Quince\Insight\Wizard\Diagnostics;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Support — wizytowka 1:1 z prototypu (`pg==='support'`, "Buy me a coffee"), pod nia
 * informacje systemowe do zgloszenia bledu (wzor: Quince CMP "System information")
 * i changelog z pliku `CHANGELOG.md` modulu.
 */
final class SupportController extends PanelController
{
    /** Ile znakow changelogu pokazac — reszta jest w pliku. */
    private const CHANGELOG_LIMIT = 12000;

    public function __construct(private readonly Diagnostics $diagnostics)
    {
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", message: 'You do not have permission to view this.')]
    public function indexAction(Request $request): Response
    {
        return $this->renderPanel('support/index.html.twig', 'support', [
            'version' => $this->moduleVersion(),
            'changelog' => $this->changelog(),
        ]);
    }

    /** Informacje systemowe i ostatnie bledy — bez sekretow (`Diagnostics`). */
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", message: 'You do not have permission to view this.')]
    public function diagnosticsAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_support_diagnostics');

        $idShop = $this->idShop();

        return $this->ok([
            'text' => $this->diagnostics->text($idShop, $this->moduleVersion()),
            'errors' => $this->diagnostics->recentErrors($idShop),
        ]);
    }

    private function moduleVersion(): string
    {
        $module = \Module::getInstanceByName('quince_insight');

        return $module instanceof \Module ? (string) $module->version : '';
    }

    private function changelog(): string
    {
        $file = _PS_MODULE_DIR_ . 'quince_insight/CHANGELOG.md';

        if (!is_file($file)) {
            return '';
        }

        $text = (string) file_get_contents($file, false, null, 0, self::CHANGELOG_LIMIT);

        return trim($text);
    }
}
