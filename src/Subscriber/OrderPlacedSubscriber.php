<?php

namespace AvalaraExtension\Subscriber;

use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTax;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRule;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class OrderPlacedSubscriber implements EventSubscriberInterface
{
  private EntityRepository $orderLineItemRepository;

  public function __construct(EntityRepository $orderLineItemRepository)
  {
    $this->orderLineItemRepository = $orderLineItemRepository;
  }

  public static function getSubscribedEvents(): array
  {
    return [
      CheckoutOrderPlacedEvent::class => 'onOrderPlaced',
    ];
  }

  public function onOrderPlaced(CheckoutOrderPlacedEvent $event): void
  {
    $order = $event->getOrder();
    $context = $event->getContext();
    $lineItems = $order->getLineItems();

    if (!$lineItems) {
      return;
    }

    $updates = [];
    $bundleGroups = [];

    foreach ($lineItems as $item) {
      $payload = $item->getPayload();

      if (!isset($payload['AvalaraLineItemChildTax'])) {
        continue;
      }

      $avalara = $payload['AvalaraLineItemChildTax'];

      if (!isset($avalara['tax'], $avalara['rate'], $avalara['bundleParentId'])) {
        continue;
      }

      $parentId = $avalara['bundleParentId'];
      $taxAmount = (float) $avalara['tax'];
      $taxRate = (float) $avalara['rate'];
      $childTotal = $item->getPrice()->getTotalPrice();

      if (!isset($bundleGroups[$parentId])) {
        $bundleGroups[$parentId] = [
          'rates' => [],
        ];
      }

      $bundleGroups[$parentId]['rates'][] = $taxRate;

      $originalPrice = $item->getPrice();
      $calculatedTax = new CalculatedTax($taxAmount, $taxRate, $childTotal);
      $taxCollection = new CalculatedTaxCollection([$calculatedTax]);
      $taxRules = new TaxRuleCollection([new TaxRule($taxRate)]);

      $newPrice = new CalculatedPrice(
        $originalPrice->getUnitPrice(),
        $childTotal,
        $taxCollection,
        $taxRules,
        $originalPrice->getQuantity(),
        $originalPrice->getReferencePrice(),
        $originalPrice->getListPrice()
      );

      $updates[] = [
        'id' => $item->getId(),
        'price' => $newPrice,
      ];
    }

    foreach ($lineItems as $item) {
      $payload = $item->getPayload();

      if (!isset($payload['bundleContent'])) {
        continue;
      }

      $bundleId = $item->getIdentifier();
      if (!isset($bundleGroups[$bundleId])) {
        continue;
      }

      $bundleParentId = $item->getId();

      $rates = $bundleGroups[$bundleId]['rates'];

      $avgTaxRate = count($rates) > 0 ? array_sum($rates) / count($rates) : 0.0;

      $originalPrice = $item->getPrice();
      $totalPrice = $originalPrice->getTotalPrice();

      $taxAmount = $totalPrice * $avgTaxRate / 100;

      $calculatedTax = new CalculatedTax($taxAmount, $avgTaxRate, $totalPrice);
      $taxCollection = new CalculatedTaxCollection([$calculatedTax]);
      $taxRules = new TaxRuleCollection([new TaxRule($avgTaxRate)]);

      $newPrice = new CalculatedPrice(
        $originalPrice->getUnitPrice(),
        $totalPrice,
        $taxCollection,
        $taxRules,
        $originalPrice->getQuantity(),
        $originalPrice->getReferencePrice(),
        $originalPrice->getListPrice()
      );

      $updates[] = [
        'id' => $bundleParentId,
        'price' => $newPrice,
      ];
    }

    if (!empty($updates)) {
      $this->orderLineItemRepository->update($updates, $context);
    }
  }
}
