<?php
require_once('AbstractController.php');
require_once(__DIR__.'/../helpers/JWT.php');

class Divante_VueStorefrontBridge_UserController extends Divante_VueStorefrontBridge_AbstractController
{
    /**
     * Login the customer and return API access token
     * https://github.com/DivanteLtd/magento1-vsbridge/blob/master/doc/VueStorefrontBridge%20API%20specs.md#post-vsbridgeuserlogin
     */
    public function loginAction()
    {
        $errMsg = $this->__("Invalid login or password.");

        if (!$this->_checkHttpMethod('POST')) {
            return $this->_result(500, 'Only POST method allowed');
        } else {
            try {
                $request = $this->_getJsonBody();

                if (!$request) {
                    return $this->_result(500, 'No JSON object found in the request body');
                } else {
                    if (!$request->username || !$request->password) {
                        return $this->_result(500, $errMsg);
                    } else {
                        $session = Mage::getSingleton( 'customer/session' );
                        $secretKey = trim(Mage::getConfig()->getNode('default/auth/secret'));

                        if($session->login($request->username, $request->password)) {
                            $user = $session->getCustomer();
                            if ($user->getId()) {
                                $refreshToken = JWT::encode($request, $secretKey, 'HS256');
                                return $this->_result(200, JWT::encode(array('id' => $user->getId()), $secretKey), array('refreshToken' => $refreshToken));
                            } else {
                                return $this->_result(500, $errMsg);
                            }
                        } else {
                            return $this->_result(500, $errMsg);
                        }


                    }
                }
            } catch (Exception $err) {
                return $this->_result(500, $errMsg);
            }
        }
    }

    /**
     * Send password reset link
     * https://github.com/DivanteLtd/magento1-vsbridge/blob/master/doc/VueStorefrontBridge%20API%20specs.md#post-vsbridgeuserresetpassword
     */
    public function resetPasswordAction() {
        if (!$this->_checkHttpMethod('POST')) {
            return $this->_result(500, 'Only POST method allowed');
        } else {
            $request = $this->_getJsonBody();
            if(!$request || !$request->email) {
                return $this->_result(500, 'No e-mail provided');
            } else {
                try {
                    $customer = Mage::getModel('customer/customer')
                    ->setWebsiteId(Mage::app()->getStore()->getWebsiteId())
                    ->loadByEmail($request->email);
	            if ($customer){
                        $customer->sendPasswordResetConfirmationEmail();
                        return $this->_result(200, "Reset password email sent.");         
                    } else {
                        return $this->_result(500, 'Wrong e-mail provided');
                    }
                } catch (Exception $err) {
                    return $this->_result(500, $err->getMessage());
                }
            }
        }
        return $this->_result(200, []);
    }

    /**
     * Change user password
     * https://github.com/DivanteLtd/magento1-vsbridge/blob/master/doc/VueStorefrontBridge%20API%20specs.md#post-vsbridgeuserchangepassword
     */
    public function changePasswordAction() {
        if (!$this->_checkHttpMethod('POST')) {
            return $this->_result(500, 'Only POST method allowed');
        } else {
            $request = $this->_getJsonBody();
            if(!$request || !$request->currentPassword || !$request->newPassword) {
                return $this->_result(500, 'No current and new passwords provided!');
            } else {
                $customer = $this->_currentCustomer($this->getRequest());
                if(!$customer) {
                    return $this->_result(500, 'No customer found with the specified token');
                } else {
                    try {
                        $customer->setWebsiteId(Mage::app()->getStore()->getWebsiteId())->authenticate($customer->getEmail(), $request->currentPassword);
                        $customer->setPassword($request->newPassword);
                        $customer->save();
                        return $this->_result(200, 'New password set for the customer');
                    } catch (Exception $err) {
                        return $this->_result(500, 'Current password does not match the customer');
                    }
                }
            }
        }
        return $this->_result(200, []);
    }



