<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Core\Google;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Db;
use Quince\Insight\Core\Clock;
use Quince\Insight\Core\Dev;
use Quince\Insight\Core\Google\Dto\Connection;
use Quince\Insight\Core\Log;
use Quince\Insight\Core\Schema;
use Quince\Insight\Core\ShopContext;
use Quince\Insight\Core\ShopRepository;

/**
 * Logowanie do Google (OAuth 2.0, przeplyw web-server) i odswiezanie tokenu.
 *
 * Adres zwrotny to kontroler frontowy modulu, nie trasa panelu: trasy panelu maja w
 * adresie zmienny `_token`, wiec nie da sie ich wpisac w Google Cloud. Powrot do panelu
 * idzie adresem zapisanym razem z jednorazowym `state` — nigdy z parametrow zadania.
 */
final class OAuthFlow
{
    public const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    public const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';
    public const USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';

    /** Parametr dopisywany do adresu powrotu: ok | denied | error | partial. */
    public const RESULT_PARAM = 'qi_oauth';

    private const STATE_TTL = 600;

    /** Ciasteczko wiążące logowanie z przeglądarką, która je zaczęła. */
    private const BIND_COOKIE = 'qi_oauth_bind';

    /** Wartość ciasteczka dla danego `state`: podpis kluczem sklepu, bez zapisu w bazie. */
    private static function binding(string $state): string
    {
        return hash_hmac('sha256', 'quince_insight_oauth|' . $state, _COOKIE_KEY_);
    }

    /** Zapas przed wygasnieciem tokenu dostepu, przy ktorym odswiezamy z wyprzedzeniem. */
    private const REFRESH_MARGIN = 60;

    public function __construct(
        private readonly ConnectionRepository $connections,
        private readonly ShopRepository $shops,
    ) {
    }

    /**
     * Staly adres zwrotny (do wklejenia w Google Cloud). Jeden na instalacje, takze w
     * multistore: adres sklepu domyslnego, bez przyjaznych adresow i bez jezyka.
     */
    public function redirectUri(): string
    {
        return ShopContext::baseUrl(ShopContext::defaultShop())
            . 'index.php?fc=module&module=quince_insight&controller=oauth';
    }

