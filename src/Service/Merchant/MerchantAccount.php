<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\Merchant;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Quince\Insight\Core\Clock;
use Quince\Insight\Core\Google\ConnectionRepository;
use Quince\Insight\Core\Google\GoogleApiException;
use Quince\Insight\Core\Google\GoogleClient;
use Quince\Insight\Core\Options;
use Quince\Insight\Core\ShopContext;

/**
 * Konto Merchant Center sklepu (kontrakt dla agenta M, ARCHITEKTURA §7.6) oraz operacje
 * konta: lista kont, rola, rejestracja projektu, konto rejestracji, adres witryny, problemy.
 *
 * Wywolania wg research/04 §2–§4, §11. Rejestracja przeniesiona z FeedForge
 * (`GoogleApiClient::registerGcpAsDeveloper()`), ale "juz zarejestrowany" rozpoznawany po
 * powodzie bledu (409 ALREADY_EXISTS / DUPLICATE_PROJECT_REGISTRATION), nie po tekscie.
 */
final class MerchantAccount
{
    public const API = 'https://merchantapi.googleapis.com';

    /** Merchant API dziala ok. 5 minut po rejestracji (research/04 §2.1). */
    public const REGISTRATION_DELAY = 300;

    public function __construct(private readonly ConnectionRepository $connections)
    {
    }

    /** '' gdy brak */
    public function accountId(int $idShop): string
    {
        return Options::str(MerchantOptions::ACCOUNT, '', $idShop);
    }

    /** Klient Merchant API sklepu. Rzuca NotConnectedException, gdy sklep nie jest polaczony. */
    public function client(int $idShop): GoogleClient
    {
        return GoogleClient::forShop($idShop, 'merchant')->withRetryProfile(GoogleClient::PROFILE_MERCHANT);
    }

    /**
     * Konta dostepne dla zalogowanego uzytkownika, razem z subkontami kont zaawansowanych.
     * Konto zaawansowane wykrywane filtrem `-capabilities:CAN_UPLOAD_PRODUCTS` (research/04 §3).
     *
     * @return array<int,array{id:string,name:string,homepage:string,parent:string,matches:bool}>
     */
    public function listAccounts(int $idShop): array
    {
        $client = $this->client($idShop);
        $accounts = $client->paginate(self::API . '/accounts/v1/accounts', 'accounts', ['pageSize' => 250], 10);
        $out = [];

        foreach ($accounts as $account) {
            $out[(string) ($account['accountId'] ?? '')] = $this->accountRow($idShop, $account, '');
        }

        try {
            $advanced = $client->paginate(self::API . '/accounts/v1/accounts', 'accounts', ['pageSize' => 250, 'filter' => '-capabilities:CAN_UPLOAD_PRODUCTS'], 5);
        } catch (GoogleApiException $e) {
            $advanced = [];
        }

        foreach ($advanced as $parent) {
            $parentId = (string) ($parent['accountId'] ?? '');

            if ($parentId === '') {
                continue;
            }

            // Konto zaawansowane samo nie przyjmuje produktow — wybiera sie jego subkonto.
            unset($out[$parentId]);

            try {
                $subs = $client->paginate(self::API . '/accounts/v1/accounts/' . rawurlencode($parentId) . ':listSubaccounts', 'accounts', ['pageSize' => 250], 10);
            } catch (GoogleApiException $e) {
                continue;
            }

            foreach ($subs as $sub) {
                $out[(string) ($sub['accountId'] ?? '')] = $this->accountRow($idShop, $sub, (string) ($parent['accountName'] ?? $parentId));
            }
        }

        unset($out['']);

        // Konto pasujace do adresu sklepu na gorze — jak w prototypie ("Matches your shop").
        uasort($out, static fn (array $a, array $b): int => [(int) !$a['matches'], $a['name']] <=> [(int) !$b['matches'], $b['name']]);

        return array_values($out);
    }

