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
use Quince\Insight\Core\Scheduler;
use Quince\Insight\Core\ShopContext;

/**
 * Wyzwalacz harmonogramu dla crona serwera.
 *
 * Adres: `{sklep}index.php?fc=module&module=quince_insight&controller=cron&group=<grupa>&token=<token>`
 * — osobny zestaw dla kazdego sklepu; przebieg dotyczy sklepu, w ktorego domenie go wywolano.
 * Grupy: orders, products, statuses, sitemap, maintenance oraz zbiorcza `all`.
 *
 * Odpowiedzi: 200 z raportem przebiegu, 403 przy zlym tokenie, 400 przy nieznanej grupie,
 * 404 gdy sklep nie istnieje.
 */
class Quince_InsightCronModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $auth = false;

    /** @var bool */
    public $ajax = true;

    public function postProcess(): void
    {
        $idShop = (int) $this->context->shop->id;
        $group = (string) Tools::getValue('group', '');

        if (!Scheduler::checkToken($idShop, (string) Tools::getValue('token', ''))) {
            $this->wyslij(403, ['error' => 'Bad token.']);
        }

        if (!ShopContext::exists($idShop)) {
            $this->wyslij(404, ['error' => 'Unknown shop.']);
        }

        if ($group !== Scheduler::GROUP_ALL && !isset(Scheduler::GROUPS[$group])) {
            $this->wyslij(400, ['error' => 'Unknown group. Use orders, products, statuses, sitemap, maintenance or all.']);
        }

        // Przebieg ma sie dokonczyc takze wtedy, gdy wywolujacy (curl z krotkim limitem
        // czasu) rozlaczy sie wczesniej.
        ignore_user_abort(true);

        $this->wyslij(200, Container::get(Scheduler::class)->run($idShop, $group, 'cron'));
    }

    /**
     * Tryb konserwacji nie moze zatrzymac crona: sklep wylaczony dla klientow nadal
     * ma wysylac zmiany produktow i zamowienia. Rdzen w tym miejscu odpowiada 503
     * kazdemu spoza listy dozwolonych adresow IP — dlatego metoda jest pusta.
     */
    protected function displayMaintenancePage()
    {
    }

    /** @param array<string,mixed> $cialo */
    private function wyslij(int $status, array $cialo): void
    {
        header('Content-Type: application/json; charset=utf-8', true, $status);
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
        echo json_encode($cialo, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
