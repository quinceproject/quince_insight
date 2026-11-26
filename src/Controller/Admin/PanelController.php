<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Controller\Admin;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShopBundle\Controller\Admin\PrestaShopAdminController;
use Quince\Insight\Core\Container;
use Quince\Insight\Core\Dev;
use Quince\Insight\Core\Google\GoogleApiException;
use Quince\Insight\Core\Google\GoogleAuthException;
use Quince\Insight\Core\Google\NotConnectedException;
use Quince\Insight\Core\ShopContext;
use Quince\Insight\Core\ShopRepository;
use Quince\Insight\Ui\PanelNavigation;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Wspolna podstawa kontrolerow panelu.
 *
 * Daje powloke (boczny pasek, nazwa sklepu), odpowiedzi JSON w jednym ksztalcie,
 * sprawdzenie CSRF i trzy reguly, o ktorych kontroler ekranu nie musi pamietac:
 *  - sklep bez ukonczonego kreatora -> przekierowanie do kreatora,
 *  - kontekst "wszystkie sklepy" -> prosba o wybranie sklepu (poza Overview, kreatorem i Support),
 *  - `layoutTitle` przetlumaczony (PrestaShop wstawia go do naglowka strony bez tlumaczenia).
 *
 * Zaleznosci powloki bierze z `Core\Container`, nie z konstruktora — klasa pochodna
 * moze miec dowolny wlasny konstruktor i nie musi niczego przekazywac wyzej.
 */
abstract class PanelController extends PrestaShopAdminController
{
    public const DOMAIN = 'Modules.Quinceinsight.Admin';

    /** Identyfikator tokenu CSRF wspolnego dla wywolan `QI.post` (naglowek X-CSRF-Token). */
    public const CSRF_PANEL = 'quince_insight_panel';

    protected const TEMPLATES = '@Modules/quince_insight/views/templates/admin/';

    /**
     * Ekrany, ktore maja sens bez wybranego sklepu — jak w prototypie (`get pg()`: overview,
     * settings, support). Pozostale pokazuja prosbe o wybor sklepu.
     */
    private const ALL_SHOPS_SCREENS = ['overview', 'settings', 'support', 'wizard', 'dev'];

    /** Sklep, ktorego dotyczy ekran: biezacy z przelacznika PrestaShop albo domyslny. */
    protected function idShop(): int
    {
        return ShopContext::currentOrDefault();
    }

    /**
     * Sklepy biezacego kontekstu pracownika (przelacznik sklepu; PrestaShop pozwala wybrac
     * tylko sklep / grupe, do ktorych pracownik ma dostep). Jak `Shop::getContextListShopID()`,
     * ale razem ze sklepami wylaczonymi (Settings pokazuje je w "wszystkie sklepy").
     *
     * @return int[]
     */
    protected function contextShopIds(): array
    {
        if (\Shop::getContext() === \Shop::CONTEXT_SHOP) {
            return [(int) \Shop::getContextShopID()];
        }

        if (\Shop::getContext() === \Shop::CONTEXT_GROUP) {
            return array_map('intval', \Shop::getShops(false, \Shop::getContextShopGroupID(), true));
        }

        return array_keys(ShopContext::shops());
    }

    /** Czy pracownik moze zmieniac to polaczenie Google (uzywa go sklep z jego kontekstu) — R-07. */
    protected function connectionInContext(int $idConnection): bool
    {
        return \Quince\Insight\Core\PanelAccess::connectionAllowed(
            $this->contextShopIds(),
            Container::get(ShopRepository::class)->shopsOfConnection($idConnection),
            array_keys(ShopContext::shops())
        );
    }

    /**
     * Renderuje szablon w powloce panelu (boczny pasek, naglowek, pasek akcji).
     *
     * @param string $template sciezka wzgledem `views/templates/admin/` (np. `analytics/index.html.twig`)
     *                         albo pelna nazwa zaczynajaca sie od `@`
     * @param string $navKey klucz pozycji bocznego paska: overview | analytics | events | merchant | gsc |
     *                       psi | schema | settings | support; `wizard` dla kreatora
     * @param array<string,mixed> $data zmienne szablonu; `layoutTitle` nadpisuje tytul strony PrestaShop
     */
    protected function renderPanel(string $template, string $navKey, array $data = []): Response
    {
        $allShops = ShopContext::current() === null;
        $idShop = $this->idShop();
        $navigation = Container::get(PanelNavigation::class);

        // Kreator jest per sklep: dopoki sklep go nie ukonczyl, kazdy jego ekran prowadzi do kreatora.
        // W kontekscie "wszystkie sklepy" nie ma jednego sklepu do sprawdzenia — decyduje pulpit.
        if ($navKey !== 'wizard' && $navKey !== 'dev' && !$allShops
            && !Container::get(ShopRepository::class)->isSetUp($idShop)) {
            return $this->redirectToRoute('quince_insight_wizard');
        }

        $title = (string) ($data['layoutTitle'] ?? ($navigation->label($navKey) ?: 'Quince Insight'));

        $shell = [
            'layoutTitle' => $this->trans($title, [], self::DOMAIN),
            'nav' => $allShops ? $navigation->items(null, $this->contextShopIds()) : $navigation->items($idShop),
            'navKey' => $navKey,
            'navTitle' => $navigation->label($navKey) ?: $title,
            'qcVersion' => $this->assetVersion(),
            'idShop' => $idShop,
            'shopName' => ShopContext::name($idShop),
            'multistore' => ShopContext::isMultistore(),
            'allShops' => $allShops,
            'fakeGoogle' => Dev::fakeGoogle(),
        ];

        // Ekran spoza listy moze sam zglosic, ze ma wersje dla wszystkich sklepow
        // (np. Settings z zakladka Shops): `'allowAllShops' => true` w danych.
        if ($allShops && !in_array($navKey, self::ALL_SHOPS_SCREENS, true) && empty($data['allowAllShops'])) {
            return $this->render(self::TEMPLATES . '_partials/choose_shop_screen.html.twig', $shell);
        }

        if ($template !== '' && $template[0] !== '@') {
            $template = self::TEMPLATES . $template;
        }

        // Zmienne powloki wygrywaja z danymi ekranu: boczny pasek nie moze zalezec od
        // tego, czy autor ekranu nie nadpisal przypadkiem `nav`.
        return $this->render($template, $shell + $data);
    }

