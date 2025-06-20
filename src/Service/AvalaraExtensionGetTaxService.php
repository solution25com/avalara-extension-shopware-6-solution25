<?php

declare(strict_types=1);

namespace AvalaraExtension\Service;

use Avalara\CreateTransactionModel;
use Monolog\Logger;
use MoptAvalara6\Adapter\AdapterInterface;
use MoptAvalara6\Bootstrap\Form;
use MoptAvalara6\Service\AbstractService;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Kernel;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Session\Session;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

class AvalaraExtensionGetTaxService extends AbstractService
{

  /**
   * @param AdapterInterface $adapter
   * @param Logger $logger
   */
  public function __construct(AdapterInterface $adapter, Logger $logger)
  {
    parent::__construct($adapter, $logger);
  }

  /**
   * @param Cart $cart
   * @param SalesChannelContext $context
   * @param Session $session
   * @return array|null
   * @throws \Doctrine\DBAL\Driver\Exception
   * @throws \Doctrine\DBAL\Exception
   */
  public function getAvalaraTaxes(
    Cart                $cart,
    SalesChannelContext $context,
    Session             $session,
    EntityRepository    $entityRepository
  )
  {
    $customer = $context->getCustomer();
    if (!$customer) {
      return $session->getValue(Form::SESSION_AVALARA_TAXES_TRANSFORMED, $this->getAdapter());
    }

    $customerId = $customer->getCustomerNumber();

    $taxIncluded = $this->isTaxIncluded($customer, $session);
    $currencyIso = $context->getCurrency()->getIsoCode();
    $avalaraRequest = $this->prepareAvalaraRequest(
      $cart,
      $customerId,
      $currencyIso,
      $taxIncluded,
      $session,
      $entityRepository,
      $context
    );
    if (!$avalaraRequest) {
      return [Form::TAX_REQUEST_STATUS => Form::TAX_REQUEST_STATUS_NOT_NEEDED];
    }

    $avalaraRequestKey = md5(json_encode($avalaraRequest));
    $sessionAvalaraRequestKey = $session->getValue(Form::SESSION_AVALARA_MODEL_KEY, $this->getAdapter());
    $sessionTaxes = $session->getValue(Form::SESSION_AVALARA_TAXES_TRANSFORMED, $this->getAdapter());

    if ($avalaraRequestKey != $sessionAvalaraRequestKey
      || $sessionTaxes[Form::TAX_REQUEST_STATUS] == Form::TAX_REQUEST_STATUS_FAILED
    ) {
      $session->setValue(Form::SESSION_AVALARA_MODEL, serialize($avalaraRequest), $this->getAdapter());
      $session->setValue(Form::SESSION_AVALARA_MODEL_KEY, $avalaraRequestKey, $this->getAdapter());

      $count = 1;
      foreach ($avalaraRequest->{'lines'} as $lineItem) {
        $lineItem->{'number'} .= '_bundleItem_' . $count;
        $count++;
      }
      return $this->makeAvalaraCall($avalaraRequest, $session, $cart);
    }

    return $sessionTaxes;
  }

  /**
   * @param Cart $cart
   * @param string $customerId
   * @param string $currencyIso
   * @param bool $taxIncluded
   * @param Session $session
   * @param EntityRepository $categoryRepository
   * @param SalesChannelContext $context
   * @return CreateTransactionModel|bool
   */
  private function prepareAvalaraRequest(
    Cart                $cart,
    string              $customerId,
    string              $currencyIso,
    bool                $taxIncluded,
    Session             $session,
    EntityRepository    $categoryRepository,
    SalesChannelContext $context
  )
  {
    $shippingCountry = $cart->getDeliveries()->getAddresses()->getCountries()->first();
    if (is_null($shippingCountry)) {
      return false;
    }
    $shippingCountryIso3 = $shippingCountry->getIso3();

    if (!$this->adapter->getFactory('AddressFactory')->checkCountryRestriction($shippingCountryIso3)) {
      $session->setValue(Form::SESSION_AVALARA_TAXES, null, $this->getAdapter());
      $session->setValue(Form::SESSION_AVALARA_TAXES_TRANSFORMED, [Form::TAX_REQUEST_STATUS => Form::TAX_REQUEST_STATUS_NOT_NEEDED], $this->getAdapter());
      $session->setValue(Form::SESSION_AVALARA_MODEL, null, $this->getAdapter());
      $session->setValue(Form::SESSION_AVALARA_MODEL_KEY, null, $this->getAdapter());
      return false;
    }

    $customerAddress = $cart->getDeliveries()->getAddresses()->first();
    $rawLineItems = $cart->getLineItems();

    $lineItems = $rawLineItems->filter(function (LineItem $item) {
      return !in_array($item->getType(), [
        LineItem::CREDIT_LINE_ITEM_TYPE,
        'LOYALTY_REDEEM'
      ], true);
    })->getFlat();

    $shippingMethod = $cart->getDeliveries()->first()->getShippingMethod();
    $shippingPrice = $cart->getShippingCosts()->getUnitPrice();

    return $this->adapter->getFactory('TransactionModelFactory')
      ->build(
        $customerAddress,
        $lineItems,
        $shippingMethod,
        $shippingPrice,
        $customerId,
        $currencyIso,
//        $taxIncluded,
        false,
        $categoryRepository,
        $context->getContext()
      );
  }

