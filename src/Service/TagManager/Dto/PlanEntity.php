<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\TagManager\Dto;

/**
 * Jedna encja planu (folder, zmienna, wyzwalacz, tag) w postaci oczekiwanej w GTM.
 *
 * `body` to zasob Tag Manager API bez identyfikatorow; odwolania do innych encji planu
 * sa zapisane jako `@<klucz>` (np. `firingTriggerId: ["@trigger.purchase"]`,
 * `parentFolderId: "@folder.main"`) i zamieniane na identyfikatory GTM przy zapisie.
 * Dzieki temu `fingerprint` nie zalezy od identyfikatorow nadanych przez Google.
 */
final class PlanEntity
{
    /**
     * @param string $kind folder | variable | trigger | tag
     * @param string $key nasz staly klucz, np. `tag.ga4.purchase`
     * @param string $group grupa do podgladu zmian (`google_tag`, `ga4_events`, `triggers`, ...)
     * @param array<string,mixed> $body zasob GTM z odwolaniami `@klucz`
     */
    public function __construct(
        public string $kind,
        public string $key,
        public string $name,
        public string $group,
        public array $body,
        public string $fingerprint,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'key' => $this->key,
            'name' => $this->name,
            'group' => $this->group,
            'body' => $this->body,
            'fingerprint' => $this->fingerprint,
        ];
    }

    /** @param array<string,mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self(
            (string) $row['kind'],
            (string) $row['key'],
            (string) $row['name'],
            (string) $row['group'],
            (array) $row['body'],
            (string) $row['fingerprint'],
        );
    }
}
