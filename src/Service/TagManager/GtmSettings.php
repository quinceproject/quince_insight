<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\TagManager;

use Quince\Insight\Core\Options;

/**
 * Odczyt ustawien Tag Manager dla uslugi Events (kontrakt ARCHITEKTURA §7.5, wiazacy).
 *
 * Rozszerzenia kontraktu (nowe metody): `ownerShop()` / `eventsShop()` — przy wspolnym
 * kontenerze (`shared`, ARCHITEKTURA §6) sklep wstawia snippet, ale zestaw zdarzen bierze
 * ze sklepu zarzadzajacego; `container()`, `containerName()`, `isConfigured()`.
 */
final class GtmSettings
{
    public function __construct(private readonly PlanSettings $plans)
    {
    }

    /** 'GTM-XXXX' albo '' */
    public function publicId(int $idShop): string
    {
        return Options::str(GtmOptions::GTM_PUBLIC_ID, '', $idShop);
    }

    /** auto | own | shared */
    public function mode(int $idShop): string
    {
        $mode = Options::str(GtmOptions::GTM_MODE, GtmOptions::MODE_AUTO, $idShop);

        return in_array($mode, [GtmOptions::MODE_AUTO, GtmOptions::MODE_OWN, GtmOptions::MODE_SHARED], true) ? $mode : GtmOptions::MODE_AUTO;
    }

    /** Sciezka `accounts/x/containers/y` albo ''. */
    public function container(int $idShop): string
    {
        return Options::str(GtmOptions::GTM_CONTAINER, '', $idShop);
    }

    public function containerName(int $idShop): string
    {
        return Options::str(GtmOptions::GTM_CONTAINER_NAME, '', $idShop);
    }

    /** Kontener wybrany i znany jego identyfikator publiczny (snippet da sie wstawic). */
    public function isConfigured(int $idShop): bool
    {
        return $this->container($idShop) !== '' && $this->publicId($idShop) !== '';
    }

    /** Sklep zarzadzajacy zawartoscia kontenera: przy `shared` — wlasciciel, inaczej ten sam sklep. */
    public function ownerShop(int $idShop): int
    {
        if ($this->mode($idShop) !== GtmOptions::MODE_SHARED) {
            return $idShop;
        }

        $owner = Options::int(GtmOptions::GTM_OWNER_SHOP, 0, $idShop);

        return $owner > 0 && $owner !== $idShop ? $owner : $idShop;
    }

    /** Sklep, ktorego ustawienia zdarzen obowiazuja w dataLayer (ARCHITEKTURA §6: wspolny kontener). */
    public function eventsShop(int $idShop): int
    {
        return $this->ownerShop($idShop);
    }

    /** Czy ostatnio zastosowany plan odpowiada biezacym ustawieniom zdarzen i Ads. */
    public function upToDate(int $idShop): bool
    {
        $mode = $this->mode($idShop);

        if ($mode === GtmOptions::MODE_OWN) {
            return true;
        }

        $owner = $this->ownerShop($idShop);

        if ($this->container($owner) === '') {
            return false;
        }

        $applied = Options::str(GtmOptions::GTM_PLAN_HASH, '', $owner);

        return $applied !== '' && hash_equals($applied, $this->plans->plan($owner)->hash);
    }
}
