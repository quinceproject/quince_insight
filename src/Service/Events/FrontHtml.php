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
 * Kod wstawiany na strone sklepu. Czysta logika (same napisy) — testowalna bez PrestaShop.
 *
 * Kolejnosc w <head> jest wazna (research/05 §F: „The order of the code here is vital”):
 * domyslne zgody -> (aktualizacja z ciasteczka narzedzia zgod) -> snippet GTM.
 * Przy Quince CMP deklaracje zgod wypisuje sam CMP — wczesniej na tym samym hooku
 * (pozycja modulu na `displayHeader`); modul ich nie powtarza (API.md CMP §3).
 *
 * Wszystko z danych sklepu w skryptach idzie przez `json()` z flagami HEX (ARCHITEKTURA §11).
 */
final class FrontHtml
{
    public const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

    public static function validGtmId(string $id): bool
    {
        return preg_match('/^GTM-[A-Z0-9]{4,12}$/', $id) === 1;
    }

    public static function json(mixed $value): string
    {
        return Json::encode($value, self::JSON_FLAGS);
    }

    /**
     * Domyslne zgody Consent Mode v2 (+ odczyt ciasteczka obcego narzedzia zgod).
     *
     * @param array<string,string> $defaults
     * @param array<string,string> $map sygnal => kategoria (tylko dla `other`)
     */
    public static function consent(string $source, array $defaults, int $waitMs, string $cookieName = '', array $map = []): string
    {
        $default = ConsentMapper::normalize($defaults);

        if ($waitMs > 0) {
            $default['wait_for_update'] = $waitMs;
        }

        $js = 'window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}'
            . 'gtag("consent","default",' . self::json($default) . ');'
            . 'gtag("set","url_passthrough",true);gtag("set","ads_data_redaction",true);';

        if ($source === 'other' && $cookieName !== '') {
            // Ten sam parser co ConsentMapper::parseCookie (PHP) — zgody czytane z ciasteczka
            // przy kazdej stronie (tryb zgod nie pamieta wyboru) i po kazdej zmianie (co 1 s).
            $js .= '(function(n,m){var A={statistics:"analytics",statistic:"analytics",performance:"analytics",analytical:"analytics",'
                . 'advertising:"marketing",ads:"marketing",targeting:"marketing",functional:"preferences",functionality:"preferences",preference:"preferences"},'
                . 'C=["analytics","marketing","preferences"],T=["1","true","yes","granted","allow","allowed","accepted","on"],last=null;'
                . 'function cat(k){k=String(k).toLowerCase().trim();return C.indexOf(k)>=0?k:(A[k]||"")}'
                . 'function tru(v){if(typeof v==="boolean")return v;if(typeof v==="number")return v>0;return typeof v==="string"&&T.indexOf(v.toLowerCase().trim())>=0}'
                . 'function walk(d,o){var l=Array.isArray(d);for(var k in d){if(!Object.prototype.hasOwnProperty.call(d,k))continue;var v=d[k];'
                . 'if(l&&typeof v==="string"){o.push(cat(v));continue}if(v&&typeof v==="object"){walk(v,o);continue}if(!l&&tru(v))o.push(cat(k))}}'
                . 'function read(){var p=document.cookie.split("; ");for(var i=0;i<p.length;i++){var e=p[i].indexOf("=");if(p[i].slice(0,e)===n)return p[i].slice(e+1)}return null}'
                . 'function parse(r){if(r===null||r==="")return null;try{if(r.indexOf("%")>=0)r=decodeURIComponent(r)}catch(x){}var o=[],d=null;'
                . 'try{d=JSON.parse(r)}catch(x){}if(d&&typeof d==="object"){walk(d,o)}else{var re=/([A-Za-z_][A-Za-z0-9_\-]*)\s*[:=]\s*([A-Za-z0-9"\']+)/g,mm,any=false;'
                . 'while((mm=re.exec(r))){any=true;if(tru(mm[2].replace(/["\']/g,"")))o.push(cat(mm[1]))}if(!any){r.split(/[\s,;|&+]+/).forEach(function(t){if(t)o.push(cat(t))})}}return o}'
                . 'function check(){var r=read();if(r===last)return;last=r;var c=parse(r);if(c===null)return;var u={};'
                . 'for(var s in m){u[s]=c.indexOf(m[s])>=0?"granted":"denied"}gtag("consent","update",u);dataLayer.push({event:"quince_insight_consent"})}'
                . 'check();setInterval(check,1000)})(' . self::json($cookieName) . ',' . self::json((object) $map) . ');';
        }

        return '<script data-qi="consent">' . $js . '</script>';
    }

