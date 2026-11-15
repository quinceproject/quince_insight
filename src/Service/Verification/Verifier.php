<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\Verification;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Quince\Insight\Core\Clock;
use Quince\Insight\Core\Google\GoogleApiException;
use Quince\Insight\Core\Google\GoogleClient;
use Quince\Insight\Core\Log;
use Quince\Insight\Core\Options;
use Quince\Insight\Core\ShopContext;

/**
 * Weryfikacja witryny sklepu: stan, sprawdzenie, tag meta, usuniecie.
 *
 * Kolejnosc z research/04 §5: najpierw token META i tag na stronie, potem `insert`
 * (Google czyta strone glowna). Usuniecie odwrotnie: najpierw tag znika ze strony,
 * potem zasob w Google — inaczej Google odpowiada 400.
 *
 * Tag trafia na strone tylko po swiadomym "Add tag and verify" (zgoda w kroku kreatora).
 */
final class Verifier
{
    public function __construct(private readonly SiteVerification $api)
    {
    }

    /** Adres weryfikowany dla sklepu: adres bazowy sklepu (z sciezka wirtualna). */
    public function siteUrl(int $idShop): string
    {
        return ShopContext::baseUrl($idShop);
    }

    /** @return array{state:string,site:string,meta:bool,at:string,method:string} */
    public function state(int $idShop): array
    {
        $meta = Options::str(VerificationOptions::META, '', $idShop);
        $state = Options::str(VerificationOptions::STATE, VerificationState::NONE, $idShop);

        return [
            'state' => $state,
            'site' => Options::str(VerificationOptions::SITE, '', $idShop),
            'meta' => $meta !== '',
            'at' => Options::str(VerificationOptions::AT, '', $idShop),
            // Sposob widoczny w kroku: tag modulu albo "I'll verify it another way".
            'method' => $state === VerificationState::MANUAL ? 'manual' : 'meta',
        ];
    }

    /**
     * Inne sklepy korzystajace z weryfikacji tego sklepu (ta sama domena, bez wlasnego tagu).
     * Wolane przed zmiana stanu — `remove()` je resetuje.
     *
     * @return int[]
     */
    public function sharingShops(int $idShop, string $site): array
    {
        $shops = [];

        foreach (array_keys(ShopContext::shops()) as $id) {
            $shops[$id] = [
                'site' => Options::str(VerificationOptions::SITE, '', $id),
                'meta' => Options::str(VerificationOptions::META, '', $id),
            ];
        }

        return VerificationState::sharingShops($idShop, $site, $shops);
    }

    public function isVerified(int $idShop): bool
    {
        return VerificationState::isVerified($this->state($idShop)['state']) || $this->coveredBy($idShop) !== null;
    }

    /** Inny sklep, ktorego weryfikacja obejmuje adres tego sklepu. */
    public function coveredBy(int $idShop): ?int
    {
        $shops = [];

        foreach (ShopContext::shops() as $id => $shop) {
            $shops[$id] = [
                'url' => $shop['base_url'],
                'state' => Options::str(VerificationOptions::STATE, VerificationState::NONE, $id),
                'site' => Options::str(VerificationOptions::SITE, '', $id),
            ];
        }

        return VerificationState::verifiedBy($idShop, $this->siteUrl($idShop), $shops);
    }

    /**
     * Sprawdzenie w Google (wejscie w krok, "Check again").
     *
     * @return array{state:string,site:string} state: verified | manual | unverified | pending
     */
    public function check(int $idShop, ?GoogleClient $client = null): array
    {
        $url = $this->siteUrl($idShop);
        $current = $this->state($idShop);

        // Zweryfikowany wczesniej — stan lokalny wystarcza, bez wywolania Google.
        if (VerificationState::isVerified($current['state']) && $current['site'] !== '') {
            return ['state' => $current['state'], 'site' => $current['site']];
        }

        $other = $this->coveredBy($idShop);

        if ($other !== null) {
            return $this->markShared($idShop, $other);
        }

        $client ??= GoogleClient::forShop($idShop, 'verification');
        $resource = VerificationState::coveringResource($this->api->resources($client), $url);

        if ($resource === null) {
            return ['state' => $current['state'] === VerificationState::PENDING ? VerificationState::PENDING : 'unverified', 'site' => ''];
        }

        $site = $resource['type'] === 'INET_DOMAIN' ? 'sc-domain:' . $resource['identifier'] : $resource['identifier'];
        // Zweryfikowany naszym tagiem (tag zostaje), albo inna droga (DNS, plik, wczesniej).
        $state = $current['meta'] && $current['state'] !== VerificationState::MANUAL ? VerificationState::VERIFIED : VerificationState::MANUAL;

        $this->save($idShop, $state, $site);

        return ['state' => $state, 'site' => $site];
    }

