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
                        $request = $this->_getJsonBody();

                        $paymentMethod = $request->paymentMethod->method;
                        $paymentAdditionalData = property_exists($request->paymentMethod, "additional_data") ? 
                            $request->paymentMethod->additional_data : null;
						
						$quoteObj->getPayment()->importData(
                            array(
                                'method' => $paymentMethod,
                                'additional_data' => $paymentAdditionalData
                            )
                        );
                        if(!$customer) {
                            $quoteObj->setCustomerIsGuest(true);
                        }

                        $quoteObj->collectTotals()->save();
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
            return $this->_result(500, $err->getMessage());
        }
    }
}
?>
