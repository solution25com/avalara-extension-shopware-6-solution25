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
        $price = $row['productPrice']['net'] ?? null;

        if ($price === null) {
            // Fallback ONLY if net is missing
            if (isset($row['productPrice']['gross'], $rate) && $rate > 0) {
                $price = round(
                    $row['productPrice']['gross'] / (1 + ($rate / 100)),
                    2
                );

                $this->logger->warning('BUNDLE PRICE FALLBACK (derived net)', [
                    'sku' => $sku,
                    'gross' => $row['productPrice']['gross'],
                    'derivedNet' => $price,
                ]);
            } else {
                // skip so it doesn't over-tax
                $this->logger->error('BUNDLE PRICE MISSING NET', [
                    'sku' => $sku,
                    'productPrice' => $row['productPrice'],
                ]);
                continue;
            }
        }
 
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

  private function trackStandaloneUsage(Cart $cart): void
{
    foreach ($cart->getLineItems() as $item) {
        if ($item->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE) {
            continue;
        }

        // Skip bundle parents
        if ($item->getPayloadValue('bundleRelations')) {
            continue;
        }

        $sku = $item->getPayloadValue('productNumber');
        if (!$sku) {
            continue;
        }

        $price = $item->getPrice();
        if (!$price) {
            continue;
        }

        $this->childUsageMap[$sku][] = [
            'bundleSku' => null, 
            'quantity'  => $item->getQuantity(),
            'lineTotal' => $price->getTotalPrice(),
        ];
    }
}


  private function allocateMergedSkuTaxes(): void
  {
    foreach ($this->childUsageMap as $sku => $usages) {
      if (!isset($this->avalaraTaxes[$sku]) || empty($usages)) {
        continue;
      }

      $totalTax = round((float) ($this->avalaraTaxes[$sku]['tax'] ?? 0.0), 2);
      $rate = (float) ($this->avalaraTaxes[$sku]['rate'] ?? 0.0);

      $totalNet = 0.0;
      foreach ($usages as $row) {
        $totalNet += (float) ($row['lineTotal'] ?? 0.0);
      }
      if ($totalNet <= 0.0) {
        continue;
      }

      $allocatedRows = [];
      $allocatedSum = 0.0;
      $lastIndex = array_key_last($usages);

      foreach ($usages as $i => $row) {
        $portion = ($i === $lastIndex)
          ? round($totalTax - $allocatedSum, 2)
          : round(((float) ($row['lineTotal'] ?? 0.0) / $totalNet) * $totalTax, 2);

        $allocatedRows[$i] = [
          'bundleSku' => $row['bundleSku'] ?? null,
          'portionTax' => $portion,
          'lineTotal' => (float) ($row['lineTotal'] ?? 0.0),
        ];
        $allocatedSum += $portion;
      }

      $standaloneTax = 0.0;
      $hasStandalone = false;

      foreach ($allocatedRows as $row) {
        $bundleSku = $row['bundleSku'];
        $portionTax = (float) $row['portionTax'];
        $lineTotal = 0.0;

        if (isset($row['lineTotal'])) {
          $lineTotal = (float) $row['lineTotal'];
        }

        if (!empty($bundleSku)) {
          $newTax = round(((float) ($this->avalaraTaxes[$bundleSku]['tax'] ?? 0.0)) + $portionTax, 2);
          $newNet = ((float) ($this->avalaraTaxes[$bundleSku]['__bundleNet'] ?? 0.0)) + $lineTotal;

          $this->avalaraTaxes[$bundleSku]['tax'] = $newTax;
          $this->avalaraTaxes[$bundleSku]['__bundleNet'] = $newNet;
          $this->avalaraTaxes[$bundleSku]['rate'] = ($newTax == 0.0 || $newNet <= 0.0)
            ? 0.0
            : round(($newTax / $newNet) * 100, 4);
          continue;
        }

        $hasStandalone = true;
        $standaloneTax = round($standaloneTax + $portionTax, 2);
      }

      if ($hasStandalone) {
        $this->avalaraTaxes[$sku]['tax'] = $standaloneTax;
        $this->avalaraTaxes[$sku]['rate'] = ($standaloneTax == 0.0) ? 0.0 : $rate;
      } else {
        unset($this->avalaraTaxes[$sku]);
      }
    }

    foreach ($this->avalaraTaxes as $taxKey => $taxRow) {
      if (is_array($taxRow) && array_key_exists('__bundleNet', $taxRow)) {
        unset($this->avalaraTaxes[$taxKey]['__bundleNet']);
      }
    }
  }


  private function collapseBundleTaxes(Cart $cart): void
  {
    foreach ($this->bundleChildMap as $bundleSku => $childRows) {

        $bundleTax = 0.0;
        $bundleNet = 0.0;

        foreach ($childRows as $row) {
            $childSku  = $row['productNumber'];
            $lineTotal = $row['lineTotal'];

            if (!isset($this->avalaraTaxes[$childSku])) {
                continue;
            }

            $bundleTax += $this->avalaraTaxes[$childSku]['tax'];
            $bundleNet += $lineTotal;

            unset($this->avalaraTaxes[$childSku]);
        }

        if ($bundleNet <= 0.0) {
            continue;
        }

        $bundleRate = round(($bundleTax / $bundleNet) * 100, 3);

        $this->avalaraTaxes[$bundleSku] = [
            'tax'  => $bundleTax,
            'rate' => $bundleRate,
        ];
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
    $usagesBySku = [];

    foreach ($cart->getLineItems() as $lineItem) {
      foreach ($lineItem->getChildren() as $childLineItem) {
        $sku = $childLineItem->getPayloadValue('productNumber');
        if (!$sku || !isset($avalaraResult[$sku])) {
          $allChildren[] = $childLineItem;
          continue;
        }

        $lineTotal = (float) ($childLineItem->getPrice()?->getTotalPrice() ?? 0.0);
        $usagesBySku[$sku][] = [
          'type' => 'child',
          'lineTotal' => $lineTotal,
          'lineItem' => $childLineItem,
          'parentId' => $lineItem->getId(),
        ];
        $allChildren[] = $childLineItem;
      }

      if ($lineItem->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE) {
        continue;
      }
      if ($lineItem->getPayloadValue('bundleRelations')) {
        continue;
      }

      $sku = $lineItem->getPayloadValue('productNumber');
      if (!$sku || !isset($avalaraResult[$sku])) {
        continue;
      }

      $lineTotal = (float) ($lineItem->getPrice()?->getTotalPrice() ?? 0.0);
      $usagesBySku[$sku][] = [
        'type' => 'standalone',
        'lineTotal' => $lineTotal,
        'lineItem' => $lineItem,
      ];
    }

    foreach ($usagesBySku as $sku => $usages) {
      $totalTax = round((float) ($avalaraResult[$sku]['tax'] ?? 0.0), 2);
      $rate = (float) ($avalaraResult[$sku]['rate'] ?? 0.0);

      $totalNet = 0.0;
      foreach ($usages as $usage) {
        $totalNet += (float) $usage['lineTotal'];
      }
      if ($totalNet <= 0.0) {
        $totalNet = (float) count($usages);
        foreach ($usages as $idx => $usage) {
          $usages[$idx]['lineTotal'] = 1.0;
        }
      }

      $allocated = 0.0;
      $lastIndex = array_key_last($usages);
      foreach ($usages as $i => $usage) {
        $taxAmount = ($i === $lastIndex)
          ? round($totalTax - $allocated, 2)
          : round(((float) $usage['lineTotal'] / $totalNet) * $totalTax, 2);
        $allocated += $taxAmount;

        if ($usage['type'] === 'child') {
          $usage['lineItem']->setPayloadValue('AvalaraLineItemChildTax', [
            'tax' => $taxAmount,
            'rate' => $taxAmount == 0.0 ? 0.0 : $rate,
            'quantity' => $usage['lineItem']->getQuantity(),
            'bundleParentId' => $usage['parentId'],
          ]);
          continue;
        }

        $usage['lineItem']->setPayloadValue('AvalaraStandaloneTax', [
          'tax' => $taxAmount,
          'rate' => $taxAmount == 0.0 ? 0.0 : $rate,
          'quantity' => $usage['lineItem']->getQuantity(),
        ]);
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
    // Cart is recalculated multiple times in one request flow.
    // Reset per-run maps to avoid leaking previous calculations.
    $this->bundleChildMap = [];
    $this->childUsageMap = [];

    $salesChannelId = $context->getSalesChannel()->getId();

    $adapter = new AvalaraExtensionAdapter($this->systemConfigService, $this->logger, $salesChannelId);
//    $adapter = new AvalaraSDKAdapter($this->systemConfigService, $this->logger, $salesChannelId);
    $this->avalaraTaxes = $this->session->getValue(Form::SESSION_AVALARA_TAXES_TRANSFORMED, $adapter);

    if ($this->isTaxesUpdateNeeded() && $original->getDeliveries()->getAddresses()->getCountries()->first()) {


      $avalaraCart = $this->cloneCart($original);

      if ($this->isRefund()) {
        $this->logger->info('Avalara refund detected – filtering cart line items for refund.');
        $this->updateLineItemsForRefund($avalaraCart);
      }
      // Track real standalone items before bundle expansion,
      // otherwise expanded bundle children can be misread as standalone.
      $this->trackStandaloneUsage($avalaraCart);
      $this->expandBundles($avalaraCart, $context);
      $this->mergeSameProducts($avalaraCart);
      $service = $adapter->getService('AvalaraExtensionGetTaxService');
      $this->avalaraTaxes = $service->getAvalaraTaxes($avalaraCart, $context, $this->session, $this->categoryRepository);
      $avalaraResult = $this->avalaraTaxes;
      $this->applyTaxesToChildren($toCalculate, $avalaraResult);
      $this->allocateMergedSkuTaxes();
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

  private function syncPriceDefinition(
    LineItem $item,
    CalculatedPrice $price
): void {
    $definition = $item->getPriceDefinition();
    if ($definition === null) {
        return;
    }

    if ($price->getCalculatedTaxes()->getAmount() == 0.0) {
        $definition->setTaxRules(new TaxRuleCollection([]));
    } else {
        $definition->setTaxRules($price->getTaxRules());
    }

    $definition->setQuantity($price->getQuantity());
    $item->setPriceDefinition($definition);
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

      $standaloneTaxPayload = $product->getPayloadValue('AvalaraStandaloneTax');
      if (is_array($standaloneTaxPayload) && array_key_exists('tax', $standaloneTaxPayload)) {
        $this->avalaraTaxes[$productNumber]['tax'] = (float) $standaloneTaxPayload['tax'];
        $this->avalaraTaxes[$productNumber]['rate'] = (float) ($standaloneTaxPayload['rate'] ?? 0.0);
      } elseif ($this->isRefund()) {
        // Refund bundle parent: use child payload totals from checkout.
        $bundleTax = 0.0;
        $effectiveRate = 0.0;

        foreach ($product->getChildren() as $child) {
          $childTaxPayload = $child->getPayloadValue('AvalaraLineItemChildTax');
          if (!is_array($childTaxPayload) || !array_key_exists('tax', $childTaxPayload)) {
            continue;
          }

          $bundleTax += (float) $childTaxPayload['tax'];
          if (isset($childTaxPayload['rate'])) {
            $effectiveRate = max($effectiveRate, (float) $childTaxPayload['rate']);
          }
        }

        $this->avalaraTaxes[$productNumber]['tax'] = $bundleTax;
        $this->avalaraTaxes[$productNumber]['rate'] = $effectiveRate;
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

  private function isRefund(): bool
{
    if (!isset($_SERVER['REQUEST_URI'])) {
        return false;
    }

    $uri = $_SERVER['REQUEST_URI'];

    return str_contains($uri, '/returns')
        || str_contains($uri, '/return')
        || str_contains($uri, 'api/_action/order/return');
}


  /**
   * @param CalculatedPrice $price
   * @param string $productNumber
   * @return CalculatedPrice
   */
  private function itemPriceCalculator(
    CalculatedPrice $price,
    string $productNumber
): CalculatedPrice {

    $taxAmount = (float) ($this->avalaraTaxes[$productNumber]['tax'] ?? 0.0);
    $rate      = (float) ($this->avalaraTaxes[$productNumber]['rate'] ?? 0.0);

    if ($taxAmount == 0.0) {
        return new CalculatedPrice(
            $price->getUnitPrice(),
            $price->getTotalPrice(),
            new CalculatedTaxCollection([
                new CalculatedTax(0.0, 0.0, $price->getTotalPrice())
            ]),
            new TaxRuleCollection([]),
            $price->getQuantity(),
            $price->getReferencePrice(),
            $price->getListPrice()
        );
    }

    if ($this->isRefund()) {
        return new CalculatedPrice(
            $price->getUnitPrice(),
            $price->getTotalPrice(),
            new CalculatedTaxCollection([
                new CalculatedTax($taxAmount, 0.0, $price->getTotalPrice())
            ]),
            new TaxRuleCollection([]),
            $price->getQuantity(),
            $price->getReferencePrice(),
            $price->getListPrice()
        );
    }

    // Normal checkout
    return new CalculatedPrice(
        $price->getUnitPrice(),
        $price->getTotalPrice(),
        new CalculatedTaxCollection([
            new CalculatedTax($taxAmount, $rate, $price->getTotalPrice())
        ]),
        new TaxRuleCollection([new TaxRule($rate)]),
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
