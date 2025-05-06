<?php declare(strict_types=1);

namespace AvalaraExtension\Core\Checkout\Cart;

use MoptAvalara6\Bootstrap\Form;
use MoptAvalara6\Service\SessionService;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Order\OrderConverter;
use Shopware\Core\Checkout\Cart\Price\QuantityPriceCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTax;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Session\Session;
use MoptAvalara6\Adapter\AvalaraSDKAdapter;
use Monolog\Logger;
use MoptAvalara6\Core\Checkout\Cart\OverwritePriceProcessor;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRule;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;

class AvalaraPriceProcessorDecorate extends OverwritePriceProcessor
{

  private QuantityPriceCalculator $calculator;

  private SystemConfigService $systemConfigService;

  private EntityRepository $categoryRepository;

  private Session $session;

  private $avalaraTaxes;

  private EntityRepository $orderLineItemRepository;

  private Logger $logger;


  public function __construct(
    QuantityPriceCalculator $calculator,
    SystemConfigService     $systemConfigService,
    EntityRepository        $categoryRepository,
    EntityRepository        $orderLineItemRepository,
    Logger                  $loggerMonolog,
  )
  {
    $this->calculator = $calculator;
    $this->systemConfigService = $systemConfigService;
    $this->session = new SessionService();
    $this->categoryRepository = $categoryRepository;
    $this->orderLineItemRepository = $orderLineItemRepository;
    $this->logger = $loggerMonolog;
  }


  public function process(CartDataCollection $data, Cart $original, Cart $toCalculate, SalesChannelContext $context, CartBehavior $behavior): void
  {
    $salesChannelId = $context->getSalesChannel()->getId();
    $adapter = new AvalaraSDKAdapter($this->systemConfigService, $this->logger, $salesChannelId);
    $this->avalaraTaxes = $this->session->getValue(Form::SESSION_AVALARA_TAXES_TRANSFORMED, $adapter);


    if ($this->isTaxesUpdateNeeded() && $original->getDeliveries()->getAddresses()->getCountries()->first()) {

      $service = $adapter->getService('GetTax');
      $this->avalaraTaxes = $service->getAvalaraTaxes($original, $context, $this->session, $this->categoryRepository);

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

    if($this->avalaraTaxes[$productNumber]['tax'] == 0) {
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
  private function validateTaxes(AvalaraSDKAdapter $adapter, Cart $toCalculate)
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
      'api/_action/order/',
      '/return'
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