    /**
     * Odpowiedz JSON udanej akcji: `{"ok":true, ...}`.
     *
     * @param array<string,mixed> $data
     */
    protected function ok(array $data = []): JsonResponse
    {
        // Kazda odpowiedz z zadaniem w tle (start, tick, status) ma przetlumaczone teksty.
        if (isset($data['job']) && is_array($data['job'])) {
            $data['job'] = $this->translatedJob($data['job']);
        }

        return new JsonResponse(['ok' => true] + $data);
    }

    /**
     * Stan zadania do przegladarki z przetlumaczonymi tekstami (komunikat, blad, lista krokow).
     * Uslugi skladaja teksty w PHP bez tlumacza (klasy logiki go nie maja), czesto jako
     * "zdanie — liczba"; tlumaczymy calosc, a gdy jej nie ma w katalogu — kawalki miedzy
     * separatorami ` — ` i ` · ` (fala 2, agent I).
     *
     * @param array<string,mixed> $job
     *
     * @return array<string,mixed>
     */
    protected function translatedJob(array $job): array
    {
        foreach (['message', 'error'] as $key) {
            if (isset($job[$key]) && is_string($job[$key]) && $job[$key] !== '') {
                $job[$key] = $this->trJob($job[$key]);
            }
        }

        if (isset($job['items']) && is_array($job['items'])) {
            foreach ($job['items'] as $i => $item) {
                // Wiersz z parametrami (`params`) tlumaczy sie w calosci, z podstawieniem.
                $withParams = is_array($item) && isset($item['text'], $item['params']) && is_string($item['text']) && is_array($item['params']) && $item['params'] !== [];

                if ($withParams) {
                    $job['items'][$i]['text'] = $this->t($item['text'], array_map('strval', $item['params']));
                    unset($job['items'][$i]['params']);
                }

                foreach (['text', 'note'] as $key) {
                    if ($key === 'text' && $withParams) {
                        continue;
                    }

                    if (is_array($item) && isset($item[$key]) && is_string($item[$key]) && $item[$key] !== '') {
                        $job['items'][$i][$key] = $this->trJob($item[$key]);
                    }
                }
            }
        }

        return $job;
    }

