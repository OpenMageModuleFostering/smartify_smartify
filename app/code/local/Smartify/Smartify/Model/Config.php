<?php
class Smartify_Smartify_Model_Config extends Mage_Core_Model_Abstract
{
  public function toOptionArray()
  {
      return array(
          array('value' => "https://api.getsmartifystaging.com", 'label' => 'Staging'),
          array('value' => "http://api.getsmartifydev.com:3000", 'label' => 'Development'),
          array('value' => "https://api.getsmartify.com", 'label' => 'Production')
      );
  }


}
