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

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Uprawnienia Google, o ktore modul prosi — tylko te, ktorych wymagaja wlaczone uslugi.
 */
final class Scopes
{
    public const EMAIL = 'openid https://www.googleapis.com/auth/userinfo.email';
    public const VERIFY = 'https://www.googleapis.com/auth/siteverification';
    public const GSC = 'https://www.googleapis.com/auth/webmasters';
    public const GA4 = 'https://www.googleapis.com/auth/analytics.edit https://www.googleapis.com/auth/analytics.readonly';
    public const GTM = 'https://www.googleapis.com/auth/tagmanager.readonly https://www.googleapis.com/auth/tagmanager.edit.containers https://www.googleapis.com/auth/tagmanager.edit.containerversions https://www.googleapis.com/auth/tagmanager.publish';
    public const GMC = 'https://www.googleapis.com/auth/content';

    /**
     * Grupy uprawnien per klucz. `verify` to weryfikacja witryny — potrzebna Search Console
     * i Merchant Center. `ads`, `gcr`, `psi` nie maja wlasnych uprawnien (Ads idzie przez
     * Tag Manager, opinie przez Merchant Center, Page speed przez klucz API).
     */
    private const BY_KEY = [
        'email' => [self::EMAIL],
        'verify' => [self::VERIFY],
        'ga4' => [self::GA4],
        'gtm' => [self::GTM],
        'gmc' => [self::GMC, self::VERIFY],
        'gsc' => [self::GSC, self::VERIFY],
        'ads' => [],
        'gcr' => [],
        'psi' => [],
    ];

    /**
     * Scope'y potrzebne dla zestawu uslug (klucze: ga4, ads, gtm, gmc, gcr, gsc, psi oraz verify).
     * E-mail konta jest dokladany zawsze — po nim modul rozpoznaje polaczone konto.
     *
     * @param string[] $serviceKeys
     *
     * @return string[] pojedyncze scope'y, bez powtorzen
     */
    public static function forServices(array $serviceKeys): array
    {
        $groups = self::BY_KEY['email'];

        foreach ($serviceKeys as $key) {
            foreach (self::BY_KEY[$key] ?? [] as $group) {
                $groups[] = $group;
            }
        }

        return self::split($groups);
    }

    /**
     * Scope'y samej uslugi, bez e-maila — do sprawdzenia, czy polaczenie je ma.
     *
     * @return string[]
     */
    public static function ofService(string $key): array
    {
        return self::split(self::BY_KEY[$key] ?? []);
    }

    /**
     * Rozbija grupy rozdzielone spacjami na pojedyncze scope'y bez powtorzen.
     *
     * @param string[] $groups
     *
     * @return string[]
     */
    public static function split(array $groups): array
    {
        $out = [];

        foreach ($groups as $group) {
            foreach (preg_split('/\s+/', trim((string) $group)) ?: [] as $scope) {
                if ($scope !== '' && !in_array($scope, $out, true)) {
                    $out[] = $scope;
                }
            }
        }

        return $out;
    }

    /**
     * Klucze uslug, ktorych uprawnien brakuje w liscie przyznanych.
     *
     * Google zwraca `userinfo.email` takze w krotkiej postaci `email`, a `openid` bywa
     * pomijane — dlatego porownanie dla e-maila jest tolerancyjne.
     *
     * @param string[] $granted
     * @param string[] $serviceKeys
     *
     * @return string[]
     */
    public static function missing(array $granted, array $serviceKeys): array
    {
        $out = [];

        foreach ($serviceKeys as $key) {
            foreach (self::ofService($key) as $scope) {
                if (!self::has($granted, $scope)) {
                    $out[] = $key;

                    break;
                }
            }
        }

        return $out;
    }

    /** @param string[] $granted */
    public static function has(array $granted, string $scope): bool
    {
        if (in_array($scope, $granted, true)) {
            return true;
        }

        if ($scope === 'openid') {
            return true;
        }

        return $scope === 'https://www.googleapis.com/auth/userinfo.email' && in_array('email', $granted, true);
    }
}
