<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Core;

/**
 * Przelaczniki deweloperskie z pliku `config/dev.php`.
 *
 * Plik nie trafia do paczki wydania (`build-package.php` go pomija), wiec w sklepie
 * produkcyjnym zadnego z tych trybow nie da sie wlaczyc — ani z panelu, ani adresem.
 * Klasa mieszka w `src/`, bo glowny plik modulu nie jest ladowany w kontekscie Symfony.
 */
final class Dev
{
    private static bool $loaded = false;

    /** Czy istnieje plik `config/dev.php` (czyli czy to instalacja deweloperska). */
    public static function enabled(): bool
    {
        return is_file(self::file());
    }

    /** Tryb atrap Google: zadnego ruchu do sieci, odpowiedzi z `tests/fixtures/google/`. */
    public static function fakeGoogle(): bool
    {
        self::load();

        return defined('QUINCE_INSIGHT_FAKE_GOOGLE') && (bool) constant('QUINCE_INSIGHT_FAKE_GOOGLE');
    }

    /** Obejscie przekierowania do kreatora, zeby dalo sie otwierac ekrany przed jego ukonczeniem. */
    public static function skipWizard(): bool
    {
        self::load();

        return defined('QUINCE_INSIGHT_SKIP_WIZARD') && (bool) constant('QUINCE_INSIGHT_SKIP_WIZARD');
    }

    /**
     * Hooki (front i panel) takze w sklepie bez ukonczonego kreatora.
     *
     * Osobno od `skipWizard()`: obejscie kreatora otwiera ekrany panelu i crony, ale
     * nie moze samo z siebie wpuscic modulu na strone sklepu. Agent pracujacy nad
     * hookami wlacza to swiadomie.
     */
    public static function hooksWithoutWizard(): bool
    {
        self::load();

        return defined('QUINCE_INSIGHT_DEV_HOOKS') && (bool) constant('QUINCE_INSIGHT_DEV_HOOKS');
    }

    /**
     * Klucz uslugi (ga4, gtm, gmc, gsc, verify), ktorej uprawnienie atrapa logowania
     * ma "odznaczyc" — symulacja zgody czesciowej. Pusty napis = wszystko przyznane.
     */
    public static function denyScope(): string
    {
        self::load();

        return defined('QUINCE_INSIGHT_FAKE_DENY_SCOPE') ? (string) constant('QUINCE_INSIGHT_FAKE_DENY_SCOPE') : '';
    }

    /** Katalog atrap odpowiedzi Google, zakonczony ukosnikiem. */
    public static function fixturesDir(): string
    {
        return dirname(__DIR__, 2) . '/tests/fixtures/google/';
    }

    private static function file(): string
    {
        return dirname(__DIR__, 2) . '/config/dev.php';
    }

    private static function load(): void
    {
        if (self::$loaded) {
            return;
        }

        self::$loaded = true;

        if (is_file(self::file())) {
            require_once self::file();
        }
    }
}
