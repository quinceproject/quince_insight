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

/**
 * Typy regul, pola warunkow, operatory i akcje — jedno miejsce dla edytora i silnika.
 *
 * Pola i operatory 1:1 z FeedForge (research/02 §1.4 "Rules engine", prototyp `RULE_FIELDS`
 * i `RULE_OPS`). Klucze typow w interfejsie sa jak w prototypie, w bazie jak w ARCHITEKTURA §7.7.
 */
final class RuleDefinitions
{
    /** Typ w interfejsie => typ w bazie (`rule.type`). */
    public const TYPES = [
        'exclusion' => 'exclude',
        'pricing' => 'price',
        'custom_label' => 'label',
        'identifier' => 'identifier',
    ];

    /** Etykiety i teksty pustej listy — doslownie z prototypu (`RULE_TYPES`). */
    public const TYPE_LABELS = [
        'exclusion' => ['label' => 'Exclusions', 'empty' => 'Add rules to exclude products from the feed automatically.'],
        'pricing' => ['label' => 'Pricing', 'empty' => 'Add rules for minimum prices, markups or rounding.'],
        'custom_label' => ['label' => 'Custom labels', 'empty' => 'Custom labels 0–4 let you segment products in Google Ads.'],
        'identifier' => ['label' => 'Identifiers', 'empty' => 'Override GTIN, MPN, brand or identifier_exists for selected products.'],
    ];

    private const NUMBER_OPS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte'];

    /** Pola warunkow: etykieta, operatory, rodzaj pola (prototyp `RULE_FIELDS`). */
    public const FIELDS = [
        'price' => ['label' => 'Price', 'ops' => self::NUMBER_OPS, 'input' => 'number'],
        'quantity' => ['label' => 'Stock', 'ops' => self::NUMBER_OPS, 'input' => 'number'],
        'weight' => ['label' => 'Weight', 'ops' => self::NUMBER_OPS, 'input' => 'number'],
        'id_category_default' => ['label' => 'Category ID', 'ops' => ['eq', 'neq', 'in', 'not_in'], 'input' => 'text'],
        'id_product' => ['label' => 'Product ID', 'ops' => ['eq', 'neq', 'in', 'not_in'], 'input' => 'text'],
        'brand' => ['label' => 'Brand', 'ops' => ['eq', 'neq', 'contains', 'empty', 'not_empty'], 'input' => 'text'],
        'ean13' => ['label' => 'EAN', 'ops' => ['eq', 'neq', 'starts_with', 'empty', 'not_empty'], 'input' => 'text'],
        'reference' => ['label' => 'Reference / SKU', 'ops' => ['eq', 'neq', 'contains', 'starts_with', 'empty', 'not_empty'], 'input' => 'text'],
        'condition' => ['label' => 'Condition', 'ops' => ['eq', 'neq'], 'input' => 'select', 'options' => ['new', 'used', 'refurbished']],
        'visibility' => ['label' => 'Visibility', 'ops' => ['eq', 'neq'], 'input' => 'select', 'options' => ['both', 'catalog', 'search', 'none']],
        'has_combination' => ['label' => 'Has variants', 'ops' => ['eq'], 'input' => 'select', 'options' => ['yes', 'no']],
    ];

    /** Operatory z etykieta w interfejsie (prototyp `RULE_OPS`). */
    public const OPS = [
        'eq' => 'equals',
        'neq' => 'not equal',
        'gt' => 'greater than',
        'gte' => 'greater or equal',
        'lt' => 'less than',
        'lte' => 'less or equal',
        'contains' => 'contains',
        'starts_with' => 'starts with',
        'in' => 'is in list',
        'not_in' => 'is not in list',
        'empty' => 'is empty',
        'not_empty' => 'is not empty',
    ];

    /** Akcje regul cenowych (prototyp `PRICING_ACTIONS`, silnik FeedForge). */
    public const PRICING_ACTIONS = [
        'min_price' => 'Minimum price',
        'markup_percent' => 'Markup (%)',
        'markup_fixed' => 'Fixed markup (amount)',
        'round_up' => 'Round up',
        'round_99' => 'Round to X.99',
    ];

    /** Cele regul identyfikatorow (prototyp `IDENT_TARGETS`). */
    public const IDENT_TARGETS = [
        'gtin' => 'GTIN (EAN / UPC)',
        'mpn' => 'MPN',
        'brand' => 'Brand',
        'identifier_exists' => 'Identifier exists',
    ];

    public static function dbType(string $uiType): ?string
    {
        return self::TYPES[$uiType] ?? null;
    }

    public static function uiType(string $dbType): ?string
    {
        $flip = array_flip(self::TYPES);

        return $flip[$dbType] ?? null;
    }