    /**
     * Zapisuje jednorazowy state i zwraca adres logowania Google.
     *
     * @param string[] $scopes scope'y albo grupy scope'ow (stale z `Scopes`, wynik `Scopes::forServices()`)
     * @param string $returnUrl pelny adres panelu, na ktory wroci przegladarka (z `_token`)
     */
    public function authUrl(int $idConnection, int $idShop, int $idEmployee, array $scopes, string $returnUrl): string
    {
        $connection = $this->connections->find($idConnection);

        if ($connection === null || !$connection->hasCredentials()) {
            throw new NotConnectedException('Google Cloud project details are missing.');
        }

        $scopes = Scopes::split($scopes);
        $state = bin2hex(random_bytes(32));

        // Stare, niewykorzystane wpisy znikaja przy okazji — tabela nie rosnie.
        Db::getInstance()->delete(Schema::name(Schema::OAUTH_STATE), '`expires_at` < "' . pSQL(Clock::now()) . '"');

        Db::getInstance()->insert(Schema::name(Schema::OAUTH_STATE), [
            'state' => pSQL($state),
            'id_connection' => $idConnection,
            'id_shop' => $idShop,
            'id_employee' => $idEmployee,
            'scopes' => pSQL(implode(' ', $scopes)),
            'return_url' => pSQL(mb_substr($returnUrl, 0, 1000), true),
            'expires_at' => pSQL(Clock::at(self::STATE_TTL)),
        ]);

        // Wiąże logowanie z przeglądarką, która je zaczęła. `state` stoi w adresie,
        // więc wycieka razem z nim (wklejony w czacie, zrzut ekranu); kto go ma,
        // mógłby dokończyć logowanie własnym kontem Google. Ciasteczka nie ma.
        setcookie(self::BIND_COOKIE, self::binding($state), [
            'expires' => time() + self::STATE_TTL,
            'path' => '/',
            'secure' => \Tools::usingSecureMode(),
            'httponly' => true,
            // Powrót z Google to zwykłe przejście GET z innej witryny: Lax je przepuszcza.
            'samesite' => 'Lax',
        ]);

        if (Dev::fakeGoogle()) {
            // Tryb atrap: zamiast ekranu Google od razu "powrot" z kodem na adres zwrotny.
            return $this->redirectUri() . '&' . http_build_query(['state' => $state, 'code' => 'fake-code']);
        }

        return self::AUTH_URL . '?' . http_build_query([
            'client_id' => $connection->clientId,
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => implode(' ', $scopes),
            'access_type' => 'offline',
            // Bez `consent` Google nie wyda ponownie tokenu odswiezania.
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
    }

    /**
     * Wolane przez controllers/front/oauth.php. Zwraca adres powrotu do panelu
     * z dopisanym wynikiem (`qi_oauth=ok|partial|denied|error`).
     *
     * @param array<string,mixed> $query parametry zadania (state, code, error)
     *
     * @throws \RuntimeException gdy `state` jest nieznany, zuzyty albo przeterminowany — nie ma dokad wrocic
     */
    public function handleCallback(array $query): string
    {
        $state = (string) ($query['state'] ?? '');

        if (preg_match('/^[a-f0-9]{64}$/', $state) !== 1) {
            throw new \RuntimeException('Invalid state.');
        }

        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . Schema::table(Schema::OAUTH_STATE) . '` WHERE `state` = "' . pSQL($state) . '"',
            false
        );

        // Jednorazowosc: wpis znika przed jakimkolwiek dalszym krokiem, a dalej idzie tylko
        // to wywolanie, ktore go faktycznie usunelo. Dwa rownolegle powroty z tym samym
        // `state` (podwojne klikniecie, odswiezenie) — drugi konczy sie tutaj (R-10).
        $db = Db::getInstance();
        $db->execute('DELETE FROM `' . Schema::table(Schema::OAUTH_STATE) . '` WHERE `state` = "' . pSQL($state) . '"');
        $deleted = (int) $db->Affected_Rows();

        if (!self::stateClaimed($row, $state, $deleted, Clock::now())) {
            throw new \RuntimeException('Sign-in link expired.');
        }

        // Powrót musi trafić do tej samej przeglądarki, w której logowanie się
        // zaczęło (ciasteczko z `authUrl()`). Wpis `state` jest już zużyty,
        // więc obca przeglądarka nie dostanie drugiej próby.
        $wiazanie = (string) ($_COOKIE[self::BIND_COOKIE] ?? '');
        setcookie(self::BIND_COOKIE, '', ['expires' => time() - 3600, 'path' => '/']);

        if (!hash_equals(self::binding($state), $wiazanie)) {
            Log::write((int) $row['id_shop'], 'core', Log::WARNING, 'Google sign-in was finished in a different browser than it started in, so it was refused.');

            throw new \RuntimeException('Sign-in started in another browser.');
        }

        $idConnection = (int) $row['id_connection'];
        $idShop = (int) $row['id_shop'];
        $returnUrl = (string) $row['return_url'];
        $requested = Scopes::split([(string) $row['scopes']]);

        if (!empty($query['error'])) {
            // User zamknal ekran zgody albo odmowil — stan polaczenia sie nie zmienia.
            Log::write($idShop, 'core', Log::WARNING, 'Google sign-in was not completed.', ['error' => substr((string) $query['error'], 0, 60)]);

            return self::withResult($returnUrl, 'denied');
        }

        $connection = $this->connections->find($idConnection);
        $code = (string) ($query['code'] ?? '');

        if ($connection === null || $code === '') {
            return self::withResult($returnUrl, 'error');
        }

        try {
            $token = $this->tokenRequest($idShop, [
                'code' => $code,
                'client_id' => $connection->clientId,
                'client_secret' => $this->connections->clientSecret($idConnection),
                'redirect_uri' => $this->redirectUri(),
                'grant_type' => 'authorization_code',
            ]);
        } catch (GoogleApiException $e) {
            $this->connections->markStatus($idConnection, $connection->status, $e->getMessage());

            return self::withResult($returnUrl, 'error');
        }

        // Przyznane scope'y bierzemy z odpowiedzi tokenowej — user mogl czesc odznaczyc.
        $granted = Scopes::split([(string) ($token['scope'] ?? '')]);

        if (Dev::fakeGoogle()) {
            $granted = $this->fakeGranted($requested);
        }

        $accessToken = (string) ($token['access_token'] ?? '');

        if ($accessToken === '') {
            $this->connections->markStatus($idConnection, $connection->status, 'Google did not return an access token.');

            return self::withResult($returnUrl, 'error');
        }

        $this->connections->saveTokens(
            $idConnection,
            $accessToken,
            (int) ($token['expires_in'] ?? 3600),
            (string) ($token['refresh_token'] ?? ''),
            $granted
        );

        $this->connections->saveAccount($idConnection, $this->userEmail($idShop, $accessToken), $this->redirectUri());

        if ($idShop > 0) {
            $this->connections->assignShop($idShop, $idConnection);
        }

        Log::write($idShop, 'core', Log::INFO, 'Google account connected.', ['id_connection' => $idConnection, 'scopes' => count($granted)]);

        // `partial`: logowanie udane, ale czesc uprawnien odznaczona — kreator pokazuje, ktorych brakuje.
        $complete = array_diff($requested, $granted) === [] || $this->onlyEmailAliasMissing($requested, $granted);

        return self::withResult($returnUrl, $complete ? 'ok' : 'partial');
    }

    /**
     * Wazny token dostepu polaczenia; odswieza go na 60 s przed wygasnieciem.
     *
     * @throws NotConnectedException gdy polaczenie nie jest zalogowane
     * @throws GoogleAuthException gdy odswiezenie sie nie udalo
     */
    public function accessToken(int $idConnection): string
    {
        $connection = $this->connections->find($idConnection);

        if ($connection === null || !$connection->isConnected()) {
            throw new NotConnectedException('Google account is not connected.');
        }

        $tokens = $this->connections->tokens($idConnection);
        $left = $tokens['expires_at'] === null ? 0 : strtotime($tokens['expires_at']) - Clock::time();

        if ($tokens['access'] !== '' && $left > self::REFRESH_MARGIN) {
            return $tokens['access'];
        }

        return $this->refresh($idConnection);
    }

    /**
     * Nowy access token; rzuca GoogleAuthException.
     *
     * `invalid_grant` (token odswiezania wygasl albo zostal cofniety) przestawia polaczenie
     * w stan `needs_reauth` — od tej chwili zadne wywolanie nie idzie do Google, dopoki
     * user nie zaloguje sie ponownie.
     */
    public function refresh(int $idConnection): string
    {
        $connection = $this->connections->find($idConnection);
        $tokens = $this->connections->tokens($idConnection);

        if ($connection === null || $tokens['refresh'] === '') {
            if ($connection !== null) {
                $this->connections->markStatus($idConnection, Connection::STATUS_NEEDS_REAUTH, 'No refresh token.');
            }

            throw new GoogleAuthException('Google account needs to be connected again.', 401, 'UNAUTHENTICATED', 'invalid_grant');
        }

        try {
            $token = $this->tokenRequest(0, [
                'client_id' => $connection->clientId,
                'client_secret' => $this->connections->clientSecret($idConnection),
                'refresh_token' => $tokens['refresh'],
                'grant_type' => 'refresh_token',
            ]);
        } catch (GoogleApiException $e) {
            // Tylko odmowa Google uniewaznia polaczenie. Brak sieci albo 5xx to chwilowy
            // klopot — token odswiezania nadal jest dobry.
            if ($e->reason() === 'invalid_grant' || $e->reason() === 'invalid_client' || $e->reason() === 'unauthorized_client') {
                $this->connections->markStatus($idConnection, Connection::STATUS_NEEDS_REAUTH, $e->getMessage());
            }

            throw new GoogleAuthException($e->getMessage(), $e->httpCode(), $e->status(), $e->reason());
        }

        $accessToken = (string) ($token['access_token'] ?? '');

        if ($accessToken === '') {
            throw new GoogleAuthException('Google did not return an access token.', 0, '', '');
        }

        $this->connections->saveTokens($idConnection, $accessToken, (int) ($token['expires_in'] ?? 3600));

        return $accessToken;
    }

    /**
     * Czy adres zwrotny zmienil sie od chwili polaczenia (inna domena sklepu domyslnego).
     * Wtedy trzeba dopisac nowy adres w Google Cloud.
     */
    public function redirectUriChanged(int $idConnection): bool
    {
        $connection = $this->connections->find($idConnection);

        return $connection !== null
            && $connection->redirectUri !== ''
            && $connection->redirectUri !== $this->redirectUri();
    }

    /**
     * @param array<string,string> $fields
     *
     * @return array<string,mixed>
     */
    private function tokenRequest(int $idShop, array $fields): array
    {
        $started = microtime(true);

        $response = Http::transport('core')->send(
            'POST',
            self::TOKEN_URL,
            ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            http_build_query($fields),
            20
        );

        $ms = (int) round((microtime(true) - $started) * 1000);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            $error = GoogleApiException::fromResponse($response['status'], $response['body'], $response['headers']);

            Log::http($idShop, 'core', Log::ERROR, 'Token request failed: ' . $error->getMessage(), 'POST', self::TOKEN_URL, $response['status'], $ms, [
                'grant_type' => $fields['grant_type'] ?? '',
                'reason' => $error->reason(),
            ]);

            throw $error;
        }

        $data = json_decode($response['body'], true);

        return is_array($data) ? $data : [];
    }

