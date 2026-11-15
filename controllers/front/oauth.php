<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

use Quince\Insight\Core\Container;
use Quince\Insight\Core\Google\OAuthFlow;

/**
 * Adres zwrotny logowania Google.
 *
 * Staly adres do wpisania w Google Cloud: bez katalogu panelu i bez `_token`, wiec
 * niezalezny od pracownika i sesji. Wejscie chroni jednorazowy `state` zapisany przez
 * panel przed wyjsciem do Google; nic tu nie czyta sesji klienta sklepu.
 *
 * Powrot do panelu to strona HTML z `location.replace()`, nie naglowek `Location`:
 * przy ciasteczku panelu `SameSite=Strict` lancuch przekierowan zaczety w Google
 * liczy sie jako miedzywitrynowy i pracownik wyladowalby na ekranie logowania.
 * Adres powrotu pochodzi wylacznie z wpisu `state` w bazie.
 */
class Quince_InsightOauthModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $auth = false;

    /** @var bool */
    public $ajax = true;

    public function postProcess(): void
    {
        try {
            $returnUrl = Container::get(OAuthFlow::class)->handleCallback([
                'state' => (string) Tools::getValue('state', ''),
                'code' => (string) Tools::getValue('code', ''),
                'error' => (string) Tools::getValue('error', ''),
            ]);
        } catch (Throwable $e) {
            // Nieznany, przeterminowany albo otwarty w innej przegladarce `state`:
            // nie wiemy, dokad wrocic, wiec konczymy tutaj zwyklym komunikatem.
            $this->strona(400, '<p>This sign-in link has expired, was already used, or was opened in a '
                . 'different browser than the one that started it. Go back to the shop admin and start '
                . 'the Google sign-in again.</p>');
        }

        $adres = json_encode($returnUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);

        $this->strona(200, '<p>Returning to the shop admin…</p>'
            . '<script>window.location.replace(' . $adres . ');</script>'
            . '<noscript><p><a href="' . htmlspecialchars($returnUrl, ENT_QUOTES, 'UTF-8') . '">Continue</a></p></noscript>');
    }

    /** Polaczenie konta ma dzialac takze przy sklepie w trybie konserwacji. */
    protected function displayMaintenancePage()
    {
    }

    private function strona(int $status, string $tresc): void
    {
        header('Content-Type: text/html; charset=utf-8', true, $status);
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
        header('Referrer-Policy: no-referrer');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex">'
            . '<title>Quince Insight</title></head><body style="font-family:sans-serif;padding:32px">'
            . $tresc . '</body></html>';
        exit;
    }
}
