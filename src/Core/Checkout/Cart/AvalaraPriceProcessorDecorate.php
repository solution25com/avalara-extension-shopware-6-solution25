<?php declare(strict_types=1);

namespace AvalaraExtension\Core\Checkout\Cart;

use AvalaraExtension\Adapter\AvalaraExtensionAdapter;
use AvalaraExtension\Service\AvalaraExtensionSessionService;
use MoptAvalara6\Bootstrap\Form;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\Error\ErrorCollection;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Order\IdStruct;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTax;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use MoptAvalara6\Adapter\AvalaraSDKAdapter;
use Monolog\Logger;
use MoptAvalara6\Core\Checkout\Cart\OverwritePriceProcessor;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRule;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;

class AvalaraPriceProcessorDecorate extends OverwritePriceProcessor
{
  private SystemConfigService $systemConfigService;

  private EntityRepository $categoryRepository;

  private EntityRepository $productRepository;
  private AvalaraExtensionSessionService $session;

  private $avalaraTaxes;

  private Logger $logger;

  private array $bundleChildMap = [];
  private array $childUsageMap = [];


  public function __construct(
    SystemConfigService $systemConfigService,
    EntityRepository    $categoryRepository,
    EntityRepository    $productRepository,
    Logger              $loggerMonolog,
  )
  {
    $this->systemConfigService = $systemConfigService;
    $this->session = new AvalaraExtensionSessionService();
    $this->categoryRepository = $categoryRepository;
    $this->productRepository = $productRepository;
    $this->logger = $loggerMonolog;
  }

  private function expandBundles(Cart $cart, SalesChannelContext $context): void
  {
    foreach ($cart->getLineItems() as $bundle) {
      $children = $bundle->getPayloadValue('bundleRelations') ?? [];
      if (!$children) {
        continue;
      }

      $bundleSku = $bundle->getPayloadValue('productNumber');
      $bundle->setPayloadValue('bundleChildrenTax', []);
      $productsInBundle = $bundle->getPayloadValue('zeobvProductsInBundle');

      $cart->remove($bundle->getId());

      foreach ($children as $row) {
        $product = array_find($productsInBundle, function ($key) use ($row) {
          return is_object($key) && method_exists($key, 'getId') && $key->getId() === $row['productId'];
        });

        if (!$product && $row['productId']) {
          $criteria = new Criteria();
          $criteria->addFilter(new EqualsFilter('productNumber', $row['productNumber']));;
          $product = $this->productRepository->search($criteria, $context->getContext())->first();
        }

        if (!$product) {
          continue;
        }

        $sku = $product->getProductNumber();
        $rate = $product->getTax()?->getTaxRate() ?? 0.0;
        $price = $row['productPrice']['net'];
        $quantity = $row['quantityInBundle'] * $bundle->getQuantity();
        $lineTotal = $price * $quantity;
        $taxAmount = $lineTotal * $rate / 100;

        $child = new LineItem(Uuid::randomHex(), LineItem::PRODUCT_LINE_ITEM_TYPE, $product->getId(), $quantity);
        $child->setPayloadValue('productNumber', $sku);
        $child->setLabel($row['productName']);
        $child->setPayloadValue('customFields', $product->getTranslated()['customFields']);
        $child->setStackable(true);
        $child->setStates($product->getStates());
        $child->setRemovable(true);

        $taxRules = new TaxRuleCollection([new TaxRule($rate)]);
        $taxes = new CalculatedTaxCollection([new CalculatedTax($taxAmount, $rate, $lineTotal)]);
        $child->setPrice(new CalculatedPrice($price, $lineTotal, $taxes, $taxRules, $quantity));

        $cart->add($child);

        // Track for bundle collapse
        $this->bundleChildMap[$bundleSku][] = [
          'productNumber' => $sku,
          'quantity' => $quantity,
          'lineTotal' => $lineTotal,
        ];

        // Track for tax allocation
        $this->childUsageMap[$sku][] = [
          'bundleSku' => $bundleSku,
          'quantity' => $quantity,
          'lineTotal' => $lineTotal,
        ];
      }
    }
  }

