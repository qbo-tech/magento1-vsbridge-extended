<?php

/**
 * Class Divante_VueStorefrontBridge_Model_Api_Cart_Totals
 *
 * @package     Divante
 * @category    VueStorefrontBridge
 * @author      Agata Firlejczyk <afirlejczyk@divante.pl
 * @copyright   Copyright (C) 2018 Divante Sp. z o.o.
 */
class Divante_VueStorefrontBridge_Model_Api_Cart_Totals
{

    /**
     * @param Mage_Sales_Model_Quote $quoteObj
     *
     * @return array
     */
    public function getTotalsAsArray(Mage_Sales_Model_Quote $quoteObj)
    {
        $shippingAddress = $quoteObj->getShippingAddress();
        $addressTotalData = $shippingAddress->getData();
        $quoteData = $quoteObj->getData();
        $totalsDTO = [
            'grand_total' => $quoteData['grand_total'],
            'base_grand_total' => $quoteData['base_grand_total'],
            'base_subtotal' => $quoteData['base_subtotal'],
            'subtotal' => $quoteData['subtotal'],
            'discount_amount' => isset($totals["discount"]) ? $totals["discount"]->getValue() : $quoteData['discount_amount'],
            'coupon_code' => $quoteObj->getCouponCode(),
            'base_discount_amount' => $quoteData['base_discount_amount'],
            'subtotal_with_discount' => $quoteData['subtotal_with_discount'],
            'shipping_amount' => $quoteData['shipping_amount'],
            'base_shipping_amount' => $quoteData['base_shipping_amount'],
            'shipping_discount_amount' => $quoteData['shipping_discount_amount'],
            'base_shipping_discount_amount' => $quoteData['base_shipping_discount_amount'],
            'tax_amount' => $quoteData['tax_amount'],
            'base_tax_amount' => $quoteData['base_tax_amount'],
            'weee_tax_applied_amount' => $quoteData['weee_tax_applied_amount'],
            'shipping_tax_amount' => $quoteData['shipping_tax_amount'],
            'base_shipping_tax_amount' => $quoteData['base_shipping_tax_amount'],
            'subtotal_incl_tax' => $quoteData['subtotal_incl_tax'],
            'base_subtotal_incl_tax' => $quoteData['base_subtotal_incl_tax'],
            'shipping_incl_tax' => $quoteData['shipping_incl_tax'],
            'base_shipping_incl_tax' => $quoteData['base_shipping_incl_tax'],                       
            'base_currency_code' => $quoteData['base_currency_code'],
            'quote_currency_code' => $quoteData['quote_currency_code'],
            'items_qty' => $quoteData['items_qty'],
            'items' => array(),
            'total_segments' => array()
        ];

        foreach ($quoteObj->getAllVisibleItems() as $item) {
            $itemDto = $item->getData();
            $product = $item->getProduct();

            if ($product && 'configurable' === $product->getTypeId()) {
                $itemDto['parentSku'] = $product->getData('sku');
            }

            $itemDto['options'] = $this->getOptions($item);
            $totalsDTO['items'][] = $itemDto;
        }

        $totalsCollection = $quoteObj->getTotals();

        foreach ($totalsCollection as $code => $total) {
            $totalsDTO['total_segments'][] = $total->getData();
        }

        return $totalsDTO;
    }

    /**
     * @param Mage_Catalog_Model_Product_Configuration_Item_Interface $item
     *
     * @return string
     */
    public function getOptions(Mage_Catalog_Model_Product_Configuration_Item_Interface $item)
    {
        $optionsData = [];
        /** @var Mage_Catalog_Helper_Product_Configuration $helper */
        $helper = Mage::helper('catalog/product_configuration');
        $options = $helper->getOptions($item);

        foreach ($options as $index => $optionValue) {
            $option = $helper->getFormattedOptionValue($optionValue);
            $optionsData[$index] = $option;
            $optionsData[$index]['label'] = $optionValue['label'];
        }

        return $this->serialize($optionsData);
    }

    /**
     * @param array $data
     *
     * @return string
     */
    public function serialize(array $data)
    {
        return json_encode($data);
    }
}
