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

// Autoloader Composera, gdy modul jedzie z `vendor/`; wlasny PSR-4, gdy nie.
// Zapasowy ma znaczenie w pracy nad modulem: zmiany w `src/` lapia sie bez
// przepuszczania wszystkiego przez Composera.
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Quince\\Insight\\';

        if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
            return;
        }

        // Zaslepki `index.php` (paczka wydania ma je w kazdym katalogu) nie sa klasami —
        // Symfony, skanujac `src/`, pyta o klase `...\index` i zaslepka konczyla zadanie przekierowaniem.
        if (substr($class, -6) === '\\index') {
            return;
        }

        $sciezka = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

        if (is_file($sciezka)) {
            require_once $sciezka;
        }
    });
}

use Quince\Insight\Core\Container;
use Quince\Insight\Core\HookRouter;
use Quince\Insight\Core\Installer;
use Quince\Insight\Core\Log;
use Quince\Insight\Core\QuinceCmp;
use Quince\Insight\Core\ShopLifecycle;

/**
 * Quince Insight — uslugi Google dla sklepu w jednym miejscu.
 *
 * Analytics, Tag Manager, Merchant Center, Search Console, Page speed i dane
 * strukturalne, z kreatorem prowadzacym przez polaczenie z Google.
 *
 * Ten plik robi tylko to, czego wymaga PrestaShop: instalacja, zakladki, rejestracja
 * hookow. Kazda metoda hooka oddaje sterowanie do `Core\HookRouter`, a ten do uslug —
 * usluga dopina sie w swojej klasie `Service\<Nazwa>\Module`, nie tutaj.
 *
 * Instalacja niczego nie wlacza i niczego nie zmienia na stronie sklepu: tag weryfikacji,
 * snippet Tag Managera, JSON-LD i reszta pojawiaja sie dopiero po swiadomym wlaczeniu
 * w kreatorze albo w panelu.
 */
class Quince_Insight extends Module
{
    /** Zakladka-kontener w menu PrestaShopa. Jedyna widoczna pozycja modulu. */
    public const TAB_PARENT = 'AdminQuinceInsight';

    /**
     * Ekrany modulu jako ukryte zakladki podrzedne — nosniki uprawnien dla tras
     * (`_legacy_controller` w `config/routes.yml`). Kazda ma klase w `controllers/admin/`.
     *
     * @var array<int,array{class_name:string,name:string}>
     */
    private const TABS = [
        ['class_name' => 'AdminQuinceInsightOverview', 'name' => 'Overview'],
        ['class_name' => 'AdminQuinceInsightWizard', 'name' => 'Setup'],
        ['class_name' => 'AdminQuinceInsightAnalytics', 'name' => 'Analytics'],
        ['class_name' => 'AdminQuinceInsightEvents', 'name' => 'Events'],
        ['class_name' => 'AdminQuinceInsightMerchant', 'name' => 'Merchant Center'],
        ['class_name' => 'AdminQuinceInsightSearchConsole', 'name' => 'Search Console'],
        ['class_name' => 'AdminQuinceInsightPageSpeed', 'name' => 'Page speed'],
        ['class_name' => 'AdminQuinceInsightStructuredData', 'name' => 'Structured data'],
        ['class_name' => 'AdminQuinceInsightSettings', 'name' => 'Settings'],
        ['class_name' => 'AdminQuinceInsightSupport', 'name' => 'Support'],
    ];

