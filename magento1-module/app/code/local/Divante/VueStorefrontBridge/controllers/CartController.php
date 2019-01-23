<?php
require_once('AbstractController.php');
require_once(__DIR__.'/../helpers/JWT.php');

/**
 * Cart Controller Logic for VSF 
 */
class Divante_VueStorefrontBridge_CartController extends Divante_VueStorefrontBridge_AbstractController
{
    /**
     * Create shopping cart - https://github.com/DivanteLtd/magento1-vsbridge/blob/master/doc/VueStorefrontBridge%20API%20specs.md#post-vsbridgecartcreate
     * @throws Mage_Core_Exception
     * @throws Mage_Core_Model_Store_Exception
     */
    public function createAction()
    {
        try {
            if ($this->getRequest()->getMethod() !== 'POST' && $this->getRequest()->getMethod() !== 'OPTIONS') {
                return $this->_result(500, 'Only POST method allowed');
            } else {
                $store = $this->_currentStore();
                $customer = $this->_currentCustomer($this->getRequest());

                $quoteObj = Mage::getModel('sales/quote')->loadByCustomer($customer);

                if(!$quoteObj || !$quoteObj->getId()) {

                    // quote assign to new customer
                    $quoteObj = Mage::getModel('sales/quote');
                    if ($customer) {
                        $quoteObj->assignCustomer($customer);
                    }
                    $quoteObj->setStoreId($store->getId()); // TODO: return existing user cart id if exists
                    $quoteObj->collectTotals();
                    $quoteObj->setIsActive(true);
                    $quoteObj->save();
                }

                $secretKey = trim(Mage::getConfig()->getNode('default/auth/secret'));
                /** Encoding cart_id for logged-in user, and returning it plain text for guests, 
                *   causes conflict with vsf order.schema.json while placing the order (cart_id is supossed to be a string).
                *   Original code:
                *   return $this->_result(200, $customer ? $quoteObj->getId() : JWT::encode(array('cartId' =>$quoteObj->getId()), $secretKey));
                * 
                * Remove this comment after PR is approved.
                */
                return $this->_result(200, 
                    JWT::encode(
                        array('cartId' => $quoteObj->getId()), $secretKey
                    )
                );
            }
        } catch (Exception $err) {
            return $this->_result(500, $err->getMessage());
        }
    }

    /**
     * Pull the server cart for synchronization
     * https://github.com/DivanteLtd/magento1-vsbridge/blob/master/doc/VueStorefrontBridge%20API%20specs.md#get-vsbridgecartpull
     */
    public function pullAction()
    {
        try {
            if (!$this->_checkHttpMethod('GET')) {
                return $this->_result(500, 'Only GET method allowed');
            } else {
                $customer = $this->_currentCustomer($this->getRequest());
                $quoteObj = $this->_currentQuote($this->getRequest());

                if(!$quoteObj) {
                    return $this->_result(500, 'No quote found for cartId = '.$this->getRequest()->getParam('cartId'));
                } else {
                    if(!$this->_checkQuotePerms($quoteObj, $customer)) {
                        return $this->_result(500, 'User is not authroized to access cartId = '.$this->getRequest()->getParam('cartId'));
                    } else {
                        $items = array();
                        foreach ($quoteObj->getAllItems() as $item) {
                            $itemDto = $item->getData();
                            $items[] = array(
                                'item_id' => $itemDto['item_id'],
                                'sku' => (string)$itemDto['sku'],
                                'name' => $itemDto['name'],
                                'price' => $itemDto['price'],
                                'qty' => $itemDto['qty'],
                                'product_type' => $itemDto['product_type'],
                                'quote_id' => $itemDto['quote_id']
                            );
                        }
                        return $this->_result(200, $items, null, false);
                    }
                }
            }
        } catch (Exception $err) {
            Mage::logException($err);
            return $this->_result(500, $err->getMessage());
        }
    }
    /**
     * Apply Discount Code
     * https://github.com/DivanteLtd/magento1-vsbridge/blob/master/doc/VueStorefrontBridge%20API%20specs.md#post-vsbridgecartapply-coupon
     * 
     * @throws Mage_Core_Exception
     * @throws Mage_Core_Model_Store_ExceptionC
     */
    public function applyCouponAction()
    {
        if (!$this->_checkHttpMethod('POST')) {
            return $this->_result(500, 'Only POST method allowed');
        } else {
            $store = $this->_currentStore();
            $customer = $this->_currentCustomer($this->getRequest());
            $quoteObj = $this->_currentQuote($this->getRequest());

            if(!$quoteObj) {
                return $this->_result(500, 'No quote found for cartId = '.$this->getRequest()->getParam('cartId'));
            } else {
                if(!$this->_checkQuotePerms($quoteObj, $customer)) {
                    return $this->_result(500, 'User is not authroized to access cartId = '.$this->getRequest()->getParam('cartId'));
                } else {
                    $couponCode = $this->getRequest()->getParam('coupon');
                    
                    if(!$couponCode) {
                        return $this->_result(500, 'Coupon code is required');
                    }
                    
                    try {
                        $request = $this->_getJsonBody();
                        $quoteObj->setCouponCode($couponCode)->collectTotals()->save();
                        if(!$quoteObj->getCouponCode()) {
                            return $this->_result(500, false);
                        }

                        return $this->_result(200, true);
                    } catch (Exception $err) {
                        return $this->_result(500, false);
                    }
                }
            }
        }
    }  

