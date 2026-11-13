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

use Quince\Insight\Core\Dto\AttentionItem;
use Quince\Insight\Core\Dto\CronTask;
use Quince\Insight\Core\Dto\ServiceHealth;
use Quince\Insight\Core\Dto\TableDefinition;

/**
 * Wspolny interfejs uslugi (`Service\<Nazwa>\Module`).
 *
 * Tedy usluga dopina sie do rdzenia: hooki, zadania cron, zadania w tle, tabele,
 * stan na pulpicie. Plik glowny modulu nie wie nic o uslugach — zna tylko rejestr.
 */
interface ServiceModule
{
    /** verification | analytics | events | tagmanager | merchant | merchanttools | searchconsole | pagespeed | structureddata */
    public function key(): string;

    /** Klucz uslugi z kreatora, od ktorej zalezy wlaczenie (ga4, gtm, gmc, gsc, psi, ads, gcr) albo null = zawsze. */
    public function requires(): ?string;

    /** @return array<string,string> nazwa hooka => nazwa metody w tej klasie */
    public function hooks(): array;

    /** @return CronTask[] */
    public function cronTasks(): array;

    /** @return array<string,string> typ zadania => klasa JobHandler */
    public function jobs(): array;

    /** @return TableDefinition[] */
    public function tables(): array;

    /** Stan do karty na pulpicie; null = usluga nie ma karty. */
    public function health(int $idShop): ?ServiceHealth;

    /** @return AttentionItem[] pozycje "Needs attention" */
    public function attention(int $idShop): array;

    /** Sprzatanie przy odlaczeniu uslugi w sklepie (nie usuwa nic w Google). */
    public function disable(int $idShop): void;

    /** Sklep usuniety: usun to, czego rdzen nie usunie sam po id_shop (pliki itp.). Bez wywolan Google. */
    public function purge(int $idShop): void;

    /** Co zostaje w Google po usunieciu sklepu — do informacji na pulpicie. @return string[] */
    public function leftInGoogle(int $idShop): array;
}
