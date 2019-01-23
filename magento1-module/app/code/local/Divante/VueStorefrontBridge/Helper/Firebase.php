<?php

class Divante_VueStorefrontBridge_Helper_Firebase extends Mage_Core_Helper_Abstract
{
    const CONTENT_TYPE = 'application/json';

    /**
     * Send push notification by token device
     */
    public function sendPush($tokenQuote, $title, $body)
    {
        $headers = array(
            'Authorization' => "key=" . Mage::getStoreConfig('vsbridge/firebase/key_access_server'),
            'Content-type' => self::CONTENT_TYPE
        );
        
        $params = array(
            "data" => array(
                "message" => array(
                    "title" => $title,
                    "body" => $body
                )
            ),
            "content_available" => true,
            "to" => $tokenQuote
        );

        $client = new Zend_Http_Client(Mage::getStoreConfig('vsbridge/firebase/url_api_firebase'));
        $client->setHeaders($headers);
        $client->setRawData(Zend_Json::encode($params), 'application/json');
        $response = $client->request(Zend_Http_Client::POST);
        $responseBody = Zend_Json::decode($response->getBody());

        return $responseBody;
    }
}