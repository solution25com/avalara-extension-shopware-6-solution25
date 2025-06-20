<?php

declare(strict_types=1);

namespace AvalaraExtension\Core\Checkout\Cart;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Zeobv\BundleProducts\Core\Checkout\Cart\Collectors\BundleProductCollector;
use Zeobv\BundleProducts\Core\Checkout\Cart\Collectors\BundleProductProcessor;
use Zeobv\BundleProducts\Service\BundlePriceCalculator;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Zeobv\BundleProducts\Struct\BundleProduct\BundlePrice;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\Content\Product\Cart\ProductCartProcessor;
use Zeobv\BundleProducts\Struct\BundleProduct\ProductBundle;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Zeobv\BundleProducts\Service\BundleProductReconfigurator;
use Shopware\Core\Checkout\Cart\Price\QuantityPriceCalculator;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\Price;
use Zeobv\BundleProducts\Struct\BundleProduct\BundleConnectionData;
use Zeobv\BundleProducts\Struct\BundleProduct\CalculatedBundlePrice;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\PriceCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\CashRoundingConfig;
use Zeobv\BundleProducts\Struct\BundleProduct\LineItem as BundleProductLineItem;

class BundleProductProcessorDecorate extends BundleProductProcessor
{
  protected ?CashRoundingConfig $cachedRoundingConfig = null;

  public function __construct(
    private QuantityPriceCalculator     $quantityPriceCalculator,
    private BundlePriceCalculator       $bundlePriceCalculator,
    private BundleProductReconfigurator $bundleProductReconfigurator
  )
  {
  }

  public function process(CartDataCollection $data, Cart $original, Cart $toCalculate, SalesChannelContext $context, CartBehavior $behavior): void
  {
    if ($behavior->hasPermission(ProductCartProcessor::SKIP_PRODUCT_RECALCULATION)) {
      return;
    }

    $this->setDecimalPrecisionToHighAccuracy($context);

    try {
      $productLineItems = $original->getLineItems()->filterType(LineItem::PRODUCT_LINE_ITEM_TYPE);

      $this->processBundleProductItems($productLineItems, $data, $context);
    } catch (\Exception $e) {
      $this->resetDecimalPrecision($context);
      throw $e;
    }
    $this->resetDecimalPrecision($context);
  }

  public function processBundleProductItems(LineItemCollection $productLineItems, CartDataCollection $data, SalesChannelContext $context): void
  {
    /** @var LineItem $productLineItem */
    foreach ($productLineItems as $productLineItem) {
      if (
        !$data->get(
          BundleProductCollector::BUNDLE_PRODUCT_DATA_KEY . $productLineItem->getReferencedId()
        )
      ) {
        continue;
      }

      /** @var SalesChannelProductEntity|null $bundleProduct */
      $bundleProduct = clone $data->get(
        BundleProductCollector::BUNDLE_PRODUCT_DATA_KEY . $productLineItem->getReferencedId()
      );

      if (
        $bundleProduct === null
        || $bundleProduct->hasExtension(ProductBundle::EXTENSION_NAME) === false
      ) {
        continue;
      }

      $calculatedBundlePrice = $data->get(
        BundleProductCollector::DISCOUNT_DATA_KEY . $productLineItem->getReferencedId()
      );

      # Compatibility with ZeobvVisibleDiscounts, recalculate bundle discount with discounts from cart applied.
      if ($discountInfo = $productLineItem->getPayloadValue('zeobvDiscountInfo')) {
        $calculatedBundlePrice = $this->fixBundleSalesChannelProductPriceForVisibleDiscounts(
          $bundleProduct,
          $context,
          $productLineItem->getQuantity(),
          $discountInfo
        );
      }

      if ($calculatedBundlePrice === null) {
        continue;
      }

      /** @var ProductBundle $productBundle */
      $productBundle = $bundleProduct->getExtension(ProductBundle::EXTENSION_NAME);

      # If the number of products in the bundle has changed for the cart,
      # recalculate the bundle price

      if (
        $productLineItem->getPayloadValue('includeBundleContent') !== false
        && $productLineItem->getPayloadValue(BundleProductCollector::IS_CUSTOM_BUNDLE_CONFIGURATION_KEY)
        && $productLineItem->getPayloadValue('zeobvProductsInBundle')
      ) {

        $rawArray = $productLineItem->getPayloadValue('zeobvProductsInBundle');
        $products = [];

        foreach ($rawArray as $i => $row) {
          if (\is_array($row)) {
            $entity = new ProductEntity();
            $entity->assign($row);
            $products[] = $entity;
          } elseif ($row instanceof ProductEntity) {
            $products[] = $row;
          }
        }
        $collection = new ProductCollection($products);


        $calculatedBundlePrice = $this->recalculateBundlePrice(
          $bundleProduct,
//                    new ProductCollection(
//                        $productLineItem->getPayloadValue('zeobvProductsInBundle')
//                    ),
          $collection,
          $productLineItem->getQuantity(),
          $context
        );

        $calculatedPrice = $calculatedBundlePrice->getCalculatedBundlePrice();

        # Perform rounding to avoid differences between
        # the calculated price in the product results
        # and the price in the cart
        $productLineItem->setPrice(new CalculatedPrice(
          round($calculatedPrice->getUnitPrice(), 2),
          round($calculatedPrice->getTotalPrice(), 2),
          $calculatedPrice->getCalculatedTaxes(),
          $calculatedPrice->getTaxRules()
        ));
      }

      /** @var ProductBundle|null $productBundle */
      $recalculateTaxes =
        $productBundle !== null
        && $productBundle->getConfig()->getPriceMode() !== BundlePrice::BUNDLE_PRICE_MODE_DISABLED
        && $productBundle->getConfig()->isDisableBundleProductItemPrices() === false;

      $this->calculateChildProductPrices(
        $productLineItem,
        $calculatedBundlePrice,
        $context,
        $recalculateTaxes
      );
    }
  }

