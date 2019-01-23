<?php
/**
 * MMDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDMMM
 * MDDDDDDDDDDDDDNNDDDDDDDDDDDDDDDDD=.DDDDDDDDDDDDDDDDDDDDDDDMM
 * MDDDDDDDDDDDD===8NDDDDDDDDDDDDDDD=.NDDDDDDDDDDDDDDDDDDDDDDMM
 * DDDDDDDDDN===+N====NDDDDDDDDDDDDD=.DDDDDDDDDDDDDDDDDDDDDDDDM
 * DDDDDDD$DN=8DDDDDD=~~~DDDDDDDDDND=.NDDDDDNDNDDDDDDDDDDDDDDDM
 * DDDDDDD+===NDDDDDDDDN~~N........8$........D ........DDDDDDDM
 * DDDDDDD+=D+===NDDDDDN~~N.?DDDDDDDDDDDDDD:.D .DDDDD .DDDDDDDN
 * DDDDDDD++DDDN===DDDDD~~N.?DDDDDDDDDDDDDD:.D .DDDDD .DDDDDDDD
 * DDDDDDD++DDDDD==DDDDN~~N.?DDDDDDDDDDDDDD:.D .DDDDD .DDDDDDDN
 * DDDDDDD++DDDDD==DDDDD~~N.... ...8$........D ........DDDDDDDM
 * DDDDDDD$===8DD==DD~~~~DDDDDDDDN.IDDDDDDDDDDDNDDDDDDNDDDDDDDM
 * NDDDDDDDDD===D====~NDDDDDD?DNNN.IDNODDDDDDDDN?DNNDDDDDDDDDDM
 * MDDDDDDDDDDDDD==8DDDDDDDDDDDDDN.IDDDNDDDDDDDDNDDNDDDDDDDDDMM
 * MDDDDDDDDDDDDDDDDDDDDDDDDDDDDDN.IDDDDDDDDDDDDDDDDDDDDDDDDDMM
 * MMDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDMMM
 *
 * @author José Castañeda <jose@qbo.tech>
 * @category Divante
 * @package Divante/VueStorefrontBridge
 * @copyright QBO Digital (http://www.qbo.tech)
 * 
 * © 2018 QBO DIGITAL SOLUTIONS. 
 *
 */
require_once('AbstractController.php');
require_once(__DIR__.'/../helpers/JWT.php');
/**
 * Order Place Controller for VSF
 */
class Divante_VueStorefrontBridge_OrderController extends Divante_VueStorefrontBridge_AbstractController
{
    const EXCEPTION_LOG_FILE = "vsf_exception.log";
    const DEBUG_LOG_FILE = "vsf_debug.log";
    const EMAIL_CUSTOMER_TEMPLATE = "vsbridge/general/customer_email_template";
    const EMAIL_ADMIN_TEMPLATE = "vsbridge/general/error_admin_email_template";
    const EMAIL_ADMIN_RECIPIENT = "vsbridge/general/error_admin_email_recipient";
    const CONST_ADMIN_NAME = "Admin";

    /**
     * Create Order from Shopping Cart 
     * @throws Mage_Core_Exception
     */
    public function createAction()
    {
        try {
            if ($this->getRequest()->getMethod() !== 'POST') {
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
                        if(!$quoteObj->getReservedOrderId()) {
                            $quoteObj->reserveOrderId()->save();
                        }

                        $orderId = $quoteObj->getReservedOrderId();
                        Mage::log(sprintf("[VSF] Processing Quote %s", $orderId), null, self::DEBUG_LOG_FILE);

                        $request = $this->_getJsonBody();

                        $paymentMethod = $request->paymentMethod->method;

                        $paymentAdditionalData = null;
                        if(property_exists($request->paymentMethod, "additional_data") && count((array)$request->paymentMethod->additional_data) > 0) {
                           $paymentAdditionalData = json_decode(json_encode($request->paymentMethod->additional_data));
                        }
		        $quoteObj->getPayment()->importData(
                            array(
                                'method' => $paymentMethod,
                                'additional_data' => $paymentAdditionalData
                            )
                        );
                        if(!$customer) {
                            $quoteObj->setCustomerIsGuest(true);
                        }
                        Mage::dispatchEvent('vsf_submit_order_before',
                            array(
                                'quote' => $quoteObj
                            )
                        );

                        //$quoteObj->collectTotals()->save();
			$service = Mage::getModel('sales/service_quote', $quoteObj);
                        $service->submitAll();
                        $order = $service->getOrder();
                        $order->sendNewOrderEmail();

                        Mage::dispatchEvent('checkout_submit_all_after', 
                            array(
                                'order' => $order, 
                                'quote' => $quoteObj
                            )
                        );

                        return $this->_result(200,
                            array(
                                "success" => true,
                                "orderId" => $order->getRealOrderId(),
                                "totals" => $order->getGrandTotal()
                            )
		        );
                    }
	        }
            }
        } catch (Exception $err) {
            Mage::logException($err);
            $this->_reportError($quoteObj, $orderId, $err);
            return $this->_result(500, $err->getMessage());
        }
    }
    /**
    * Report Order Error
    *
    * @param Mage_Sales_Model_Order_Quote
    * @param string
    * @param Exception
    */
    protected function _reportError($quoteObj, $orderId, $err)
    {
        Mage::log(
            sprintf("[VSF] Error placing order: %s, %s", $orderId, $err->getMessage()), 
            null, 
            self::EXCEPTION_LOG_FILE
        );
       
        $templateId =  Mage::getStoreConfig(self::EMAIL_CUSTOMER_TEMPLATE);
        $senderName =  Mage::getStoreConfig('trans_email/ident_support/name');
        $senderEmail = Mage::getStoreConfig('trans_email/ident_support/email');

        $sender = array(
            'name' => $senderName,
            'email' => $senderEmail
        );
        // Get Store ID     
        $storeId = Mage::app()->getStore()->getId(); 

        // Set recepient information
        $recepientEmail = $quoteObj->getCustomerEmail();
        $recepientName = $quoteObj->getCustomerName() ? : ""; 
    
        Mage::getModel('core/email_template')->sendTransactional(
            $templateId, 
            $sender,
            $recepientEmail,
            $recepientName, 
            array(),
            $storeId
        );
        // Report error to Store Admin
        $vars = array('reason' => $err->getMessage());

        $_adminTemplateId = Mage::getStoreConfig(self::EMAIL_ADMIN_TEMPLATE);
        $senderEmail = Mage::getStoreConfig('trans_email/ident_support/email');
        $recipientEmail = Mage::getStoreConfig(self::EMAIL_ADMIN_RECIPIENT);

        Mage::getModel('core/email_template')->sendTransactional(
            $_adminTemplateId,
            $sender,
            $recepientEmail,
            self::CONST_ADMIN_NAME,
            $vars,
            $storeId
        );
    }
}
?>
