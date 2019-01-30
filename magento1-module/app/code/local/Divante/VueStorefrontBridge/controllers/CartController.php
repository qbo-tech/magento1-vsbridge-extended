<?php

require_once('AbstractController.php');
require_once(__DIR__.'/../helpers/JWT.php');

/**
 * Class Divante_VueStorefrontBridge_CartController
 *
 * @package     Divante
 * @category    VueStorefrontBridge
 * @copyright   Copyright (C) 2018 Divante Sp. z o.o.
 */
class Divante_VueStorefrontBridge_CartController extends Divante_VueStorefrontBridge_AbstractController
{

    /**
     * @var Divante_VueStorefrontBridge_Model_Api_Cart
     */
    private $cartModel;

    /**
     * @var Divante_VueStorefrontBridge_Model_Api_Cart_Totals
     */
    private $totalsModel;

    /**
     * @var Divante_VueStorefrontBridge_Model_Api_Request
     */
    private $requestModel;

    /**
     * Divante_VueStorefrontBridge_WishlistController constructor.
     *
     * @param Zend_Controller_Request_Abstract  $request
     * @param Zend_Controller_Response_Abstract $response
     * @param array                             $invokeArgs
     */
    public function __construct(
        Zend_Controller_Request_Abstract $request,
        Zend_Controller_Response_Abstract $response,
        array $invokeArgs = []
    ) {
        parent::__construct($request, $response, $invokeArgs);
        $this->cartModel = Mage::getSingleton('vsbridge/api_cart');
        $this->totalsModel = Mage::getSingleton('vsbridge/api_cart_totals');
        $this->requestModel = Mage::getSingleton('vsbridge/api_request');
    }

    /**
     * Create shopping cart -
     * https://github.com/DivanteLtd/magento1-vsbridge/blob/master/doc/VueStorefrontBridge%20API%20specs.md#post-vsbridgecartcreate
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

                    if ($customer instanceof Mage_Customer_Model_Customer) {
                        $quoteObj->assignCustomer($customer);
                    }
                    $quoteObj->setStoreId($store->getId()); // TODO: return existing user cart id if exists
                    $quoteObj->collectTotals();
                    $quoteObj->setIsActive(true);
                    $quoteObj->save();
                }
                $secretKey = trim(Mage::getConfig()->getNode('default/auth/secret'));

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
            }

            if (!$this->requestModel->validateQuote($this->getRequest())) {
                return $this->_result(500, $this->requestModel->getErrorMessage());
            }

            /** @var Mage_Sales_Model_Quote $quoteObj */
            $quoteObj = $this->requestModel->currentQuote($this->getRequest());
            $items = [];

            foreach ($quoteObj->getAllVisibleItems() as $item) {
                $items[] = $this->cartModel->getItemAsArray($item);
            }