   /**
     * Delete Discount Code
     * https://github.com/DivanteLtd/magento1-vsbridge/blob/master/doc/VueStorefrontBridge%20API%20specs.md#post-vsbridgecartdelete-coupon
     * 
     * @throws Mage_Core_Exception
     * @throws Mage_Core_Model_Store_Exception
     */
    public function deleteCouponAction()
    {
        if (!$this->_checkHttpMethod('POST')) {
            return $this->_result(500, 'Only POST method allowed');
        } else {
            $store = $this->_currentStore();
            $customer = $this->_currentCustomer($this->getRequest());
            $quoteObj = $this->_currentQuote($this->getRequest());

            if(!$quoteObj) {
                return $this->_result(500, 'No quote found for cartId = '.$this->getRequest()->getParam('cartId'));
            } else {
                if(!$this->_checkQuotePerms($quoteObj, $customer)) {
                    return $this->_result(500, 'User is not authroized to access cartId = '.$this->getRequest()->getParam('cartId'));
                } else {
                    try {
                        $request = $this->_getJsonBody();
                        $quoteObj->setCouponCode('')->collectTotals()->save();

                        return $this->_result(200, true);
                    } catch (Exception $err) {
                         Mage::logException($err);
                        return $this->_result(500, false);
                    }

                }
            }
        }
    }      
    