    /**
     * "Add tag and verify": token META, tag na stronie, `insert`.
     *
     * @return array{state:string,site:string} state: verified | error
     */
    public function start(int $idShop, ?GoogleClient $client = null): array
    {
        $url = $this->siteUrl($idShop);
        $client ??= GoogleClient::forShop($idShop, 'verification');

        $content = $this->api->metaToken($client, $url);

        if ($content === '') {
            throw new \RuntimeException('Google did not return a verification token.');
        }

        // Tag musi byc na stronie, zanim Google po niego przyjdzie.
        Options::set(VerificationOptions::META, $content, $idShop);
        Options::set(VerificationOptions::STATE, VerificationState::PENDING, $idShop);

        try {
            $this->api->insert($client, $url);
        } catch (GoogleApiException $e) {
            // 400 = Google nie znalazl tagu (pamiec podreczna strony, przekierowanie). Tag zostaje,
            // "Try again" wysle `insert` jeszcze raz. Inne bledy (limit, uprawnienia) idą wyzej.
            if ($e->httpCode() === 400) {
                Log::write($idShop, 'verification', Log::WARNING, 'Google could not verify the site: ' . $e->getMessage());

                return ['state' => 'error', 'site' => ''];
            }

            throw $e;
        }

        $this->save($idShop, VerificationState::VERIFIED, $url);
        Log::write($idShop, 'verification', Log::INFO, 'Site verified with the meta tag.');

        return ['state' => VerificationState::VERIFIED, 'site' => $url];
    }

    /**
     * Usuniecie: tag ze strony, potem wlasnosc w Google. Sklepy, ktore korzystaly z tej
     * weryfikacji (ta sama domena), wracaja do stanu "niezweryfikowany".
     *
     * @return array{removed:bool,google:bool} google=false gdy Google nie usunal zasobu
     */
    public function remove(int $idShop, ?GoogleClient $client = null): array
    {
        $state = $this->state($idShop);
        $site = $state['site'];
        $ours = $state['meta'];

        Options::set(VerificationOptions::META, '', $idShop);
        Options::set(VerificationOptions::STATE, VerificationState::NONE, $idShop);
        Options::delete(VerificationOptions::SITE, $idShop);
        Options::delete(VerificationOptions::AT, $idShop);

        foreach ($this->sharingShops($idShop, $site) as $id) {
            Options::set(VerificationOptions::STATE, VerificationState::NONE, $id);
            Options::delete(VerificationOptions::SITE, $id);
        }

        $google = true;

        // W Google usuwamy tylko to, co zrobil modul (tag meta). Weryfikacji zrobionej inna
        // droga modul nie dotyka.
        if ($ours && $site !== '' && !str_starts_with($site, 'sc-domain:')) {
            try {
                $client ??= GoogleClient::forShop($idShop, 'verification');
                $this->api->delete($client, $site);
            } catch (\Throwable $e) {
                $google = false;
                Log::write($idShop, 'verification', Log::WARNING, 'The verification was not removed at Google: ' . $e->getMessage());
            }
        }

        return ['removed' => true, 'google' => $google];
    }

    /**
     * Sklep obejmowany weryfikacja innego sklepu (ta sama domena) — zapis bez tagu.
     *
     * @return array{state:string,site:string}
     */
    public function markShared(int $idShop, int $byShop): array
    {
        $site = Options::str(VerificationOptions::SITE, '', $byShop);

        if (Options::str(VerificationOptions::META, '', $idShop) === '') {
            $this->save($idShop, VerificationState::MANUAL, $site);
        }

        return ['state' => VerificationState::MANUAL, 'site' => $site];
    }

    private function save(int $idShop, string $state, string $site): void
    {
        Options::set(VerificationOptions::STATE, $state, $idShop);
        Options::set(VerificationOptions::SITE, $site, $idShop);
        Options::set(VerificationOptions::AT, Clock::now(), $idShop);
    }
}
