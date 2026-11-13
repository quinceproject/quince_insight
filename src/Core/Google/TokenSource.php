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

/**
 * Skad `GoogleClient` bierze token dostepu. W module: `OAuthFlow` dla polaczenia sklepu;
 * w testach: podmiana liczaca odswiezenia.
 */
interface TokenSource
{
    /** Wazny token (odswiezony z wyprzedzeniem, jesli trzeba). */
    public function accessToken(): string;

    /** Wymusza odswiezenie — po odpowiedzi 401. Rzuca GoogleAuthException. */
    public function refresh(): string;
}