   /**
     * Get Discount Code
     * https://github.com/DivanteLtd/magento1-vsbridge/blob/master/doc/VueStorefrontBridge%20API%20specs.md#get-vsbridgecartcoupon
     * 
     * @throws Mage_Core_Exception
     * @throws Mage_Core_Model_Store_ExceptionC
     */
    public function couponAction()
    {
        if (!$this->_checkHttpMethod('GET')) {
            return $this->_result(500, 'Only GET method allowed');
        } else {
            $store = $this->_currentStore();
            $customer = $this->_currentCustomer($this->getRequest());
            $quoteObj = $this->_currentQuote($this->getRequest());

            if(!$quoteObj) {
                return $this->_result(500, 'No quote found for cartId = '.$this->getRequest()->getParam('cartId'));
            } else {
                if(!$this->_checkQuotePerms($quoteObj, $customer)) {
                    return $this->_result(500, 'User is not authroized to access cartId = '.$this->getRequest()->getParam('cartId'));
                } else {
                    try {
                        return $this->_result(200, $quoteObj->getCouponCode());
                    } catch (Exception $err) {
                        return $this->_result(500, false);
                    }

                }
            }
        }
    }    
    /**
     * Get Quote totals and Collect totals and set shipping information
     * https://github.com/DivanteLtd/magento1-vsbridge/blob/master/doc/VueStorefrontBridge%20API%20specs.md#post-vsbridgecartcollect-totals
     * https://github.com/DivanteLtd/magento1-vsbridge/blob/master/doc/VueStorefrontBridge%20API%20specs.md#get-vsbridgecarttotals
     * https://github.com/DivanteLtd/magento1-vsbridge/blob/master/doc/VueStorefrontBridge%20API%20specs.md#post-vsbridgecartshipping-information
     */
    public function totalsAction()
    {
        try {
            if (!$this->_checkHttpMethod(array('GET', 'POST'))) {
                return $this->_result(500, 'Only GET or POST methods allowed');
            } else {
                $customer = $this->_currentCustomer($this->getRequest());
                $quoteObj = $this->_currentQuote($this->getRequest());

                if(!$quoteObj) {
                    return $this->_result(500, 'No quote found for cartId = '.$this->getRequest()->getParam('cartId'));
                } else {
                    if(!$this->_checkQuotePerms($quoteObj, $customer)) {
                        return $this->_result(500, 'User is not authroized to access cartId = '.$this->getRequest()->getParam('cartId'));
                    } else {
                        $request = $this->_getJsonBody();

                        if ($request) {
                            //$paymentMethodCode = "paypal_plus";
                            $paymentMethodCode = $request->methods->paymentMethod->method;
                            $shippingMethodCode = $request->addressInformation->shippingMethodCode;
                            $shippingMethodCarrier = $request->addressInformation->shippingCarrierCode;                           
                            $address = null;

                            if ($quoteObj->isVirtual()) {
                                $address = $quoteObj->getBillingAddress();
                            } else {
                                $address = $quoteObj->getShippingAddress();
                            }

                            if($request->addressInformation) {
                                $countryId = $request->addressInformation->shippingAddress->countryId;
                                if($countryId) {
                                    $address->setCountryId($countryId)->save();
                                }
                            }
                           
                            $address->setCollectShippingRates(true)
                                ->setShippingMethod($shippingMethodCode . "_" . $shippingMethodCode)
                                ->collectShippingRates()
                                ->save();                            
                                                 
                           
                            if($paymentMethodCode) {
                                $address->setPaymentMethod($paymentMethodCode)->save();
                            }
                            
                        } else {
                            $shippingAddress = $quoteObj->getShippingAddress()->setCollectShippingRates(true)
                                ->collectShippingRates();
                        }
                        
                        $quoteObj->collectTotals()->save();
                        $quoteData = $quoteObj->getData();
                        
                        $totals = $quoteObj->getTotals();
                    
                        $totalsDTO = array(
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
                        );

                        foreach ($quoteObj->getAllItems() as $item) {
                            $itemDto = $item->getData();
                            $totalsDTO['items'][] = $itemDto;
                        }

                        $totalsCollection = $quoteObj->getTotals();
                        foreach($totalsCollection as $code => $total) {
                            $totalsDTO['total_segments'][] = $total->getData();
                        }
                        if ($quoteObj->isVirtual() || !$quoteData['shipping_amount'] )  {
                             $totalsDTO['total_segments'][] = array(
                                 'code' => 'shipping',
                                 'title' => Mage::helper("sales")->__("Shipping"),
                                 'value' => 0,
                                 'address' => []
                             );
                        }
                        return $this->_result(200, $totalsDTO);
                    }
                }
            }
        } catch (Exception $err) {
            return $this->_result(500, $err->getMessage());
        }
    }    
    /**
     * Check if payment method is available for current Quote.
     */
    protected function _canUsePaymentMethod($method, $quote)
    {   
        if (!($method->isGateway() || $method->canUseInternal())) {
            return false;
        }

        if (!$method->canUseForCountry($quote->getBillingAddress()->getCountry())) {
            return false;
        }

        if (!$method->canUseForCurrency(Mage::app()->getStore($quote->getStoreId())->getBaseCurrencyCode())) {
            return false;
        }
        /**
         * Checking for min/max order total for assigned payment method
         */
        $total = $quote->getBaseGrandTotal();
        $minTotal = $method->getConfigData('min_order_total');
        $maxTotal = $method->getConfigData('max_order_total');

        if ((!empty($minTotal) && ($total < $minTotal)) || (!empty($maxTotal) && ($total > $maxTotal))) {
            return false;
        }

        return true;
    }    

