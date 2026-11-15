<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\MerchantTools;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Czysta logika regul — przeniesiona z FeedForge (`Service/RuleEngine.php`) bez bazy.
 *
 * Zamiast repozytorium dostaje liste regul (wiersze tabeli `rule` albo tablice w tym
 * samym ksztalcie). Warunki w regule: AND, pusta lista pasuje zawsze — jak w FeedForge.
 * Poprawki wzgledem FeedForge (research/02 §1.4 [bug]):
 *  - `has_combination` (i kazda wartosc logiczna) porownywane po normalizacji 1/0/yes/no/true/false,
 *    wiec `has_combination eq 0` i `eq no` wreszcie pasuja;
 *  - po dodaniu GTIN/MPN regula identyfikatora przelicza `identifierExists` (gdy tryb automatyczny);
 *  - regula z wyzszym priorytetem wygrywa takze przy custom labels (pierwsza pasujaca ustawia etykiete);
 *  - cena w ksztalcie `{value, currency}` (DataMapper) albo `{amountMicros, currencyCode}` (Merchant API).
 */
final class RuleSet
{
    /**
     * Pierwsza aktywna regula wykluczajaca, ktorej warunki pasuja; null = produkt nie jest wykluczony.
     *
     * @param array<int,array<string,mixed>> $rules
     * @param array<string,mixed> $context
     *
     * @return array<string,mixed>|null
     */
    public function excludedBy(array $rules, array $context): ?array
    {
        foreach ($rules as $rule) {
            if (!$this->active($rule)) {
                continue;
            }

            if ($this->evaluateConditions($this->decodeJson($rule['conditions'] ?? '[]'), $context)) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * @param array<int,array<string,mixed>> $rules
     * @param array<string,mixed> $context
     */
    public function isExcluded(array $rules, array $context): bool
    {
        return $this->excludedBy($rules, $context) !== null;
    }

    /**
     * @param array<int,array<string,mixed>> $rules
     * @param array<string,mixed> $mapped
     * @param array<string,mixed> $context
     *
     * @return array<string,mixed>
     */
    public function applyPricingRules(array $rules, array $mapped, array $context): array
    {
        foreach ($rules as $rule) {
            if (!$this->active($rule) || !$this->evaluateConditions($this->decodeJson($rule['conditions'] ?? '[]'), $context)) {
                continue;
            }

            $mapped = $this->executePricingActions($mapped, $this->decodeJson($rule['actions'] ?? '{}'));
        }

        return $mapped;
    }

    /**
     * @param array<int,array<string,mixed>> $rules
     * @param array<string,mixed> $mapped
     * @param array<string,mixed> $context
     *
     * @return array<string,mixed>
     */
    public function applyCustomLabelRules(array $rules, array $mapped, array $context): array
    {
        $set = [];

        foreach ($rules as $rule) {
            if (!$this->active($rule) || !$this->evaluateConditions($this->decodeJson($rule['conditions'] ?? '[]'), $context)) {
                continue;
            }

            $actions = $this->decodeJson($rule['actions'] ?? '{}');
            $index = (int) ($actions['label_index'] ?? 0);

            // Reguly ida od najwyzszego priorytetu — ta etykieta jest juz ustawiona przez wazniejsza regule.
            if (isset($set[$index])) {
                continue;
            }

            $before = $mapped;
            $mapped = $this->executeCustomLabelActions($mapped, $actions);

            if ($mapped !== $before) {
                $set[$index] = true;
            }
        }

        return $mapped;
    }

    /**
     * @param array<int,array<string,mixed>> $rules
     * @param array<string,mixed> $mapped
     * @param array<string,mixed> $context
     * @param bool $autoIdentifierExists tryb "Automatic" z Mapping: identifierExists liczone z GTIN/MPN
     *
     * @return array<string,mixed>
     */
    public function applyIdentifierRules(array $rules, array $mapped, array $context, bool $autoIdentifierExists = true): array
    {
        $explicitExists = false;

        foreach ($rules as $rule) {
            if (!$this->active($rule) || !$this->evaluateConditions($this->decodeJson($rule['conditions'] ?? '[]'), $context)) {
                continue;
            }

            $actions = $this->decodeJson($rule['actions'] ?? '{}');

            if (($actions['field'] ?? '') === 'identifier_exists') {
                if ($explicitExists) {
                    continue;
                }

                $explicitExists = true;
            }

            $mapped = $this->executeIdentifierActions($mapped, $actions, $context);
        }

        if ($autoIdentifierExists && !$explicitExists && (!empty($mapped['gtin']) || !empty($mapped['mpn']))) {
            $mapped['identifierExists'] = true;
        }

        return $mapped;
    }

    /**
     * Warunki reguly — wszystkie musza pasowac (AND). Format: [{"field","operator","value"}].
     *
     * @param array<int,array<string,mixed>> $conditions
     * @param array<string,mixed> $context
     */
    public function evaluateConditions(array $conditions, array $context): bool
    {
        if ($conditions === []) {
            return true;
        }

        foreach ($conditions as $condition) {
            if (!is_array($condition) || !$this->evaluateCondition($condition, $context)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string,mixed> $condition
     * @param array<string,mixed> $context
     */
    public function evaluateCondition(array $condition, array $context): bool
    {
        $field = (string) ($condition['field'] ?? '');
        $operator = (string) ($condition['operator'] ?? ($condition['op'] ?? 'eq'));
        $expected = $condition['value'] ?? '';

        $actual = $context[$field] ?? null;

        if ($actual === null) {
            return $operator === 'empty' || $operator === 'not_exists';
        }

        // Poprawka FeedForge: (string) false === '' nigdy nie bylo rowne '0' z interfejsu.
        if (is_bool($actual)) {
            $actual = $actual ? '1' : '0';
            $expected = self::boolString($expected);
        }

        $list = static fn (mixed $v): array => array_map('trim', explode(',', (string) $v));

        return match ($operator) {
            'eq' => (string) $actual === (string) $expected,
            'neq', 'ne' => (string) $actual !== (string) $expected,
            'gt' => (float) $actual > (float) $expected,
            'gte', 'ge' => (float) $actual >= (float) $expected,
            'lt' => (float) $actual < (float) $expected,
            'lte', 'le' => (float) $actual <= (float) $expected,
            'contains' => str_contains(mb_strtolower((string) $actual), mb_strtolower((string) $expected)),
            'not_contains' => !str_contains(mb_strtolower((string) $actual), mb_strtolower((string) $expected)),
            'starts_with' => str_starts_with(mb_strtolower((string) $actual), mb_strtolower((string) $expected)),
            'ends_with' => str_ends_with(mb_strtolower((string) $actual), mb_strtolower((string) $expected)),
            'in' => in_array((string) $actual, $list($expected), true),
            'not_in' => !in_array((string) $actual, $list($expected), true),
            'empty' => empty($actual),
            'not_empty' => !empty($actual),
            'regex' => (bool) @preg_match('/' . str_replace('/', '\/', (string) $expected) . '/i', (string) $actual),
            default => false,
        };
    }

    private static function boolString(mixed $value): string
    {
        $v = strtolower(trim((string) (is_bool($value) ? ($value ? '1' : '0') : $value)));

        return in_array($v, ['1', 'yes', 'true', 'on'], true) ? '1' : '0';
    }

    /**
     * @param array<string,mixed> $mapped
     * @param array<string,mixed> $actions
     *
     * @return array<string,mixed>
     */
    private function executePricingActions(array $mapped, array $actions): array
    {
        $action = (string) ($actions['action'] ?? '');
        $value = (float) ($actions['value'] ?? 0);

        $price = self::readPrice($mapped['price'] ?? null);

        if ($price === null) {
            return $mapped;
        }

        $new = match ($action) {
            'min_price' => $price < $value ? $value : $price,
            'markup_percent' => $price * (1 + $value / 100),
            'markup_fixed' => $price + $value,
            'round_up' => ceil(round($price * 100, 6)) / 100,
            'round_99' => floor($price) + 0.99,
            default => $price,
        };

        $mapped['price'] = self::writePrice($mapped['price'], $new);

        return $mapped;
    }

    /** Cena jako liczba z `{value}` albo `{amountMicros}`; null gdy brak. */
    public static function readPrice(mixed $price): ?float
    {
        if (!is_array($price)) {
            return null;
        }

        if (isset($price['value'])) {
            return (float) $price['value'];
        }

        if (isset($price['amountMicros'])) {
            return (float) $price['amountMicros'] / 1000000;
        }

        return null;
    }

    /**
     * @param array<string,mixed> $price
     *
     * @return array<string,mixed>
     */
    private static function writePrice(array $price, float $value): array
    {
        if (isset($price['value'])) {
            $price['value'] = number_format($value, 2, '.', '');
        } else {
            $price['amountMicros'] = (string) (int) round(round($value, 2) * 1000000);
        }

        return $price;
    }

    /**
     * @param array<string,mixed> $mapped
     * @param array<string,mixed> $actions
     *
     * @return array<string,mixed>
     */
    private function executeCustomLabelActions(array $mapped, array $actions): array
    {
        $labelIndex = (int) ($actions['label_index'] ?? 0);
        $value = (string) ($actions['value'] ?? '');

        if ($labelIndex < 0 || $labelIndex > 4 || $value === '') {
            return $mapped;
        }

        $mapped['customLabel' . $labelIndex] = mb_substr($value, 0, 100); // limit Google

        return $mapped;
    }

    /**
     * @param array<string,mixed> $mapped
     * @param array<string,mixed> $actions
     * @param array<string,mixed> $context
     *
     * @return array<string,mixed>
     */
    private function executeIdentifierActions(array $mapped, array $actions, array $context): array
    {
        $field = (string) ($actions['field'] ?? '');
        $source = (string) ($actions['source'] ?? 'fixed');
        $value = $actions['value'] ?? '';

        if ($source === 'context') {
            $value = (string) ($context[$value] ?? '');
        }

        if (empty($value)) {
            return $mapped;
        }

        switch ($field) {
            case 'gtin':
            case 'mpn':
            case 'brand':
                if (empty($mapped[$field])) {
                    $mapped[$field] = (string) $value;
                }
                break;
            case 'identifier_exists':
                // "no" z edytora to niepusty napis — (bool) "no" byloby true.
                $mapped['identifierExists'] = self::boolString($value) === '1';
                break;
        }

        return $mapped;
    }

    /** @param array<string,mixed> $rule */
    private function active(array $rule): bool
    {
        return (bool) (int) ($rule['active'] ?? 1);
    }

    /** @return array<mixed> */
    private function decodeJson(mixed $json): array
    {
        return RuleDefinitions::decode($json);
    }
}
