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

use Db;
use Quince\Insight\Service\TagManager\GtmOptions;

/**
 * Dodanie i usuniecie sklepu w PrestaShop (hooki `actionObjectShopAddAfter` / `DeleteAfter`).
 *
 * Nowy sklep dostaje wiersz z nieukonczonym kreatorem i nic z modulu w nim nie dziala.
 * Po usunietym sklepie znikaja dane lokalne; **w Google modul niczego nie usuwa** —
 * zostawia na pulpicie informacje, co tam zostalo.
 */
final class ShopLifecycle
{
    public function __construct(
        private readonly ServiceRegistry $registry,
        private readonly Schema $schema,
        private readonly ShopRepository $shops,
        private readonly NoticeRepository $notices,
    ) {
    }

    public function onShopAdded(int $idShop): void
    {
        ShopContext::flush();
        $this->shops->ensure($idShop);
    }

    /**
     * @param string $shopName nazwa usunietego sklepu (z obiektu przekazanego w hooku — w bazie juz jej nie ma)
     */
    public function onShopDeleted(int $idShop, string $shopName = ''): void
    {
        // 1. Najpierw informacja na pulpit: uslugi czytaja swoje ustawienia, ktore za chwile znikna.
        $left = [];

        foreach ($this->registry->all() as $key => $service) {
            try {
                $left[$key] = $service->leftInGoogle($idShop);
            } catch (\Throwable $e) {
                Log::write($idShop, $key, Log::ERROR, 'leftInGoogle failed: ' . $e->getMessage());
            }
        }

        $items = ShopCleanup::noticeItems($left);

        if ($items !== []) {
            $this->notices->add(
                NoticeRepository::KIND_SHOP_DELETED,
                $shopName !== '' ? $shopName : ('#' . $idShop),
                $items
            );
        }

        // 2. Wspolny kontener Tag Manager: zarzadzanie przechodzi na kolejny sklep.
        $this->handOverGtm($idShop);

        // 3. To, czego nie widac w tabelach (pliki map witryny itp.).
        foreach ($this->registry->all() as $key => $service) {
            try {
                $service->purge($idShop);
            } catch (\Throwable $e) {
                Log::write($idShop, $key, Log::ERROR, 'purge failed: ' . $e->getMessage());
            }
        }

        // 4. Wiersze sklepu ze wszystkich tabel per sklep (rdzen i uslugi).
        $tables = [];

        foreach ($this->schema->all() as $definition) {
            $tables[] = ['name' => $definition->name, 'perShop' => $definition->perShop];
        }

        foreach (ShopCleanup::tablesToPurge($tables) as $table) {
            try {
                Db::getInstance()->delete(Schema::name($table), '`id_shop` = ' . $idShop);
            } catch (\Throwable $e) {
                Log::write($idShop, 'core', Log::ERROR, 'Shop cleanup failed on table ' . $table . ': ' . $e->getMessage());
            }
        }

        Options::flush();
        $this->shops->delete($idShop);
        ShopContext::flush();

        Log::write(0, 'core', Log::INFO, 'Shop removed, local data deleted.', ['id_shop' => $idShop]);
    }

    private function handOverGtm(int $deletedShop): void
    {
        $rows = Db::getInstance()->executeS(
            'SELECT DISTINCT `id_shop` FROM `' . Schema::table(Schema::SETTING) . '`'
        ) ?: [];

        $settings = [];

        foreach ($rows as $row) {
            $idShop = (int) $row['id_shop'];

            $settings[$idShop] = [
                'mode' => Options::str(GtmOptions::MODE, '', $idShop),
                'container' => Options::str(GtmOptions::CONTAINER, '', $idShop),
                'owner' => Options::int(GtmOptions::OWNER_SHOP, 0, $idShop),
            ];
        }

        $handover = ShopCleanup::gtmHandover($deletedShop, $settings);

        if ($handover === null) {
            return;
        }

        Options::set(GtmOptions::MODE, GtmOptions::MODE_AUTO, $handover['newOwner']);
        Options::delete(GtmOptions::OWNER_SHOP, $handover['newOwner']);

        foreach ($handover['followers'] as $idShop) {
            Options::set(GtmOptions::OWNER_SHOP, $handover['newOwner'], $idShop);
        }

        Log::write($handover['newOwner'], 'tagmanager', Log::INFO, 'Shared container is now managed by this shop.', [
            'previous_owner' => $deletedShop,
        ]);
    }
}
