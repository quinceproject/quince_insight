<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\PrestaShop\Adapter\SymfonyContainer;

/**
 * Ukryta zakladka ekranu — nosnik uprawnien dla tras z `_legacy_controller`.
 *
 * Sama niczego nie renderuje: ekran jest trasa Symfony (`quince_insight_settings`).
 * Klasa istnieje, bo PrestaShop wymaga klasy dla kazdej zakladki, a role pracownikow
 * wisza na zakladce.
 */
class AdminQuinceInsightSettingsController extends ModuleAdminController
{
    public function initContent(): void
    {
        $router = SymfonyContainer::getInstance()?->get('router');

        // Poza kernelem (np. wywolanie z CLI) kontenera nie ma — wtedy lepiej nie
        // przekierowywac nigdzie niz wywrocic sie na `null`.
        if ($router === null) {
            parent::initContent();

            return;
        }

        Tools::redirectAdmin($router->generate('quince_insight_settings'));
    }
}