    /**
     * Zapis wybranego konta. Sprawdza dostep (GET konta) — reczne ID, do ktorego login nie ma
     * dostepu, konczy sie bledem Google zamiast cichego zapisu.
     */
    public function select(int $idShop, string $accountId): string
    {
        $accountId = trim($accountId);

        if (!ctype_digit($accountId)) {
            throw new \InvalidArgumentException('Enter the numeric Merchant ID.');
        }

        try {
            $account = $this->client($idShop)->get(self::API . '/accounts/v1/accounts/' . $accountId);
            $name = (string) ($account['accountName'] ?? $accountId);
        } catch (GoogleApiException $e) {
            // Przed rejestracja projektu Merchant API nie odpowiada na nic poza samym
            // `registerGcp` — numer konta zapisujemy bez nazwy, nazwa dojdzie po rejestracji.
            if ($e->reason() !== 'GCP_NOT_REGISTERED') {
                throw $e;
            }

            $name = $accountId;
        }

        if ($this->accountId($idShop) !== $accountId) {
            // Inne konto = inny stan przejecia adresu i inne problemy konta.
            foreach ([MerchantOptions::CLAIM_STATE, MerchantOptions::CLAIM_URI, MerchantOptions::ACCOUNT_ISSUES, MerchantOptions::ISSUES_AT, MerchantOptions::ACCESS_RIGHTS] as $key) {
                Options::delete($key, $idShop);
            }
        }

        Options::set(MerchantOptions::ACCOUNT, $accountId, $idShop);
        Options::set(MerchantOptions::ACCOUNT_NAME, $name, $idShop);

        try {
            $this->accessRights($idShop);
        } catch (GoogleApiException $e) {
            // Rola jest pomocnicza (podpowiedz przy rejestracji i przejeciu) — brak nie blokuje wyboru.
        }

        return $name;
    }

    /** @return string[] np. ['ADMIN', 'STANDARD'] */
    public function accessRights(int $idShop): array
    {
        $id = $this->requireAccount($idShop);
        $me = $this->client($idShop)->get(self::API . '/accounts/v1/accounts/' . $id . '/users/me');
        $rights = array_values(array_map('strval', (array) ($me['accessRights'] ?? [])));

        Options::set(MerchantOptions::ACCESS_RIGHTS, $rights, $idShop);

        return $rights;
    }

    public function isAdmin(int $idShop): bool
    {
        return in_array('ADMIN', Options::json(MerchantOptions::ACCESS_RIGHTS, [], $idShop), true);
    }

    /**
     * Rejestracja projektu Cloud jako dewelopera Merchant API (raz na projekt, ARCHITEKTURA §6).
     * Projekt juz zarejestrowany (tu albo na innym koncie) to sukces — zapisujemy konto, na
     * ktorym jest zarejestrowany, i stosujemy je do wszystkich sklepow tego polaczenia.
     *
     * @return array{account:string,already:bool}
     */
    public function register(int $idShop): array
    {
        $id = $this->requireAccount($idShop);
        $connection = $this->connections->forShop($idShop);
        $email = $connection !== null ? $connection->googleEmail : '';
        $already = false;

        try {
            $this->client($idShop)->post(
                self::API . '/accounts/v1/accounts/' . $id . '/developerRegistration:registerGcp',
                $email !== '' ? ['developerEmail' => $email] : []
            );
            $registeredOn = $id;
        } catch (GoogleApiException $e) {
            if ($e->httpCode() !== 409 && !in_array(self::reasonOf($e), ['ALREADY_EXISTS', 'DUPLICATE_PROJECT_REGISTRATION', 'ALREADY_REGISTERED'], true)) {
                throw $e;
            }

            $already = true;
            $registeredOn = $this->registrationAccount($idShop) ?: $id;
        }

        $this->rememberRegistration($idShop, $registeredOn, 'ok', $already ? '' : Clock::now());

        return ['account' => $registeredOn, 'already' => $already];
    }

    /**
     * Konto, na ktorym zarejestrowany jest projekt (`getAccountForGcpRegistration`).
     * '' gdy projekt nie jest zarejestrowany (401 GCP_NOT_REGISTERED).
     */
    public function registrationAccount(int $idShop): string
    {
        try {
            $data = $this->client($idShop)->get(self::API . '/accounts/v1/accounts:getAccountForGcpRegistration');
        } catch (GoogleApiException $e) {
            if (self::reasonOf($e) === 'GCP_NOT_REGISTERED' || self::reasonOf($e) === 'AUTH_GCP_NOT_REGISTERED') {
                return '';
            }

            throw $e;
        }

        $name = (string) ($data['name'] ?? '');

        return str_starts_with($name, 'accounts/') ? substr($name, 9) : $name;
    }

