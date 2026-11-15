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

use Db;
use Quince\Insight\Core\Clock;
use Quince\Insight\Core\Crypto;
use Quince\Insight\Core\Google\Dto\Connection;
use Quince\Insight\Core\Log;
use Quince\Insight\Core\Schema;
use Quince\Insight\Core\ShopRepository;

/**
 * Polaczenia z Google (tabela `connection`): projekt Google Cloud + zalogowane konto.
 *
 * Domyslnie jedno polaczenie dla wszystkich sklepow; sklep wskazuje swoje w tabeli `shop`.
 * Sekrety leza zaszyfrowane (`Crypto`) i wychodza stad tylko do klas rdzenia
 * (`OAuthFlow`, `GoogleClient`) — nigdy do szablonu ani odpowiedzi JSON.
 */
final class ConnectionRepository
{
    public function __construct(private readonly ShopRepository $shops)
    {
    }

    public function forShop(int $idShop): ?Connection
    {
        $idConnection = $this->shops->connectionId($idShop);

        return $idConnection > 0 ? $this->find($idConnection) : null;
    }

    public function find(int $idConnection): ?Connection
    {
        $row = $this->row($idConnection);

        return $row === null ? null : $this->hydrate($row);
    }

    /** @return Connection[] */
    public function all(): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT * FROM `' . Schema::table(Schema::CONNECTION) . '` ORDER BY `id_connection` ASC'
        ) ?: [];

        return array_map(fn (array $row): Connection => $this->hydrate($row), $rows);
    }

    /**
     * Zapisuje dane projektu Google Cloud. Pusty `$clientSecret` albo `$apiKey` przy
     * istniejacym polaczeniu znaczy "bez zmian" (formularz nie odsyla zapisanych sekretow).
     * Zmiana Client ID uniewaznia logowanie — tokeny naleza do poprzedniego klienta.
     *
     * @return int identyfikator polaczenia
     */
    public function saveCredentials(?int $idConnection, string $label, string $clientId, string $clientSecret, string $apiKey): int
    {
        $now = pSQL(Clock::now());
        $existing = $idConnection ? $this->row($idConnection) : null;

        if ($existing === null) {
            Db::getInstance()->insert(Schema::name(Schema::CONNECTION), [
                'label' => pSQL(mb_substr($label, 0, 120), true),
                'client_id' => pSQL(trim($clientId)),
                'client_secret' => '',
                'api_key' => '',
                'status' => Connection::STATUS_NEW,
                'scopes' => '',
                'date_add' => $now,
                'date_upd' => $now,
            ]);

            // Sekrety po wstawieniu: ich szyfrowanie wiaze identyfikator polaczenia.
            $idNew = (int) Db::getInstance()->Insert_ID();
            $this->update($idNew, [
                'client_secret' => pSQL(Crypto::encrypt(trim($clientSecret), self::context($idNew, 'client_secret'))),
                'api_key' => pSQL(Crypto::encrypt(trim($apiKey), self::context($idNew, 'api_key'))),
            ]);

            return $idNew;
        }

        $fields = [
            'label' => pSQL(mb_substr($label, 0, 120), true),
            'client_id' => pSQL(trim($clientId)),
            'date_upd' => $now,
        ];

        if (trim($clientSecret) !== '') {
            $fields['client_secret'] = pSQL(Crypto::encrypt(trim($clientSecret), self::context((int) $idConnection, 'client_secret')));
        }

        if (trim($apiKey) !== '') {
            $fields['api_key'] = pSQL(Crypto::encrypt(trim($apiKey), self::context((int) $idConnection, 'api_key')));
        }

        if (trim($clientId) !== (string) $existing['client_id']) {
            $fields['refresh_token'] = '';
            $fields['access_token'] = '';
            $fields['access_expires_at'] = null;
            $fields['scopes'] = '';
            $fields['status'] = Connection::STATUS_NEW;
        }

        $this->update((int) $idConnection, $fields);

        return (int) $idConnection;
    }

    /** Usuwa zapisany klucz API (pole w formularzu wyczyszczone swiadomie). */
    public function clearApiKey(int $idConnection): void
    {
        $this->update($idConnection, ['api_key' => '']);
    }

    public function setProductionConfirmed(int $idConnection, bool $confirmed): void
    {
        $this->update($idConnection, ['production_confirmed' => (int) $confirmed]);
    }

    public function assignShop(int $idShop, int $idConnection): void
    {
        $this->shops->setConnection($idShop, $idConnection);
    }

    public function hasScope(int $idConnection, string $scope): bool
    {
        $connection = $this->find($idConnection);

        return $connection !== null && Scopes::has($connection->scopes, $scope);
    }

    /**
     * Uslugi, ktorych scope'ow brakuje (user odznaczyl przy zgodzie).
     *
     * @param string[] $serviceKeys
     *
     * @return string[]
     */
    public function missingFor(int $idConnection, array $serviceKeys): array
    {
        $connection = $this->find($idConnection);

        return Scopes::missing($connection ? $connection->scopes : [], $serviceKeys);
    }

    /**
     * Odlacza konto Google: cofa token w Google (gdy sie da) i usuwa tokeny lokalnie.
     * Dane projektu Cloud i przypisania sklepow zostaja — mozna zalogowac sie ponownie.
     * W Google niczego poza tokenem nie usuwa.
     */
    public function disconnect(int $idConnection, bool $revokeAtGoogle = true): void
    {
        $row = $this->row($idConnection);

        if ($row === null) {
            return;
        }

        if ($revokeAtGoogle) {
            $token = Crypto::decrypt((string) $row['refresh_token'], self::context($idConnection, 'refresh_token'))
                ?: Crypto::decrypt((string) $row['access_token'], self::context($idConnection, 'access_token'));

            if ($token !== '') {
                // Nieudane cofniecie (token juz niewazny, brak sieci) nie blokuje odlaczenia.
                $response = Http::transport('core')->send(
                    'POST',
                    OAuthFlow::REVOKE_URL,
                    ['Content-Type: application/x-www-form-urlencoded'],
                    http_build_query(['token' => $token]),
                    20
                );

                Log::http(0, 'core', $response['status'] === 200 ? Log::INFO : Log::WARNING, 'Google access revoked.', 'POST', OAuthFlow::REVOKE_URL, $response['status'], 0, [
                    'id_connection' => $idConnection,
                ]);
            }
        }

        $this->update($idConnection, [
            'refresh_token' => '',
            'access_token' => '',
            'access_expires_at' => null,
            'scopes' => '',
            'status' => Connection::STATUS_REVOKED,
            'last_error' => '',
        ]);
    }

    /** Usuwa polaczenie calkowicie (Settings); sklepy zostaja bez polaczenia. */
    public function delete(int $idConnection): void
    {
        foreach ($this->shops->shopsOfConnection($idConnection) as $idShop) {
            $this->shops->setConnection($idShop, 0);
        }

        Db::getInstance()->delete(Schema::name(Schema::CONNECTION), '`id_connection` = ' . $idConnection);
    }

    // ------------------------------------------------------------------
    // Ponizej: tylko dla klas rdzenia (OAuthFlow, GoogleClient). Zwracaja sekrety.
    // ------------------------------------------------------------------

    public function clientSecret(int $idConnection): string
    {
        return Crypto::decrypt((string) ($this->row($idConnection)['client_secret'] ?? ''), self::context($idConnection, 'client_secret'));
    }

    public function apiKey(int $idConnection): string
    {
        return Crypto::decrypt((string) ($this->row($idConnection)['api_key'] ?? ''), self::context($idConnection, 'api_key'));
    }

    /** Kontekst szyfrowania sekretu: pole w jednym polaczeniu. */
    public static function context(int $idConnection, string $field): string
    {
        return 'connection|' . $idConnection . '|' . $field;
    }

    /**
     * @return array{access:string,refresh:string,expires_at:?string}
     */
    public function tokens(int $idConnection): array
    {
        $row = $this->row($idConnection) ?? [];

        return [
            'access' => Crypto::decrypt((string) ($row['access_token'] ?? ''), self::context($idConnection, 'access_token')),
            'refresh' => Crypto::decrypt((string) ($row['refresh_token'] ?? ''), self::context($idConnection, 'refresh_token')),
            'expires_at' => !empty($row['access_expires_at']) ? (string) $row['access_expires_at'] : null,
        ];
    }

    /**
     * Zapis po udanym logowaniu albo odswiezeniu. Pusty `$refreshToken` zostawia dotychczasowy
     * (Google przysyla go tylko przy pierwszej zgodzie i przy `prompt=consent`).
     *
     * @param string[]|null $scopes null = bez zmian
     */
    public function saveTokens(int $idConnection, string $accessToken, int $expiresIn, string $refreshToken = '', ?array $scopes = null): void
    {
        $fields = [
            'access_token' => pSQL(Crypto::encrypt($accessToken, self::context($idConnection, 'access_token'))),
            'access_expires_at' => pSQL(Clock::at(max(0, $expiresIn))),
            'status' => Connection::STATUS_OK,
            'last_error' => '',
        ];

        if ($refreshToken !== '') {
            $fields['refresh_token'] = pSQL(Crypto::encrypt($refreshToken, self::context($idConnection, 'refresh_token')));
        }

        if ($scopes !== null) {
            $fields['scopes'] = pSQL(implode(' ', $scopes));
        }

        $this->update($idConnection, $fields);
    }

    /** Dane konta zapisane po udanym logowaniu. */
    public function saveAccount(int $idConnection, string $email, string $redirectUri): void
    {
        $row = $this->row($idConnection);

        $fields = [
            'google_email' => pSQL(mb_substr($email, 0, 255)),
            'redirect_uri' => pSQL(mb_substr($redirectUri, 0, 500)),
            'connected_at' => pSQL(Clock::now()),
        ];

        // Polaczenie bez nazwy dostaje e-mail konta — tak je potem widac w Settings.
        if ($row !== null && trim((string) $row['label']) === '' && $email !== '') {
            $fields['label'] = pSQL(mb_substr($email, 0, 120));
        }

        $this->update($idConnection, $fields);
    }

    public function markStatus(int $idConnection, string $status, string $error = ''): void
    {
        $this->update($idConnection, [
            'status' => pSQL($status),
            'last_error' => pSQL(mb_substr($error, 0, 500), true),
        ]);
    }

    /** @return array<string,mixed>|null */
    private function row(int $idConnection): ?array
    {
        if ($idConnection <= 0) {
            return null;
        }

        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . Schema::table(Schema::CONNECTION) . '` WHERE `id_connection` = ' . $idConnection,
            false
        );

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $fields wartosci juz zabezpieczone; `null` = NULL w bazie
     */
    private function update(int $idConnection, array $fields): void
    {
        $fields['date_upd'] = pSQL(Clock::now());

        // Nie przez `$null_values = true` w Db::update(): ten tryb zamienia na NULL takze
        // puste napisy, a kolumny tekstowe sa tu NOT NULL.
        foreach ($fields as $name => $value) {
            if ($value === null) {
                $fields[$name] = ['type' => 'sql', 'value' => 'NULL'];
            }
        }

        Db::getInstance()->update(Schema::name(Schema::CONNECTION), $fields, '`id_connection` = ' . $idConnection);
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): Connection
    {
        return new Connection(
            (int) $row['id_connection'],
            (string) $row['label'],
            (string) $row['client_id'],
            (string) $row['client_secret'] !== '',
            (string) $row['api_key'] !== '',
            (bool) $row['production_confirmed'],
            (string) $row['google_email'],
            Scopes::split([(string) $row['scopes']]),
            (string) $row['status'],
            (string) $row['last_error'],
            (string) $row['redirect_uri'],
            !empty($row['connected_at']) ? (string) $row['connected_at'] : null,
        );
    }
}
