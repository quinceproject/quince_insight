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

/**
 * Tytul i opis oferty — czysta logika (przeniesione z FeedForge `DataMapper::buildTitle()`
 * i `buildDescription()`, research/02 §1.5).
 */
final class OfferText
{
    public const TITLE_MAX = 150;
    public const DESCRIPTION_MAX = 5000;

    /**
     * Szablon tytulu z prototypu: {name}, {brand}, {category}, {reference} (+ {ean} z FeedForge).
     * Kombinacja dostaje na koncu " - wartosc1, wartosc2", zeby oferty grupy roznily sie tytulem.
     *
     * @param array<string,string> $vars
     * @param string[] $variantValues
     */
    public static function title(string $template, array $vars, array $variantValues = []): string
    {
        $template = trim($template) !== '' ? $template : '{name}';
        $title = strtr($template, [
            '{name}' => $vars['name'] ?? '',
            '{brand}' => $vars['brand'] ?? '',
            '{category}' => $vars['category'] ?? '',
            '{reference}' => $vars['reference'] ?? '',
            '{ean}' => $vars['ean'] ?? '',
        ]);

        $values = array_values(array_filter(array_map('trim', $variantValues), static fn (string $v): bool => $v !== ''));

        if ($values) {
            $title = rtrim($title) . ' - ' . implode(', ', $values);
        }

        // Pusta zmienna zostawia podwojne spacje albo wiszace separatory.
        $title = trim((string) preg_replace('/\s{2,}/u', ' ', $title), " \t\n\r\0\x0B-|·");

        return mb_substr($title, 0, self::TITLE_MAX);
    }

    /** Opis z wybranego zrodla, bez HTML, z zachowanymi akapitami, do 5000 znakow. */
    public static function description(string $source, string $short, string $long): string
    {
        $text = match ($source) {
            'description' => $long !== '' ? $long : $short,
            'both' => trim(self::plain($short) . "\n\n" . self::plain($long)),
            default => $short !== '' ? $short : $long,
        };

        return mb_substr(self::plain($text), 0, self::DESCRIPTION_MAX);
    }

    public static function plain(string $html): string
    {
        $text = (string) preg_replace('#<(br|/p|/div|/li|/h[1-6])\b[^>]*>#i', "\n", $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace("/[ \t\x{00A0}]+/u", ' ', $text);
        $text = (string) preg_replace("/\n\s*\n\s*(\n\s*)+/", "\n\n", $text);

        return trim((string) preg_replace("/ *\n */", "\n", $text));
    }
}