    /**
     * Get active payment methods
     * https://github.com/DivanteLtd/magento1-vsbridge/blob/master/doc/VueStorefrontBridge%20API%20specs.md#get-vsbridgecartpayment-methods
     */
    public function paymentMethodsAction()
    {
            try {
            if (!$this->_checkHttpMethod('GET')) {
                return $this->_result(500, 'Only GET method allowed');
            } else {
                $customer = $this->_currentCustomer($this->getRequest());
                $quoteObj = $this->_currentQuote($this->getRequest());

                if(!$quoteObj) {
                    return $this->_result(500, 'No quote found for cartId = ' . $this->getRequest()->getParam('cartId'));
                } else {
                    if(!$this->_checkQuotePerms($quoteObj, $customer)) {
                        return $this->_result(500, 'User is not authroized to access cartId = ' . $this->getRequest()->getParam('cartId'));
                    } else {

                        $store = $quoteObj->getStoreId();
                
                        $total = $quoteObj->getBaseSubtotal();
                
                        $methodsResult = array();
                        $methods = Mage::helper('payment')->getStoreMethods($store, $quoteObj);
                
                        foreach ($methods as $method) {
                            /** @var $method Mage_Payment_Model_Method_Abstract */
                            if ($this->_canUsePaymentMethod($method, $quoteObj)) {
                                $isRecurring = $quoteObj->hasRecurringItems() && $method->canManageRecurringProfiles();
                
                                if ($total != 0 || $method->getCode() == 'free' || $isRecurring) {
                                    $methodsResult[] = array(
                                        'code' => $method->getCode(),
                                        'title' => $method->getTitle()
                                    );
                                }
                            }
                        }
                                    
                        return $this->_result(200, $methodsResult);

                    }
                }
            }
        } catch (Exception $err) {
            return $this->_result(500, $err->getMessage());
        }
    }
    /**
     * Collect Shipping Methods
     */
    protected function _getAllShippingMethods()
    {
        $methods = Mage::getSingleton('shipping/config')->getActiveCarriers();
    
        $options = array();
    
        foreach($methods as $_ccode => $_carrier)
        {
            $_methodOptions = array();
            if($_methods = $_carrier->getAllowedMethods())
            {
                foreach($_methods as $_mcode => $_method)
                {
                    $_code = $_ccode . '_' . $_mcode;
                    $_methodOptions[] = array('value' => $_code, 'label' => $_method);
                }
    
                if(!$_title = Mage::getStoreConfig("carriers/$_ccode/title"))
                    $_title = $_ccode;
    
                $options[] = array('value' => $_methodOptions, 'label' => $_title);
            }
        }
    
        return $options;
    }
    /**
     * Get active shipping methods
     * https://github.com/DivanteLtd/magento1-vsbridge/blob/master/doc/VueStorefrontBridge%20API%20specs.md#post-vsbridgecartshipping-methods
     */
    public function shippingMethodsAction()
    {
            try {
            if (!$this->_checkHttpMethod('POST')) {
                return $this->_result(500, 'Only POST method allowed');
            } else {
                $customer = $this->_currentCustomer($this->getRequest());
                $quoteObj = $this->_currentQuote($this->getRequest());

                if(!$quoteObj) {
                    return $this->_result(500, 'No quote found for cartId = '.$this->getRequest()->getParam('cartId'));
                } else {
                    if(!$this->_checkQuotePerms($quoteObj, $customer)) {
                        return $this->_result(500, 'User is not authroized to access cartId = '.$this->getRequest()->getParam('cartId'));
                    } else {
                        $request = $this->_getJsonBody();
                        $quoteShippingAddress = $quoteObj->getShippingAddress();

                        if($request->address) {
                            $countryId = $request->address->country_id;
                            if($countryId) {
                                $quoteShippingAddress->setCountryId($countryId)->setCollectShippingrates(true)->save();
                            }
                        }
                        
                        $store = $quoteObj->getStoreId();
                        if (is_null($quoteShippingAddress->getId())) {
                            $this->_result(500, 'Shipping address is not set');
                        }
                
                        try {
                            $groupedRates = $quoteShippingAddress->setCollectShippingRates(true)->collectShippingRates()->getGroupedAllShippingRates();
                            $ratesResult = array();
                           
                            foreach ($groupedRates as $carrierCode => $rates ) {
                                $carrierName = $carrierCode;
                                if (!is_null(Mage::getStoreConfig('carriers/'.$carrierCode.'/title'))) {
                                    $carrierName = Mage::getStoreConfig('carriers/'.$carrierCode.'/title');
                                }
                                foreach ($rates as $rate) {
                                    $rateItem = $rate->getData();
                                    $rateItem['method_title'] = $carrierName;
                                    $rateItem['carrier_code'] = $carrierCode;
                                    $rateItem['method_code'] = $rateItem['method'];
                                    $rateItem['amount'] = $rateItem['price'];
                                   
                                    if($quoteObj->isVirtual()){
                                        $rateItem['price'] = 0;
                                        $rateItem['amount'] = 0;
                                    }
                                    $ratesResult[] = $rateItem;
                                    unset($rateItem);
                                }
                            }
                            return $this->_result(200, $ratesResult);
                        } catch (Mage_Core_Exception $e) {
                            return$this->_result(500, $e->getMessage());
                        }
                    }
                }
            }
        } catch (Exception $err) {
            return $this->_result(500, $err->getMessage());
        }
    }    
    /**
     * Update Cart Action
     */
    public function updateAction()
    {
        if (!$this->_checkHttpMethod('POST')) {
            return $this->_result(500, 'Only POST method allowed');
        } else {

            $request = $this->_getJsonBody();

            if (!$request) {
                return $this->_result(500, 'No JSON object found in the request body');
            } else {
                if ((!property_exists($request, "cartItem") || !$request->cartItem)) {
                    return $this->_result(500, 'No cartItem data provided!');
                } else {

                    $cartItem = $request->cartItem;

                    $customer = $this->_currentCustomer($this->getRequest());
                    $quoteObj = $this->_currentQuote($this->getRequest());

                    if(!$quoteObj) {
                        return $this->_result(500, 'No quote found for cartId = '.$this->getRequest()->getParam('cartId'));
                    } else {
                        if (!$this->_checkQuotePerms($quoteObj, $customer)) {
                            return $this->_result(500, 'User is not authroized to access cartId = ' . $this->getRequest()->getParam('cartId'));
                        } else {

                           try {
                                if ($cartItem->item_id && $cartItem->qty) { // update action
                                    $item = $quoteObj->updateItem($cartItem->item_id, array('qty' => max(1, $cartItem->qty)));
                                    $quoteObj->collectTotals()->save();

                                    $itemDto = $item->getData();

                                    return $this->_result(200, array(
                                        'item_id' => $itemDto['item_id'],
                                        'sku' => $itemDto['sku'],
                                        'name' => $itemDto['name'],
                                        'price' => $itemDto['price'],
                                        'qty' => $itemDto['qty'],
                                        'product_type' => $itemDto['product_type'],
                                        'quote_id' => $itemDto['quote_id']
                                    ));

                                } else {
                                    $product_id = Mage::getModel('catalog/product')->getIdBySku($cartItem->sku);
                                    $product = Mage::getModel('catalog/product')->load($product_id);

                                    if (!$product) {
                                        return $this->_result(500, 'No product found with given SKU = ' . $cartItem->sku);
                                    } else { // stock quantity check required or not?
                                        $alreadyInCart = false;
                                        foreach($quoteObj->getAllVisibleItems() as $item) {
                                            if ($item->getData('prodduct_id') == $product_id) {
                                                $alreadyInCart = true;
                                                break;
                                            }
                                        }
                                        if(!$alreadyInCart) {
                                            $item = $quoteObj->addProduct($product, max(1, $cartItem->qty));
                                        }   
                                        $quoteObj->collectTotals()->save();

                                        $itemDto = $item->getData();
                                        return $this->_result(200, array(
                                            'item_id' => $itemDto['item_id'],
                                            'sku' => $itemDto['sku'],
                                            'name' => $itemDto['name'],
                                            'price' => $itemDto['price'],
                                            'qty' => $itemDto['qty'],
                                            'product_type' => $itemDto['product_type'],
                                            'quote_id' => $itemDto['quote_id']
                                        ));
                                    }
                                }
                           } catch (Exception $err) {
                               Mage::logException($err);
                               return $this->_result(500, $this->__("The requested quantity for this product is not available."));
                           }
                        }
                    }

                }
            }

        }

    }
    /**
     * Delete Cart Action
     */
    public function deleteAction()
    {
        if (!$this->_checkHttpMethod('POST')) {
            return $this->_result(500, 'Only POST method allowed');
        } else {

            $request = $this->_getJsonBody();

            if (!$request) {
                return $this->_result(500, 'No JSON object found in the request body');
            } else {
                if ((!$request->cartItem)) {
                    return $this->_result(500, 'No cartItem data provided!');
                } else {

                    $cartItem = $request->cartItem;
                    $customer = $this->_currentCustomer($this->getRequest());
                    $quoteObj = $this->_currentQuote($this->getRequest());

                    if (!$quoteObj) {
                        return $this->_result(500, 'No quote found for cartId = ' . $this->getRequest()->getParam('cartId'));
                    } else {
                        if (!$this->_checkQuotePerms($quoteObj, $customer)) {
                            return $this->_result(500, 'User is not authroized to access cartId = ' . $this->getRequest()->getParam('cartId'));
                        } else {

                            try {
                                if ($cartItem->item_id) { // update action
                                    $quoteObj->removeItem($cartItem->item_id);
                                    $quoteObj->collectTotals()->save();

                                    return $this->_result(200, true);

                                }
                            } catch (Exception $err) {
                                Mage::logException($err);
                                return $this->_result(500, $err->getMessage());
                            }

                        }
                    }

                }
    }
}
    }
    /**
    * Set Billing Address to Quote
    */
    public function billingInformationAction() 
    {
        try {
            if (!$this->_checkHttpMethod('POST')) {
                return $this->_result(500, 'Only POST method allowed');
            } else {
                $cartId = $this->getRequest()->getParam('cartId');
                $customer = $this->_currentCustomer($this->getRequest());
                $quoteObj = $this->_currentQuote($this->getRequest());

                if(!$quoteObj) {
                    return $this->_result(500, sprintf('No quote found for Cart ID %s ', $cartId));
                } else {
                    if(!$this->_checkQuotePerms($quoteObj, $customer)) {
                        return $this->_result(500, sprintf('User is not authroized to access Cart ID %s ', $cartId));
                    } else {
                        $request = $this->_getJsonBody();

                        $billingAddress = $this->_getBillingAddress($request, $quoteObj);
                        if($customer) {
                            $billingAddress->setSaveInAddressBook(true);
                        } else {
                            $billingAddress->setFirstname($request->address->firstname)
                                ->setLastname($request->address->lastname)
                                ->setEmail($request->address->email);
                        } 
                        $billingAddress->save();

                        $quoteObj->setBillingAddress($billingAddress);

                        return $this->_result(200, 
                            array('totals' => $quoteObj->getGrandTotal())
                        );
                    }
                }
            }
        } catch (Exception $err) {
            Mage::logException($err);
            return $this->_result(500, $err->getMessage());
        }
    }
    /**
    * Set Shipping Address to Quote
    */
    public function shippingInformationAction() 
    {
         try {
            if (!$this->_checkHttpMethod('POST')) {
                return $this->_result(500, 'Only POST method allowed');
            } else {
                $cartId = $this->getRequest()->getParam('cartId');
                $customer = $this->_currentCustomer($this->getRequest());
                $quote = $this->_currentQuote($this->getRequest());

                if(!$quote) {
                    return $this->_result(500, sprintf('No quote found for Cart ID %s ', $cartId));
                } else {
                    if(!$this->_checkQuotePerms($quote, $customer)) {
                        return $this->_result(500, sprintf('User is not authroized to access Cart ID %s ', $cartId));
                    } else {
                        $request = $this->_getJsonBody();
			
                        $address = $this->_getShippingAddress($request, $quote);
                        if($customer) {
                            $quote->setCustomer($customer);
                        }
                        $this->_prepareShippingAddress($address, $customer, $request);

                        // User may change Fist, latname and email address during VUE checkout. Overwrite thos values for the order.
                        $quote->setCustomerEmail($request->addressInformation->shippingAddress->email)
                            ->setCustomerFirstname($request->addressInformation->shippingAddress->firstname)
                            ->setCustomerLastname($request->addressInformation->shippingAddress->lastname)   
                            ->setShippingAddress($address)
                            ->collectTotals()
                            ->save();

                        return $this->_result(200, 
                            array('totals' => $quote->getGrandTotal())
                        );
                    }
                }
            }
        } catch (Exception $err) {
            Mage::logException($err);
            return $this->_result(500, $err->getMessage());
        }
    }
    /**
     * Build Shipping address Array
     * @param Array $request
     * @param Mage_Sales_Model_Quote $quote
     */
    protected function _getShippingAddress($request, $quote)
    {
        return $quote->getShippingAddress()->addData(array(
            'customer_address_id' => '',
            'prefix' => '',
            'firstname' => $request->addressInformation->shippingAddress->firstname,
            'lastname' => $request->addressInformation->shippingAddress->lastname,
            'suffix' => '',
            'company' => $request->addressInformation->shippingAddress->company, 
            'street' => implode("\n", array(
                '0' => $request->addressInformation->shippingAddress->street[0],
                '1' => $request->addressInformation->shippingAddress->street[1]
            )),
            'city' => $request->addressInformation->shippingAddress->city,
            'country_id' => $request->addressInformation->shippingAddress->countryId,
            'region_id' => property_exists($request->addressInformation->shippingAddress, "regionCode") ? $request->addressInformation->shippingAddress->regionCode : 491,
            'postcode' => $request->addressInformation->shippingAddress->postcode,
            'telephone' => $request->addressInformation->shippingAddress->telephone
        ))->save();

    }
    /**
     * Prepare and collect Shipping address rates
     * @param Array $shippingAddress
     */
    protected function _prepareShippingAddress(&$shippingAddress, $customer, $request)
    {
        if($customer) {
            $shippingAddress->setSaveInAddressBook(true);
        } else {
            $shippingAddress->setFirstname($request->addressInformation->shippingAddress->firstname)
                ->setLastname($request->addressInformation->shippingAddress->lastname)
                ->setEmail($request->addressInformation->shippingAddress->email);
        }

        // Collect Rates and Set Shipping Method
        $shipingMethod = $request->addressInformation->shippingMethodCode;
        
        $shippingAddress
            ->setShippingMethod($request->addressInformation->shippingCarrierCode . "_" . $request->addressInformation->shippingMethodCode)
            ->setCollectShippingRates(true)
            ->collectShippingRates()
            //->setShippingMethod($request->addressInformation->shippingMethodCode . "_" . $request->addressInformation->shippingCarrierCode)
            //->setShippingMethod($request->addressInformation->shippingMethodCode . "_" . $request->addressInformation->shippingMethodCode)
            ->save();

        return $shippingAddress;
    }

