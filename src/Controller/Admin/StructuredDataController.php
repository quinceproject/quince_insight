<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Controller\Admin;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Configuration;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use Quince\Insight\Core\JobRunner;
use Quince\Insight\Core\ShopContext;
use Quince\Insight\Service\PageSpeed\PageSpeedScreen;
use Quince\Insight\Service\PageSpeed\SamplePages;
use Quince\Insight\Service\StructuredData\JsonLdBuilder;
use Quince\Insight\Service\StructuredData\Output;
use Quince\Insight\Service\StructuredData\ScanJob;
use Quince\Insight\Service\StructuredData\Settings;
use Quince\Insight\Service\StructuredData\ShippingSource;
use Quince\Insight\Service\StructuredData\Types;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ekran Structured data (ARCHITEKTURA §9 blok H): skan stron sklepu, typy do wlaczenia
 * z blokada typow juz obecnych, zapis i podglad JSON-LD per typ strony.
 */
final class StructuredDataController extends PanelController
{
    public function __construct(
        private readonly Settings $settings,
        private readonly JobRunner $jobs,
        private readonly Output $output,
        private readonly SamplePages $samples,
        private readonly ShippingSource $shipping,
    ) {
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", message: 'You do not have permission to view this.')]
    public function indexAction(Request $request): Response
    {
        if (ShopContext::current() === null) {
            return $this->renderPanel('schema/index.html.twig', 'schema');
        }

        $idShop = $this->idShop();
        $scan = $this->settings->scan($idShop);
        $base = ShopContext::baseUrl($idShop);
        $pages = [];

        foreach ((array) ($scan['pages'] ?? []) as $page) {
            $pages[] = [
                'page' => $page['label'],
                'path' => PageSpeedScreen::path((string) $page['url'], $base),
                'found' => $page['found'] ?? [],
                'error' => (string) ($page['error'] ?? ''),
            ];
        }

        $blog = $scan !== null ? !empty($scan['blog']) : SamplePages::blogEnabled($idShop);
        $job = $this->jobs->active($idShop, ScanJob::TYPE);

        return $this->renderPanel('schema/index.html.twig', 'schema', [
            'sd' => [
                'scanned' => $scan !== null ? substr((string) ($scan['at'] ?? ''), 0, 16) : '',
                'scan' => $pages,
                'present' => (object) ($scan['present'] ?? []),
                'types' => Types::definitions(),
                'noForce' => Types::NO_FORCE,
                'on' => $this->settings->on($idShop),
                'force' => (object) array_diff_key(array_filter($this->settings->force($idShop)), array_flip(Types::NO_FORCE)),
                'blog' => $blog,
                'shippingData' => $this->shipping->forShop($idShop) !== null,
                'job' => $job?->toArray(),
                'autoScan' => $scan === null && $job === null,
                'urls' => [
                    'scan' => $this->generateUrl('quince_insight_schema_scan'),
                    'save' => $this->generateUrl('quince_insight_schema_save'),
                    'preview' => $this->generateUrl('quince_insight_schema_preview'),
                ],
            ],
        ]);
    }

    /** "Scan again": zadanie `structureddata.scan`. */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function scanAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_schema_scan');

        $idJob = $this->jobs->start($this->idShop(), ScanJob::TYPE, ['source' => 'panel']);

        return $this->ok(['job' => $this->jobs->status($idJob)->toArray()]);
    }

    /**
     * Zapis wlaczonych typow. Typ obecny juz na stronach (motyw / inny modul) przechodzi tylko
     * z "Add anyway". Zapis zmienia kod stron sklepu — dlatego dopiero po potwierdzeniu
     * na ekranie (przycisk Save pod opisem skutku, jak w prototypie).
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function saveAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_schema_save');

        $idShop = $this->idShop();
        $data = $this->payload($request);
        $scan = $this->settings->scan($idShop);

        if ($scan === null) {
            return $this->fail($this->t('Scan the shop first, so the module knows what the theme already outputs.'), 400);
        }

        $rejected = $this->settings->save($idShop, (array) ($data['on'] ?? []), (array) ($data['force'] ?? []), (array) ($scan['present'] ?? []));

        // Decyzja #18: warianty przy Product z motywu / innego modulu — blokada bez „Add anyway”.
        if (in_array(Types::VARIANTS, $rejected, true)) {
            return $this->fail($this->t('Product variants stay off while the theme or another module outputs Product on product pages. Turn Product off there, then scan again.'), 400);
        }

        if ($rejected !== []) {
            return $this->fail($this->t('The theme already outputs %types%. Click Add anyway first.', [
                '%types%' => implode(', ', array_map([Types::class, 'name'], $rejected)),
            ]), 400);
        }

        $count = count(array_filter($this->settings->on($idShop)));

        return $this->ok([
            'message' => $count > 0
                ? $this->t('Saved — %count% types added to the shop pages', ['%count%' => $count])
                : $this->t('Saved — the module adds no structured data'),
        ]);
    }

    /**
     * Podglad JSON-LD dla strony danego typu z biezacymi (jeszcze niezapisanymi) przelacznikami.
     * Te same klasy co na stronie sklepu (`Output`), wiec podglad = to, co trafi na strone.
     */
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", message: 'You do not have permission to view this.')]
    public function previewAction(Request $request): JsonResponse
    {
        $this->guardCsrf($request, 'quince_insight_schema_preview');

        $idShop = $this->idShop();
        $data = $this->payload($request);
        $page = (string) ($data['page'] ?? Output::HOME);
        $on = Types::normalize((array) ($data['on'] ?? []));

        if (!in_array($page, [Output::HOME, Output::CATEGORY, Output::PRODUCT, Output::BLOG], true)) {
            return $this->fail($this->t('Unknown page type.'), 400);
        }

        $result = ShopContext::run($idShop, function () use ($idShop, $page, $on): array {
            $sample = $this->samples->forShop($idShop)[$page === Output::HOME ? SamplePages::HOME : $page] ?? null;

            if ($sample === null && $page !== Output::HOME) {
                return ['nodes' => [], 'skipped' => [], 'url' => ''];
            }

            $idLang = (int) Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);
            $built = $this->output->build($idShop, $idLang, $page, (int) ($sample['id'] ?? 0), $on);
            $built['url'] = (string) ($sample['url'] ?? '');

            return $built;
        });

        $text = implode("\n\n", array_map(static fn (array $node): string => JsonLdBuilder::encode($node, true), $result['nodes']));

        $skipped = array_map(fn (array $s): string => $this->t('%type% is not added: missing %fields%.', [
            '%type%' => $s['type'],
            '%fields%' => implode(', ', $s['errors']),
        ]), $result['skipped']);

        return $this->ok(['json' => $text, 'skipped' => $skipped, 'url' => $result['url']]);
    }
}