    /** E-mail zalogowanego konta; '' gdy sie nie da odczytac (polaczenie i tak dziala). */
    private function userEmail(int $idShop, string $accessToken): string
    {
        $response = Http::transport('core')->send(
            'GET',
            self::USERINFO_URL,
            ['Authorization: Bearer ' . $accessToken, 'Accept: application/json'],
            null,
            20
        );

        $data = json_decode($response['body'], true);

        if ($response['status'] !== 200 || !is_array($data)) {
            Log::http($idShop, 'core', Log::WARNING, 'Could not read the Google account e-mail.', 'GET', self::USERINFO_URL, $response['status'], 0);

            return '';
        }

        return (string) ($data['email'] ?? '');
    }

    /**
     * Tryb atrap: przyznane jest wszystko, o co proszono, poza uprawnieniami uslugi
     * wskazanej w `QUINCE_INSIGHT_FAKE_DENY_SCOPE` (symulacja odznaczonego pola zgody).
     *
     * @param string[] $requested
     *
     * @return string[]
     */
    private function fakeGranted(array $requested): array
    {
        $deny = Dev::denyScope();

        if ($deny === '') {
            return $requested;
        }

        // Weryfikacja witryny jest wspolna dla Search Console i Merchant Center —
        // odznaczenie jednej uslugi nie zabiera jej drugiej.
        $denied = array_diff(Scopes::ofService($deny), $deny === 'verify' ? [] : Scopes::ofService('verify'));

        return array_values(array_diff($requested, $denied));
    }

    /**
     * @param string[] $requested
     * @param string[] $granted
     */
    private function onlyEmailAliasMissing(array $requested, array $granted): bool
    {
        foreach (array_diff($requested, $granted) as $scope) {
            if (!Scopes::has($granted, $scope)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Czy to wywolanie przejmuje `state`: wiersz odczytany, ten sam, nieprzeterminowany
     * i usuniety wlasnie przez to wywolanie (dokladnie jeden wiersz).
     *
     * @param array<string,mixed>|false|null $row
     */
    public static function stateClaimed($row, string $state, int $deletedRows, string $now): bool
    {
        return is_array($row)
            && $deletedRows === 1
            && hash_equals((string) ($row['state'] ?? ''), $state)
            && (string) ($row['expires_at'] ?? '') >= $now;
    }

    private static function withResult(string $returnUrl, string $result): string
    {
        return $returnUrl . (strpos($returnUrl, '?') === false ? '?' : '&') . self::RESULT_PARAM . '=' . $result;
    }
}
