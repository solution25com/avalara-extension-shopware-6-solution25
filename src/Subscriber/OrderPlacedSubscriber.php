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

    /**
     * CHILD ITEMS
     */
    foreach ($lineItems as $item) {
      $payload = $item->getPayload();

      if (!isset($payload['AvalaraLineItemChildTax'])) {
        continue;
      }

      $avalara = $payload['AvalaraLineItemChildTax'];

      if (!isset($avalara['tax'], $avalara['rate'], $avalara['bundleParentId'])) {
        continue;
      }

      $parentId  = $avalara['bundleParentId'];
      $taxAmount = (float) $avalara['tax'];
      $taxRate   = (float) $avalara['rate'];
      $childTotal = $item->getPrice()->getTotalPrice();

      if (!isset($bundleGroups[$parentId])) {
        $bundleGroups[$parentId] = [
          'rates' => [],
        ];
      }

      $bundleGroups[$parentId]['rates'][] = $taxRate;

      $originalPrice = $item->getPrice();

      //  Extract existing taxExtension
      $taxExtension = null;
      foreach ($originalPrice->getTaxRules() as $rule) {
        if ($rule->hasExtension('taxExtension')) {
          $taxExtension = $rule->getExtension('taxExtension');
          break;
        }
      }

      $calculatedTax = new CalculatedTax($taxAmount, $taxRate, $childTotal);
      $taxCollection = new CalculatedTaxCollection([$calculatedTax]);

      if ($taxAmount == 0.0) {
        $taxRules = new TaxRuleCollection([]);
      } else {
        $taxRule = new TaxRule($taxRate);
        if ($taxExtension !== null) {
          $taxRule->addExtension('taxExtension', clone $taxExtension);
        }
        $taxRules = new TaxRuleCollection([$taxRule]);
      }

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
        'id'    => $item->getId(),
        'price' => $newPrice,
      ];
    }

        /**
     * STANDALONE PRODUCTS (same SKU as bundle children)
     */
    foreach ($lineItems as $item) {
        $payload = $item->getPayload();

        if (!isset($payload['AvalaraStandaloneTax'])) {
            continue;
        }

        $avalara = $payload['AvalaraStandaloneTax'];

        if (!isset($avalara['tax'], $avalara['rate'])) {
            continue;
        }

        $taxAmount = (float) $avalara['tax'];
        $taxRate   = (float) $avalara['rate'];

        $originalPrice = $item->getPrice();
        $totalPrice    = $originalPrice->getTotalPrice();

        // Preserve existing taxExtension (important for Shopware admin/refunds)
        $taxExtension = null;
        foreach ($originalPrice->getTaxRules() as $rule) {
            if ($rule->hasExtension('taxExtension')) {
                $taxExtension = $rule->getExtension('taxExtension');
                break;
            }
        }

        $calculatedTax = new CalculatedTax($taxAmount, $taxRate, $totalPrice);
        $taxCollection = new CalculatedTaxCollection([$calculatedTax]);

        if ($taxAmount == 0.0) {
            $taxRules = new TaxRuleCollection([]);
        } else {
            $taxRule = new TaxRule($taxRate);
            if ($taxExtension !== null) {
                $taxRule->addExtension('taxExtension', clone $taxExtension);
            }
            $taxRules = new TaxRuleCollection([$taxRule]);
        }

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
            'id'    => $item->getId(),
            'price' => $newPrice,
        ];
    }


    /**
 * BUNDLE PARENTS (FIXED – NO RATE, NO RECALC)
 */
foreach ($lineItems as $item) {
    $payload = $item->getPayload();

    if (!isset($payload['bundleContent'])) {
        continue;
    }

    $bundleId = $item->getId();
    if (!isset($bundleGroups[$bundleId])) {
        continue;
    }

    $originalPrice = $item->getPrice();
    $totalPrice    = $originalPrice->getTotalPrice();

    /**
     *  Sum tax ONLY from children (never recalc)
     */
    $taxAmount = 0.0;

    foreach ($lineItems as $child) {
        $childPayload = $child->getPayload();

        if (
            isset($childPayload['AvalaraLineItemChildTax']) &&
            $childPayload['AvalaraLineItemChildTax']['bundleParentId'] === $item->getId()
        ) {
            $taxAmount += (float) $childPayload['AvalaraLineItemChildTax']['tax'];
        }
    }

    /**
     * no tax rate ,no tax rules ,Flat Avalara tax only
     */
    $calculatedTax = new CalculatedTax(
        round($taxAmount, 2),
        0.0,
        $totalPrice
    );

    $taxCollection = new CalculatedTaxCollection([$calculatedTax]);

    $newPrice = new CalculatedPrice(
        $originalPrice->getUnitPrice(),
        $totalPrice,
        $taxCollection,
        new TaxRuleCollection([]), //   prevent refund recalculation
        $originalPrice->getQuantity(),
        $originalPrice->getReferencePrice(),
        $originalPrice->getListPrice()
    );

    $updates[] = [
        'id'    => $item->getId(),
        'price' => $newPrice,
    ];
}


    if (!empty($updates)) {
      $this->orderLineItemRepository->update($updates, $context);
    }
  }
}
