<?php declare(strict_types=1);

namespace Preisliste\Service;

use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\CalculatedPriceCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class PriceListCsvService
{
    private const CUSTOM_FIELD_PRICE_PER_STACK = 'custom_product_information_price_per_stack';

    /** @var array<string,bool> */
    private array $printedCategories = [];

    public function __construct(
        private readonly SalesChannelRepository $salesChannelProductRepository,
        private readonly EntityRepository $categoryRepository,
        private readonly SystemConfigService $systemConfigService,
    ) {
    }

    public function buildCsv(SalesChannelContext $salesChannelContext, array $selectedCategoryIds): string
    {
        $this->printedCategories = [];

        $categories = $this->loadCategories($selectedCategoryIds, $salesChannelContext->getContext());

        $handle = fopen('php://temp', 'w+');
        fwrite($handle, "\xEF\xBB\xBF");

        fputcsv($handle, ['Artikelnummer', 'Produktname', 'Nettopreis', 'Preis gilt für', 'Einheit'], ';');

        foreach ($categories as $categoryId => $categoryData) {

            if (!isset($this->printedCategories[$categoryId])) {
                fputcsv($handle, [$categoryData['categoryLabel']], ';');
                $this->printedCategories[$categoryId] = true;
            }

            $this->writeCategoryProducts($handle, $salesChannelContext, $categoryId);
            fputcsv($handle, [], ';');
        }

        rewind($handle);
        return stream_get_contents($handle) ?: '';
    }

    public function streamCsv(SalesChannelContext $salesChannelContext, array $selectedCategoryIds, $outputHandle): void
    {
        $this->printedCategories = [];

        fwrite($outputHandle, "\xEF\xBB\xBF");
        fputcsv($outputHandle, ['Artikelnummer', 'Produktname', 'Nettopreis', 'Preis gilt für', 'Einheit'], ';');

        $categories = $this->loadCategories($selectedCategoryIds, $salesChannelContext->getContext());

        foreach ($categories as $categoryId => $categoryData) {

            if (!isset($this->printedCategories[$categoryId])) {
                fputcsv($outputHandle, [$categoryData['categoryLabel']], ';');
                $this->printedCategories[$categoryId] = true;
            }

            $this->writeCategoryProducts($outputHandle, $salesChannelContext, $categoryId);
            fputcsv($outputHandle, [], ';');
        }
    }

    private function loadCategories(array $selectedCategoryIds, Context $context): array
    {
        if ($selectedCategoryIds === []) return [];

        $criteria = new Criteria($selectedCategoryIds);
        $criteria->addAssociation('parent');
        $criteria->addAssociation('translations');

        $categories = $this->categoryRepository->search($criteria, $context)->getEntities();

        $result = [];
        foreach ($categories as $category) {
            $parentName = $category->getParent()?->getTranslation('name') ?? '';
            $categoryName = $category->getTranslation('name') ?? '';

            $groupLabel = $parentName !== '' ? $parentName : $categoryName;
            $categoryLabel = $parentName !== '' ? "$parentName > $categoryName" : $categoryName;

            $result[$category->getId()] = [
                'groupLabel' => $groupLabel,
                'categoryLabel' => $categoryLabel,
            ];
        }

        uasort($result, fn($a, $b) => [$a['groupLabel'], $a['categoryLabel']] <=> [$b['groupLabel'], $b['categoryLabel']]);
        return $result;
    }

    private function writeCategoryProducts($handle, SalesChannelContext $context, string $categoryId): void
    {
        $limit = 5000;
        $offset = 0;

        do {
            $criteria = new Criteria();
            $criteria->addAssociation('prices');
            $criteria->addAssociation('children');
            $criteria->addAssociation('translations');
            $criteria->addFilter(new EqualsFilter('active', true));
            $criteria->addFilter(new EqualsAnyFilter('categoriesRo.id', [$categoryId]));
            $criteria->setLimit($limit)->setOffset($offset);

            $products = $this->salesChannelProductRepository->search($criteria, $context)->getEntities();
            $products->sort(fn($a, $b) => strcmp($a->getProductNumber(), $b->getProductNumber()));

            foreach ($products as $product) {

                if ($product->getChildCount() > 0) continue;

                $productNumber = $product->getProductNumber() ?? '';
                $productName = $product->getTranslation('name') ?? '';

                // Preis NUR aus Advanced Prices
                $basePrice = $this->resolvePriceFromAllAdvancedPrices($product, $context);

                // Kein Match → kein Preis
                if ($basePrice === null) {
                    fputcsv($handle, [$productNumber, $productName, '', '', ''], ';');
                    continue;
                }

                $customFields = $product->getCustomFields() ?? [];
                $pricePerStackRaw = $customFields[self::CUSTOM_FIELD_PRICE_PER_STACK] ?? null;
                $pricePerStack = $this->normalizeStackValue($pricePerStackRaw);

                $calculatedExportPrice = round($basePrice * $pricePerStack, 2);
                $unit = $this->resolvePackUnit($product, $pricePerStack);

                fputcsv($handle, [
                    $productNumber,
                    $productName,
                    number_format($calculatedExportPrice, 2, ',', ''),
                    $this->formatStackValue($pricePerStackRaw, $pricePerStack),
                    $unit,
                ], ';');
            }

            $offset += $limit;
        } while ($products->count() > 0);
    }

    private function resolvePriceFromAllAdvancedPrices(
        SalesChannelProductEntity $product,
        SalesChannelContext $context
    ): ?float {

        $advanced = $product->getPrices()?->getElements() ?? [];
        if ($advanced === []) return null;

        $activeRules = $context->getRuleIds();
        $configuredRules = $this->systemConfigService->get('Preisliste.config.priceGroupRuleIds') ?? [];

        $matchingRules = array_values(array_intersect($activeRules, $configuredRules));
        if ($matchingRules === []) return null;

        $ruleId = $matchingRules[0];

        foreach ($advanced as $price) {
            if ($price->getRuleId() === $ruleId) {
                $currencyPrice = $price->getPrice()?->getCurrencyPrice($context->getCurrencyId());
                return $currencyPrice?->getNet() ?? null;
            }
        }

        return null;
    }

    private function normalizeStackValue(mixed $value): float
    {
        if ($value === null || $value === '') return 1.0;
        $value = str_replace(',', '.', (string)$value);
        return max(1.0, (float)$value);
    }

    private function formatStackValue(mixed $rawValue, float $normalizedValue): string
    {
        if ($rawValue === null || $rawValue === '') return '1';
        if (floor($normalizedValue) === $normalizedValue) return (string)(int)$normalizedValue;
        return rtrim(rtrim(number_format($normalizedValue, 6, '.', ''), '0'), '.');
    }

    private function resolvePackUnit(SalesChannelProductEntity $product, float $pricePerStack): string
    {
        return $pricePerStack === 1.0
            ? (string)($product->getPackUnit() ?? '')
            : (string)($product->getPackUnitPlural() ?: $product->getPackUnit() ?: '');
    }
}