    /**
     * Dobowa kontrola konta rejestracji (ARCHITEKTURA §6): usuniete konto albo brak uzytkownika
     * "API developer" przez ponad 30 dni odcina wszystkie sklepy projektu.
     *
     * @return string stan: ok | not_registered | no_contact | error
     */
    public function checkRegistration(int $idShop): string
    {
        $state = 'ok';
        $account = Options::str(MerchantOptions::REG_ACCOUNT, '', $idShop);

        try {
            $current = $this->registrationAccount($idShop);

            if ($current === '') {
                $state = 'not_registered';
            } else {
                $account = $current;
            }
        } catch (GoogleApiException $e) {
            $state = self::reasonOf($e) === 'GCP_NOT_REGISTERED_NO_CONTACT' ? 'no_contact' : 'error';
        }

        $this->rememberRegistration($idShop, $state === 'not_registered' ? '' : $account, $state, '');

        return $state;
    }

    /** Projekt zarejestrowany, ale moze jeszcze nie dzialac (pierwsze ~5 minut). */
    public function registrationPending(int $idShop): bool
    {
        $at = Options::str(MerchantOptions::REG_AT, '', $idShop);
        $since = Clock::secondsSince($at !== '' ? $at : null);

        return $since !== null && $since < self::REGISTRATION_DELAY;
    }

    public function isRegistered(int $idShop): bool
    {
        return Options::str(MerchantOptions::REG_ACCOUNT, '', $idShop) !== ''
            && Options::str(MerchantOptions::REG_STATE, 'ok', $idShop) === 'ok';
    }

    /**
     * Adres witryny konta i jego stan (research/04 §4).
     *
     * @return array{uri:string,claimed:bool}
     */
    public function homepage(int $idShop): array
    {
        $id = $this->requireAccount($idShop);
        $data = $this->client($idShop)->get(self::API . '/accounts/v1/accounts/' . $id . '/homepage');

        return ['uri' => (string) ($data['uri'] ?? ''), 'claimed' => (bool) ($data['claimed'] ?? false)];
    }

    /**
     * Przejecie adresu sklepu. Gdy konto ma inny adres albo zaden — najpierw ustawia adres
     * sklepu (zmiana adresu usuwa dotychczasowe przejecie, research/04 §4, wiec tylko wtedy,
     * gdy adres sie rozni). `overwrite` odbiera adres innemu kontu — tylko po decyzji usera.
     *
     * @return string stan: claimed | other
     */
    public function claim(int $idShop, bool $overwrite): string
    {
        $id = $this->requireAccount($idShop);
        $client = $this->client($idShop);
        $uri = ShopContext::baseUrl($idShop);
        $home = $this->homepage($idShop);

        if (rtrim($home['uri'], '/') !== rtrim($uri, '/')) {
            $client->patch(self::API . '/accounts/v1/accounts/' . $id . '/homepage', [
                'name' => 'accounts/' . $id . '/homepage',
                'uri' => $uri,
            ], ['updateMask' => 'uri']);
        }

        try {
            $client->post(self::API . '/accounts/v1/accounts/' . $id . '/homepage:claim', $overwrite ? ['overwrite' => true] : []);
        } catch (GoogleApiException $e) {
            if (!$overwrite && self::claimedElsewhere($e)) {
                Options::set(MerchantOptions::CLAIM_STATE, 'other', $idShop);
                Options::set(MerchantOptions::CLAIM_URI, $uri, $idShop);

                return 'other';
            }

            throw $e;
        }

        $home = $this->homepage($idShop);
        $state = $home['claimed'] ? 'claimed' : 'unclaimed';
        Options::set(MerchantOptions::CLAIM_STATE, $state, $idShop);
        Options::set(MerchantOptions::CLAIM_URI, $home['uri'] !== '' ? $home['uri'] : $uri, $idShop);

        return $state;
    }

    /** Odswieza stan przejecia z Google (bez zmian na koncie). */
    public function refreshClaim(int $idShop): string
    {
        $home = $this->homepage($idShop);
        $previous = Options::str(MerchantOptions::CLAIM_STATE, 'unclaimed', $idShop);
        $state = $home['claimed'] ? 'claimed' : ($previous === 'other' ? 'other' : 'unclaimed');

        Options::set(MerchantOptions::CLAIM_STATE, $state, $idShop);
        Options::set(MerchantOptions::CLAIM_URI, $home['uri'], $idShop);

        return $state;
    }