  private function fixBundleSalesChannelProductPriceForVisibleDiscounts(
    SalesChannelProductEntity $bundleProduct,
    SalesChannelContext       $context,
    int                       $lineItemQuantity,
    array                     $discountInfo
  ): ?CalculatedBundlePrice
  {
    /** @var Price|null $currentBundleProductPrice */
    $currentBundleProductPrice = $bundleProduct->getPrice()->first();

    if (
      $currentBundleProductPrice === null
      || !key_exists('discount', $discountInfo)
      || $discountInfo['discount'] <= 0
    ) {
      return null;
    }

    $discount = $discountInfo['discount'];
    $currentBundleProductPrice->setGross($currentBundleProductPrice->getGross() - ($currentBundleProductPrice->getGross() * $discount / 100));
    $currentBundleProductPrice->setNet($currentBundleProductPrice->getNet() - ($currentBundleProductPrice->getNet() * $discount / 100));

    $bundleProduct->setPrice(new PriceCollection([$currentBundleProductPrice]));

    return $this->bundlePriceCalculator->createForBundleProduct(
      $bundleProduct,
      $context,
      $lineItemQuantity
    );
  }

  private function recalculateBundlePrice(SalesChannelProductEntity $bundleProduct, ProductCollection $productsStillInBundle, int $bundleQuantityInCart, SalesChannelContext $context): CalculatedBundlePrice
  {
    $itemSelection = [];

    /** @var ProductEntity $product */
    foreach ($productsStillInBundle as $product) {
      /** @var BundleConnectionData $connectionData */
//      $connectionData = $product->getExtension(BundleConnectionData::EXTENSION_NAME);
      $connectionData = $product->get('extensions')[BundleConnectionData::EXTENSION_NAME];

      $itemSelection[is_object($connectionData) ? $connectionData->getId() : $connectionData['id']] = [
        'productId' => $product->getId(),
        'qty' => is_object($connectionData) ? $connectionData->getQuantity() : $connectionData['quantity'],
      ];
    }

    return $this->bundleProductReconfigurator->determineCalculatedBundlePrice(
      $bundleProduct->getId(),
      $itemSelection,
      $context,
      $bundleQuantityInCart
    );
  }

