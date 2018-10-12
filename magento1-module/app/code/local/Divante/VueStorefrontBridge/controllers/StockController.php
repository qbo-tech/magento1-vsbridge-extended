<?php
require_once('AbstractController.php');
require_once(__DIR__.'/../helpers/JWT.php');
class Divante_VueStorefrontBridge_StockController extends Divante_VueStorefrontBridge_AbstractController
{

   

    public function checkAction()
    {
        $params = $this->getRequest()->getParams();
        if (!$this->_checkHttpMethod('GET')) {
            return $this->_result(500, 'Only GET method allowed');
        } else {
            $sku = @array_keys($params)[0];
            if(!$sku) {
                return $this->_result(500, 'No SKU provided');
            } else {

                try {
                    $product_id = Mage::getModel('catalog/product')->getIdBySku($sku);
                    $product = Mage::getModel('catalog/product')->load($product_id);
                    $stock = $product->getStockItem();
                    $stockDTO = $stock->getData();
                    $stockDTO['qty'] = 1; // TODO FIX ME
                    $stockDTO['is_in_stock'] = true;
                    return $this->_result(200, $stockDTO);
                    
                } catch (Exception $err) {
                    return $this->_result(500, $err->getMessage());
                }
            }
        }
    }

}
?>