<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\Events;

use Quince\Insight\Core\Cache;

/**
 * Identyfikatory GA i zgody klienta zapamietane przy kasie, per koszyk.
 *
 * Wiele platnosci potwierdza zamowienie powiadomieniem z serwera operatora (bez ciasteczek
 * klienta) — `actionValidateOrder` nie widzi wtedy `_ga` ani zgod. Zapis z ostatniej strony
 * kasy wypelnia te luke. To nadal prawdziwe wartosci z przegladarki klienta, nic wymyslonego.
 * Trzymane w `cache` rdzenia (klucz `ev.cart.<id>`), wygasaja po 3 dniach.
 */
final class CheckoutContext
{
    private const TTL = 3 * 86400;

    public function __construct(private readonly Cache $cache)
    {
    }

    /**
     * @param array<string,string> $consent
     */
    public function remember(int $idShop, int $idCart, string $clientId, string $sessionId, array $consent): void
    {
        if ($idCart <= 0) {
            return;
        }

        $value = ['client_id' => $clientId, 'session_id' => $sessionId, 'consent' => $consent];

        // Zapis tylko przy zmianie — strony kasy odswiezaja sie czesto.
        if ($this->cache->get($idShop, $this->key($idCart)) === $value) {
            return;
        }

        $this->cache->set($idShop, $this->key($idCart), $value, self::TTL);
    }

    /** @return array{client_id:string, session_id:string, consent:array<string,string>}|null */
    public function find(int $idShop, int $idCart): ?array
    {
        $value = $this->cache->get($idShop, $this->key($idCart));

        if (!is_array($value)) {
            return null;
        }

        return [
            'client_id' => (string) ($value['client_id'] ?? ''),
            'session_id' => (string) ($value['session_id'] ?? ''),
            'consent' => ConsentMapper::normalize((array) ($value['consent'] ?? [])),
        ];
    }

    private function key(int $idCart): string
    {
        return 'ev.cart.' . $idCart;
    }
}
