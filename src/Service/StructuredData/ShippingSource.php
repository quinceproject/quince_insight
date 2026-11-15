<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\StructuredData;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Quince\Insight\Core\Container;

/**
 * Dane wysylki i zwrotow do JSON-LD — te same, ktore modul wysyla do Merchant Center
 * (PLAN-BUDOWY §5: H czyta ustawienia E/M, bez wywolan Google).
 *
 * Zrodlo: klasa uslugi Merchant Center — narzedzia (agent M)
 * `Quince\Insight\Service\MerchantTools\ShippingSettings` z metoda
 * `forStructuredData(int $idShop): ?array` w ksztalcie opisanym w `JsonLdBuilder`
 * (currency, services[], returns). Dopoki jej nie ma albo ustawienia wysylki nie zostaly
 * jeszcze wyslane do Merchant Center — `null`, a modul nie wypisuje wysylki ani zwrotow
 * (nie zgaduje wartosci, ktore moglyby rozjechac sie z Merchant Center).
 */
final class ShippingSource
{
    public const PROVIDER = 'Quince\\Insight\\Service\\MerchantTools\\ShippingSettings';

    /** @var array<string,mixed>|null|false tylko testy (false = brak podmiany) */
    private static array|null|false $override = false;

    /**
     * @return array<string,mixed>|null
     */
    public function forShop(int $idShop): ?array
    {
        if (self::$override !== false) {
            return self::$override;
        }

        if (!class_exists(self::PROVIDER) || !method_exists(self::PROVIDER, 'forStructuredData')) {
            return null;
        }

        try {
            $data = Container::get(self::PROVIDER)->forStructuredData($idShop);
        } catch (\Throwable $e) {
            return null;
        }

        return self::valid($data) ? $data : null;
    }

    /** Czy zrodlo danych wysylki w ogole istnieje (do opisu na ekranie). */
    public static function available(): bool
    {
        return class_exists(self::PROVIDER) && method_exists(self::PROVIDER, 'forStructuredData');
    }

    /**
     * Tylko testy i podglad: podmiana danych.
     *
     * @param array<string,mixed>|null|false $data false = przywroc zrodlo
     */
    public static function override(array|null|false $data): void
    {
        self::$override = $data;
    }

    /** Minimalna kontrola ksztaltu — reszte sprawdza `Validator` na gotowym bloku. */
    public static function valid(mixed $data): bool
    {
        if (!is_array($data) || strlen((string) ($data['currency'] ?? '')) !== 3) {
            return false;
        }

        return !empty($data['services']) || !empty($data['returns']);
    }
}
