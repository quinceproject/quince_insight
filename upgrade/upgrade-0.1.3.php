<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

use Quince\Insight\Core\Crypto;
use Quince\Insight\Core\Google\ConnectionRepository;
use Quince\Insight\Core\Options;
use Quince\Insight\Core\Schema;

/**
 * 0.1.3: sekrety zapisane bez kontekstu (`qi1:`) dostaja kontekst (`qi2:`).
 *
 * Wartosci odszyfrowujemy starym sposobem i szyfrujemy ponownie z kontekstem pola
 * i polaczenia albo ustawienia i sklepu. Wartosc, ktorej nie da sie odczytac
 * (inny `_COOKIE_KEY_`), zostaje bez zmian: odczyt i tak zwraca dla niej pusty tekst.
 */
function upgrade_module_0_1_3($module): bool
{
    $db = Db::getInstance();

    $polaczenia = $db->executeS(
        'SELECT `id_connection`, `client_secret`, `api_key`, `access_token`, `refresh_token`'
        . ' FROM `' . Schema::table(Schema::CONNECTION) . '`'
    ) ?: [];

    foreach ($polaczenia as $wiersz) {
        $id = (int) $wiersz['id_connection'];
        $pola = [];

        foreach (['client_secret', 'api_key', 'access_token', 'refresh_token'] as $pole) {
            $nowa = quince_insight_rebind((string) $wiersz[$pole], ConnectionRepository::context($id, $pole));

            if ($nowa !== null) {
                $pola[$pole] = pSQL($nowa);
            }
        }

        if ($pola !== [] && !$db->update(Schema::name(Schema::CONNECTION), $pola, '`id_connection` = ' . $id)) {
            return false;
        }
    }

    $ustawienia = $db->executeS(
        'SELECT `id_shop`, `name`, `value` FROM `' . Schema::table(Schema::SETTING) . '`'
        . ' WHERE `value` LIKE "qi1:%"'
    ) ?: [];

    foreach ($ustawienia as $wiersz) {
        $idShop = (int) $wiersz['id_shop'];
        $nazwa = (string) $wiersz['name'];
        $nowa = quince_insight_rebind((string) $wiersz['value'], Options::secretContext($idShop, $nazwa));

        if ($nowa === null) {
            continue;
        }

        $zapisane = $db->update(
            Schema::name(Schema::SETTING),
            ['value' => pSQL($nowa, true)],
            '`id_shop` = ' . $idShop . ' AND `name` = "' . pSQL($nazwa) . '"'
        );

        if (!$zapisane) {
            return false;
        }
    }

    return true;
}

/** Nowa postac wartosci albo null, gdy nie ma czego przepisywac. */
function quince_insight_rebind(string $zapisana, string $kontekst): ?string
{
    if (!Crypto::isLegacy($zapisana)) {
        return null;
    }

    $jawna = Crypto::decrypt($zapisana, $kontekst);

    return $jawna === '' ? null : Crypto::encrypt($jawna, $kontekst);
}
