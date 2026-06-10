<?php declare(strict_types=1);

namespace Preisliste\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
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
        private readonly ?LoggerInterface $logger = null,
        private readonly ?ContainerInterface $container = null,
        private readonly ?EntityRepository $productPriceRepository = null
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

                // Kein Match → kein Preis (kein Fallback!)
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

    /**
     * Hauptmethode: Preise aus erweiterten Preisen (Rule-basiert) ermitteln
     * KEIN Fallback auf Standardpreise!
     */
    private function resolvePriceFromAllAdvancedPrices(
        SalesChannelProductEntity $product,
        SalesChannelContext $context
    ): ?float {
        // Erweiterte Preise laden (Fix für 6.7.10.2)
        $advancedPrices = $this->loadAdvancedPrices($product->getId(), $context);
        
        if ($advancedPrices === []) {
            $this->logger?->debug('No advanced prices found for product', [
                'productId' => $product->getId(),
                'productNumber' => $product->getProductNumber()
            ]);
            return null;
        }

        $activeRules = $context->getRuleIds();
        $configuredRules = $this->systemConfigService->get('Preisliste.config.priceGroupRuleIds') ?? [];
        
        if (!is_array($configuredRules) || $configuredRules === []) {
            $this->logger?->debug('No configured rules found');
            return null;
        }

        $matchingRules = array_values(array_intersect($activeRules, $configuredRules));
        if ($matchingRules === []) {
            $this->logger?->debug('No matching rules', [
                'activeRules' => $activeRules,
                'configuredRules' => $configuredRules
            ]);
            return null;
        }

        $ruleId = $matchingRules[0];

        foreach ($advancedPrices as $price) {
            $priceRuleId = $this->getPriceRuleId($price);
            
            if ($priceRuleId === $ruleId) {
                $netPrice = $this->extractNetPriceFromPrice($price, $context);
                if ($netPrice !== null && is_numeric($netPrice) && $netPrice > 0) {
                    $this->logger?->debug('Found matching price', [
                        'productId' => $product->getId(),
                        'ruleId' => $ruleId,
                        'netPrice' => $netPrice
                    ]);
                    return (float) $netPrice;
                }
            }
        }

        return null;
    }

    /**
     * Lädt erweiterte Preise für ein Produkt (kompatibel mit 6.7.10.2)
     */
    private function loadAdvancedPrices(string $productId, SalesChannelContext $context): array
    {
        // Versuche über Repository (wenn injiziert)
        if ($this->productPriceRepository !== null) {
            try {
                $criteria = new Criteria();
                $criteria->addFilter(new EqualsFilter('productId', $productId));
                $criteria->addAssociation('rule');
                
                $prices = $this->productPriceRepository->search($criteria, $context->getContext())->getEntities();
                
                if ($prices->count() > 0) {
                    return iterator_to_array($prices);
                }
            } catch (\Throwable $e) {
                $this->logger?->warning('Repository price loading failed, trying DBAL fallback', [
                    'productId' => $productId,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Fallback: Direkte DB-Abfrage über DBAL
        return $this->loadPricesViaDBAL($productId, $context);
    }

    /**
     * DBAL-Fallback für erweiterte Preise
     */
    private function loadPricesViaDBAL(string $productId, SalesChannelContext $context): array
    {
        if ($this->container === null) {
            return [];
        }

        try {
            /** @var \Doctrine\DBAL\Connection $connection */
            $connection = $this->container->get(\Doctrine\DBAL\Connection::class);
            
            // Konvertiere UUID von hex zu binary
            $productIdBin = hex2bin(str_replace('-', '', $productId));
            
            $sql = "
                SELECT 
                    pp.id,
                    pp.product_id,
                    pp.rule_id,
                    pp.quantity_start,
                    pp.quantity_end,
                    pp.price as price_json
                FROM product_price pp
                WHERE pp.product_id = :productId
            ";
            
            $results = $connection->fetchAllAssociative($sql, ['productId' => $productIdBin]);
            
            $prices = [];
            foreach ($results as $row) {
                $priceData = json_decode($row['price_json'], true);
                $prices[] = [
                    'id' => bin2hex($row['id']),
                    'ruleId' => $row['rule_id'] ? bin2hex($row['rule_id']) : null,
                    'quantityStart' => $row['quantity_start'],
                    'quantityEnd' => $row['quantity_end'],
                    'price' => $priceData
                ];
            }
            
            $this->logger?->debug('Loaded prices via DBAL', [
                'productId' => $productId,
                'count' => count($prices)
            ]);
            
            return $prices;
        } catch (\Throwable $e) {
            $this->logger?->error('DBAL price loading failed', [
                'productId' => $productId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Extrahiert die Rule-ID aus einem Price-Objekt oder Array
     */
    private function getPriceRuleId($price): ?string
    {
        // Objekt mit getRuleId() Methode
        if (is_object($price) && method_exists($price, 'getRuleId')) {
            $ruleId = $price->getRuleId();
            return $ruleId ?: null;
        }
        
        // Objekt mit getRule() Methode
        if (is_object($price) && method_exists($price, 'getRule')) {
            $rule = $price->getRule();
            if ($rule && method_exists($rule, 'getId')) {
                return $rule->getId();
            }
        }
        
        // Array-Zugriff
        if (is_array($price) && isset($price['ruleId'])) {
            return $price['ruleId'];
        }
        
        return null;
    }

    /**
     * Extrahiert den Netto-Preis aus einem Price-Objekt oder Array
     */
    private function extractNetPriceFromPrice($price, SalesChannelContext $context): ?float
    {
        $currencyId = $context->getCurrency()->getId();
        
        // Objekt mit getPrice() Methode (Standard Shopware)
        if (is_object($price) && method_exists($price, 'getPrice')) {
            $priceStruct = $price->getPrice();
            if ($priceStruct && method_exists($priceStruct, 'getCurrencyPrice')) {
                $currencyPrice = $priceStruct->getCurrencyPrice($currencyId);
                if ($currencyPrice && method_exists($currencyPrice, 'getNet')) {
                    $net = $currencyPrice->getNet();
                    return $net !== null ? (float) $net : null;
                }
            }
        }
        
        // Array-Zugriff für DBAL-Fallback
        if (is_array($price) && isset($price['price'])) {
            foreach ($price['price'] as $currencyPrice) {
                if (isset($currencyPrice['currencyId']) && $currencyPrice['currencyId'] === $currencyId) {
                    if (isset($currencyPrice['net'])) {
                        return (float) $currencyPrice['net'];
                    }
                    if (isset($currencyPrice['gross'])) {
                        // Falls nur brutto vorhanden, netto schätzen (20% MwSt.)
                        return (float) $currencyPrice['gross'] / 1.19;
                    }
                }
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