    /**
     * Refresh the user token
     */
    public function refreshAction() {
        try {
            if (!$this->_checkHttpMethod('POST')) {
                return $this->_result(500, 'Only POST method allowed');
            } else {
                $request = $this->_getJsonBody();
                if(!$request || !$request->refreshToken) {
                    return $this->_result(500, 'No request token provided');
                } else  {
                    $secretKey = trim(Mage::getConfig()->getNode('default/auth/secret'));
                    $loginRequest = JWT::decode($request->refreshToken, $secretKey, 'HS256');
                    if(!$loginRequest || !$loginRequest->username || !$loginRequest->password) {
                        return $this->_result(500, 'Invalid token or no username password pair');
                    } else {
                        $session = Mage::getSingleton( 'customer/session' );

                        if($session->login($loginRequest->username, $loginRequest->password)) {
                            $user = $session->getCustomer();
                            if ($user->getId()) {
                                $refreshToken = JWT::encode($loginRequest, $secretKey);
                                return $this->_result(200, JWT::encode(array('id' => $user->getId()), $secretKey), array('refreshToken' => $refreshToken));
                            } else {
                                return $this->_result(500, 'You did not sign in correctly or your account is temporarily disabled.');
                            }
                        } else {
                            return $this->_result(500, 'You did not sign in correctly or your account is temporarily disabled.');
                        }
                    }                
                }
            }
        } catch (Exception $err) {
            return $this->_result(500, $err->getMessage());
        }
    }

    public function orderHistoryAction() {
        if (!$this->_checkHttpMethod('GET')) {
            return $this->_result(500, 'Only GET method allowed');
        } else {
            $customer = $this->_currentCustomer($this->getRequest());

            if ($customer) {
                $request = $this->getRequest();
                $page = max(abs(intval($request->getParam('page'))), 1);
                $pageSize = min(abs(intval($request->getParam('pageSize'))), 50);
                
                $collection = Mage::getModel("sales/order")->getCollection()
                            ->addAttributeToSelect('*')
                            ->addFieldToFilter('customer_id', $customer->getId())
                            ->setPageSize($pageSize)->setCurPage($page);
                
                $ordersDTO = array();
                foreach ($collection as $order) {
                    $orderDTO = $order->getData();
                    $orderDTO['id'] = $orderDTO['entity_id'];
                    $orderDTO['items'] = array();

                    foreach($order->getAllItems() as $item) {
                        $itemDTO = $item->getData();
                        $itemDTO['id'] = $itemDTO['item_id'];
                        $orderDTO['items'][] = $itemDTO;
                    }
                    $methods = Mage::getSingleton('shipping/config')->getActiveCarriers();
                    $_title = $this->__("No shipping information available.");

                    foreach($methods as $_code => $_method) {
                        if(strpos($order->getShippingMethod(), $_code) !== false ) {
                             $_title = Mage::getStoreConfig("carriers/$_code/title");
                         }
                    }
                    $orderDTO['shipping_description'] = $_title;
                    $orderDTO['status'] = $order->getStatusLabel();
                    $orderDTO['payment']['additional_information'][] = $order->getPayment()->getMethodInstance()->getTitle();
                    $orderDTO['extension_attributes']['shipping_assignments'][]['shipping']['address'] = $order->getShippingAddress()->toArray();
                    $orderDTO['billing_address'] = $order->getBillingAddress()->toArray();
                    $ordersDTO[] = $orderDTO;
                }
                //Mage::log($ordersDTO);
                return $this->_result(200, array('items' => $ordersDTO));
            } else {
                return $this->_result(500, 'User is not authroized to access self');                
            }

        }
    }

    /**
     * Register the customer
     * https://github.com/DivanteLtd/magento1-vsbridge/blob/master/doc/VueStorefrontBridge%20API%20specs.md#post-vsbridgeusercreate
     */
    public function createAction()
    {
        if (!$this->_checkHttpMethod('POST')) {
            return $this->_result(500, 'Only POST method allowed');
        } else {

            $request = $this->_getJsonBody();

            if (!$request) {
                return $this->_result(500, 'No JSON object found in the request body');
            } else {
                if ((!$request->customer || !$request->customer->email) || !$request->password) {
                    return $this->_result(500, 'No customer data or password provided!');
                } else {


                    $websiteId = Mage::app()->getWebsite()->getId();
                    $store = Mage::app()->getStore();

                    $customer = Mage::getModel("customer/customer");
                    $customer->setWebsiteId($websiteId)
                        ->setStore($store)
                        ->setFirstname($request->customer->firstname)
                        ->setLastname($request->customer->lastname)
                        ->setEmail($request->customer->email)
                        ->setPassword($request->password);

                    try{
                        $customer->save();
                        $filteredCustomerData = $this->_filterDTO($customer->getData(), array('password', 'password_hash', 'password_confirmation', 'confirmation', 'entity_type_id'));
                        return $this->_result(200, $filteredCustomerData); // TODO: add support for 'Refresh-token'
                    }
                    catch (Exception $e) {
                        return $this->_result(500, Mage::helper('customer')->__($e->getMessage()));
                    }
                }
            }

        }
    }


