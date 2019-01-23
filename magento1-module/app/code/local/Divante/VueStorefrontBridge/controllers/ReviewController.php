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
 * © 2019 QBO DIGITAL SOLUTIONS. 
 *
 */
require_once('AbstractController.php');

class Divante_VueStorefrontBridge_ReviewController extends Divante_VueStorefrontBridge_AbstractController
{
    /**
    * Save reviews posted from VSF
    */
    public function postAction()
    {
        $result = array();
        if($this->getRequest()->getMethod() == 'POST' ||  $this->getRequest()->getMethod() == 'OPTIONS') {

          $request = json_decode(json_encode($this->_getJsonBody()), true);
       
          if (!empty($request) && isset($request['review'])) {
            $data = $request['review'];
            /* @var $session Mage_Core_Model_Session */
            $review = Mage::getModel('review/review')->setData($data);
            /* @var $review Mage_Review_Model_Review */
            $validate = $review->validate();
            if ($validate === true) {
                try {
                    $productId = (int)$data['product_id'];
                    if(!empty($data['customer_id'])) {
                        $review->setCustomerId((int)$data['customer_id']);
                    }            
                    $review->setEntityId(Mage_Review_Model_Review::ENTITY_PRODUCT)
                        ->setEntityPkValue($productId)
                        ->setStatusId(Mage_Review_Model_Review::STATUS_PENDING)
                        ->setStoreId(Mage::app()->getStore()->getId())
                        ->setStores(array(Mage::app()->getStore()->getId()))
                        ->save();

                    $review->aggregate();
                    $result = array(
                        'id' => $review->getId(),
                        'review_status' => $review->getStatus()
                    );
                } catch (Exception $e) {
                    Mage::logException($e->getMessage());
                    return $this->_result(500, $this->__('Unable to post review. Please, try again later.'));
                }
            } else {
              return $this->_result(500, $this->__('Invalid form data.'));
            }
          }
        } else {
            return $this->_result(500, 'Only POST method allowed');
        }
        return $this->_result(200, $result);
    }
}
?>
