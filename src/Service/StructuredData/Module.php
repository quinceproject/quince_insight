<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\StructuredData;

use Context;
use Quince\Insight\Core\Container;
use Quince\Insight\Core\Dto\AttentionItem;
use Quince\Insight\Core\Dto\ServiceHealth;
use Quince\Insight\Core\Log;
use Quince\Insight\Core\Options;
use Quince\Insight\Core\ServiceModule;
use Quince\Insight\Core\ShopContext;
use Tools;

/**
 * Usluga `structureddata` — opcjonalny JSON-LD (SPEC §5.9). Domyslnie nic nie wypisuje;
 * kazdy typ wlacza user na ekranie Structured data, po skanie stron sklepu.
 *
 * Wypisywanie w `displayHeader` bez kontenera Symfony (blad Rankateera, SPEC §5.9 [F]):
 * klasy budowane przez `Core\Container`, cala obsluga w try/catch.
 */
final class Module implements ServiceModule
{
    public function key(): string
    {
        return 'structureddata';
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
        return [ScanJob::TYPE => ScanJob::class];
    }

    public function tables(): array
    {
        return [];
    }

    /**
     * JSON-LD wlaczonych typow na biezacej stronie sklepu.
     *
     * @param array<string,mixed> $params
     */
    public function onHeader(array $params): string
    {
        try {
            $idShop = ShopContext::currentOrDefault();
            $on = Types::normalize(Options::json(StructuredDataOptions::ON, [], $idShop));

            // Nic nie wlaczone = ani bajtu na stronie (stan domyslny).
            if (!in_array(true, $on, true)) {
                return '';
            }

            $context = Context::getContext();
            [$page, $id] = self::page($context->controller ?? null);

            if ($page === Output::BLOG) {
                return '';
            }

            $output = Container::get(Output::class);
            $result = $output->build($idShop, (int) $context->language->id, $page, $id, $on, max(1, (int) Tools::getValue('page', 1)));

            return Output::html($result['nodes']);
        } catch (\Throwable $e) {
            Log::write(ShopContext::currentOrDefault(), 'structureddata', Log::ERROR, 'JSON-LD output failed: ' . $e->getMessage(), [
                'file' => basename($e->getFile()) . ':' . $e->getLine(),
            ]);

            return '';
        }
    }

    /**
     * Typ strony z kontrolera frontu: [typ, id obiektu].
     *
     * @return array{0:string,1:int}
     */
    public static function page(mixed $controller): array
    {
        if ($controller instanceof \ModuleFrontController) {
            $module = $controller->module ?? null;

            return [($module instanceof \Module && $module->name === 'quince_blog') ? Output::BLOG : Output::OTHER, 0];
        }

        $name = is_object($controller) ? (string) ($controller->php_self ?? '') : '';

        if ($name === 'index') {
            return [Output::HOME, 0];
        }

        if ($name === 'category') {
            $category = method_exists($controller, 'getCategory') ? $controller->getCategory() : null;

            return [Output::CATEGORY, (int) ($category->id ?? Tools::getValue('id_category'))];
        }

        if ($name === 'product') {
            $product = method_exists($controller, 'getProduct') ? $controller->getProduct() : null;

            return [Output::PRODUCT, (int) ($product->id ?? Tools::getValue('id_product'))];
        }

        return [Output::OTHER, 0];
    }

    public function health(int $idShop): ?ServiceHealth
    {
        $on = array_keys(array_filter(Types::normalize(Options::json(StructuredDataOptions::ON, [], $idShop))));

        if ($on === []) {
            return new ServiceHealth(ServiceHealth::OFF, 'The module adds no structured data.');
        }

        return new ServiceHealth(ServiceHealth::OK, '%count% types added to the shop pages', implode(', ', array_map([Types::class, 'name'], $on)), ['%count%' => count($on)]);
    }

    public function attention(int $idShop): array
    {
        $settings = Container::get(Settings::class);
        $scan = $settings->scan($idShop);

        if ($scan === null) {
            return [];
        }

        $force = $settings->force($idShop);
        $out = [];

        // Typ wlaczony w module, ktory po ostatnim skanie wypisuje juz motyw albo inny modul
        // (np. po aktualizacji motywu), a user nie potwierdzil "Add anyway" — duplikat.
        foreach (Settings::rejected($settings->on($idShop), $force, (array) ($scan['present'] ?? [])) as $key) {
            if ($key === Types::VARIANTS) {
                $out[] = new AttentionItem(
                    AttentionItem::WARNING,
                    'Structured data: the theme now outputs Product on product pages, next to Product variants from the module. Turn one of them off.',
                    'quince_insight_schema'
                );

                continue;
            }

            $out[] = new AttentionItem(
                AttentionItem::WARNING,
                'Structured data: %type% is now output by the theme too. Google sees it twice.',
                'quince_insight_schema',
                [],
                ['%type%' => Types::name($key)]
            );
        }

        return $out;
    }

    public function disable(int $idShop): void
    {
    }

    public function purge(int $idShop): void
    {
        // Ustawienia i wynik skanu (tabela `cache`) czysci rdzen po `id_shop`.
    }

    public function leftInGoogle(int $idShop): array
    {
        return [];
    }
}
