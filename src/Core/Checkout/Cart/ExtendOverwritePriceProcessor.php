<?php

namespace AvalaraExtension\Core\Checkout\Cart;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTax;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRule;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class ExtendOverwritePriceProcessor implements CartProcessorInterface
{
    public function process(CartDataCollection $data, Cart $original, Cart $toCalculate, SalesChannelContext $context, CartBehavior $behavior): void
    {
        $currentPage = $_SERVER['REQUEST_URI'];
        $allowedPages = [
          'checkout/cart',
          'checkout/confirm',
          'checkout/order',
          'store-api/checkout/order',
          'capture',
          'google-capture',
          'apple-capture'
        ];

        $containsAllowedPage = array_filter($allowedPages, function ($page) use ($currentPage) {
            return str_contains($currentPage, $page);
        });

        if (preg_match('#^/api/_action/order/#', $currentPage) ||  preg_match('#^/api/_proxy-order/#', $currentPage) || !empty($containsAllowedPage)) {
        } else {
            return;
        }
        foreach ($toCalculate->getLineItems() as $lineItem) {
            $payload = $lineItem->getPayload();
            $calculatedTaxes = $lineItem->getPrice()->getCalculatedTaxes()->getElements();
            $filteredTaxes = array_filter($calculatedTaxes, function ($calculatedTax) {
                return !$calculatedTax->getExtension('tax_label');
            });

            $payload['avalaraCalculatedTaxes'] = json_encode($filteredTaxes);
            $existingPrice = $lineItem->getPrice();

            foreach ($filteredTaxes as $filteredTax) {
                $taxRate = $filteredTax->getTaxRate();
                $taxRules[] = new TaxRule($taxRate);
            }

            $taxRuleCollection = new TaxRuleCollection(array_merge($existingPrice->getTaxRules()->getElements(), $taxRules));
            $payload['avalaraTaxRules'] = json_encode($taxRuleCollection);
            $lineItem->setPayload($payload);
            $newTotalPrice = $existingPrice->getTotalPrice();

            if (preg_match('#^/api/_action/order/#', $currentPage) ||  preg_match('#^/api/_proxy-order/#', $currentPage) || !empty($containsAllowedPage)) {
                $calculatedTaxElements = $lineItem->getPrice()->getCalculatedTaxes()->getElements();
                $avalaraTaxes = json_decode($payload['avalaraCalculatedTaxes'], true);
                $avalaraTaxRules = [];
                foreach ($avalaraTaxes as $avalaraTax) {
                  if($avalaraTax){
                    $avalaraTaxRate = is_array($avalaraTax) ? $avalaraTax['taxRate'] : $avalaraTax->getTaxRate();
                    $avalaraTaxRules[] = new TaxRule($avalaraTaxRate);
                  }
                }
                foreach ($avalaraTaxes as $taxRate => $taxData) {
                    if (!isset($calculatedTaxElements[$taxRate])) {
                        $calculatedTaxElements[$taxRate] = new CalculatedTax(
                            $taxData['tax'],
                            $taxRate,
                            $taxData['price']
                        );
                    }
                }
                $taxes = new CalculatedTaxCollection($calculatedTaxElements);
                $avalaraTaxRuleCollection = new TaxRuleCollection(array_merge($existingPrice->getTaxRules()->getElements(), $avalaraTaxRules));

                $calculatedPrice = new CalculatedPrice(
                    $existingPrice->getUnitPrice(),
                    $newTotalPrice,
                    $taxes,
                    $avalaraTaxRuleCollection,
                    $lineItem->getQuantity()
                );

                $lineItem->setPrice($calculatedPrice);
            }
        }
    }
}