    /**
     * Sprawdza i porzadkuje regule z edytora. Zwraca [regula, blad] — blad '' gdy w porzadku.
     *
     * @param array<string,mixed> $input
     *
     * @return array{0: array<string,mixed>, 1: string}
     */
    public static function normalize(array $input): array
    {
        $type = (string) ($input['type'] ?? '');

        if (!isset(self::TYPES[$type])) {
            return [[], 'Unknown rule type.'];
        }

        $conditions = [];

        foreach ((array) ($input['conds'] ?? []) as $cond) {
            if (!is_array($cond)) {
                continue;
            }

            $field = (string) ($cond['field'] ?? '');
            $op = (string) ($cond['op'] ?? ($cond['operator'] ?? ''));

            if (!isset(self::FIELDS[$field]) || !in_array($op, self::FIELDS[$field]['ops'], true)) {
                return [[], 'Choose a field and an operator for every condition.'];
            }

            $value = in_array($op, ['empty', 'not_empty'], true) ? '' : trim((string) ($cond['value'] ?? ''));

            if ($value === '' && !in_array($op, ['empty', 'not_empty'], true)) {
                return [[], 'Fill in the value of every condition.'];
            }

            if (self::FIELDS[$field]['input'] === 'select' && !in_array($value, self::FIELDS[$field]['options'], true)) {
                return [[], 'Choose a value from the list.'];
            }

            if (self::FIELDS[$field]['input'] === 'number' && !is_numeric(str_replace(',', '.', $value))) {
                return [[], 'Use a number in conditions on price, stock and weight.'];
            }

            if (self::FIELDS[$field]['input'] === 'number') {
                $value = str_replace(',', '.', $value);
            }

            // Zapis 1:1 z FeedForge: {"field", "operator", "value"}.
            $conditions[] = ['field' => $field, 'operator' => $op, 'value' => $value];
        }

        $actions = [];

        if ($type === 'pricing') {
            $action = (string) ($input['action'] ?? '');

            if (!isset(self::PRICING_ACTIONS[$action])) {
                return [[], 'Choose what the rule does with the price.'];
            }

            $value = str_replace(',', '.', trim((string) ($input['value'] ?? '')));

            if (in_array($action, ['min_price', 'markup_percent', 'markup_fixed'], true) && !is_numeric($value)) {
                return [[], 'Enter a number for the price action.'];
            }

            $actions = ['action' => $action, 'value' => is_numeric($value) ? (float) $value : 0];
        } elseif ($type === 'custom_label') {
            $index = (int) ($input['label'] ?? 0);
            $value = mb_substr(trim((string) ($input['labelValue'] ?? '')), 0, 100);

            if ($index < 0 || $index > 4 || $value === '') {
                return [[], 'Enter the label value.'];
            }

            $actions = ['label_index' => $index, 'value' => $value];
        } elseif ($type === 'identifier') {
            $target = (string) ($input['target'] ?? '');
            $value = trim((string) ($input['value'] ?? ''));

            if (!isset(self::IDENT_TARGETS[$target]) || $value === '') {
                return [[], 'Choose the identifier and enter its value.'];
            }

            if ($target === 'identifier_exists') {
                $lower = strtolower($value);

                if (!in_array($lower, ['yes', 'no'], true)) {
                    return [[], 'Use yes or no for identifier_exists.'];
                }

                $value = $lower;
            }

            $actions = ['field' => $target, 'source' => 'fixed', 'value' => $value];
        }

        $name = mb_substr(trim((string) ($input['name'] ?? '')), 0, 120);

        return [[
            'type' => self::TYPES[$type],
            'name' => $name !== '' ? $name : 'Untitled rule',
            'conditions' => $conditions,
            'actions' => $actions,
            'position' => max(0, min(100, (int) ($input['priority'] ?? 0))),
            'active' => !empty($input['on']),
        ], ''];
    }

    /**
     * Regula z bazy w ksztalcie edytora z prototypu.
     *
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    public static function toEditor(array $row): array
    {
        $conds = [];

        foreach (self::decode($row['conditions'] ?? '[]') as $c) {
            $conds[] = ['field' => (string) ($c['field'] ?? ''), 'op' => (string) ($c['operator'] ?? 'eq'), 'value' => (string) ($c['value'] ?? '')];
        }

        $actions = self::decode($row['actions'] ?? '{}');
        $type = self::uiType((string) ($row['type'] ?? '')) ?? 'exclusion';

        return [
            'id' => (int) ($row['id_rule'] ?? 0),
            'type' => $type,
            'name' => (string) ($row['name'] ?? ''),
            'on' => (bool) (int) ($row['active'] ?? 1),
            'priority' => (int) ($row['position'] ?? 0),
            'feed' => (int) ($row['id_feed'] ?? 0),
            'conds' => $conds,
            'action' => (string) ($actions['action'] ?? 'min_price'),
            'value' => $type === 'pricing'
                ? (isset($actions['value']) && (float) $actions['value'] != 0.0 ? (string) $actions['value'] : '')
                : (string) ($actions['value'] ?? ''),
            'label' => (int) ($actions['label_index'] ?? 0),
            'labelValue' => $type === 'custom_label' ? (string) ($actions['value'] ?? '') : '',
            'target' => (string) ($actions['field'] ?? 'gtin'),
            'source' => 'fixed',
        ];
    }

    /** @return array<mixed> */
    public static function decode(mixed $json): array
    {
        if (is_array($json)) {
            return $json;
        }

        if (!is_string($json) || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
