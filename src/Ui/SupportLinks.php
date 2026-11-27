<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Ui;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Adresy ekranu „Support”: wsparcie projektu, strona projektu i zgloszenia
 * w repozytorium modulu. Te same adresy co `Quince\Engine\Ui\SupportLinks`,
 * ale wlasna kopia, bo modul dziala bez silnika.
 */
final class SupportLinks
{
    /** Strona wsparcia projektu na Ko-fi. */
    public const KOFI = 'https://ko-fi.com/quinceproject';

    /** Strona calego projektu. */
    public const PROJECT = 'https://quinceproject.github.io/';

    /** Zgloszenia w repozytorium modulu. */
    public const ISSUES = 'https://github.com/quinceproject/quince_insight/issues';
}