  private function calculateChildProductPrices(LineItem $bundleLineItem, CalculatedBundlePrice $bundlePrice, SalesChannelContext $context, bool $recalculateTaxes = false): void
  {
    # If the bundle items are not included, do not recalculate the taxes
    if (key_exists('zeobvBundleProductsOfferBundleOptionally', $bundleLineItem->getPayload()['customFields'])) {
      if (
        $bundleLineItem->getPayload()['customFields']['zeobvBundleProductsOfferBundleOptionally'] === true
        && key_exists('includeBundleContent', $bundleLineItem->getPayload()) === false
      ) {
        return;
      }
    }

    if ($recalculateTaxes) {
      $bundleLineItem->getPrice()->getCalculatedTaxes()->clear();
    }

    /** @var LineItem $lineItem */
    foreach ($bundleLineItem->getChildren() as $lineItem) {
      if ($lineItem->getPayloadValue('zeobvCustomLineItemType') !== BundleProductLineItem::BUNDLE_PRODUCT_LINE_ITEM_TYPE) {
        continue;
      }

      $calculatedQuantity = $bundleLineItem->getQuantity() * $lineItem->getPayloadValue('quantity');

      $lineItem->setQuantity($calculatedQuantity);
      if ($calculatedPrice = $bundlePrice->getCalculatedProductPrice($lineItem->getId())) {
        $lineItem->setPrice($calculatedPrice);

        $qtyDefinition = new QuantityPriceDefinition(
          $calculatedPrice->getUnitPrice(),
          $context->buildTaxRules($lineItem->getPayloadValue('taxId')),
          $calculatedQuantity
        );

        $qtyDefinition->setIsCalculated(true);
        $lineItem->setPriceDefinition($qtyDefinition);
      }

      $priceDefinition = $lineItem->getPriceDefinition();

      if (!$priceDefinition instanceof QuantityPriceDefinition) {
        throw new \RuntimeException(sprintf('Product "%s" has invalid price definition', $lineItem->getLabel()));
      }

      $lineItem->setPrice(
        $this->quantityPriceCalculator->calculate($priceDefinition, $context)
      );

      # After this if statement we recalculate the net price of the product based on the tax rules of the product.
      if (!$recalculateTaxes || $context->getTaxState() === CartPrice::TAX_STATE_FREE) {
        if ($bundleLineItem->hasPayloadValue('atlProductConfigurator')) {
          $lineItem->setId(Uuid::randomHex());
        }
        continue;
      }

      # Add the tax rule to the bundle product line item
      foreach ($lineItem->getPrice()->getTaxRules() as $taxRule) {
        $bundleLineItem->getPrice()->getTaxRules()->add($taxRule);
      }

      $childTax = clone $lineItem->getPrice()->getCalculatedTaxes()->first();

      # If in a previous iteration a TaxEntity was added with
      # the same tax rate we want to merge it in the current childTax
      if ($currentTax = $bundleLineItem->getPrice()->getCalculatedTaxes()->get((string)$childTax->getTaxRate())) {
        $childTax->setTax($childTax->getTax() + $currentTax->getTax());
        $childTax->setPrice($childTax->getPrice() + $currentTax->getPrice());
      }

      # Fix the calculated taxes of the bundle product for a
      # correct presentation in the summary
      $bundleLineItem->getPrice()->getCalculatedTaxes()->add($childTax);
      if ($bundleLineItem->hasPayloadValue('atlProductConfigurator')) {
        $lineItem->setId(Uuid::randomHex());
      }
    }
  }

  private function setDecimalPrecisionToHighAccuracy(SalesChannelContext $context): void
  {
    $this->cachedRoundingConfig = $context->getItemRounding();
    $accurateRoundingConfig = clone $this->cachedRoundingConfig;
    $accurateRoundingConfig->setDecimals(BundlePriceCalculator::NUM_OF_DECIMALS_FOR_ACCURATE_PRECISION);
    $context->setItemRounding($accurateRoundingConfig);
  }

  private function resetDecimalPrecision(SalesChannelContext $context): void
  {
    if ($this->cachedRoundingConfig instanceof CashRoundingConfig) {
      $context->setItemRounding($this->cachedRoundingConfig);
    }
  }
}