    private function trJob(string $text): string
    {
        $whole = $this->t($text);

        if ($whole !== $text) {
            return $whole;
        }

        $parts = preg_split('/( — | · )/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];

        if (count($parts) === 1) {
            return $text;
        }

        $out = '';

        for ($i = 0, $n = count($parts); $i < $n; $i += 2) {
            $piece = $parts[$i];
            $sep = $parts[$i + 1] ?? '';

            if ($sep !== '' && ($glued = $this->t($piece . $sep)) !== $piece . $sep) {
                $out .= $glued;

                continue;
            }

            $out .= ($piece === '' ? '' : $this->t($piece)) . $sep;
        }

        return $out;
    }

    /**
     * Odpowiedz JSON nieudanej akcji: `{"ok":false,"error":"..."}` z prawdziwym kodem HTTP.
     * `$message` jest pokazywany userowi (toast) — po angielsku, przetlumaczony przez `$this->t()`.
     *
     * @param array<string,mixed> $data
     */
    protected function fail(string $message, int $code = 400, array $data = []): JsonResponse
    {
        return new JsonResponse(['ok' => false, 'error' => $message] + $data, $code);
    }

    /**
     * Sprawdza token CSRF i przerywa zadanie (403), gdy jest zly.
     *
     * Przyjmuje dwa tokeny:
     *  - formularz: pole `_csrf_token` z `csrf_token('<nazwa trasy>')`,
     *  - `QI.post`: naglowek `X-CSRF-Token` z tokenem trasy albo z tokenem panelu
     *    (`csrf_token('quince_insight_panel')`, ktory layout wystawia skryptom — dzieki temu
     *    JS nie musi znac osobnego tokenu dla kazdego adresu).
     */
    protected function guardCsrf(Request $request, string $routeName): void
    {
        if (!$this->csrfValid($request, $routeName)) {
            throw new AccessDeniedHttpException('Invalid security token, please reload the page and try again.');
        }
    }

    /** Jak guardCsrf(), ale oddaje wynik — dla akcji formularzowych, ktore wola flash + przekierowanie. */
    protected function csrfValid(Request $request, string $routeName): bool
    {
        $field = $request->request->get('_csrf_token');

        if (is_string($field) && $field !== '') {
            return $this->isCsrfTokenValid($routeName, $field);
        }

        $header = (string) $request->headers->get('X-CSRF-Token', '');

        if ($header === '') {
            return false;
        }

        return $this->isCsrfTokenValid($routeName, $header) || $this->isCsrfTokenValid(self::CSRF_PANEL, $header);
    }

    /**
     * Dane zadania POST niezaleznie od sposobu wyslania: JSON z `QI.post` albo pola formularza.
     *
     * @return array<string,mixed>
     */
    protected function payload(Request $request): array
    {
        if (str_contains((string) $request->headers->get('Content-Type', ''), 'json')) {
            $data = json_decode((string) $request->getContent(), true);

            return is_array($data) ? $data : [];
        }

        return $request->request->all();
    }

    /** Tlumaczenie w domenie modulu. Tylko literaly jako `$text`; dane w `$params` (`%nazwa%`). */
    protected function t(string $text, array $params = []): string
    {
        return $this->trans($text, $params, self::DOMAIN);
    }

    /**
     * Zamienia wyjatki Google na czytelny komunikat dla usera (limit, brak uprawnien, brak polaczenia).
     * Komunikat jest juz przetlumaczony; szczegoly techniczne zostaja w dzienniku.
     */
    protected function googleError(\Throwable $e): string
    {
        if ($e instanceof NotConnectedException) {
            return $this->t('This shop is not connected to Google yet. Connect a Google account in Settings.');
        }

        if ($e instanceof GoogleAuthException) {
            // Odmowa po odswiezeniu tokenu: zwykle wygasle logowanie, ale Google uzywa 401 tez
            // dla innych przyczyn — tresc odpowiedzi pokazuje, ktora to jest.
            $detail = trim($e->getMessage());

            return $this->t('The Google account needs to be connected again. Open Settings and sign in with Google.')
                . ($detail !== '' && $e->reason() !== 'invalid_grant' ? ' — Google: ' . $detail : '');
        }

        if ($e instanceof GoogleApiException) {
            if ($e->isApiDisabled()) {
                return $this->t('This Google API is not enabled in your Google Cloud project. Enable it in the API library and try again.');
            }

            if ($e->reason() === 'GCP_NOT_REGISTERED') {
                return $this->t('Your Google Cloud project is not registered with Merchant Center yet.');
            }

            if ($e->isDailyQuota()) {
                return $this->t('The daily Google limit has been reached. Try again tomorrow.');
            }

            if ($e->isQuota()) {
                return $this->t('Google is limiting requests right now. Wait a moment and try again.');
            }

            if ($e->isAuth()) {
                return $this->t('Google refused access. Check that the connected account has permission to this resource.');
            }

            if ($e->httpCode() === 404) {
                return $this->t('Google could not find this resource. It may have been removed.');
            }

            if ($e->httpCode() === 0 || $e->httpCode() >= 500) {
                return $this->t('Google did not respond. Try again in a moment.');
            }

            // Komunikat Google dla bledow danych (400) mowi userowi, co poprawic — idzie doslownie.
            return $this->t('Google returned an error: %message%', ['%message%' => $e->getMessage()]);
        }

        return $this->t('Something went wrong. Details are in the module log.');
    }

    /**
     * Znacznik wersji przy plikach CSS i JS: wersja modulu + czas zmiany najswiezszego pliku.
     *
     * Sama wersja nie wystarcza — sklep z wylaczonym trybem debugowania trzymalby arkusz
     * w przegladarce do nastepnego wydania.
     */
    private function assetVersion(): string
    {
        $module = \Module::getInstanceByName('quince_insight');
        $version = $module instanceof \Module ? (string) $module->version : '0.1.0';
        $newest = 0;

        // Bez GLOB_BRACE: tej stalej nie ma w PHP zbudowanym z musl (obrazy Alpine).
        foreach (['views/css', 'views/css/tokens', 'views/css/pages', 'views/js', 'views/js/admin'] as $dir) {
            foreach (['css', 'js'] as $ext) {
                foreach (glob(_PS_MODULE_DIR_ . 'quince_insight/' . $dir . '/*.' . $ext) ?: [] as $file) {
                    $newest = max($newest, (int) filemtime($file));
                }
            }
        }

        return $version . '.' . $newest;
    }
}
