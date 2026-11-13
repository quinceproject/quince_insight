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

use Quince\Insight\Core\Google\ConnectionRepository;

/**
 * Ktore uslugi Google sa wlaczone w sklepie (wybor z kroku Services kreatora).
 */
final class Services
{
    public const KEYS = ['ga4', 'ads', 'gtm', 'gmc', 'gcr', 'gsc', 'psi'];

    /** Usluga => usluga, bez ktorej nie dziala. Google Ads idzie przez Tag Manager, opinie przez Merchant Center. */
    public const DEPENDS_ON = ['ads' => 'gtm', 'gcr' => 'gmc'];

    public function __construct(
        private readonly ShopRepository $shops,
        private readonly ConnectionRepository $connections,
    ) {
    }

    public function enabled(int $idShop, string $key): bool
    {
        if (!in_array($key, self::KEYS, true)) {
            return false;
        }

        return Options::bool(CoreOptions::SVC_PREFIX . strtoupper($key), false, $idShop);
    }

    /** @return string[] */
    public function enabledKeys(int $idShop): array
    {
        $out = [];

        foreach (self::KEYS as $key) {
            if ($this->enabled($idShop, $key)) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /**
     * Wlacza albo wylacza usluge, pilnujac zaleznosci: wlaczenie `ads` wlacza `gtm`,
     * wlaczenie `gcr` wlacza `gmc`; wylaczenie `gtm` wylacza `ads`, wylaczenie `gmc` wylacza `gcr`.
     */
    public function setEnabled(int $idShop, string $key, bool $on): void
    {
        if (!in_array($key, self::KEYS, true)) {
            throw new \InvalidArgumentException('Quince Insight: unknown service "' . $key . '".');
        }

        Options::set(CoreOptions::SVC_PREFIX . strtoupper($key), $on, $idShop);

        if ($on && isset(self::DEPENDS_ON[$key])) {
            Options::set(CoreOptions::SVC_PREFIX . strtoupper(self::DEPENDS_ON[$key]), true, $idShop);
        }

        if (!$on) {
            foreach (self::DEPENDS_ON as $dependent => $base) {
                if ($base === $key) {
                    Options::set(CoreOptions::SVC_PREFIX . strtoupper($dependent), false, $idShop);
                }
            }
        }
    }

    /**
     * Usluga wlaczona, sklep po kreatorze i polaczenie ma wazne uprawnienia.
     *
     * `psi` nie korzysta z logowania, tylko z klucza API polaczenia. `ads` i `gcr`
     * nie maja wlasnych uprawnien — licza sie uprawnienia uslugi, z ktorej korzystaja.
     */
    public function ready(int $idShop, string $key): bool
    {
        if (!$this->enabled($idShop, $key) || !$this->shops->isSetUp($idShop)) {
            return false;
        }

        $connection = $this->connections->forShop($idShop);

        if ($connection === null) {
            return false;
        }

        if ($key === 'psi') {
            return $connection->hasApiKey;
        }

        if (!$connection->isConnected()) {
            return false;
        }

        $scopeKey = self::DEPENDS_ON[$key] ?? $key;

        return $this->connections->missingFor($connection->id, [$scopeKey]) === [];
    }
}
