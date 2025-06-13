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

    foreach ($lineItems as $item) {
      $payload = $item->getPayload();

      if (!isset($payload['AvalaraLineItemChildTax'])) {
        continue;
      }

      $avalara = $payload['AvalaraLineItemChildTax'];

      if (!isset($avalara['tax'], $avalara['rate'])) {
        continue;
      }

      $taxAmount = (float) $avalara['tax'];
      $taxRate = (float) $avalara['rate'];
      $originalPrice = $item->getPrice();
      $calculatedTax = new CalculatedTax($taxAmount, $taxRate, $originalPrice->getTotalPrice());
      $taxCollection = new CalculatedTaxCollection([$calculatedTax]);


      $taxRule = new TaxRule($taxRate);
      $taxRules = new TaxRuleCollection([$taxRule]);

      $newPrice = new CalculatedPrice(
        $originalPrice->getUnitPrice(),
        $originalPrice->getTotalPrice(),
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

    if (!empty($updates)) {
      $this->orderLineItemRepository->update($updates, $context);
    }
  }
}
