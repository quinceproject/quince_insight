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

use Quince\Insight\Core\Cache;
use Quince\Insight\Core\Options;
use Quince\Insight\Core\Services;
use Quince\Insight\Core\ShopContext;
use Quince\Insight\Service\Analytics\Ga4Settings;

/**
 * Konta i kontenery Tag Manager: lista (z pamiecia podreczna), utworzenie kontenera web,
 * wybor kontenera dla sklepu z rozpoznaniem kontenera wspolnego (ARCHITEKTURA §6).
 */
final class ContainerService
{
    /** Komunikat, gdy kontener innego sklepu nie moze byc wspolny (decyzja #7). */
    public const DIFFERENT_GA4 = 'This container is set up from another shop that uses a different GA4 property. Create a separate container for this shop.';

    private const CACHE_KEY = 'gtm.accounts';
    private const CACHE_TTL = 600;

    public function __construct(
        private readonly GtmApi $api,
        private readonly Cache $cache,
        private readonly Ga4Settings $ga4,
        private readonly Services $services,
    ) {
    }

    /**
     * Konta z kontenerami web. Kontener ma tez `usedBy` — sklepy, ktore go juz wybraly,
     * z `sameGa4` (decyzja #7: wspolny kontener tylko przy tej samej usludze GA4).
     *
     * @return array<int,array{path:string,name:string,containers:array<int,array<string,mixed>>}>
     */
    public function accounts(int $idShop, bool $refresh = false): array
    {
        if ($refresh) {
            $this->cache->forget($idShop, self::CACHE_KEY);
        }

        $accounts = $this->cache->remember($idShop, self::CACHE_KEY, self::CACHE_TTL, function () use ($idShop): array {
            $api = $this->api->forShop($idShop);
            $out = [];

            foreach ($api->accounts() as $account) {
                $path = (string) ($account['path'] ?? '');

                if ($path === '') {
                    continue;
                }

                $containers = [];

                foreach ($api->containers($path) as $container) {
                    // Tylko kontenery stron WWW — snippet GTM dziala tylko z nimi.
                    if (!in_array('web', (array) ($container['usageContext'] ?? []), true)) {
                        continue;
                    }

                    $containers[] = [
                        'path' => (string) ($container['path'] ?? ''),
                        'publicId' => (string) ($container['publicId'] ?? ''),
                        'name' => (string) ($container['name'] ?? ''),
                    ];
                }

                $out[] = ['path' => $path, 'name' => (string) ($account['name'] ?? ''), 'containers' => $containers];
            }

            return $out;
        });

        foreach ($accounts as &$account) {
            foreach ($account['containers'] as &$container) {
                $container['usedBy'] = $this->usedBy((string) $container['path'], $idShop);
            }
        }

        return $accounts;
    }

    /**
     * Kontener web „<domena sklepu>” w koncie. Idempotentnie: istniejacy o tej nazwie jest
     * uzywany zamiast tworzenia drugiego.
     *
     * @return array{path:string,publicId:string,name:string}
     */
    public function create(int $idShop, string $accountPath): array
    {
        if (!preg_match('#^accounts/\d+$#', $accountPath)) {
            throw new \RuntimeException('Choose a Tag Manager account.');
        }

        $name = $this->newName($idShop);
        $api = $this->api->forShop($idShop);

        foreach ($api->containers($accountPath) as $container) {
            if (($container['name'] ?? '') === $name && in_array('web', (array) ($container['usageContext'] ?? []), true)) {
                return ['path' => (string) $container['path'], 'publicId' => (string) ($container['publicId'] ?? ''), 'name' => $name];
            }
        }

        $created = $api->createContainer($accountPath, $name, (string) preg_replace('/:\d+$/', '', (string) (ShopContext::shops()[$idShop]['domain'] ?? '')));
        $this->cache->forget($idShop, self::CACHE_KEY);

        return ['path' => (string) ($created['path'] ?? ''), 'publicId' => (string) ($created['publicId'] ?? ''), 'name' => (string) ($created['name'] ?? $name)];
    }