    /**
     * Hooki z ARCHITEKTURA §8. Wszystkie rejestrowane przy instalacji; hook dodany
     * w pozniejszej wersji trzeba zarejestrowac takze w skrypcie `upgrade/`, bo
     * `install()` wykonuje sie tylko raz.
     *
     * @var string[]
     */
    private const HOOKS = [
        'displayHeader',
        'displayAfterBodyOpeningTag',
        'displayBeforeBodyClosingTag',
        'actionFrontControllerSetMedia',
        'displayOrderConfirmation',
        'actionValidateOrder',
        'actionOrderStatusPostUpdate',
        'actionOrderSlipAdd',
        'actionAuthentication',
        'actionCustomerAccountAdd',
        'actionProductSave',
        'actionProductUpdate',
        'actionProductDelete',
        'actionUpdateQuantity',
        'actionObjectSpecificPriceAddAfter',
        'actionObjectSpecificPriceUpdateAfter',
        'actionObjectSpecificPriceDeleteAfter',
        'actionAttributeCombinationSave',
        'actionAttributeCombinationDelete',
        'actionObjectCategoryUpdateAfter',
        'actionObjectCmsUpdateAfter',
        'actionObjectCartRuleUpdateAfter',
        'actionObjectCartRuleDeleteAfter',
        'actionObjectShopAddAfter',
        'actionObjectShopDeleteAfter',
        'moduleRoutes',
        'displayBackOfficeHeader',
        'actionAdminMetaAfterWriteRobotsFile',
        'actionModuleRegisterHookAfter',
    ];

