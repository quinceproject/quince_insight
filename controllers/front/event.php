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

use Quince\Insight\Core\Container;
use Quince\Insight\Service\Events\Beacon;

/**
 * Zgloszenia `navigator.sendBeacon` ze skryptu `views/js/front/events.js` (dziennik zdarzen
 * z przegladarki, wykrycie drugiego kontenera GTM). Tylko POST, odpowiedz bez tresci.
 * Logika i ograniczenia: `Service\Events\Beacon`.
 */
class Quince_InsightEventModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $auth = false;

    /** @var bool */
    public $ajax = true;

    public function postProcess(): void
    {
        $status = 405;

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            try {
                $status = Container::get(Beacon::class)->handle((int) $this->context->shop->id, (string) file_get_contents('php://input', false, null, 0, 20000));
            } catch (\Throwable $e) {
                $status = 500;
            }
        }

        http_response_code($status);
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
        exit;
    }
}
