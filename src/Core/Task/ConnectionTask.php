<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Core\Task;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Quince\Insight\Core\Google\ConnectionRepository;
use Quince\Insight\Core\Google\GoogleAuthException;
use Quince\Insight\Core\Google\NotConnectedException;
use Quince\Insight\Core\Google\OAuthFlow;

/**
 * Zadanie `core.connection` (grupa `maintenance`): sprawdza, czy logowanie do Google zyje.
 *
 * Token odswiezania nieuzywany przez 6 miesiecy Google uniewaznia sam, a user moze cofnac
 * dostep w ustawieniach konta. Lepiej dowiedziec sie o tym raz na dobe z pulpitu niz
 * z feedu, ktory przestal sie aktualizowac.
 */
final class ConnectionTask
{
    public function __construct(
        private readonly ConnectionRepository $connections,
        private readonly OAuthFlow $flow,
    ) {
    }

    public function run(int $idShop): string
    {
        $connection = $this->connections->forShop($idShop);

        if ($connection === null) {
            return 'No Google connection for this shop.';
        }

        if (!$connection->isConnected()) {
            return 'Connection status: ' . $connection->status . '.';
        }

        try {
            // Samo pobranie tokenu wystarcza: odswiezy go, gdy trzeba, a odmowa Google
            // przestawi polaczenie w stan `needs_reauth`.
            $this->flow->accessToken($connection->id);
        } catch (GoogleAuthException $e) {
            return 'Google sign-in needs attention: ' . $e->getMessage();
        } catch (NotConnectedException $e) {
            return 'Not connected.';
        }

        return 'Connection is working.';
    }
}
