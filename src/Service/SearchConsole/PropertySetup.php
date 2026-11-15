<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\SearchConsole;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Quince\Insight\Core\Google\ConnectionRepository;
use Quince\Insight\Core\JobRunner;
use Quince\Insight\Core\Log;
use Quince\Insight\Core\Options;
use Quince\Insight\Core\ShopContext;
use Quince\Insight\Service\SearchConsole\Job\SitemapRebuildJob;

/**
 * Wybor uslugi Search Console i zrodla mapy — wspolne dla kroku kreatora `gsc`
 * i tras JSON `gsc_sites` / `gsc_site_add` / `gsc_save`.
 */
final class PropertySetup
{
    public function __construct(
        private readonly SearchConsoleApi $api,
        private readonly SearchConsoleService $service,
        private readonly ConnectionRepository $connections,
        private readonly JobRunner $jobs,
    ) {
    }

    /** Czy polaczenie sklepu ma scope Search Console (user mogl go odznaczyc przy zgodzie). */
    public function needsGrant(int $idShop): bool
    {
        $connection = $this->connections->forShop($idShop);

        return $connection === null || !$connection->isConnected() || $this->connections->missingFor($connection->id, ['gsc']) !== [];
    }

    /**
     * Uslugi konta dopasowane do sklepu (rzuca wyjatki Google — kontroler zamienia je na komunikat).
     *
     * @return array{prefix:array<string,mixed>,domain:array<string,mixed>,others:array<int,array<string,mixed>>}
     */
    public function properties(int $idShop): array
    {
        return SiteMatcher::match(ShopContext::baseUrl($idShop), $this->api->sites($this->api->client($idShop)));
    }

    /**
     * Zapis wyboru: `property` = `prefix` | `sc-domain` | adres innej uslugi z konta;
     * `sitemap` = `module` | `existing`. Zgloszenie mapy nie jest juz wyborem (decyzja #21 — zawsze).
     *
     * @param array<string,mixed> $input
     *
     * @return array<string,string> bledy per pole ([] = zapisane)
     */
    public function save(int $idShop, array $input): array
    {
        $choice = trim((string) ($input['property'] ?? 'prefix'));
        $mode = ($input['sitemap'] ?? 'module') === 'existing' ? 'existing' : 'module';

        $client = $this->api->client($idShop);
        $matched = SiteMatcher::match(ShopContext::baseUrl($idShop), $this->api->sites($client));

        if ($choice === 'prefix') {
            $site = (string) $matched['prefix']['site'];

            if ($site === '') {
                return ['property' => 'The shop address is not valid for Search Console.'];
            }

            // Usluga URL-prefix sklepu, ktorej nie ma na koncie — dodajemy (idempotentnie: najpierw lista).
            if (!$matched['prefix']['listed']) {
                $this->api->addSite($client, $site);
                Log::write($idShop, SearchConsoleApi::SERVICE, Log::INFO, 'Search Console property added.', ['site' => $site]);
            }
        } elseif ($choice === 'sc-domain') {
            if (!$matched['domain']['ok']) {
                return ['property' => 'The Domain property needs a DNS record and is not verified on this account.'];
            }

            $site = (string) $matched['domain']['site'];
        } else {
            $sites = array_column($matched['others'], 'site');

            if (!in_array($choice, $sites, true)) {
                return ['property' => 'Choose a property from the list.'];
            }

            $site = $choice;
        }

        $permission = (string) $this->api->site($client, $site)['permissionLevel'];

        Options::set(SearchConsoleOptions::GSC_SITE, $site, $idShop);
        Options::set(SearchConsoleOptions::GSC_PERMISSION, $permission, $idShop);
        Options::set(SearchConsoleOptions::SM_MODE, $mode, $idShop);

        if ($mode === 'existing') {
            $existing = $this->service->existingSitemap($idShop);

            if ($existing === null) {
                return ['sitemap' => 'No existing sitemap was found in the shop.'];
            }

            Options::set(SearchConsoleOptions::SM_EXISTING_URL, $existing['url'], $idShop);
        }

        return [];
    }

    /**
     * Zadanie z kroku kreatora („Generate and submit” / „Save and submit”): przebudowa
     * (albo istniejaca mapa), zgloszenie, pierwszy import w tle.
     */
    public function startSetupJob(int $idShop): int
    {
        // Brak prawa zapisu nie jest tu cicho pomijany — Google odpowie 403 i zadanie pokaze powod.
        return $this->jobs->start($idShop, SitemapRebuildJob::TYPE, ['submit' => true, 'import' => true]);
    }
}