    /**
     * Problemy konta (research/04 §11) — zapisywane do pokazania bez odpytywania Google.
     *
     * @return array<int,array{title:string,detail:string,severity:string,uri:string}>
     */
    public function refreshIssues(int $idShop): array
    {
        $id = $this->requireAccount($idShop);
        $items = $this->client($idShop)->paginate(self::API . '/accounts/v1/accounts/' . $id . '/issues', 'accountIssues', ['pageSize' => 100], 10);
        $out = [];

        foreach ($items as $issue) {
            $out[] = [
                'title' => mb_substr((string) ($issue['title'] ?? ''), 0, 255),
                'detail' => mb_substr((string) ($issue['detail'] ?? ''), 0, 500),
                'severity' => strtolower((string) ($issue['severity'] ?? '')),
                'uri' => (string) ($issue['documentationUri'] ?? ''),
            ];
        }

        Options::set(MerchantOptions::ACCOUNT_ISSUES, $out, $idShop);
        Options::set(MerchantOptions::ISSUES_AT, Clock::now(), $idShop);

        return $out;
    }

    /** @return array<int,array{title:string,detail:string,severity:string,uri:string}> */
    public function issues(int $idShop): array
    {
        return Options::json(MerchantOptions::ACCOUNT_ISSUES, [], $idShop);
    }

    /**
     * Powod bledu Merchant API: `details[].reason` (ErrorInfo) albo `metadata.REASON` — Google
     * zaleca rozgaleziac po tym drugim (research/04 §18).
     */
    public static function reasonOf(GoogleApiException $e): string
    {
        foreach ((array) ($e->payload()['error']['details'] ?? []) as $detail) {
            if (is_array($detail) && !empty($detail['metadata']['REASON'])) {
                return (string) $detail['metadata']['REASON'];
            }
        }

        return $e->reason() !== '' ? $e->reason() : $e->status();
    }

    /**
     * Czy odmowa przejecia oznacza, ze adres ma inne konto. Ksztalt tej odpowiedzi nie jest
     * udokumentowany (research/04 §4: FAILED_PRECONDITION "nieudane przejecie — szczegoly
     * w komunikacie") — rozpoznajemy zachowawczo po komunikacie; reszta idzie jako blad.
     */
    public static function claimedElsewhere(GoogleApiException $e): bool
    {
        if (!in_array($e->status(), ['FAILED_PRECONDITION', 'ALREADY_EXISTS'], true) && $e->httpCode() !== 409) {
            return false;
        }

        return (bool) preg_match('/another|other account|already claimed|claimed by/i', $e->getMessage())
            || in_array(self::reasonOf($e), ['HOMEPAGE_ALREADY_CLAIMED', 'ALREADY_CLAIMED'], true);
    }

    private function requireAccount(int $idShop): string
    {
        $id = $this->accountId($idShop);

        if ($id === '') {
            throw new \InvalidArgumentException('Choose a Merchant Center account first.');
        }

        return $id;
    }

    /** Konto rejestracji jest wspolne dla projektu — zapis we wszystkich sklepach tego polaczenia. */
    private function rememberRegistration(int $idShop, string $account, string $state, string $at): void
    {
        $connection = $this->connections->forShop($idShop);
        $shops = [$idShop];

        if ($connection !== null) {
            foreach (array_keys(ShopContext::shops()) as $other) {
                $otherConnection = $this->connections->forShop((int) $other);

                if ($otherConnection !== null && $otherConnection->id === $connection->id) {
                    $shops[] = (int) $other;
                }
            }
        }

        foreach (array_unique($shops) as $shop) {
            Options::set(MerchantOptions::REG_ACCOUNT, $account, $shop);
            Options::set(MerchantOptions::REG_STATE, $state, $shop);
            Options::set(MerchantOptions::REG_CHECKED_AT, Clock::now(), $shop);

            if ($at !== '') {
                Options::set(MerchantOptions::REG_AT, $at, $shop);
            }
        }
    }

    /**
     * @param array<string,mixed> $account
     *
     * @return array{id:string,name:string,homepage:string,parent:string,matches:bool}
     */
    private function accountRow(int $idShop, array $account, string $parent): array
    {
        $home = (string) ($account['homePageUri'] ?? '');
        $shopHost = (string) parse_url(ShopContext::baseUrl($idShop), PHP_URL_HOST);
        $host = (string) parse_url($home, PHP_URL_HOST);

        return [
            'id' => (string) ($account['accountId'] ?? ''),
            'name' => (string) ($account['accountName'] ?? ($account['accountId'] ?? '')),
            'homepage' => $home,
            'parent' => $parent,
            'matches' => $host !== '' && $shopHost !== '' && preg_replace('/^www\./', '', strtolower($host)) === preg_replace('/^www\./', '', strtolower($shopHost)),
        ];
    }
}
