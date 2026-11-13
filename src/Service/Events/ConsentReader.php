<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\Events;

/**
 * Stan zgod biezacego odwiedzajacego po stronie serwera (zadanie z przegladarki klienta).
 *
 * Quince CMP pytamy przez jego API (`allowed()` / `consentState()`), nigdy nie czytamy
 * jego ciasteczka (Quince CMP API.md: „nobody reads the quince_cmp cookie on their own”).
 */
final class ConsentReader
{
    public function __construct(private readonly EventsSettings $settings)
    {
    }

    /** Czy w sklepie dziala Quince CMP (wlaczony modul z metoda consentState). */
    public static function qcmpAvailable(): bool
    {
        if (!class_exists(\Module::class) || !\Module::isEnabled('quince_cmp')) {
            return false;
        }

        $cmp = \Module::getInstanceByName('quince_cmp');

        return is_object($cmp) && method_exists($cmp, 'consentState');
    }

    /**
     * Zrodlo zgod, ktore naprawde obowiazuje: wybor `qcmp` bez modulu w sklepie dziala jak `none`.
     */
    public function effectiveSource(int $idShop): string
    {
        $source = $this->settings->consentSource($idShop);

        if ($source === 'qcmp' && !self::qcmpAvailable()) {
            return 'none';
        }

        if ($source === 'other' && $this->settings->consentCookie($idShop) === '') {
            return 'none';
        }

        return $source;
    }

    /**
     * Cztery sygnaly Consent Mode dla biezacego zadania.
     *
     * @param array<string,mixed>|null $cookies domyslnie $_COOKIE
     *
     * @return array<string,string>
     */
    public function current(int $idShop, ?array $cookies = null): array
    {
        $cookies ??= $_COOKIE;
        $defaults = $this->settings->consentDefaults($idShop);

        switch ($this->effectiveSource($idShop)) {
            case 'qcmp':
                try {
                    $state = \Module::getInstanceByName('quince_cmp')->consentState($idShop);
                    $signals = is_array($state['signals'] ?? null) ? $state['signals'] : [];

                    return ConsentMapper::normalize($signals);
                } catch (\Throwable $e) {
                    return ConsentMapper::normalize($defaults);
                }
            case 'other':
                $name = $this->settings->consentCookie($idShop);
                $raw = $cookies[$name] ?? null;

                return ConsentMapper::signalsFromCategories(
                    ConsentMapper::parseCookie(is_string($raw) ? $raw : null),
                    $this->settings->consentMap($idShop),
                    $defaults
                );
            default:
                return ConsentMapper::normalize($defaults);
        }
    }
}
