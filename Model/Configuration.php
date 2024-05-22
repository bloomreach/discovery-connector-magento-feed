<?php

namespace Bloomreach\Feed\Model;

use Bloomreach\Feed\Helper\AttrNameMappings;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class Configuration
{
  /**
   * Store config path public constants for settings under GENERAL
   */
  const SETTINGS_GENERAL_PATH = 'bloomreach_feed_settings/general';
  const SETTINGS_ACC_ID = self::SETTINGS_GENERAL_PATH . '/accountid';
  const SETTINGS_API_KEY = self::SETTINGS_GENERAL_PATH . '/api_key';
  const SETTINGS_CATALOG_KEY = self::SETTINGS_GENERAL_PATH . '/catalog_key';

  /**
   * Store config path public constants for settings under API URL
   */
  const SETTINGS_APIURL_PATH = 'bloomreach_feed_settings/api_url';
  const SETTINGS_CATALOG_ENVIRONMENT = self::SETTINGS_APIURL_PATH . '/catalog_environment';

  /**
   * Store config path public constants for settings under Attribute Transformation Configs
   */
  const SETTINGS_ATTR_CONFIGS_PATH = 'bloomreach_feed_settings/attribute_configs';
  const SETTINGS_NAME_MAPPINGS_PATH = self::SETTINGS_ATTR_CONFIGS_PATH . '/name_mappings';
  const SETTINGS_INDEX_ATTRS_PATH = self::SETTINGS_ATTR_CONFIGS_PATH . '/index_value_attributes';
  const SETTINGS_VAR_ONLY_PATH = self::SETTINGS_ATTR_CONFIGS_PATH . '/variant_only_attributes';
  const SETTINGS_SKIP_ATTRS_PATH = self::SETTINGS_ATTR_CONFIGS_PATH . '/skip_attributes';
  /**
   * @var ScopeConfigInterface
   */
  private $scopeConfig;

  /**
   * @var StoreManagerInterface
   */
  private $storeManager;

  /**
   * @var AttrNameMappings
   */
  private $attrNameMappingsHelper;

  public function __construct(
    ScopeConfigInterface $scopeConfig,
    AttrNameMappings $attrNameMappingsHelper,
    StoreManagerInterface $storeManager
  ) {
    $this->scopeConfig = $scopeConfig;
    $this->attrNameMappingsHelper = $attrNameMappingsHelper;
    $this->storeManager = $storeManager;
  }

  /**
   * Returning store config value, check all potential scope ranges the values can be set
   *
   * @param string $path
   * @return mixed
   **/
  public function getStoreConfigValue($path)
  {
    // Check all potential scope ranges the values can be set
    $scope = ScopeInterface::SCOPE_STORE;
    $scopeId = $this->storeManager->getStore()->getId();

    // Check if the value is set at the store level
    $value = $this->scopeConfig->getValue($path, $scope, $scopeId);

    // If the value is not set at the store level, check the website level
    if (!$value) {
      $scope = ScopeInterface::SCOPE_WEBSITES;
      $scopeId = $this->storeManager->getStore()->getWebsiteId();
      $value = $this->scopeConfig->getValue($path, $scope, $scopeId);
    }

    // If the value is not set at the website level, check the default level
    if (!$value) {
      $value = $this->scopeConfig->getValue($path);
    }

    return $value;
  }

  /**
   * Returning attribute name mappings config
   *
   * @return array
   **/
  public function getAttributeNameMappings() {
    $value = $this->getStoreConfigValue(self::SETTINGS_NAME_MAPPINGS_PATH);
    $value = $this->attrNameMappingsHelper->getConfigValue($value);
    foreach ($value as $srcAttr => $tgtAttrs) {
      $value[$srcAttr] = preg_split('/\s*,\s*/', $tgtAttrs, -1, PREG_SPLIT_NO_EMPTY);
    }
    return $value;
  }

  /**
   * Returning attributes transformation config value as an array, separating by comma
   *
   * @param string $path
   * @return string[]
   */
  public function getAttributesConfig($path) {
    $value = $this->getStoreConfigValue($path);
    return preg_split('/\s*,\s*/', $value, -1, PREG_SPLIT_NO_EMPTY);
  }
}