            return $this->_result(200, $items);
        } catch (Exception $err) {
            return $this->_result(500, $err->getMessage());
        }
    }

    /**
     * Apply Discount Code
     * https://github.com/DivanteLtd/magento1-vsbridge/blob/master/doc/VueStorefrontBridge%20API%20specs.md#post-vsbridgecartapply-coupon
     */
    public function applyCouponAction()
    {
        if (!$this->_checkHttpMethod('POST')) {
            return $this->_result(500, 'Only POST method allowed');
        }

        if (!$this->requestModel->validateQuote($this->getRequest())) {
            return $this->_result(500, $this->requestModel->getErrorMessage());
        }

        $quoteObj = $this->requestModel->currentQuote($this->getRequest());
        $couponCode = $this->getRequest()->getParam('coupon');

        if (!$couponCode) {
            return $this->_result(500, 'Coupon code is required');
        }

        try {
            $quoteObj->setCouponCode($couponCode);
            $quoteObj->collectTotals()->save();

            if(!$quoteObj->getCouponCode()) {
                return $this->_result(500, false);
            }

            return $this->_result(200, true);
        } catch (Exception $err) {
            return $this->_result(500, false);
        }
    }

    /**
     * Delete Discount Code
     * https://github.com/DivanteLtd/magento1-vsbridge/blob/master/doc/VueStorefrontBridge%20API%20specs.md#post-vsbridgecartdelete-coupon
     */
    public function deleteCouponAction()
    {
        if (!$this->_checkHttpMethod('POST')) {
            return $this->_result(500, 'Only POST method allowed');
        }

        if (!$this->requestModel->validateQuote($this->getRequest())) {
            return $this->_result(500, $this->requestModel->getErrorMessage());
        }

        try {
            $quoteObj = $this->requestModel->currentQuote($this->getRequest());
            $quoteObj->setCouponCode('')->collectTotals()->save();

            return $this->_result(200, true);
        } catch (Exception $err) {
            return $this->_result(500, false);
        }
    }

    /**
     * Get Discount Code
     * https://github.com/DivanteLtd/magento1-vsbridge/blob/master/doc/VueStorefrontBridge%20API%20specs.md#get-vsbridgecartcoupon
     */
    public function couponAction()
    {
        if (!$this->_checkHttpMethod('GET')) {
            return $this->_result(500, 'Only GET method allowed');
        }

        if (!$this->requestModel->validateQuote($this->getRequest())) {
            return $this->_result(500, $this->requestModel->getErrorMessage());
        }

        try {
            $quoteObj = $this->requestModel->currentQuote($this->getRequest());

            return $this->_result(200, $quoteObj->getCouponCode());
        } catch (Exception $err) {
            return $this->_result(500, false);
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
            }

            if (!$this->requestModel->validateQuote($this->getRequest())) {
                return $this->_result(500, $this->requestModel->getErrorMessage());
            }

            $quoteObj = $this->requestModel->currentQuote($this->getRequest());
            $request = $this->_getJsonBody();

            if ($request && isset($request->methods)) {
                $paymentMethodCode = $request->methods->paymentMethod->method;

                if(property_exists($request->methods->shippingMethodCode)) {
                    $shippingMethodCode = $request->methods->shippingMethodCode;  
                } else {
                    $shippingMethodCode = $request->addressInformation->shippingMethodCode;
                }

                $address = null;

                if ($quoteObj->isVirtual()) {
                    $address = $quoteObj->getBillingAddress();
                } else {
                    $address = $quoteObj->getShippingAddress();
                    $shippingAddress = $quoteObj->getShippingAddress();

                    if ($request->addressInformation) {
                        $shippingMethodCode = $request->addressInformation->shipping_method_code;
                        $countryId = $request->addressInformation->shipping_address->country_id;

                        if ($countryId) {
                            $shippingAddress->setCountryId($countryId)->setCollectShippingrates(true)->save();
                        }
                    }
                    $address->setCollectShippingRates(true)
                        ->setShippingMethod($shippingMethodCode)
                        ->collectShippingRates()
                        ->save();  
                }

                if ($address && $paymentMethodCode) {
                    $address->setPaymentMethod($paymentMethodCode);
                }
            } else {
                $quoteObj->getShippingAddress()->setCollectShippingRates(true)->collectShippingRates();
            }

            $this->cartModel->saveQuote($quoteObj);
            $totalsDTO = $this->totalsModel->getTotalsAsArray($quoteObj);

            return $this->_result(200, $totalsDTO);
        } catch (Exception $err) {
            return $this->_result(500, $err->getMessage());
        }
    }

    /**
     * @param $method
     * @param $quote
     *
     * @return bool
     * @throws Mage_Core_Model_Store_Exception
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
        if (!$this->_checkHttpMethod('GET')) {
            return $this->_result(500, 'Only GET method allowed');
        }

        try {
            if (!$this->requestModel->validateQuote($this->getRequest())) {
                return $this->_result(500, $this->requestModel->getErrorMessage());
            }

            $quoteObj = $this->requestModel->currentQuote($this->getRequest());
            $store = $quoteObj->getStoreId();
            $total = $quoteObj->getBaseSubtotal();
            $methodsResult = [];
            $methods = Mage::helper('payment')->getStoreMethods($store, $quoteObj);

            foreach ($methods as $method) {
                /** @var $method Mage_Payment_Model_Method_Abstract */
                if ($this->_canUsePaymentMethod($method, $quoteObj)) {
                    $isRecurring = $quoteObj->hasRecurringItems() && $method->canManageRecurringProfiles();

                    if ($total != 0 || $method->getCode() == 'free' || $isRecurring) {
                        $methodsResult[] = [
                            'code' => $method->getCode(),
                            'title' => $method->getTitle(),
                        ];
                    }
                }
            }

            return $this->_result(200, $methodsResult);
        } catch (Exception $err) {
            return $this->_result(500, $err->getMessage());
        }
    }

    /**
     * @return array
     */
    protected function _getAllShippingMethods()
    {
        $methods = Mage::getSingleton('shipping/config')->getActiveCarriers();
        $options = [];

        foreach ($methods as $ccode => $_carrier) {
            $methodOptions = [];
            $allowedMethods = $_carrier->getAllowedMethods();

            if ($allowedMethods) {
                foreach ($allowedMethods as $allowMcode => $allowMethod) {
                    $code = $ccode . '_' . $allowMcode;
                    $methodOptions[] = ['value' => $code, 'label' => $allowMethod];
                }

                $title = Mage::getStoreConfig("carriers/$ccode/title");

                if (!$title) {
                    $title = $ccode;
                }

                $options[] = ['value' => $methodOptions, 'label' => $title];
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
            }

            if (!$this->requestModel->validateQuote($this->getRequest())) {
                return $this->_result(500, $this->requestModel->getErrorMessage());
            }

            $quoteObj = $this->requestModel->currentQuote($this->getRequest());
            $request = $this->_getJsonBody();
            $quoteShippingAddress = $quoteObj->getShippingAddress();

            if ($request->address) {
                $countryId = $request->address->country_id;
                if ($countryId) {
                    $quoteShippingAddress->setCountryId($countryId)->setCollectShippingrates(true)->save();
                }
            }

            if (is_null($quoteShippingAddress->getId())) {
                $this->_result(500, 'Shipping address is not set');
            }

            try {
                $groupedRates = $quoteShippingAddress->setCollectShippingRates(true)->collectShippingRates()
                    ->getGroupedAllShippingRates();
                $ratesResult = [];

                foreach ($groupedRates as $carrierCode => $rates) {
                    $carrierName = $carrierCode;
                    if (!is_null(Mage::getStoreConfig('carriers/' . $carrierCode . '/title'))) {
                        $carrierName = Mage::getStoreConfig('carriers/' . $carrierCode . '/title');
                    }

                    foreach ($rates as $rate) {
                        $rateItem = $rate->getData();
                        $rateItem['carrier_title'] = $carrierName;
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
                return $this->_result(500, $e->getMessage());
            }
        } catch (Exception $err) {
            return $this->_result(500, $err->getMessage());
        }
    }

    /**
     * Add/Update Item in Cart
     */
    public function updateAction()
    {
        if (!$this->_checkHttpMethod('POST')) {
            return $this->_result(500, 'Only POST method allowed');
        }

        $request = $this->_getJsonBody();

        if (!$request) {
            return $this->_result(500, 'No JSON object found in the request body');
        }

        if (!$request->cartItem) {
            return $this->_result(500, 'No cartItem data provided!');
        }

        if (!$this->requestModel->validateQuote($this->getRequest())) {
            return $this->_result(500, $this->requestModel->getErrorMessage());
        }

        $cartItem = $request->cartItem;
        /** @var Mage_Sales_Model_Quote $quoteObj */
        $quoteObj = $this->requestModel->currentQuote($this->getRequest());

        try {
            if (isset($cartItem->item_id) && isset($cartItem->qty)) { // update action
                $item = $this->cartModel->updateItem($quoteObj, $cartItem);

                return $this->_result(200, $item);
            }

            $product = $this->cartModel->getProduct($cartItem->sku);

            if (!$product) {
                return $this->_result(500, 'No product found with given SKU = ' . $cartItem->sku);
            }

            $item = $this->cartModel->addProductToCart($quoteObj, $cartItem);

            return $this->_result(200, $item);
        } catch (Exception $err) {
            return $this->_result(500, $err->getMessage());
        }
    }

    /**
     * Delete item from cart
     */
    public function deleteAction()
    {
        if (!$this->_checkHttpMethod('POST')) {
            return $this->_result(500, 'Only POST method allowed');
        }

        $request = $this->_getJsonBody();

        if (!$request) {
            return $this->_result(500, 'No JSON object found in the request body');
        }

        if ((!$request->cartItem)) {
            return $this->_result(500, 'No cartItem data provided!');
        }

        if (!$this->requestModel->validateQuote($this->getRequest())) {
            return $this->_result(500, $this->requestModel->getErrorMessage());
        }

        $cartItem = $request->cartItem;
        $quoteObj = $this->requestModel->currentQuote($this->getRequest());

        try {
            if ($cartItem->item_id) { // update action
                $quoteObj->removeItem($cartItem->item_id);
                $this->cartModel->saveQuote($quoteObj);

                return $this->_result(200, true);
            }
        } catch (Exception $err) {
            return $this->_result(500, $err->getMessage());
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
                $quote = $this->requestModel->currentQuote($this->getRequest());
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
                $quoteObj = $this->requestModel->currentQuote($this->getRequest());
                
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
