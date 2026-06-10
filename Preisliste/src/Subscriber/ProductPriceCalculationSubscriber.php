<?php declare(strict_types=1);

namespace Preisliste\Subscriber;

use Psr\Log\LoggerInterface;
use Psr\Container\ContainerInterface;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\CalculatedPrice;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\CalculatedPriceCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Core\Content\Product\Events\ProductPriceCalculatedEvent;
use Shopware\Core\Content\Product\Events\SalesChannelProductLoadedEvent;
use Shopware\Core\Content\Product\SalesChannel\Price\AbstractProductPriceCalculator;

class ProductPriceCalculationSubscriber implements EventSubscriberInterface
{
    private array $candidateCalculatorIds = [
        'Shopware\Core\Content\Product\SalesChannel\Price\AbstractProductPriceCalculator',
        'Shopware\Core\Content\Product\SalesChannel\Price\ProductPriceCalculator',
        'shopware.product_price_calculator',
        'Shopware\Core\Content\Product\SalesChannel\Price\AppScriptProductPriceCalculator',
    ];

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ?LoggerInterface $logger = null
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            ProductPriceCalculatedEvent::class => ['onProductPriceCalculated', -10],
            SalesChannelProductLoadedEvent::class => ['onSalesChannelProductsLoaded', 5],
        ];
    }

    public function onProductPriceCalculated(ProductPriceCalculatedEvent $event): void
    {
        $context = $event->getSalesChannelContext();
        
        foreach ($event->getProducts() as $product) {
            $this->ensureValidPrices($product, $context);
        }
    }

    public function onSalesChannelProductsLoaded(SalesChannelProductLoadedEvent $event): void
    {
        $calculator = $this->resolveCalculator();
        if ($calculator === null) {
            $this->logger?->warning('ProductPriceCalculationSubscriber: No calculator found');
            return;
        }

        $context = $event->getSalesChannelContext();

        foreach ($event->getEntities() as $entity) {
            if (!$entity instanceof SalesChannelProductEntity) {
                continue;
            }

            $this->ensureValidPrices($entity, $context, $calculator);
        }
    }

    private function ensureValidPrices(
        SalesChannelProductEntity $product, 
        SalesChannelContext $context, 
        ?object $calculator = null
    ): void {
        $hasCalculated = ($product->getCalculatedPrices() instanceof CalculatedPriceCollection && $product->getCalculatedPrices()->count() > 0)
            || ($product->getCalculatedPrice() instanceof CalculatedPrice);

        if ($hasCalculated) {
            return;
        }

        if ($calculator !== null) {
            $this->calculatePricesWithCalculator($calculator, $product, $context);
            return;
        }

        $calc = $this->resolveCalculator();
        if ($calc !== null) {
            $this->calculatePricesWithCalculator($calc, $product, $context);
            return;
        }

        // KEIN Fallback auf falsche Preise!
        $this->logger?->error('ProductPriceCalculationSubscriber: No calculator available - prices will be missing', [
            'productId' => $product->getId(),
            'productNumber' => $product->getProductNumber(),
        ]);
    }

    private function calculatePricesWithCalculator(
        object $calculator, 
        SalesChannelProductEntity $product, 
        SalesChannelContext $context
    ): void {
        try {
            if ($calculator instanceof AbstractProductPriceCalculator) {
                $result = $calculator->calculate($product, $context);
                if ($result instanceof CalculatedPrice) {
                    $product->setCalculatedPrice($result);
                } elseif ($result instanceof CalculatedPriceCollection) {
                    $product->setCalculatedPrices($result);
                }
                return;
            }

            $result = $this->invokeCalculatorSafely($calculator, $product, $context);
            
            if ($result instanceof CalculatedPriceCollection) {
                $product->setCalculatedPrices($result);
            } elseif ($result instanceof CalculatedPrice) {
                $product->setCalculatedPrice($result);
            }
        } catch (\Throwable $e) {
            // KEIN Fallback - nur loggen
            $this->logger?->error('ProductPriceCalculationSubscriber: calculator failed', [
                'productId' => $product->getId(),
                'productNumber' => $product->getProductNumber(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function resolveCalculator(): ?object
    {
        foreach ($this->candidateCalculatorIds as $id) {
            try {
                if ($this->container->has($id)) {
                    $service = $this->container->get($id);
                    if (is_object($service)) {
                        $this->logger?->debug('ProductPriceCalculationSubscriber: Found calculator', [
                            'serviceId' => $id,
                            'class' => get_class($service)
                        ]);
                        return $service;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger?->warning('ProductPriceCalculationSubscriber: error resolving calculator', [
                    'serviceId' => $id,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    private function invokeCalculatorSafely(object $calculator, SalesChannelProductEntity $product, SalesChannelContext $context)
    {
        if (is_callable([$calculator, 'calculate'])) {
            return $calculator->calculate($product, $context);
        }

        if (is_callable([$calculator, 'calculatePrice'])) {
            return $calculator->calculatePrice($product, $context);
        }

        if (is_callable([$calculator, 'calculateProductPrice'])) {
            return $calculator->calculateProductPrice($product, $context);
        }

        return null;
    }
}
