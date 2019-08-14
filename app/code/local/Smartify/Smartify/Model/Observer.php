<?php
class Smartify_Smartify_Model_Observer extends Mage_Core_Model_Abstract
{
  const SMARTIFY_EXTENSION_VERSION        = "1.0.0";
  const SMARTIFY_EXTENSION_STORE          = "magento";
  const SMARTIFY_EXTENSION_DOMAIN         = "https://api.getsmartify.com";
  const SMARTIFY_API_VERSION              = 1;
  const SMARTIFY_MAGENTO_PATH             = "/api/v1/retail/orders/";
  const SMARTIFY_OAUTH_TOKEN_PATH         = "/oauth/token";


  function domain_name(){
    if(Mage::getStoreConfig('smartify_options/setup/endpoint') == null){
      return "" . self::SMARTIFY_EXTENSION_DOMAIN;
    } else {
      return "" . Mage::getStoreConfig('smartify_options/setup/endpoint');
    }
  }

  function api_url($action_name){
    return "" . $this->domain_name() . self::SMARTIFY_MAGENTO_PATH . $action_name;
  }


  // Get the Oauth Access token
  function get_oauth_access_token(){
    $url = "" . $this->domain_name() . self::SMARTIFY_OAUTH_TOKEN_PATH;
    $access_key = Mage::getStoreConfig('smartify_options/setup/oauth_access_key');
    $secret_key = Mage::getStoreConfig('smartify_options/setup/oauth_secret');

    $data = array();
    $data['grant_type'] = "client_credentials";
    $data['client_id'] = $access_key;
    $data['client_secret'] = $secret_key;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $result = json_decode(curl_exec($ch));

    return $result->{'access_token'};
  }

  // Do a POST sending data as a json object
  function do_json_post_request($url, $data){
    $token = $this->get_oauth_access_token();
    $data_string = json_encode($data);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Content-Length: ' . strlen($data_string),
        'Authorization: Bearer ' . $token
    ));

    $result = curl_exec($ch);
    return $observer;
  }

  // will take a category id and output the category tree for the leaf category
  function getParentCategories($cat_id){
    $cat = Mage::getModel('catalog/category')->setStoreId(Mage::app()->getStore()->getId())->load($cat_id);
    $parent_cat = $cat->getParentCategory();
    if($parent_cat->getLevel() <= 1){
      return '' . $cat->getName();
    } else {
      $str = $this->getParentCategories($parent_cat->getId());
      return $str . '/' . $cat->getName();
    }
  }

  // data sent with a shopping cart object
  function quoteData($quote){
    $cart_data = array();
    $cart_data['id'] = $quote->getId();
    $cart_data['total_price_before_discounts'] = $quote->getBaseSubtotal();
    $cart_data['total_price_after_discounts'] = $quote->getBaseSubtotalWithDiscount();
    $cart_data['currency'] = $quote->getBaseCurrencyCode();
    $cart_data['is_active'] = $quote->getIsActive();
    $cart_items = array();
    foreach($quote->getAllItems() as $item) {
      $node = array();
      $prod = $item->getProduct();
      if($item->getParentItemId() == null){
        $node['name'] = $item->getName();
        $node['sku'] = $item->getSku();
        $node['description'] = trim( preg_replace( '/\s+/', ' ', strip_tags(Mage::getModel('catalog/product')->load($prod->getId())->getDescription()) ) );
        $node['qty'] = (int)$item->getQty();
        $categories = array();
        foreach ($prod->getCategoryIds() as $category_id) {
          $categories[] = $this->getParentCategories($category_id);
        }
        $node['categories'] = array_unique($categories);
        $node['unit_price'] = $item->getBasePrice();
        $node['total_price_before_discounts'] = null;
        $node['total_price_after_discounts'] = $item->getBaseRowTotal();
        $node['currency'] = $quote->getBaseCurrencyCode();

        $cart_items[] = $node;
      }
    }

    $cart_data['items'] = $cart_items;
    return $cart_data;
  }

  function orderData($order){
    $order_data = array();
    $order_data['id'] = $order->getId();
    $order_data['grand_total'] = $order->getBaseGrandTotal();
    $order_data['subtotal'] = $order->getBaseSubtotal();
    $order_data['shipping_cost'] = $order->getBaseShippingAmount();
    $order_data['tax_amount'] = $order->getBaseTaxAmount();
    $order_data['currency'] = $order->getBaseCurrencyCode();
    $order_data['cart_id'] = $order->getQuoteId();
    $order_data['status'] = $order->getStatus();
    $order_data['state'] = $order->getState();
    return $order_data;
  }

  function userData(){
    $user_data = array();
    $user_data['id'] = Mage::getSingleton('customer/session')->getCustomer()->getId();
    $user_data['checkout_session_id'] = Mage::getSingleton('checkout/session')->getSessionId();
    return $user_data;
  }

  function generalData(){
    $gen_data = array();
    $gen_data['smartify_config_id'] = Mage::getStoreConfig('smartify_options/setup/config_id');
    $gen_data['time'] = time();
    $gen_data['smartify_extension_version'] = self::SMARTIFY_EXTENSION_VERSION;
    $gen_data['smartify_extension_platform'] = self::SMARTIFY_EXTENSION_STORE;
    $gen_data['smartify_api_version'] = self::SMARTIFY_API_VERSION;
    return $gen_data;
  }

  // Callback for updating a cart
  public function updateQuote($observer){
    if(Mage::getStoreConfig('smartify_options/setup/enabled') == true){
      if (isset($observer['quote']) && ($observer['quote']->getIsActive() != false)) {
        $data = array();
        $data['general'] = $this->generalData();
        $data['user'] = $this->userData();
        $data['cart'] = $this->quoteData($observer['quote']);

        $url = $this->api_url("cart_changed");
        $this->do_json_post_request($url, $data);
      }
    }
    return $observer;
  }

  // Callback for updating an order
  public function updateOrder($observer){
    if(Mage::getStoreConfig('smartify_options/setup/enabled') == true){
      if (isset($observer['order'])) {
        $data = array();
        $quote = Mage::getModel('sales/quote')->load($observer['order']->getQuoteId());
        $data['general'] = $this->generalData();
        $data['user'] = $this->userData();
        $data['cart'] = $this->quoteData($quote);
        $data['order'] = $this->orderData($observer['order']);

        $url = $this->api_url("order_changed");
        $this->do_json_post_request($url, $data);
      }
    }
    return $observer;
  }



}
?>