  private function collapseBundleTaxes(Cart $cart): void
  {
    foreach ($this->childUsageMap as $sku => $bundleUses) {
      if (!isset($this->avalaraTaxes[$sku])) {
        continue;
      }

      $totalLineTotal = array_sum(array_column($bundleUses, 'lineTotal'));
      $totalTax = $this->avalaraTaxes[$sku]['tax'];

      foreach ($bundleUses as $usage) {
        $bundleSku = $usage['bundleSku'];
        $lineTotal = $usage['lineTotal'];

        if ($totalLineTotal <= 0.0) {
          continue;
        }

        $share = $lineTotal / $totalLineTotal;
        $shareTax = $totalTax * $share;

        if (!isset($this->avalaraTaxes[$bundleSku])) {
          $this->avalaraTaxes[$bundleSku] = [
            'tax' => 0.0,
            'rate' => 0.0,
          ];
        }

        $this->avalaraTaxes[$bundleSku]['tax'] += $shareTax;
        $this->avalaraTaxes[$bundleSku]['rate'] = round(($this->avalaraTaxes[$bundleSku]['tax'] / $lineTotal) * 100, 3);
      }

      unset($this->avalaraTaxes[$sku]);
    }
  }


//  private function collapseBundleTaxes(): void
//  {
//    foreach ($this->bundleChildMap as $bundleSku => $childRows) {
//
//      $taxSum = 0.0;
//      $netSum = 0.0;
//
//      foreach ($childRows as $childSku => $lineTotal) {
//        if (!isset($this->avalaraTaxes[$childSku])) {
//          continue;
//        }
//        $taxSum += $this->avalaraTaxes[$childSku]['tax'];
//        $netSum += $lineTotal;
//        unset($this->avalaraTaxes[$childSku]);
//      }
//
//      if ($netSum === 0.0) {
//        continue;
//      }
//
//      $this->avalaraTaxes[$bundleSku] = [
//        'tax' => $taxSum,
//        'rate' => round($taxSum / $netSum * 100, 4),
//      ];
//    }
//  }

  private function cloneCart(Cart $cart): Cart
  {
    $errors = new ErrorCollection();
    foreach ($cart->getErrors() as $error) {
      try {
        $c = clone $error;
        $errors->add($c);
      } catch (\Throwable $e) {
      }
    }
    $cart->setErrors($errors);
    return clone $cart;
  }

  private function mergeSameProducts(Cart $cart): void
  {
    $lineItems = $cart->getLineItems();
    $productMap = [];
    $idsToRemove = [];

    foreach ($lineItems as $lineItem) {
      $productNumber = $lineItem->getPayloadValue('productNumber');

      if (!$productNumber) {
        continue;
      }

      if (isset($productMap[$productNumber])) {
        if ($productMap[$productNumber]->getId() !== $lineItem->getId()) {

          $price = $productMap[$productNumber]->getPrice();

          $productMap[$productNumber]->setQuantity(
            $productMap[$productNumber]->getQuantity() + $lineItem->getQuantity()
          );

          $productMap[$productNumber]->setPrice(new CalculatedPrice(
            $price->getUnitPrice(), $price->getUnitPrice() * $productMap[$productNumber]->getQuantity(), $price->getCalculatedTaxes(), $price->getTaxRules(), $productMap[$productNumber]->getQuantity()
          ));


          $idsToRemove[] = $lineItem;
        }
      } else {
        $productMap[$productNumber] = $lineItem;
      }
    }

    foreach ($idsToRemove as $lineItem) {
      if ($cart->getLineItems()->has($lineItem->getId())) {
        $cart->remove($lineItem->getId());

      }
    }
  }
  private function applyTaxesToChildren(Cart $cart, array $avalaraResult): array
  {
    $allChildren = [];

    foreach ($cart->getLineItems() as $lineItem) {
      foreach ($lineItem->getChildren() as $childLineItem) {
        $productNumber = $childLineItem->getPayloadValue('productNumber');

        if (!$productNumber || !isset($avalaraResult[$productNumber])) {
          $allChildren[] = $childLineItem;
          continue;
        }

        $taxData = $avalaraResult[$productNumber];

        $totalAvalaraTax = $taxData['tax'] ?? 0.0;
        $totalAvalaraQty = $taxData['quantity'] ?? 1;
        $rate = $taxData['rate'] ?? 0.0;

        if ($totalAvalaraQty <= 0) {
          $totalAvalaraQty = 1;
        }

        $childQty = $childLineItem->getQuantity();
        $unitTax = $totalAvalaraTax / $totalAvalaraQty;
        $childTaxAmount = $unitTax * $childQty;

        $calculatedTaxInfo = [
          'tax' => $childTaxAmount,
          'rate' => $rate,
          'quantity' => $childQty,
          'bundleParentId' => $lineItem->getId(),
        ];

        $childLineItem->setPayloadValue('AvalaraLineItemChildTax', $calculatedTaxInfo);
        $allChildren[] = $childLineItem;
      }
    }

    return $allChildren;
  }

