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

/**
 * Instalacja i odinstalowanie danych modulu (tabele, wiersze sklepow, pliki).
 *
 * Zakladki i hooki zostaja w pliku glownym — to sprawy PrestaShop. Tutaj jest tylko
 * to, co nalezy do modulu. Instalacja nie zapisuje zadnych ustawien i niczego nie
 * wlacza: po niej sklep wyglada dla odwiedzajacego dokladnie tak samo jak przed.
 */
final class Installer
{
    public function __construct(
        private readonly Schema $schema,
        private readonly ServiceRegistry $registry,
        private readonly ShopRepository $shops,
    ) {
    }

    public function install(): bool
    {
        if (!$this->schema->install()) {
            return false;
        }

        foreach (array_keys(ShopContext::shops()) as $idShop) {
            $this->shops->ensure((int) $idShop);
        }

        foreach ($this->registry->all() as $service) {
            if ($service instanceof Installable && !$service->install()) {
                return false;
            }
        }

        return true;
    }

    public function uninstall(): bool
    {
        foreach ($this->registry->all() as $service) {
            if ($service instanceof Installable) {
                try {
                    $service->uninstall();
                } catch (\Throwable $e) {
                    // Sprzatanie uslugi nie moze zablokowac odinstalowania modulu.
                }
            }
        }

        // Katalog `var/` nalezy wylacznie do modulu (pliki map witryny) — znika w calosci.
        self::removeTree(dirname(__DIR__, 2) . '/var');

        return $this->schema->drop();
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir((string) $item) : @unlink((string) $item);
        }

        @rmdir($path);
    }
}
