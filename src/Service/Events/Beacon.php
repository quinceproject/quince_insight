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

use Db;
use Quince\Insight\Core\Clock;
use Quince\Insight\Core\Options;
use Quince\Insight\Core\Schema as CoreSchema;
use Quince\Insight\Core\Services;
use Quince\Insight\Core\ShopRepository;
use Quince\Insight\Service\TagManager\GtmSettings;

/**
 * Zgloszenia skryptu frontu (`controllers/front/event.php`): zdarzenia z przegladarki do
 * dziennika i inne kontenery GTM znalezione na stronie.
 *
 * Adres jest publiczny, wiec wejscie jest waskie: token sklepu, tylko zdarzenia skryptu,
 * tylko gdy dziennik wlaczony, ograniczony rozmiar. Token nie jest sekretem (jest na stronie) —
 * odsiewa tylko przypadkowe wywolania. Dlatego dochodzi limit czestotliwosci (R-05,
 * `BeaconLimit`): na kubelek IP i na sklep, a lista innych kontenerow tylko sie dopisuje.
 */
final class Beacon
{
    private const MAX_BYTES = 16000;

    public function __construct(
        private readonly EventsSettings $settings,
        private readonly EventLog $log,
        private readonly Services $services,
        private readonly ShopRepository $shops,
        private readonly GtmSettings $gtm,
    ) {
    }

    public static function token(int $idShop): string
    {
        return substr(hash_hmac('sha256', 'events.beacon.' . $idShop, (string) _COOKIE_KEY_), 0, 24);
    }

    /** Zwraca kod HTTP odpowiedzi. */
    public function handle(int $idShop, string $body): int
    {
        if (strlen($body) > self::MAX_BYTES || !$this->shops->isLive($idShop) || !$this->services->enabled($idShop, 'gtm')) {
            return 404;
        }

        $data = json_decode($body, true);

        if (!is_array($data) || !hash_equals(self::token($idShop), (string) ($data['t'] ?? ''))) {
            return 403;
        }

        if (!$this->withinLimit($idShop)) {
            return 429;
        }

        if (array_key_exists('gtm', $data)) {
            $ids = array_values(array_filter(array_map('strval', (array) $data['gtm']), [FrontHtml::class, 'validGtmId']));
            $current = Options::json(EventsOptions::EV_OTHER_GTM, [], $idShop);
            $next = BeaconLimit::otherGtm($current, array_slice($ids, 0, 5), Clock::secondsSince((string) ($current['at'] ?? '')), Clock::now());

            if ($next !== null) {
                Options::set(EventsOptions::EV_OTHER_GTM, $next, $idShop);
            }

            return 204;
        }

        $event = (string) ($data['e'] ?? '');

        if (!in_array($event, EventsSettings::JS_EVENTS, true) || !$this->settings->eventOn($this->gtm->eventsShop($idShop), $event)) {
            return 400;
        }

        // Do bazy idzie tylko to, co wysyla nasz skrypt, i najwyzej ~2 kB (BeaconLimit::cleanPayload).
        $payload = BeaconLimit::cleanPayload($event, is_array($data['d'] ?? null) ? $data['d'] : []);
        $this->log->write($idShop, 'browser', (string) ($data['p'] ?? ''), [['event' => $event, 'payload' => $payload]]);

        return 204;
    }

    /** Limit zgloszen w oknie 60 s: kubelek IP, potem caly sklep (licznik atomowy, `BeaconLimit::hitSql`). */
    private function withinLimit(int $idShop): bool
    {
        $ip = class_exists(\Tools::class) ? (string) \Tools::getRemoteAddr() : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $limits = [
            'ev.beacon.ip.' . BeaconLimit::bucket($ip) => BeaconLimit::PER_IP,
            'ev.beacon.shop' => BeaconLimit::PER_SHOP,
        ];
        $db = Db::getInstance();
        $table = CoreSchema::table(CoreSchema::CACHE);

        foreach ($limits as $key => $limit) {
            $db->execute(BeaconLimit::hitSql($table, $idShop, $key, Clock::now(), Clock::at(BeaconLimit::WINDOW)));
            $count = (int) $db->getValue('SELECT `payload` FROM `' . $table . '` WHERE `id_shop` = ' . $idShop . ' AND `cache_key` = "' . pSQL($key) . '"', false);

            if (!BeaconLimit::allowed($count, $limit)) {
                return false;
            }
        }

        return true;
    }
}