  private function updateLineItemsForRefund(Cart $cart): void
  {

    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (strpos($uri, '/return') === false) {
      return;
    }
    $body = json_decode(file_get_contents('php://input'), true);
    if (empty($body['lineItems']) || !is_array($body['lineItems'])) {
      return;
    }

    $keep = array_flip(array_column($body['lineItems'], 'orderLineItemId'));

    foreach ($cart->getLineItems() as $item) {
      $origExt = $item->getExtension('originalId');
      if (!$origExt instanceof IdStruct) {
        continue;
      }

      $origId = $origExt->getId();
      if (!isset($keep[$origId])) {
        $cart->remove($item->getId());
      }
    }
  }



  public function process(CartDataCollection $data, Cart $original, Cart $toCalculate, SalesChannelContext $context, CartBehavior $behavior): void
  {
    $salesChannelId = $context->getSalesChannel()->getId();

    $adapter = new AvalaraExtensionAdapter($this->systemConfigService, $this->logger, $salesChannelId);
//    $adapter = new AvalaraSDKAdapter($this->systemConfigService, $this->logger, $salesChannelId);
    $this->avalaraTaxes = $this->session->getValue(Form::SESSION_AVALARA_TAXES_TRANSFORMED, $adapter);

    if ($this->isTaxesUpdateNeeded() && $original->getDeliveries()->getAddresses()->getCountries()->first()) {


      $avalaraCart = $this->cloneCart($original);
//      $this->updateLineItemsForRefund($avalaraCart);
      $this->expandBundles($avalaraCart, $context);
      $this->mergeSameProducts($avalaraCart);
      $service = $adapter->getService('AvalaraExtensionGetTaxService');
      $this->avalaraTaxes = $service->getAvalaraTaxes($avalaraCart, $context, $this->session, $this->categoryRepository);
      $avalaraResult = $this->avalaraTaxes;
      $this->applyTaxesToChildren($toCalculate, $avalaraResult);
      $this->collapseBundleTaxes($avalaraCart);
      $this->validateTaxes($adapter, $toCalculate);
    }

    if ($this->avalaraTaxes
      && array_key_exists(Form::TAX_REQUEST_STATUS, $this->avalaraTaxes)
      && $this->avalaraTaxes[Form::TAX_REQUEST_STATUS] == Form::TAX_REQUEST_STATUS_SUCCESS) {

      $this->changeTaxes($toCalculate);
      $this->changeShippingCosts($toCalculate);
      $this->changePromotionsTaxes($toCalculate);
      $toCalculate->getShippingCosts();
    }
  }

  /**
   * @param Cart $toCalculate
   * @return void
   */
  private function changeTaxes(Cart $toCalculate)
  {
    $products = $toCalculate->getLineItems()->filterType(LineItem::PRODUCT_LINE_ITEM_TYPE);

    foreach ($products as $product) {
      $productNumber = $product->getPayloadValue('productNumber');
      if (!array_key_exists($productNumber, $this->avalaraTaxes)) {
        continue;
      }

      $originalPrice = $product->getPrice();

      $avalaraProductPriceCalculated = $this->itemPriceCalculator($originalPrice, $productNumber);

      $product->setPrice($avalaraProductPriceCalculated);
    }
  }

