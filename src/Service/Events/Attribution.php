<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\Events;

/**
 * Zrodlo ruchu zamowienia (utm, gclid, odsylacz). Czysta logika przeniesiona z TagPilota
 * (`parseAttributionFromUrl` / `getOrderAttribution`, research/02 §3.5 — „verbatim”).
 *
 * Zapisywane w `order_log.source` przy zlozeniu zamowienia. Przy wysylce po czasie
 * (brakujace zamowienia) idzie do GA4 zdarzeniem `campaign_details` z parametrami
 * `source`, `medium`, `campaign`, `campaign_id`, `term`, `content`
 * (https://developers.google.com/analytics/devguides/collection/protocol/ga4/reference/events, sprawdzone 2026-11-11).
 */
final class Attribution
{
    private const EMPTY = ['source' => '', 'medium' => '', 'campaign' => '', 'campaign_id' => '', 'term' => '', 'content' => '', 'gclid' => '', 'referrer' => ''];

    /** @return array<string,string> */
    public static function fromUrl(string $uri, string $referrer = ''): array
    {
        $out = self::EMPTY;
        $query = [];
        $qs = parse_url($uri, PHP_URL_QUERY);

        if (is_string($qs) && $qs !== '') {
            parse_str($qs, $query);
        }

        $get = static fn (string $key): string => is_string($query[$key] ?? null) ? substr(trim($query[$key]), 0, 100) : '';

        if ($get('gclid') !== '') {
            $out['gclid'] = $get('gclid');
            $out['source'] = 'google';
            $out['medium'] = 'cpc';
        }

        $braid = $get('gbraid') !== '' ? $get('gbraid') : $get('wbraid');

        if ($braid !== '' && $out['source'] === '') {
            $out['gclid'] = $braid;
            $out['source'] = 'google';
            $out['medium'] = 'cpc';
        }

        if ($get('gad_campaignid') !== '') {
            $out['campaign_id'] = $get('gad_campaignid');
        }

        if ($get('gad_source') !== '' && $out['source'] === '') {
            $out['source'] = 'google';
            $out['medium'] = 'cpc';
        }

        foreach (['utm_source' => 'source', 'utm_medium' => 'medium', 'utm_campaign' => 'campaign', 'utm_id' => 'campaign_id', 'utm_term' => 'term', 'utm_content' => 'content'] as $param => $field) {
            if ($get($param) !== '') {
                $out[$field] = $get($param);
            }
        }

        $host = strtolower((string) preg_replace('/^www\./', '', (string) parse_url($referrer, PHP_URL_HOST)));

        if ($host !== '') {
            $out['referrer'] = substr($host, 0, 100);
        }

        if ($out['source'] === '' && $host !== '') {
            if (preg_match('/^(google|bing|duckduckgo|yahoo)\./', $host, $m)) {
                $out['source'] = $m[1];
                $out['medium'] = 'organic';
            } elseif (preg_match('/(facebook|instagram|twitter|linkedin|tiktok)\.|(^|\.)x\.com$/', $host, $m)) {
                $out['source'] = ($m[1] ?? '') !== '' ? $m[1] : 'x';
                $out['medium'] = 'social';
            } else {
                $out['source'] = $host;
                $out['medium'] = 'referral';
            }
        }

        return $out;
    }

    /**
     * Najlepsze zrodlo z listy wejsc (od najstarszego): klikniecie reklamy wygrywa,
     * potem pierwsze z oznaczonym zrodlem. Odsylacze z wlasnej domeny sklepu sa pomijane.
     *
     * @param array<int,array{request_uri?:string,http_referer?:string}> $visits
     *
     * @return array<string,string>
     */
    public static function best(array $visits, string $ownHost = ''): array
    {
        $best = self::EMPTY;
        $ownHost = strtolower((string) preg_replace('/^www\./', '', $ownHost));

        foreach ($visits as $visit) {
            $referrer = (string) ($visit['http_referer'] ?? '');
            $refHost = strtolower((string) preg_replace('/^www\./', '', (string) parse_url($referrer, PHP_URL_HOST)));

            if ($ownHost !== '' && $refHost === $ownHost) {
                $referrer = '';
            }

            $parsed = self::fromUrl((string) ($visit['request_uri'] ?? ''), $referrer);

            if ($parsed['gclid'] !== '') {
                return $parsed;
            }

            if ($best['source'] === '' && $parsed['source'] !== '') {
                $best = $parsed;
            }
        }

        return $best;
    }

    /**
     * Parametry zdarzenia `campaign_details`; [] gdy nie ma zrodla.
     *
     * @param array<string,string> $source
     *
     * @return array<string,string>
     */
    public static function campaignParams(array $source): array
    {
        if (($source['source'] ?? '') === '') {
            return [];
        }

        return array_filter([
            'source' => (string) ($source['source'] ?? ''),
            'medium' => (string) ($source['medium'] ?? ''),
            'campaign' => (string) ($source['campaign'] ?? ''),
            'campaign_id' => (string) ($source['campaign_id'] ?? ''),
            'term' => (string) ($source['term'] ?? ''),
            'content' => (string) ($source['content'] ?? ''),
        ], static fn (string $v): bool => $v !== '');
    }
}