    public function __construct()
    {
        $this->name = 'quince_insight';
        $this->tab = 'analytics_stats';
        $this->version = '0.1.2';
        $this->author = 'Quince Project';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '9.0.2', 'max' => '9.99.99'];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('Quince Insight', [], 'Modules.Quinceinsight.Admin');
        $this->description = $this->trans(
            'Connects the shop to Google in one place: Analytics, Tag Manager, Merchant Center, Search Console, page speed and structured data.',
            [],
            'Modules.Quinceinsight.Admin'
        );
        $this->confirmUninstall = $this->trans(
            'Uninstall the module? Its settings, logs and sitemap files are removed from the shop. Nothing is removed at Google.',
            [],
            'Modules.Quinceinsight.Admin'
        );
    }

    /** Teksty modulu sa w plikach XLF (`translations/`), nie w starych plikach PHP. */
    public function isUsingNewTranslationSystem(): bool
    {
        return true;
    }

    public function install(): bool
    {
        if (!parent::install()
            || !Container::get(Installer::class)->install()
            || !$this->installTabs()) {
            return false;
        }

        foreach (self::HOOKS as $hook) {
            if (!$this->registerHook($hook)) {
                return false;
            }
        }

        $this->placeHeaderAfterCmp();

        return true;
    }

    /**
     * `displayHeader` tego modulu za `quince_cmp` (decyzja #8a): kontener Tag Managera
     * nie moze wyjsc przed deklaracja zgod Consent Mode. Blad nie przerywa instalacji.
     */
    private function placeHeaderAfterCmp(): void
    {
        try {
            Container::get(QuinceCmp::class)->placeAfterCmp();
        } catch (\Throwable $e) {
            Log::write(0, 'core', Log::WARNING, 'Could not place displayHeader after Quince CMP: ' . $e->getMessage());
        }
    }

    /**
     * Odinstalowanie usuwa tabele, ustawienia, pliki map i zakladki.
     * W Google niczego nie usuwa.
     */
    public function uninstall(): bool
    {
        $this->uninstallTabs();

        return Container::get(Installer::class)->uninstall()
            && $this->usunUstawienia()
            && parent::uninstall();
    }

    /** Przekierowuje przycisk "Konfiguruj" na pulpit modulu. */
    public function getContent(): void
    {
        Tools::redirectAdmin(
            $this->get('router')->generate('quince_insight_overview')
        );
    }

    // ------------------------------------------------------------------
    // Hooki. Kazda metoda tylko wola rozdzielacz; logika jest w uslugach.
    // ------------------------------------------------------------------

    /** @param array<string,mixed> $params */
    public function hookDisplayHeader(array $params): string
    {
        return HookRouter::dispatch('displayHeader', $params);
    }

    /** @param array<string,mixed> $params */
    public function hookDisplayAfterBodyOpeningTag(array $params): string
    {
        return HookRouter::dispatch('displayAfterBodyOpeningTag', $params);
    }

    /** @param array<string,mixed> $params */
    public function hookDisplayBeforeBodyClosingTag(array $params): string
    {
        return HookRouter::dispatch('displayBeforeBodyClosingTag', $params);
    }

    /** @param array<string,mixed> $params */
    public function hookActionFrontControllerSetMedia(array $params): void
    {
        HookRouter::dispatch('actionFrontControllerSetMedia', $params);
    }

    /** @param array<string,mixed> $params */
    public function hookDisplayOrderConfirmation(array $params): string
    {
        return HookRouter::dispatch('displayOrderConfirmation', $params);
    }

    /** @param array<string,mixed> $params */
    public function hookActionValidateOrder(array $params): void
    {
        HookRouter::dispatch('actionValidateOrder', $params);
    }

    /** @param array<string,mixed> $params */
    public function hookActionOrderStatusPostUpdate(array $params): void
    {
        HookRouter::dispatch('actionOrderStatusPostUpdate', $params);
    }

    /** @param array<string,mixed> $params */
    public function hookActionOrderSlipAdd(array $params): void
    {
        HookRouter::dispatch('actionOrderSlipAdd', $params);
    }

    /** @param array<string,mixed> $params */
    public function hookActionAuthentication(array $params): void
    {
        HookRouter::dispatch('actionAuthentication', $params);
    }

    /** @param array<string,mixed> $params */
    public function hookActionCustomerAccountAdd(array $params): void
    {
        HookRouter::dispatch('actionCustomerAccountAdd', $params);
    }

    /** @param array<string,mixed> $params */
    public function hookActionProductSave(array $params): void
    {
        HookRouter::dispatch('actionProductSave', $params);
    }

    /** @param array<string,mixed> $params */
    public function hookActionProductUpdate(array $params): void
    {
        HookRouter::dispatch('actionProductUpdate', $params);
    }

    /** @param array<string,mixed> $params */
    public function hookActionProductDelete(array $params): void
    {
        HookRouter::dispatch('actionProductDelete', $params);
    }

    /** @param array<string,mixed> $params */
    public function hookActionUpdateQuantity(array $params): void
    {
        HookRouter::dispatch('actionUpdateQuantity', $params);
    }

    /** @param array<string,mixed> $params */
    public function hookActionObjectSpecificPriceAddAfter(array $params): void
    {
        HookRouter::dispatch('actionObjectSpecificPriceAddAfter', $params);
    }

    /** @param array<string,mixed> $params */
    public function hookActionObjectSpecificPriceUpdateAfter(array $params): void
    {
        HookRouter::dispatch('actionObjectSpecificPriceUpdateAfter', $params);
    }

    /** @param array<string,mixed> $params */
    public function hookActionObjectSpecificPriceDeleteAfter(array $params): void
    {
        HookRouter::dispatch('actionObjectSpecificPriceDeleteAfter', $params);
    }

    /** @param array<string,mixed> $params */
    public function hookActionAttributeCombinationSave(array $params): void
    {
        HookRouter::dispatch('actionAttributeCombinationSave', $params);
    }

    /** @param array<string,mixed> $params */
    public function hookActionAttributeCombinationDelete(array $params): void
    {
        HookRouter::dispatch('actionAttributeCombinationDelete', $params);
    }

    /** @param array<string,mixed> $params */
    public function hookActionObjectCategoryUpdateAfter(array $params): void
    {
        HookRouter::dispatch('actionObjectCategoryUpdateAfter', $params);
    }

    /** @param array<string,mixed> $params */
    public function hookActionObjectCmsUpdateAfter(array $params): void
    {
        HookRouter::dispatch('actionObjectCmsUpdateAfter', $params);
    }

    /** @param array<string,mixed> $params */
    public function hookActionObjectCartRuleUpdateAfter(array $params): void
    {
        HookRouter::dispatch('actionObjectCartRuleUpdateAfter', $params);
    }

    /** @param array<string,mixed> $params */
    public function hookActionObjectCartRuleDeleteAfter(array $params): void
    {
        HookRouter::dispatch('actionObjectCartRuleDeleteAfter', $params);
    }

    /**
     * Przyjazne adresy modulu (pliki mapy witryny).
     *
     * @param array<string,mixed> $params
     *
     * @return array<string,mixed>
     */
    public function hookModuleRoutes(array $params): array
    {
        return HookRouter::collect('moduleRoutes', $params);
    }

    /**
     * Na cudzych ekranach panelu modul nie dodaje nic. Zasoby wlasnych ekranow
     * laduje `layout.html.twig`; hook zostaje dla uslug, ktore beda go potrzebowac.
     *
     * @param array<string,mixed> $params
     */
    public function hookDisplayBackOfficeHeader(array $params): string
    {
        return HookRouter::dispatch('displayBackOfficeHeader', $params);
    }

    /**
     * Zapis robots.txt przez PrestaShop (Ustawienia SEO -> "Generuj robots.txt"): usluga
     * Search Console dopisuje linie `Sitemap:` — tylko gdy user wlaczyl to swiadomie.
     * Uchwyt pliku (`write_fd`) przechodzi w tablicy bez kopiowania zasobu.
     *
     * @param array<string,mixed> $params
     */
    public function hookActionAdminMetaAfterWriteRobotsFile(array $params): void
    {
        HookRouter::dispatch('actionAdminMetaAfterWriteRobotsFile', $params);
    }

    /**
     * Quince CMP (instalacja albo reset) podpial `displayHeader` — PrestaShop stawia go na
     * koncu, czyli za tym modulem. Przestawiamy sie za niego (decyzja #8a). Nic na stronie
     * sklepu i nic w uslugach — dlatego bez rozdzielacza hookow.
     *
     * @param array{object?:object,hook_name?:string} $params
     */
    public function hookActionModuleRegisterHookAfter(array $params): void
    {
        $module = $params['object'] ?? null;

        if (is_object($module) && ($module->name ?? '') === QuinceCmp::MODULE
            && strtolower((string) ($params['hook_name'] ?? '')) === strtolower(QuinceCmp::HOOK)) {
            $this->placeHeaderAfterCmp();
        }
    }

    /**
     * Nowy sklep dostaje wiersz z nieukonczonym kreatorem — nic z modulu w nim nie
     * dziala, dopoki user nie przejdzie kreatora.
     *
     * @param array{object?:object} $params
     */
    public function hookActionObjectShopAddAfter(array $params): void
    {
        $sklep = $params['object'] ?? null;

        if (!$sklep instanceof Shop || !$sklep->id) {
            return;
        }

        try {
            Container::get(ShopLifecycle::class)->onShopAdded((int) $sklep->id);
        } catch (Throwable $e) {
            // Blad modulu nie moze przerwac zakladania sklepu w PrestaShop.
        }
    }

    /**
     * Sklep usuniety: dane lokalne znikaja, w Google nic nie jest usuwane, a na
     * pulpicie zostaje informacja, co tam zostalo.
     *
     * @param array{object?:object} $params
     */
    public function hookActionObjectShopDeleteAfter(array $params): void
    {
        $sklep = $params['object'] ?? null;

        if (!$sklep instanceof Shop || !$sklep->id) {
            return;
        }

        try {
            Container::get(ShopLifecycle::class)->onShopDeleted((int) $sklep->id, (string) $sklep->name);
        } catch (Throwable $e) {
            // Jak wyzej: usuniecie sklepu w PrestaShop ma sie udac niezaleznie od modulu.
        }
    }

    // ------------------------------------------------------------------
    // Zakladki
    // ------------------------------------------------------------------

    /**
     * Zaklada zakladke-kontener i ukryte zakladki ekranow.
     *
     * Zakladki tworzone sa obiektami `Tab`, a nie przez wlasciwosc `$this->tabs`:
     * te obsluguje usluga `ModuleTabRegister`, uruchamiana wylacznie w przeplywie
     * menedzera modulow, przez co instalacja z linii polecen pomijalaby menu.
     */
    private function installTabs(): bool
    {
        $idRodzica = $this->idKontenera();

        if ($idRodzica === 0) {
            return false;
        }

        $jezyki = Language::getLanguages(false);

        foreach (self::TABS as $opis) {
            $istniejaca = (int) Tab::getIdFromClassName($opis['class_name']);

            // Zakladka pozostala po wczesniejszej instalacji jest aktualizowana,
            // nie duplikowana — PrestaShop dopuszcza wiele wpisow o tej samej klasie.
            $tab = $istniejaca ? new Tab($istniejaca) : new Tab();

            // Zakladka istnieje, ale nie pokazuje sie w menu. Potrzebna jest wylacznie
            // jako wieszak na uprawnienia: atrybuty `#[AdminSecurity]` pytaja o prawa
            // do `_legacy_controller`, a te siedza w rolach wypisanych przy zakladce.
            $tab->active = 0;
            $tab->class_name = $opis['class_name'];
            $tab->module = $this->name;
            $tab->id_parent = $idRodzica;

            foreach ($jezyki as $jezyk) {
                $tab->name[(int) $jezyk['id_lang']] = $opis['name'];
            }

            if (!($istniejaca ? $tab->save() : $tab->add())) {
                return false;
            }
        }

        return true;
    }

    /**
     * Zwraca identyfikator zakladki-kontenera, zakladajac ja w razie potrzeby.
     */
    private function idKontenera(): int
    {
        $istniejacy = (int) Tab::getIdFromClassName(self::TAB_PARENT);

        if ($istniejacy) {
            return $istniejacy;
        }

        $kontener = new Tab();
        // Widoczny. To jedyna pozycja modulu w menu sklepu; ekrany pod nia zostaja
        // ukryte, bo modul ma wlasny boczny pasek.
        $kontener->active = 1;
        $kontener->route_name = 'quince_insight_overview';
        $kontener->class_name = self::TAB_PARENT;
        $kontener->id_parent = $this->idGalezi();
        $kontener->module = '';

        if (property_exists($kontener, 'icon')) {
            $kontener->icon = 'insights';
        }

        foreach (Language::getLanguages(false) as $jezyk) {
            $kontener->name[(int) $jezyk['id_lang']] = 'Quince Insight';
        }

        return $kontener->add() ? (int) $kontener->id : 0;
    }

    /**
     * Galaz menu, pod ktora wieszamy modul — jak w Quince CMP.
     *
     * Najpierw "Quince project" silnika, zeby moduly Quince staly razem. Gdy silnika
     * w sklepie nie ma, zostaje sekcja "Ulepszanie". Kopia z przyrostkiem `_MTR` idzie
     * pierwsza: gdy do galezi trafia pierwsze dziecko, PrestaShop kopiuje rodzica i to
     * kopia staje sie kontenerem (`ModuleTabRegister::duplicateParentIfAlone`).
     */
    private function idGalezi(): int
    {
        foreach (['AdminQuinceProject_MTR', 'AdminQuinceProject', 'IMPROVE', 'AdminParentModulesSf'] as $klasa) {
            $id = (int) Tab::getIdFromClassName($klasa);

            if ($id > 0) {
                return $id;
            }
        }

        return 0;
    }

    /**
     * Usuwa zakladki modulu. Kontener usuwany jest tylko wtedy, gdy nie pozostala
     * pod nim zadna pozycja.
     */
    private function uninstallTabs(): bool
    {
        foreach (self::TABS as $opis) {
            $id = (int) Tab::getIdFromClassName($opis['class_name']);

            if ($id) {
                (new Tab($id))->delete();
            }
        }

        $idRodzica = (int) Tab::getIdFromClassName(self::TAB_PARENT);

        if ($idRodzica && !Tab::getTabs((int) Configuration::get('PS_LANG_DEFAULT'), $idRodzica)) {
            (new Tab($idRodzica))->delete();
        }

        return true;
    }

    /**
     * Ustawienia modulu leza we wlasnej tabeli i znikaja razem z nia. Tu sprzatamy
     * tylko to, co mogloby trafic do `ps_configuration` pod naszym przedrostkiem —
     * zeby po odinstalowaniu na pewno nie zostalo nic.
     */
    private function usunUstawienia(): bool
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT DISTINCT `name` FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` LIKE "QUINCE\_INSIGHT\_%"'
        ) ?: [];

        foreach ($wiersze as $wiersz) {
            Configuration::deleteByName((string) $wiersz['name']);
        }

        return true;
    }
}
