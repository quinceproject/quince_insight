<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Wizard;

use Db;
use Quince\Insight\Core\Dev;
use Quince\Insight\Core\Google\ConnectionRepository;
use Quince\Insight\Core\Log;
use Quince\Insight\Core\Scheduler;
use Quince\Insight\Core\Schema;
use Quince\Insight\Core\Services;
use Quince\Insight\Core\ShopContext;
use Quince\Insight\Core\ShopRepository;

/**
 * Informacje do zgloszenia bledu (Support → System information).
 *
 * Bez sekretow: zadnych tokenow, Client secret, klucza API, adresow crona z tokenem.
 * E-mail konta Google jest zamaskowany, adresy z dziennika przechodza przez
 * `Log::safeUrl()`.
 */
final class Diagnostics
{
    public function __construct(
        private readonly ConnectionRepository $connections,
        private readonly ShopRepository $shops,
        private readonly Services $services,
        private readonly CronBlock $cron,
        private readonly Scheduler $scheduler,
    ) {
    }

    /** @return array<string,string> etykieta => wartosc */
    public function system(int $idShop, string $moduleVersion): array
    {
        $connection = $this->connections->forShop($idShop);
        $shopList = ShopContext::shops();

        $out = [
            'Quince Insight' => $moduleVersion,
            'PrestaShop' => defined('_PS_VERSION_') ? (string) _PS_VERSION_ : '',
            'PHP' => PHP_VERSION,
            'Multistore' => ShopContext::isMultistore() ? 'on, ' . count($shopList) . ' shops' : 'off',
            'Shop' => '#' . $idShop . ' ' . ShopContext::name($idShop),
            'Shop address' => ShopContext::baseUrl($idShop),
            'HTTPS' => ShopContext::isHttps($idShop) ? 'yes' : 'no',
            'Setup finished' => $this->shops->wizardDone($idShop) ? 'yes' : 'no',
            'Services' => implode(', ', $this->services->enabledKeys($idShop)) ?: 'none',
            'Google connection' => $connection === null
                ? 'none'
                : $connection->status . ' · ' . self::maskEmail($connection->googleEmail) . ' · ' . count($connection->scopes) . ' scopes'
                    . ($connection->hasApiKey ? ' · API key saved' : ''),
            'cURL' => function_exists('curl_version') ? (string) (curl_version()['version'] ?? 'yes') : 'missing',
            'OpenSSL' => defined('OPENSSL_VERSION_TEXT') ? OPENSSL_VERSION_TEXT : 'missing',
        ];

        if (Dev::fakeGoogle()) {
            $out['Fake Google mode'] = 'on';
        }

        foreach ($this->cron->data($idShop)['rows'] as $row) {
            $state = $this->scheduler->groupState($idShop, (string) $row['key']);
            $out['Cron ' . $row['key']] = ($state['last_run'] ?? 'never')
                . ($state['last_status'] !== '' ? ' · ' . $state['last_status'] : '')
                . ($state['overdue'] ? ' · late' : '');
        }

        return $out;
    }

    /**
     * Ostatnie bledy i ostrzezenia z dziennika modulu (bez tresci `context`).
     *
     * @return array<int,array{date:string,service:string,level:string,code:int,message:string,url:string}>
     */
    public function recentErrors(int $idShop, int $limit = 10): array
    {
        try {
            $rows = Db::getInstance()->executeS(
                'SELECT `date_add`, `service`, `level`, `http_code`, `message`, `http_method`, `url`
                 FROM `' . Schema::table(Schema::API_LOG) . '`
                 WHERE `id_shop` IN (0, ' . $idShop . ') AND `level` IN ("error", "warning")
                 ORDER BY `id_log` DESC LIMIT ' . max(1, min(50, $limit))
            ) ?: [];
        } catch (\Throwable $e) {
            return [];
        }

        return array_map(static fn (array $row): array => [
            'date' => (string) $row['date_add'],
            'service' => (string) $row['service'],
            'level' => (string) $row['level'],
            'code' => (int) $row['http_code'],
            'message' => (string) $row['message'],
            'url' => trim((string) $row['http_method'] . ' ' . Log::safeUrl((string) $row['url'])),
        ], $rows);
    }

    /** Calosc jako tekst do wklejenia w zgloszenie. */
    public function text(int $idShop, string $moduleVersion): string
    {
        $lines = [];

        foreach ($this->system($idShop, $moduleVersion) as $label => $value) {
            $lines[] = $label . ': ' . $value;
        }

        $errors = $this->recentErrors($idShop);
        $lines[] = '';
        $lines[] = 'Recent errors: ' . ($errors === [] ? 'none' : count($errors));

        foreach ($errors as $error) {
            $lines[] = $error['date'] . ' ' . $error['level'] . ' ' . $error['service']
                . ($error['code'] > 0 ? ' ' . $error['code'] : '') . ' ' . $error['message']
                . ($error['url'] !== '' ? ' [' . $error['url'] . ']' : '');
        }

        return implode("\n", $lines);
    }

    /** "owner@yourshop.com" -> "o***@yourshop.com". */
    public static function maskEmail(string $email): string
    {
        $at = strpos($email, '@');

        if ($email === '' || $at === false) {
            return $email === '' ? '-' : '***';
        }

        return substr($email, 0, 1) . '***' . substr($email, $at);
    }
}
