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

use Quince\Insight\Core\Options;
use Quince\Insight\Service\Merchant\FeedRepository;
use Quince\Insight\Service\Merchant\ProductData;
use Quince\Insight\Service\MerchantTools\Dto\RuleResult;

/**
 * Silnik regul feedu (kontrakt dla agenta E, ARCHITEKTURA §7.7).
 *
 * Kolejnosc jak w FeedForge (`DataMapper::mapProduct`): wykluczenia → ceny → custom labels →
 * identyfikatory. Agent E wola najpierw `Mapping::apply()`, potem to — reguly identyfikatorow
 * uzupelniaja tylko to, czego mapowanie nie dalo. Logika bez bazy: `RuleSet`.
 */
final class RuleEngine
{
    private RuleSet $set;

    public function __construct(
        private readonly RuleRepository $rules,
        private readonly RuleContext $context,
        private readonly FeedRepository $feeds,
    ) {
        $this->set = new RuleSet();
    }

    /** Stosuje reguly sklepu i feedu. Zmienione pola sa w $product. */
    public function apply(int $idShop, int $idFeed, ProductData $product): RuleResult
    {
        $all = $this->rules->forFeed($idShop, $idFeed, null, true);

        if ($all === []) {
            return new RuleResult(false, '');
        }

        $context = $this->context->for($idShop, $this->country($idFeed), $product);

        return $this->applyRules($all, $product, $context, $this->autoIdentifierExists($idShop));
    }

    /**
     * Czesc bez bazy — dla testow i dla wywolan z gotowym kontekstem.
     *
     * @param array<int,array<string,mixed>> $rules wiersze tabeli `rule` w kolejnosci priorytetu
     * @param array<string,mixed> $context
     */
    public function applyRules(array $rules, ProductData $product, array $context, bool $autoIdentifierExists = true): RuleResult
    {
        $of = static fn (string $type): array => array_values(array_filter($rules, static fn (array $r): bool => ($r['type'] ?? '') === $type));

        $excludedBy = $this->set->excludedBy($of(RuleDefinitions::TYPES['exclusion']), $context);

        if ($excludedBy !== null) {
            return new RuleResult(true, (string) ($excludedBy['name'] ?? ''));
        }

        $fields = $product->fields;
        $fields = $this->set->applyPricingRules($of(RuleDefinitions::TYPES['pricing']), $fields, $context);
        $fields = $this->set->applyCustomLabelRules($of(RuleDefinitions::TYPES['custom_label']), $fields, $context);
        $fields = $this->set->applyIdentifierRules($of(RuleDefinitions::TYPES['identifier']), $fields, $context, $autoIdentifierExists);
        $product->fields = $fields;

        return new RuleResult(false, '');
    }

    /** Czy sklep/feed ma jakakolwiek aktywna regule wykluczajaca (licznik moze wtedy pominac petle). */
    public function hasExclusions(int $idShop, int $idFeed): bool
    {
        return $this->rules->forFeed($idShop, $idFeed, RuleDefinitions::TYPES['exclusion'], true) !== [];
    }

    /**
     * Ktore z podanych produktow wyklucza regula — dla licznika "What is sent" i listy Products
     * (te same reguly co w synchronizacji). Cena w kontekscie: bazowa sklepu (bez budowy produktu).
     *
     * @param array<int,array{0:int,1:int}> $pairs [id_product, id_product_attribute]
     *
     * @return array<string,string> "idProduct-idAttribute" => nazwa reguly
     */
    public function excludedPairs(int $idShop, int $idFeed, array $pairs): array
    {
        $rules = $this->rules->forFeed($idShop, $idFeed, RuleDefinitions::TYPES['exclusion'], true);

        if ($rules === [] || $pairs === []) {
            return [];
        }

        $country = strtoupper($this->country($idFeed));
        $out = [];

        foreach ($this->context->rows($idShop, $pairs) as $key => $context) {
            $context['country_code'] = $country;
            $rule = $this->set->excludedBy($rules, $context);

            if ($rule !== null) {
                $out[$key] = (string) ($rule['name'] ?? '');
            }
        }

        return $out;
    }

    private function country(int $idFeed): string
    {
        return $idFeed > 0 ? (string) ($this->feeds->find($idFeed)?->country ?? '') : '';
    }

    private function autoIdentifierExists(int $idShop): bool
    {
        return Options::str(MerchantToolsOptions::ID_EXISTS_AUTO, 'auto', $idShop) === 'auto';
    }
}
