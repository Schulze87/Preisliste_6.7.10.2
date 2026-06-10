<?php declare(strict_types=1);

namespace Preisliste\Subscriber;

use Psr\Log\LoggerInterface;
use Psr\Container\ContainerInterface;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\CalculatedPrice;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\CalculatedPriceCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Core\Content\Product\Events\SalesChannelProductLoadedEvent;

class ProductPriceCalculationSubscriber implements EventSubscriberInterface
{
    private array $candidateCalculatorIds = [
        'shopware.product_price_calculator',
        'Shopware\Core\Content\Product\SalesChannel\Price\ProductPriceCalculator',
        'Shopware\Core\Content\Product\SalesChannel\Price\AppScriptProductPriceCalculator',
    ];

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ?LoggerInterface $logger = null
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            SalesChannelProductLoadedEvent::class => 'onSalesChannelProductsLoaded',
        ];
    }

    public function onSalesChannelProductsLoaded(SalesChannelProductLoadedEvent $event): void
    {
        $calculator = $this->resolveCalculator();
        if ($calculator === null) {
            // Kein Calculator vorhanden — nichts zu tun
            return;
        }

        $context = $event->getSalesChannelContext();

        foreach ($event->getEntities() as $entity) {
            if (!$entity instanceof SalesChannelProductEntity) {
                continue;
            }

            // Wenn bereits calculatedPrices oder calculatedPrice gesetzt sind, nicht überschreiben
            $hasCalculated = ($entity->getCalculatedPrices() instanceof CalculatedPriceCollection && $entity->getCalculatedPrices()->count() > 0)
                || ($entity->getCalculatedPrice() instanceof CalculatedPrice);

            if ($hasCalculated) {
                continue;
            }

            try {
                $calculated = $this->invokeCalculatorSafely($calculator, $entity, $context);

                if ($calculated instanceof CalculatedPriceCollection) {
                    $entity->setCalculatedPrices($calculated);
                } elseif ($calculated instanceof CalculatedPrice) {
                    $entity->setCalculatedPrice($calculated);
                } elseif (is_array($calculated)) {
                    // flexible handling: array with 'unitPrice' or 'calculatedPrices'
                    if (isset($calculated['calculatedPrices']) && $calculated['calculatedPrices'] instanceof CalculatedPriceCollection) {
                        $entity->setCalculatedPrices($calculated['calculatedPrices']);
                    } elseif (isset($calculated['unitPrice']) && is_numeric($calculated['unitPrice'])) {
                        $unit = (float) $calculated['unitPrice'];
                        $cp = new CalculatedPrice((string) $unit, $unit, [], $entity->getId());
                        $entity->setCalculatedPrice($cp);
                    }
                }
            } catch (\Throwable $e) {
                $this->logger?->warning('ProductPriceCalculationSubscriber: calculator failed for product', [
                    'productId' => $entity->getId(),
                    'exception' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                // Fehler defensiv: nicht weiterwerfen, damit Export/Seite nicht abstürzt
            }
        }
    }

    private function resolveCalculator(): ?object
    {
        foreach ($this->candidateCalculatorIds as $id) {
            try {
                if ($this->container->has($id)) {
                    $service = $this->container->get($id);
                    if (is_object($service)) {
                        return $service;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger?->warning('ProductPriceCalculationSubscriber: error resolving calculator service', [
                    'serviceId' => $id,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    /**
     * Versucht verschiedene Aufrufvarianten des Calculators, ohne Exceptions nach außen zu werfen.
     *
     * @return CalculatedPrice|CalculatedPriceCollection|array|null
     */
    private function invokeCalculatorSafely(object $calculator, SalesChannelProductEntity $product, SalesChannelContext $context)
    {
        try {
            // 1) Standard: calculate($product, $context)
            if (is_callable([$calculator, 'calculate'])) {
                $result = $calculator->calculate($product, $context);
                if ($result !== null) {
                    return $result;
                }
            }

            // 2) Manche Implementierungen: calculatePrice($product, $context)
            if (is_callable([$calculator, 'calculatePrice'])) {
                $result = $calculator->calculatePrice($product, $context);
                if ($result !== null) {
                    return $result;
                }
            }

            // 3) Manche AppScript-Implementierungen liefern calculatePrices or similar
            if (is_callable([$calculator, 'calculatePrices'])) {
                $result = $calculator->calculatePrices($product, $context);
                if ($result !== null) {
                    return $result;
                }
            }

            // 4) Fallback: manche liefern ein Array via __invoke oder andere Methode
            if (is_callable($calculator)) {
                $result = $calculator($product, $context);
                if ($result !== null) {
                    return $result;
                }
            }
        } catch (\Throwable $e) {
            // Log und return null
            $this->logger?->warning('ProductPriceCalculationSubscriber: calculator invocation threw', [
                'productId' => $product->getId(),
                'exception' => $e->getMessage(),
            ]);
            return null;
        }

        return null;
    }
}
