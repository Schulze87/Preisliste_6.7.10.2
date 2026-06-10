<?php declare(strict_types=1);

namespace Preisliste\Storefront\Controller;

use Preisliste\Service\PriceListAccessService;
use Preisliste\Service\PriceListCsvService;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Psr\Log\LoggerInterface;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StorefrontRouteScope::ID]])]
class PriceListController extends StorefrontController
{
    private const CONFIG_KEY_AVAILABLE_CATEGORY_IDS = 'Preisliste.config.availableCategoryIds';
    private const VISIBLE_PATH_START_LEVEL = 3;

    public function __construct(
        private readonly PriceListCsvService $priceListCsvService,
        private readonly PriceListAccessService $priceListAccessService,
        private readonly SystemConfigService $systemConfigService,
        private readonly EntityRepository $categoryRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    #[Route(
        path: '/account/preisliste',
        name: 'frontend.account.preisliste.page',
        defaults: ['_loginRequired' => true, '_noStore' => true],
        methods: ['GET']
    )]
    public function index(Request $request, SalesChannelContext $salesChannelContext): Response
    {
        $this->denyUnlessAllowed($salesChannelContext);

        $availableCategories = $this->loadAvailableCategories($salesChannelContext);
        $selectedCategoryIds = $request->query->all('categories');

        if (!is_array($selectedCategoryIds)) {
            $selectedCategoryIds = [];
        }

        $selectedCategoryIds = array_values(array_unique(array_filter(
            $selectedCategoryIds,
            static fn ($value): bool => is_string($value) && $value !== ''
        )));

        return $this->renderStorefront('@Preisliste/storefront/page/account/preisliste/index.html.twig', [
            'availableCategories' => $availableCategories,
            'selectedCategoryIds' => $selectedCategoryIds,
        ]);
    }

    #[Route(
        path: '/account/preisliste/download',
        name: 'frontend.account.preisliste.download',
        defaults: ['_loginRequired' => true, '_noStore' => true],
        methods: ['GET']
    )]
    public function download(Request $request, SalesChannelContext $salesChannelContext): Response
    {
        $this->denyUnlessAllowed($salesChannelContext);

        $selectedCategoryIds = $request->query->all('categories');
        $allowedCategoryIds = $this->getConfiguredCategoryIds($salesChannelContext);

        if (!is_array($selectedCategoryIds)) {
            $selectedCategoryIds = [];
        }

        $selectedCategoryIds = array_values(array_unique(array_filter(
            $selectedCategoryIds,
            static fn ($value): bool =>
                is_string($value)
                && $value !== ''
                && (empty($allowedCategoryIds) || in_array($value, $allowedCategoryIds, true))
        )));

        if ($selectedCategoryIds === []) {
            throw new NotFoundHttpException();
        }

        $this->logger->info('Preisliste download requested', [
            'salesChannelId' => $salesChannelContext->getSalesChannel()->getId(),
            'customerId' => $salesChannelContext->getCustomer()?->getId(),
            'customerGroupId' => $salesChannelContext->getCustomer()?->getGroupId(),
            'selectedCategoryCount' => count($selectedCategoryIds),
        ]);

        $fileName = 'preisliste-' . date('Y-m-d-H-i-s') . '.csv';

        // Variante A: In-Memory (einfach, für kleine bis mittlere Exporte)
        try {
            $csv = $this->priceListCsvService->buildCsv($salesChannelContext, $selectedCategoryIds);

            if ($csv !== '') {
                $headers = [
                    'Content-Type' => 'text/csv; charset=UTF-8',
                    'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
                    'X-Robots-Tag' => 'noindex, nofollow, noarchive, nosnippet',
                    'Content-Length' => (string) strlen($csv),
                ];

                return new Response($csv, 200, $headers);
            }

            // Falls CSV leer ist, liefern wir trotzdem eine leere Datei mit BOM
            $emptyCsv = "\xEF\xBB\xBF";
            $headers = [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
                'X-Robots-Tag' => 'noindex, nofollow, noarchive, nosnippet',
                'Content-Length' => (string) strlen($emptyCsv),
            ];

            return new Response($emptyCsv, 200, $headers);
        } catch (\Throwable $e) {
            // Wenn In-Memory fehlschlägt (z. B. OOM), versuchen wir Temp-File Variante
            $this->logger->warning('In-memory CSV build failed, falling back to temp file', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        // Variante B: Temp-Datei + BinaryFileResponse (skalierbar, empfohlen für große Exporte)
        $tmpPath = tempnam(sys_get_temp_dir(), 'preisliste_');
        if ($tmpPath === false) {
            $this->logger->error('Could not create temporary file for preisliste export');
            return new Response('Fehler beim Erzeugen der Preisliste', 500);
        }

        try {
            $csv = $this->priceListCsvService->buildCsv($salesChannelContext, $selectedCategoryIds);
            if ($csv === '') {
                // Schreibe zumindest BOM, damit Excel die Datei korrekt erkennt
                file_put_contents($tmpPath, "\xEF\xBB\xBF");
            } else {
                file_put_contents($tmpPath, $csv);
            }

            $response = new BinaryFileResponse($tmpPath);
            $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $fileName);
            $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
            $response->deleteFileAfterSend(true);

            $this->logger->info('Preisliste download prepared (temp file)', [
                'fileName' => $fileName,
                'tmpPath' => $tmpPath,
            ]);

            return $response;
        } catch (\Throwable $e) {
            @unlink($tmpPath);
            $this->logger->error('Preisliste download failed (temp file)', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return new Response('Fehler beim Erzeugen der Preisliste', 500);
        }
    }

    private function denyUnlessAllowed(SalesChannelContext $salesChannelContext): void
    {
        if (!$this->priceListAccessService->isAllowed($salesChannelContext)) {
            throw new NotFoundHttpException();
        }
    }

    /**
     * @return list<string>
     */
    private function getConfiguredCategoryIds(SalesChannelContext $salesChannelContext): array
    {
        $configuredCategoryIds = $this->systemConfigService->get(
            self::CONFIG_KEY_AVAILABLE_CATEGORY_IDS,
            $salesChannelContext->getSalesChannelId()
        );

        if (!is_array($configuredCategoryIds)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            $configuredCategoryIds,
            static fn ($value): bool => is_string($value) && $value !== ''
        )));
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    private function loadAvailableCategories(SalesChannelContext $salesChannelContext): array
    {
        $categoryIds = $this->getConfiguredCategoryIds($salesChannelContext);

        if ($categoryIds === []) {
            return [];
        }

        $categoryMap = $this->loadCategoryMap($categoryIds, $salesChannelContext);

        $result = [];

        foreach ($categoryIds as $categoryId) {
            if (!isset($categoryMap[$categoryId])) {
                continue;
            }

            $label = $this->buildCategoryLabelFromLevelThree($categoryMap[$categoryId], $categoryMap);

            $result[] = [
                'id' => $categoryId,
                'label' => $label,
            ];
        }

        usort(
            $result,
            static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label'])
        );

        return $result;
    }

    /**
     * @param list<string> $categoryIds
     * @return array<string, CategoryEntity>
     */
    private function loadCategoryMap(array $categoryIds, SalesChannelContext $salesChannelContext): array
    {
        $pendingIds = $categoryIds;
        $loadedCategories = [];

        while ($pendingIds !== []) {
            $idsToLoad = array_values(array_diff($pendingIds, array_keys($loadedCategories)));

            if ($idsToLoad === []) {
                break;
            }

            $criteria = new Criteria($idsToLoad);
            /** @var EntitySearchResult $searchResult */
            $searchResult = $this->categoryRepository->search($criteria, $salesChannelContext->getContext());

            $pendingIds = [];

            /** @var CategoryEntity $category */
            foreach ($searchResult->getEntities() as $category) {
                $loadedCategories[$category->getId()] = $category;

                $parentId = $category->getParentId();

                if ($parentId !== null && !isset($loadedCategories[$parentId])) {
                    $pendingIds[] = $parentId;
                }
            }

            $pendingIds = array_values(array_unique($pendingIds));
        }

        return $loadedCategories;
    }

    /**
     * @param array<string, CategoryEntity> $categoryMap
     */
    private function buildCategoryLabelFromLevelThree(CategoryEntity $category, array $categoryMap): string
    {
        $chain = [];
        $currentCategory = $category;

        while ($currentCategory !== null) {
            $chain[] = $currentCategory;
            $parentId = $currentCategory->getParentId();

            if ($parentId === null || !isset($categoryMap[$parentId])) {
                break;
            }

            $currentCategory = $categoryMap[$parentId];
        }

        $chain = array_reverse($chain);

        $visibleNames = [];

        foreach ($chain as $chainCategory) {
            $level = $chainCategory->getLevel() ?? 0;

            if ($level < self::VISIBLE_PATH_START_LEVEL) {
                continue;
            }

            $name = $chainCategory->getTranslation('name') ?? $chainCategory->getName() ?? '';

            if ($name === '') {
                continue;
            }

            $visibleNames[] = $name;
        }

        if ($visibleNames === []) {
            return $category->getTranslation('name') ?? $category->getName() ?? '';
        }

        return implode(' - ', $visibleNames);
    }
}