     /**
     * Build Billing address Array
     * @param Array $request
     * @param Mage_Sales_Model_Quote $quote
     */
    protected function _getBillingAddress($request, $quoteObj)
    {
        return $quoteObj->getBillingAddress()->addData(array(
            'customer_address_id' => '',
            'prefix' => '',
            'firstname' => $request->address->firstname,
            'lastname' => $request->address->lastname,
            'suffix' => '',
            'street' => implode("\n", array(
                '0' => $request->address->street[0],
                '1' => $request->address->street[1]
            )),
            'city' => $request->address->city,
            'country_id' => $request->address->countryId,
            'region_id' => property_exists($request->address, "regionCode") ? $request->address->regionCode : 491,
            'postcode' => $request->address->postcode,
            'telephone' => $request->address->telephone
        )); 
    }
     /**
     * Get regions list by country code
     */
    public function regionsAction()
    {
        try {
            if (!$this->_checkHttpMethod('GET')) {
                return $this->_result(500, 'Only GET method allowed');
            } else {
                $quoteObj = $this->_currentQuote($this->getRequest());
                
                if(!$quoteObj) {
                    return $this->_result(500, 'No quote found for cartId = '.$this->getRequest()->getParam('cartId'));
                } else {
                    $countryId = $this->getRequest()->getParam('countryCode');
                    if (!$countryId) {
                        return $this->_result(400, 'Please specify a Country Code');
                    }
                    $regionCollection  = Mage::getModel('directory/region')->getResourceCollection()
                        ->addCountryFilter($countryId)
                        ->setOrder('region_id', Varien_Data_Collection::SORT_ORDER_ASC)
                        ->load();

                     $items = array();
                     foreach($regionCollection as $region) {
                         Mage::log($region->getData());
                         $items[$region->getId()] = $region->getName();
                     }
                     return $this->_result(200, $items, null, false);
                }
            }
        } catch (Exception $err) {
            Mage::logException($err);
            return $this->_result(500, $err->getMessage());
        }
    }
}
?>