    /**
     * Zapis wyboru. Tryb `auto` na kontenerze, ktorym juz zarzadza inny sklep, staje sie
     * `shared` (snippet bez zmian w kontenerze). Zwraca zapisany tryb.
     */
    public function select(int $idShop, string $mode, string $path, string $publicId, string $name, string $accountPath): string
    {
        $owner = $mode === GtmOptions::MODE_OWN ? 0 : $this->managingShop($path, $idShop);

        // Decyzja #7: zdarzenia wspolnego kontenera ida do GA4 sklepu zarzadzajacego — przy innej
        // usludze GA4 dane obu sklepow by sie mieszaly. Wtedy tylko osobny kontener.
        if ($owner > 0 && !$this->sameGa4($idShop, $owner)) {
            throw new \RuntimeException(self::DIFFERENT_GA4);
        }
        $final = $mode === GtmOptions::MODE_OWN ? GtmOptions::MODE_OWN : ($owner > 0 ? GtmOptions::MODE_SHARED : GtmOptions::MODE_AUTO);
        $previous = Options::str(GtmOptions::GTM_CONTAINER, '', $idShop);

        Options::set(GtmOptions::GTM_MODE, $final, $idShop);
        Options::set(GtmOptions::GTM_OWNER_SHOP, $owner, $idShop);
        Options::set(GtmOptions::GTM_ACCOUNT, $accountPath !== '' ? $accountPath : (string) preg_replace('#/containers/.*$#', '', $path), $idShop);
        Options::set(GtmOptions::GTM_CONTAINER, $path, $idShop);
        Options::set(GtmOptions::GTM_PUBLIC_ID, $publicId, $idShop);
        Options::set(GtmOptions::GTM_CONTAINER_NAME, $name, $idShop);

        if ($previous !== $path) {
            // Inny kontener = inna historia publikacji.
            foreach ([GtmOptions::GTM_PLAN_HASH, GtmOptions::GTM_VERSION, GtmOptions::GTM_VERSION_NAME, GtmOptions::GTM_PUBLISHED_AT] as $key) {
                Options::delete($key, $idShop);
            }
        }

        return $final;
    }

    /** Sklep (inny niz $idShop) zarzadzajacy tym kontenerem w trybie `auto`; 0 = brak. */
    public function managingShop(string $path, int $idShop): int
    {
        if ($path === '') {
            return 0;
        }

        foreach (Options::shopsWhere(GtmOptions::GTM_CONTAINER, $path) as $other) {
            if ($other !== $idShop && ShopContext::exists($other)
                && Options::str(GtmOptions::GTM_MODE, GtmOptions::MODE_AUTO, $other) === GtmOptions::MODE_AUTO) {
                return $other;
            }
        }

        return 0;
    }

    /**
     * Nazwa nowego kontenera dla sklepu. Zwykle domena; gdy kontenerem o tej nazwie zarzadza juz
     * sklep z inna usluga GA4 (np. sklepy multistore w jednej domenie), dochodzi nazwa sklepu —
     * inaczej idempotentne „create” trafiloby na cudzy kontener (decyzja #7).
     */
    public function newName(int $idShop): string
    {
        $name = self::defaultName($idShop);

        foreach (Options::shopsWhere(GtmOptions::GTM_CONTAINER_NAME, $name) as $other) {
            if ($other !== $idShop && ShopContext::exists($other)
                && Options::str(GtmOptions::GTM_MODE, GtmOptions::MODE_AUTO, $other) === GtmOptions::MODE_AUTO
                && !$this->sameGa4($idShop, $other)) {
                return $name . ' - ' . ShopContext::name($idShop);
            }
        }

        return $name;
    }

    /** Czy dwa sklepy uzywaja tej samej uslugi GA4 (decyzja #7). */
    public function sameGa4(int $a, int $b): bool
    {
        return self::sameGa4Identity($this->ga4Identity($a), $this->ga4Identity($b));
    }

    /**
     * Ta sama usluga GA4: ta sama wlasciwosc (`properties/…`) albo — gdy ktoras jest nieznana
     * (GA4 z recznym identyfikatorem w kroku `gtm`) — ten sam identyfikator pomiaru. Dwa sklepy
     * zupelnie bez GA4 nie mieszaja zadnych danych GA4, wiec tez „ta sama”.
     *
     * @param array{property:string,mid:string} $a
     * @param array{property:string,mid:string} $b
     */
    public static function sameGa4Identity(array $a, array $b): bool
    {
        if ($a['property'] !== '' && $b['property'] !== '') {
            return $a['property'] === $b['property'];
        }

        return strtoupper($a['mid']) === strtoupper($b['mid']);
    }

    /** @return array{property:string,mid:string} */
    public function ga4Identity(int $idShop): array
    {
        return [
            'property' => $this->services->enabled($idShop, 'ga4') ? $this->ga4->property($idShop) : '',
            'mid' => trim($this->ga4->measurementId($idShop)),
        ];
    }

    /** Nazwa nowego kontenera: domena sklepu (prototyp: „Create a container “yourshop.com””). */
    public static function defaultName(int $idShop): string
    {
        // Bez portu: `domainName` kontenera to nazwa hosta.
        $domain = (string) preg_replace('/:\d+$/', '', (string) (ShopContext::shops()[$idShop]['domain'] ?? ''));

        return $domain !== '' ? $domain : ShopContext::name($idShop);
    }

    /** @return array<int,array{id:int,name:string,mode:string}> */
    private function usedBy(string $path, int $idShop): array
    {
        $out = [];

        foreach (Options::shopsWhere(GtmOptions::GTM_CONTAINER, $path) as $other) {
            if ($other !== $idShop && ShopContext::exists($other)) {
                $out[] = [
                    'id' => $other,
                    'name' => ShopContext::name($other),
                    'mode' => Options::str(GtmOptions::GTM_MODE, GtmOptions::MODE_AUTO, $other),
                    'sameGa4' => $this->sameGa4($idShop, $other),
                ];
            }
        }

        return $out;
    }
}