    /** Snippet Tag Managera do <head> (oficjalny kod Google). */
    public static function gtmHead(string $id): string
    {
        return '<script data-qi="gtm">(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({"gtm.start":new Date().getTime(),event:"gtm.js"});'
            . 'var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!="dataLayer"?"&l="+l:"";j.async=true;'
            . 'j.src="https://www.googletagmanager.com/gtm.js?id="+i+dl;f.parentNode.insertBefore(j,f);})(window,document,"script","dataLayer",'
            . self::json($id) . ');</script>';
    }

    /** `<noscript>` Tag Managera tuz po <body>. */
    public static function gtmNoscript(string $id): string
    {
        return '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=' . rawurlencode($id)
            . '" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>';
    }

    /**
     * Zdarzenia strony do dataLayer i dane dla skryptu frontu.
     *
     * Kazde zdarzenie e-commerce poprzedza `{ecommerce: null}` (research/05 §G.2).
     * Zdarzenia z kluczem `once` (begin_checkout, add_shipping_info, purchase) ida najwyzej
     * raz na karte (sessionStorage) — strona kasy i potwierdzenia bywaja odswiezane.
     *
     * @param array<int,array<string,mixed>> $events
     * @param array<string,mixed> $config
     */
    public static function events(array $events, array $config): string
    {
        $list = [];

        foreach ($events as $event) {
            $list[] = array_filter([
                'd' => $event['data'],
                'e' => !empty($event['ecommerce']) ? 1 : null,
                'o' => $event['once'] ?? null,
            ], static fn ($v): bool => $v !== null);
        }

        return '<script data-qi="events">(function(w){var c=w.quinceInsight=' . self::json($config) . ',l=' . self::json($list) . ',d=w.dataLayer=w.dataLayer||[];'
            . 'l.forEach(function(e){if(e.o){try{var k="qi_"+e.o;if(sessionStorage.getItem(k))return;sessionStorage.setItem(k,"1")}catch(x){}}'
            . 'if(e.e)d.push({ecommerce:null});d.push(e.d)});})(window);</script>';
    }

    /**
     * Okienko Google Customer Reviews (research/04 §15 — kod przed </body>).
     *
     * @param array{merchant_id:int, order_id:string, email:string, delivery_country:string, estimated_delivery_date:string, opt_in_style:string} $data
     */
    public static function customerReviews(array $data, string $lang): string
    {
        $render = [
            'merchant_id' => (int) $data['merchant_id'],
            'order_id' => (string) $data['order_id'],
            'email' => (string) $data['email'],
            'delivery_country' => (string) $data['delivery_country'],
            'estimated_delivery_date' => (string) $data['estimated_delivery_date'],
            'opt_in_style' => (string) $data['opt_in_style'],
        ];

        return '<script data-qi="gcr">window.___gcfg={lang:' . self::json($lang) . '};window.renderOptIn=function(){window.gapi.load("surveyoptin",function(){window.gapi.surveyoptin.render('
            . self::json($render) . ');});};</script>'
            . '<script src="https://apis.google.com/js/platform.js?onload=renderOptIn" async defer></script>';
    }
}
