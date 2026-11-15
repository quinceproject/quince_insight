<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\TagManager;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Quince\Insight\Core\Google\GoogleClient;

/**
 * Klient Tag Manager API v2 (research/05 §E.1). Cienka warstwa nad `GoogleClient`
 * z profilem ponawiania `gtm` (403 z powodem limitu = limit).
 *
 * Liczy wywolania (`calls()`), zeby zadanie `gtm.apply` moglo pilnowac tempa
 * 25 zapytan / 100 s. Testy podmieniaja klienta przez `useClient()`.
 */
final class GtmApi
{
    public const BASE = 'https://tagmanager.googleapis.com/tagmanager/v2/';

    /** Segmenty sciezek i klucze list w odpowiedziach wg rodzaju encji. */
    public const COLLECTIONS = ['tag' => 'tags', 'trigger' => 'triggers', 'variable' => 'variables', 'folder' => 'folders'];

    private static ?GoogleClient $override = null;

    private ?GoogleClient $client = null;

    private int $calls = 0;

    /** Tylko testy: jeden klient dla wszystkich sklepow (null przywraca normalny). */
    public static function useClient(?GoogleClient $client): void
    {
        self::$override = $client;
    }

    /** Klient dla sklepu. Rzuca NotConnectedException, gdy sklep nie ma polaczenia z Google. */
    public function forShop(int $idShop): self
    {
        $api = new self();
        $api->client = (self::$override ?? GoogleClient::forShop($idShop, 'tagmanager'))->withRetryProfile(GoogleClient::PROFILE_GTM);

        return $api;
    }

    public function calls(): int
    {
        return $this->calls;
    }

    /** @return array<int,array<string,mixed>> */
    public function accounts(): array
    {
        return $this->paginate('accounts', 'account');
    }

    /** @return array<int,array<string,mixed>> */
    public function containers(string $accountPath): array
    {
        return $this->paginate($accountPath . '/containers', 'container');
    }

    /** @return array<string,mixed> */
    public function container(string $containerPath): array
    {
        return $this->get($containerPath);
    }

    /** @return array<string,mixed> */
    public function createContainer(string $accountPath, string $name, string $domain): array
    {
        $body = ['name' => $name, 'usageContext' => ['web']];

        if ($domain !== '') {
            $body['domainName'] = [$domain];
        }

        return $this->write('post', $accountPath . '/containers', $body);
    }

    /** @return array<int,array<string,mixed>> */
    public function workspaces(string $containerPath): array
    {
        return $this->paginate($containerPath . '/workspaces', 'workspace');
    }

    /** @return array<string,mixed> */
    public function createWorkspace(string $containerPath, string $name, string $description): array
    {
        return $this->write('post', $containerPath . '/workspaces', ['name' => $name, 'description' => $description]);
    }

    /** @return array<string,mixed> `workspaceChange[]`, `mergeConflict[]` */
    public function workspaceStatus(string $workspacePath): array
    {
        return $this->get($workspacePath . '/status');
    }

    /** @return array<string,mixed> */
    public function syncWorkspace(string $workspacePath): array
    {
        return $this->write('post', $workspacePath . ':sync', []);
    }

    /** @return array<int,array<string,mixed>> encje danego rodzaju w workspace */
    public function entities(string $workspacePath, string $kind): array
    {
        $collection = self::COLLECTIONS[$kind];

        return $this->paginate($workspacePath . '/' . $collection, $kind);
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function create(string $workspacePath, string $kind, array $body): array
    {
        return $this->write('post', $workspacePath . '/' . self::COLLECTIONS[$kind], $body);
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function update(string $workspacePath, string $kind, string $id, array $body, string $fingerprint): array
    {
        return $this->write('put', $workspacePath . '/' . self::COLLECTIONS[$kind] . '/' . rawurlencode($id), $body, $fingerprint !== '' ? ['fingerprint' => $fingerprint] : []);
    }

    public function delete(string $workspacePath, string $kind, string $id): void
    {
        ++$this->calls;
        $this->client()->delete(self::BASE . $workspacePath . '/' . self::COLLECTIONS[$kind] . '/' . rawurlencode($id));
    }

    /**
     * `workspaces.bulk_update` (research/05 §E.3): wszystkie zmiany jednym wywolaniem.
     *
     * @param array<int,array<string,mixed>> $changes
     *
     * @return array<string,mixed>
     */
    public function bulkUpdate(string $workspacePath, array $changes): array
    {
        return $this->write('post', $workspacePath . '/bulk_update', ['changes' => $changes]);
    }

    /** @return array<string,mixed> `containerVersion`, `compilerError`, `syncStatus`, `newWorkspacePath` */
    public function createVersion(string $workspacePath, string $name, string $notes): array
    {
        return $this->write('post', $workspacePath . ':create_version', ['name' => $name, 'notes' => $notes]);
    }

    /** @return array<string,mixed> */
    public function publish(string $versionPath): array
    {
        return $this->write('post', $versionPath . ':publish', []);
    }

    /** @return array<string,mixed> */
    public function latestVersionHeader(string $containerPath): array
    {
        return $this->get($containerPath . '/version_headers:latest');
    }

    /** @return array<string,mixed> */
    private function get(string $path): array
    {
        ++$this->calls;

        return $this->client()->get(self::BASE . $path);
    }

    /** @return array<int,array<string,mixed>> */
    private function paginate(string $path, string $key): array
    {
        ++$this->calls;

        return $this->client()->paginate(self::BASE . $path, $key, [], 20);
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,mixed> $query
     *
     * @return array<string,mixed>
     */
    private function write(string $method, string $path, array $body, array $query = []): array
    {
        ++$this->calls;

        return $this->client()->{$method}(self::BASE . $path, $body, $query);
    }

    private function client(): GoogleClient
    {
        if ($this->client === null) {
            throw new \LogicException('GtmApi: call forShop() first.');
        }

        return $this->client;
    }
}