  /**
   * @param Cart $toCalculate
   * @return void
   */
  private function changeShippingCosts(Cart $toCalculate)
  {
    $delivery = $toCalculate->getDeliveries()->first();

    if ($delivery === null) {
      return;
    }

    $originalPrice = $delivery->getShippingCosts();

    $avalaraShippingCalculated = $this->itemPriceCalculator($originalPrice, 'Shipping');

    $delivery->setShippingCosts($avalaraShippingCalculated);
  }

  private function changePromotionsTaxes(Cart $toCalculate)
  {
    $promotions = $toCalculate->getLineItems()->filterType(LineItem::PROMOTION_LINE_ITEM_TYPE);

    foreach ($promotions as $promotion) {
      $promotionId = $promotion->getPayloadValue('promotionId');

      if (!array_key_exists($promotionId, $this->avalaraTaxes)) {
        continue;
      }

      $originalPrice = $promotion->getPrice();

      $avalaraPromotionCalculated = $this->itemPriceCalculator($originalPrice, $promotionId);

      $promotion->setPrice($avalaraPromotionCalculated);
    }
  }

  /**
   * @param CalculatedPrice $price
   * @param string $productNumber
   * @return CalculatedPrice
   */
  private function itemPriceCalculator(CalculatedPrice $price, string $productNumber): CalculatedPrice
  {
    $avalaraCalculatedTax = new CalculatedTax(
      $this->avalaraTaxes[$productNumber]['tax'],
      $this->avalaraTaxes[$productNumber]['rate'],
      $price->getTotalPrice()
    );

    if ($this->avalaraTaxes[$productNumber]['tax'] == 0) {
      $this->avalaraTaxes[$productNumber]['rate'] = 0;
    }

    //For TaxRules
    $taxRules[] = new TaxRule($this->avalaraTaxes[$productNumber]['rate']);

    $taxRuleCollection = new TaxRuleCollection(array_merge($price->getTaxRules()->getElements(), $taxRules));

    $avalaraCalculatedTaxCollection = new CalculatedTaxCollection();
    $avalaraCalculatedTaxCollection->add($avalaraCalculatedTax);

    return new CalculatedPrice(
      $price->getUnitPrice(),
      $price->getTotalPrice(),
      $avalaraCalculatedTaxCollection,
      $taxRuleCollection,
      $price->getQuantity(),
      $price->getReferencePrice(),
      $price->getListPrice()
    );
  }

  /**
   * @param AvalaraSDKAdapter $adapter
   * @param Cart $toCalculate
   * @return void
   */
  private function validateTaxes(Mixed $adapter, Cart $toCalculate)
  {
    if ($adapter->getPluginConfig(Form::BLOCK_CART_ON_ERROR_FIELD)) {
      $products = $toCalculate->getLineItems()->filterType(LineItem::PRODUCT_LINE_ITEM_TYPE);
      $status = Form::TAX_REQUEST_STATUS_FAILED;
      if (is_array($this->avalaraTaxes) && array_key_exists(Form::TAX_REQUEST_STATUS, $this->avalaraTaxes)) {
        $status = $this->avalaraTaxes[Form::TAX_REQUEST_STATUS];
      }
      foreach ($products as $product) {
        $product->setPayloadValue(Form::TAX_REQUEST_STATUS, $status);
      }
    }
  }

  /**
   * @return bool
   */
  private function isTaxesUpdateNeeded()
  {
    if (!array_key_exists('REQUEST_URI', $_SERVER)) {
      return true;
    }

    $pagesForUpdate = [
      'checkout/cart',
      'checkout/confirm',
      'checkout/order',
      'store-api/checkout/order',
      'capture',
      'google-capture',
      'apple-capture',
      'api/_proxy-order/',
      'api/_action/order/'
    ];


    $currentPage = $_SERVER['REQUEST_URI'];

    foreach ($pagesForUpdate as $page) {
      if (strripos($currentPage, $page)) {
        return true;
      }
    }
    return false;
  }

}
