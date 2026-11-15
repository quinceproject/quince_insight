<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\MerchantTools;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Db;
use Quince\Insight\Core\Container;
use Quince\Insight\Core\Dto\AttentionItem;
use Quince\Insight\Core\Dto\CronTask;
use Quince\Insight\Core\Dto\ServiceHealth;
use Quince\Insight\Core\Installable;
use Quince\Insight\Core\Log;
use Quince\Insight\Core\Scheduler;
use Quince\Insight\Core\Schema as CoreSchema;
use Quince\Insight\Core\ServiceModule;
use Quince\Insight\Service\Merchant\Dto\Feed;
use Quince\Insight\Service\Merchant\FeedRepository;
use Quince\Insight\Service\MerchantTools\Task\PromotionsTask;

/**
 * Usluga `merchanttools` — Merchant Center: mapowanie, reguly, promocje, wysylka, raporty.
 *
 * Karty na pulpicie nie ma (Merchant Center ma karte agenta E); usluga zglasza tylko
 * "Needs attention": niezmapowane kategorie (gdy feed jest aktywny) i promocje z bledem.
 */
final class Module implements ServiceModule, Installable
{
    public function key(): string
    {
        return 'merchanttools';
    }

    public function requires(): ?string
    {
        return 'gmc';
    }

    public function hooks(): array
    {
        return [
            'actionObjectCartRuleUpdateAfter' => 'onCartRuleChange',
            'actionObjectCartRuleDeleteAfter' => 'onCartRuleChange',
        ];
    }

    /**
     * Zmiana lub usuniecie reguly koszyka: tylko oznaczenie — wysylka (albo zakonczenie) przy cronie.
     *
     * @param array<string,mixed> $params
     */
    public function onCartRuleChange(array $params): void
    {
        $object = $params['object'] ?? null;
        $id = is_object($object) && isset($object->id) ? (int) $object->id : 0;

        if ($id > 0) {
            Container::get(PromotionService::class)->markCartRule($id);
        }
    }

    public function cronTasks(): array
    {
        return [new CronTask('merchanttools.promotions', Scheduler::GROUP_STATUSES, PromotionsTask::class, 60, 30)];
    }

    public function jobs(): array
    {
        return [];
    }

    public function tables(): array
    {
        return Schema::tables();
    }

    /** Taksonomia Google ze wszystkich plikow `data/google_taxonomy/`. */
    public function install(): bool
    {
        try {
            (new Taxonomy())->loadAll();
        } catch (\Throwable $e) {
            // Brak taksonomii nie blokuje instalacji — wczyta sie przy pierwszym wyszukiwaniu.
            Log::write(0, 'merchanttools', Log::WARNING, 'Google taxonomy was not loaded at install.', ['error' => mb_substr($e->getMessage(), 0, 200)]);
        }

        return true;
    }

    public function uninstall(): void
    {
    }

    public function health(int $idShop): ?ServiceHealth
    {
        return null;
    }

    public function attention(int $idShop): array
    {
        $items = [];
        $promotions = Container::get(PromotionService::class);
        $errors = $promotions->errorCount($idShop);

        if ($errors > 0) {
            $items[] = new AttentionItem(AttentionItem::ERROR, '%count% promotions were not accepted by Google.', 'quince_insight_merchant', ['tab' => 'promotions'], ['%count%' => $errors]);
        }

        $active = array_filter(Container::get(FeedRepository::class)->forShop($idShop), static fn (Feed $f): bool => $f->status === Feed::STATUS_ACTIVE);

        if ($active !== []) {
            $unmapped = $this->unmappedCategoriesWithProducts($idShop);

            if ($unmapped > 0) {
                $feed = reset($active);
                $items[] = new AttentionItem(AttentionItem::WARNING, '%count% shop categories with products have no Google category.', 'quince_insight_merchant', ['tab' => 'mapping', 'feed' => $feed->id], ['%count%' => $unmapped]);
            }
        }

        return $items;
    }

    public function disable(int $idShop): void
    {
    }

    public function purge(int $idShop): void
    {
    }

    public function leftInGoogle(int $idShop): array
    {
        $out = [];

        if ((int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . CoreSchema::table(Schema::PROMOTION) . '` WHERE `id_shop` = ' . $idShop . ' AND `status` = "sent"') > 0) {
            $out[] = 'Promotions sent to Merchant Center (they end on their end date).';
        }

        return $out;
    }

    /** Kategorie z produktami bez wlasnego i odziedziczonego mapowania. */
    private function unmappedCategoriesWithProducts(int $idShop): int
    {
        $mapping = Container::get(Mapping::class);
        $ids = array_map('intval', array_column(Db::getInstance()->executeS(
            'SELECT DISTINCT ps.`id_category_default` AS id FROM `' . _DB_PREFIX_ . 'product_shop` ps
             WHERE ps.`id_shop` = ' . $idShop . ' AND ps.`active` = 1'
        ) ?: [], 'id'));

        return count(array_filter($ids, static fn (int $id): bool => $mapping->googleCategory($idShop, $id) === null));
    }
}