    public function meAction(){
        $customer = $this->_currentCustomer($this->getRequest());
        if(!$customer) {
            return $this->_result(500, 'User is not authroized to access self');
        } else { 
            try {
                if ($this->_checkHttpMethod(array('POST'))) { // modify user data
                    $request = _object_to_array($this->_getJsonBody());
                    if(!$request['customer']) {
                        return $this->_result(500, 'No customer data provided!');
                    }

                    //die(print_r($customer->getData(), true));
                    $updatedCustomer = $request['customer'];
                    $updatedCustomer['entity_id'] = $customer->getId();
                    
                    $customer->setData('firstname', $updatedCustomer['firstname'])
                            ->setData('lastname', $updatedCustomer['lastname'])
                            ->setData('email', $updatedCustomer['email'])
                            ->save();    

                    $updatedShippingId = 0;
                    $updatedBillingId = 0; 
                    if ($updatedCustomer['addresses']) {
                        foreach($updatedCustomer['addresses'] as $updatedAdress) {
                            $updatedAdress['region'] = $updatedAdress['region']['region'];

                            if($updatedAdress['default_billing']) {
                                $bAddressId = $customer->getDefaultBilling();
                                $bAddress = Mage::getModel('customer/address');
                                
                                if($bAddressId) 
                                    $bAddress->load($bAddressId);

                                $updatedAdress['parent_id'] = $customer->getId();


                                $bAddress->setData($updatedAdress)->setIsDefaultBilling(1)->save();
                                $updatedBillingId = $bAddress->getId();
                            }
                            if($updatedAdress['default_shipping']) {
                                $sAddressId = $customer->getDefaultShipping();
                                $sAddress = Mage::getModel('customer/address');
                                
                                if($sAddressId) 
                                    $sAddress->load($sAddressId);

                                $updatedAdress['parent_id'] = $customer->getId();           
                                $sAddress->setData($updatedAdress)->setIsDefaultShipping(1)->save();
                                $updatedShippingId = $sAddress->getId();
                            }                        
                        }
                    }
                }
                $customer->load($customer->getId());

                $customerDTO = $customer->getData();
                $customerDTO['id'] = (int)$customer->getId();
                $customerDTO['website_id'] = (int)$customer->getWebsiteId();
               
                $allAddress = $customer->getAddresses();
                $defaultBilling  = $customer->getDefaultBilling();
                $defaultShipping = $customer->getDefaultShipping();
                $customerDTO['addresses'] = array();

                foreach ($allAddress as $address) {
                    $addressDTO = $address->getData();
                    
                    $addressDTO['id'] = (int)$addressDTO['entity_id'];
                    $addressDTO['region'] = array('region' => $addressDTO['region']);
                    $streetDTO = explode("\n", $addressDTO['street']);
                    if(count($streetDTO) < 2)
                        $streetDTO[]='';

                    $addressDTO['street'] = $streetDTO;
                    if(!$addressDTO['firstname'])
                        $addressDTO['firstname'] = $customerDTO['firstname'];
                    if(!$addressDTO['lastname'])
                        $addressDTO['lastname'] = $customerDTO['lastname'];
                    if(!$addressDTO['city'])
                        $addressDTO['city'] = '';
                    if(!$addressDTO['country_id'])
                        $addressDTO['country_id'] = 'US';                        
                    if(!$addressDTO['postcode'])
                        $addressDTO['postcode'] = '';          
                    if(!$addressDTO['telephone'])
                        $addressDTO['telephone'] = '';                                
                    //die(print_r($addressDTO, true));
                    $addressDTO['postcode'] = (string)$addressDTO['postcode'];
                    $addressDTO['telephone'] = (string)$addressDTO['telephone'];

                    if($defaultBilling == $address->getId() || $address->getId() == $updatedBillingId) {
                        // TODO: Street + Region fields (region_code should be)

                        // its customer default billing address
                        $addressDTO['default_billing'] = true;
                        $customerDTO['default_billing'] = $address->getId();
                        $customerDTO['addresses'][] = $addressDTO;
                    } else if($defaultShipping == $address->getId()|| $address->getId() == $updatedShippingId) {
                        // its customer default shipping address
                        $addressDTO['default_shipping'] = true;
                        $customerDTO['default_shipping'] = $address->getId();
                        $customerDTO['addresses'][] = $addressDTO;
                    }
                    $customerDTO['id'] = (int)$customerDTO['entity_id'];
                }
                
                $filteredCustomerData = $this->_filterDTO($customerDTO, array('password', 'password_hash', 'password_confirmation', 'confirmation', 'entity_type_id'));
                return $this->_result(200, $filteredCustomerData, null, false);
            } catch (Exception $err) {
                return $this->_result(500, $err->getMessage());
            }
        }
    }
}
?>