  /**
   * @param CreateTransactionModel $avalaraRequest
   * @param Session $session
   * @param Cart $cart
   * @return array
   */
  private function makeAvalaraCall(CreateTransactionModel $avalaraRequest, Session $session, Cart $cart)
  {
    $response = $this->calculate($avalaraRequest);

    $transformedTaxes = $this->transformResponse($response, $cart);

    $session->setValue(Form::SESSION_AVALARA_TAXES, $response, $this->getAdapter());
    $session->setValue(Form::SESSION_AVALARA_TAXES_TRANSFORMED, $transformedTaxes, $this->getAdapter());

    return $transformedTaxes;
  }

  /**
   * @param mixed $response
   * @param Cart $cart
   * @return array
   */
  private function transformResponse($response, Cart $cart): array
  {
    $transformedTax = [Form::TAX_REQUEST_STATUS => Form::TAX_REQUEST_STATUS_FAILED];
    if (!is_object($response)) {
      return $transformedTax;
    }

    if (is_null($response->lines)) {
      return $transformedTax;
    }

    foreach ($response->lines as $line) {
      $rate = 0;
      foreach ($line->details as $detail) {
        $rate += $detail->rate;
      }
      $transformedTax[$line->itemCode] = [
        'tax' => $line->tax,
        'rate' => $rate * 100,
      ];
    }

    $promotions = $cart->getLineItems()->filterType(LineItem::PROMOTION_LINE_ITEM_TYPE);

    //promotions taxes are included in item price and should be 0
    foreach ($promotions as $promotion) {
      $promotionId = $promotion->getPayloadValue('promotionId');
      $transformedTax[$promotionId] = [
        'tax' => 0,
        'rate' => 0,
      ];
    }

    $transformedTax['summary'] = $response->summary;
    $transformedTax[Form::TAX_REQUEST_STATUS] = Form::TAX_REQUEST_STATUS_SUCCESS;
    return $transformedTax;
  }

  /**
   * @param CustomerEntity $customer
   * @return bool
   * @throws \Doctrine\DBAL\Driver\Exception
   * @throws \Doctrine\DBAL\Exception
   */
  private function isTaxIncluded(CustomerEntity $customer, $session): bool
  {
    $isTaxIncluded = $session->getValue(Form::SESSION_AVALARA_IS_GROSS_PRICE, $this->getAdapter());

    if (is_null($isTaxIncluded)) {
      $groupId = $customer->getGroupId();
      $connection = Kernel::getConnection();

      $sql = "SELECT display_gross FROM customer_group WHERE id = UNHEX('$groupId')";

      $isTaxIncluded = $connection->executeQuery($sql)->fetchAssociative();

      $isTaxIncluded = (bool)$isTaxIncluded['display_gross'];
      $session->setValue(Form::SESSION_AVALARA_IS_GROSS_PRICE, $isTaxIncluded, $this->getAdapter());
    }

    return $isTaxIncluded;
  }

  /**
   * @param CreateTransactionModel $model
   * @return mixed
   */
  public function calculate(CreateTransactionModel $model)
  {
    $client = $this->getAdapter()->getAvaTaxClient();
    $model->date = date(DATE_W3C);
    try {
      $this->log('Avalara request', 0, $model);
      $response = $client->createTransaction(null, $model);
      $this->log('Avalara response', 0, $response);
      return $response;
    } catch (\Exception $e) {
      $this->log($e->getMessage(), Logger::ERROR);
    }

    return false;
  }
}