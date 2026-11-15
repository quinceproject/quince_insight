<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Core;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Context;

/**
 * Rozdziela hooki PrestaShop miedzy uslugi.
 *
 * Plik glowny modulu ma metode dla kazdego hooka i kazda robi tylko jedno: wola te
 * klase. Usluga wskazuje swoje metody w `ServiceModule::hooks()`.
 *
 * Dwie reguly, ktorych nie wolno zlamac:
 *  - sklep bez ukonczonego kreatora nie dostaje **nic** — ani bajtu w HTML, ani zapisu;
 *  - wyjatek uslugi jest lapany i trafia do dziennika; nigdy nie psuje strony sklepu
 *    ani zapisu w panelu.
 */
final class HookRouter
{
    /**
     * Hooki, ktore dzialaja w sklepie wyswietlanej strony. Pozostale (`action*` z panelu)
     * moga dotyczyc danych kilku sklepow naraz, wiec ida do uslugi wlaczonej w
     * ktorymkolwiek sklepie — usluga sama ustala sklep z parametrow hooka.
     */
    private const FRONT_HOOKS = [
        'displayHeader',
        'displayAfterBodyOpeningTag',
        'displayBeforeBodyClosingTag',
        'displayOrderConfirmation',
        'actionFrontControllerSetMedia',
        'actionAuthentication',
        'actionCustomerAccountAdd',
        'moduleRoutes',
    ];

    /** Hooki zamowien: dzialaja w sklepie, do ktorego nalezy zamowienie. */
    public const ORDER_HOOKS = [
        'actionValidateOrder',
        'actionOrderStatusPostUpdate',
        'actionOrderSlipAdd',
    ];

    /**
     * Sklep zamowienia z parametrow hooka; null = to nie jest hook zamowienia.
     * 0 = zamowienia nie da sie ustalic (wtedy hook nie idzie do zadnej uslugi).
     *
     * @param array<string,mixed> $params
     * @param callable(int):int $byOrderId sklep po numerze zamowienia
     */
    public static function orderShopId(string $hook, array $params, callable $byOrderId): ?int
    {
        if (!in_array($hook, self::ORDER_HOOKS, true)) {
            return null;
        }

        $order = $params['order'] ?? null;

        if (is_object($order) && (int) ($order->id_shop ?? 0) > 0) {
            return (int) $order->id_shop;
        }

        $idOrder = (int) ($params['id_order'] ?? (is_object($order) ? ($order->id ?? 0) : 0));

        return $idOrder > 0 ? max(0, (int) $byOrderId($idOrder)) : 0;
    }

    /** Sklep zamowienia z bazy; 0 gdy zamowienia nie ma. */
    public static function shopOfOrder(int $idOrder): int
    {
        return (int) \Db::getInstance()->getValue(
            'SELECT `id_shop` FROM `' . _DB_PREFIX_ . 'orders` WHERE `id_order` = ' . $idOrder
        );
    }

    /**
     * Wola metody wszystkich uslug obslugujacych hook; wyniki tekstowe skleja.
     *
     * @param array<string,mixed> $params
     */
    public static function dispatch(string $hook, array $params = []): string
    {
        $out = '';

        foreach (self::run($hook, $params) as $result) {
            if (is_string($result)) {
                $out .= $result;
            }
        }

        return $out;
    }

    /**
     * Jak dispatch(), ale dla hookow oddajacych tablice (np. `moduleRoutes`): wyniki sa laczone.
     *
     * @param array<string,mixed> $params
     *
     * @return array<mixed>
     */
    public static function collect(string $hook, array $params = []): array
    {
        $out = [];

        foreach (self::run($hook, $params) as $result) {
            if (is_array($result)) {
                $out = array_merge($out, $result);
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $params
     *
     * @return array<int,mixed> wyniki metod uslug, w kolejnosci rejestru
     */
    private static function run(string $hook, array $params): array
    {
        $results = [];

        try {
            $registry = Container::get(ServiceRegistry::class);
            $services = Container::get(Services::class);
            $shops = Container::get(ShopRepository::class);

            $idShop = (int) (Context::getContext()->shop->id ?? 0);
            $front = in_array($hook, self::FRONT_HOOKS, true);

            // Hooki zamowien dotycza sklepu ZAMOWIENIA, nie kontekstu (zmiana statusu w panelu
            // "wszystkie sklepy"). Sklep zamowienia musi byc na zywo — inaczej nic (R-02).
            $orderShop = self::orderShopId($hook, $params, [self::class, 'shopOfOrder']);

            if ($orderShop !== null) {
                if ($orderShop <= 0 || !$shops->isLive($orderShop)) {
                    return [];
                }

                $idShop = $orderShop;
                $front = true;
            }

            if ($front && ($idShop <= 0 || !$shops->isLive($idShop))) {
                // Jedyny wyjatek: tag meta weryfikacji witryny. Krok "Site verification" jest
                // W TRAKCIE kreatora, a Google sprawdza tag na zywej stronie — bez tego weryfikacja
                // nowego sklepu nigdy by nie przeszla (fala 2, agent I). Tag pojawia sie tylko po
                // swiadomym "Add this tag to the shop" w tym kroku (ARCHITEKTURA §11).
                if ($hook === 'displayHeader' && $idShop > 0) {
                    try {
                        $tag = \Quince\Insight\Service\Verification\Module::headerFor($idShop);

                        return $tag === '' ? [] : [$tag];
                    } catch (\Throwable $e) {
                        Log::write($idShop, 'verification', Log::ERROR, 'Hook displayHeader failed: ' . $e->getMessage());
                    }
                }

                return [];
            }

            $activeShops = null;

            foreach ($registry->all() as $module) {
                $method = $module->hooks()[$hook] ?? null;

                if ($method === null) {
                    continue;
                }

                $requires = $module->requires();

                if ($front) {
                    if ($requires !== null && !$services->enabled($idShop, $requires)) {
                        continue;
                    }
                } else {
                    $activeShops ??= self::shopsInUse($shops);

                    if ($activeShops === []) {
                        return [];
                    }

                    if ($requires !== null && !self::enabledAnywhere($services, $activeShops, $requires)) {
                        continue;
                    }
                }

                try {
                    $results[] = $module->{$method}($params);
                } catch (\Throwable $e) {
                    Log::write($idShop, $module->key(), Log::ERROR, 'Hook ' . $hook . ' failed: ' . $e->getMessage(), [
                        'exception' => get_class($e),
                        'file' => basename($e->getFile()) . ':' . $e->getLine(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // Blad samego rozdzielacza (np. brak tabel w trakcie odinstalowania) tez nie moze wyjsc na strone.
            Log::write(0, 'core', Log::ERROR, 'Hook router failed on ' . $hook . ': ' . $e->getMessage());
        }

        return $results;
    }

    /**
     * Sklepy, w ktorych hooki dzialaja (kreator ukonczony albo `QUINCE_INSIGHT_DEV_HOOKS`).
     *
     * @return int[]
     */
    private static function shopsInUse(ShopRepository $shops): array
    {
        if (Dev::hooksWithoutWizard()) {
            return array_keys(ShopContext::shops());
        }

        return $shops->setUpShops();
    }

    /** @param int[] $shopIds */
    private static function enabledAnywhere(Services $services, array $shopIds, string $key): bool
    {
        foreach ($shopIds as $idShop) {
            if ($services->enabled($idShop, $key)) {
                return true;
            }
        }

        return false;
    }
}
