<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Service\Merchant;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Db;
use Quince\Insight\Core\Clock;
use Quince\Insight\Core\Google\GoogleApiException;
use Quince\Insight\Core\Options;
use Quince\Insight\Core\Schema as CoreSchema;

/**
 * Odswiezanie statusow produktow i problemow konta (cron `statuses`, przycisk w panelu).
 *
 * W FeedForge tylko na klikniecie (research/02 §1.4); powiadomienia Google nie obejmuja
 * bezplatnych informacji (ARCHITEKTURA §12 pkt 7), wiec cron jest jedynym zrodlem.
 * Produkt dopasowywany po feedzie (`contentLanguage` + `feedLabel`) i zapisanym `offer_id`,
 * nie po rozbieraniu identyfikatora jak w FeedForge.
 */
final class StatusRefresher
{
    public function __construct(
        private readonly FeedRepository $feeds,
        private readonly MerchantAccount $account,
        private readonly MerchantApi $api,
    ) {
    }

    /**
     * @return array{updated:int,pages:int,complete:bool}
     */
    public function run(int $idShop, float $deadline): array
    {
        $feeds = [];

        foreach ($this->feeds->forShop($idShop) as $feed) {
            if (!$feed->isDraft() && $feed->dataSource !== '') {
                $feeds[strtolower($feed->contentLanguage) . '~' . strtoupper($feed->feedLabel)] = $feed;
            }
        }

        $result = ['updated' => 0, 'pages' => 0, 'complete' => true];

        if ($feeds === [] || $this->account->accountId($idShop) === '') {
            return $result;
        }

        $token = Options::str(MerchantOptions::STATUS_PAGE_TOKEN, '', $idShop);
        $table = CoreSchema::name(Schema::FEED_PRODUCT);

        do {
            $page = $this->api->products($idShop, $token);
            ++$result['pages'];

            foreach ($page['products'] as $product) {
                $key = strtolower((string) ($product['contentLanguage'] ?? '')) . '~' . strtoupper((string) ($product['feedLabel'] ?? ''));
                $feed = $feeds[$key] ?? null;
                $offer = (string) ($product['offerId'] ?? '');

                if ($feed === null || $offer === '') {
                    continue;
                }

                $status = (array) ($product['productStatus'] ?? []);
                $issues = StatusParser::issues($status);

                Db::getInstance()->update($table, [
                    'google_status' => pSQL(StatusParser::overall($status, $feed->country)),
                    'google_issues' => pSQL((string) json_encode($issues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true),
                    'status_at' => pSQL(Clock::now()),
                ], '`id_feed` = ' . $feed->id . ' AND `offer_id` = "' . pSQL($offer) . '" AND `sync_state` IN ("synced", "error")');

                $result['updated'] += (int) Db::getInstance()->Affected_Rows();
            }

            $token = $page['next'];
        } while ($token !== '' && microtime(true) < $deadline);

        Options::set(MerchantOptions::STATUS_PAGE_TOKEN, $token, $idShop);
        $result['complete'] = $token === '';

        if ($result['complete']) {
            foreach ($feeds as $feed) {
                $this->feeds->touchStatusSync($feed->id);
            }

            // Problemy konta i przejecie adresu przy okazji pelnego przejscia (tanie, 2 wywolania).
            try {
                $this->account->refreshIssues($idShop);
                $this->account->refreshClaim($idShop);
            } catch (GoogleApiException $e) {
                // Statusy produktow sa zapisane; konto odswiezy sie przy nastepnym przejsciu.
            }
        }

        return $result;
    }
}
