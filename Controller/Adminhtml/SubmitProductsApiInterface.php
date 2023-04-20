<?php
namespace Bloomreach\Feed\Controller\Adminhtml;

interface SubmitProductsApiInterface
{
  /**
   * Store config path public constants for settings under GENERAL
   */
  public const SETTINGS_GENERAL_PATH = 'br_feed_section/general';
  public const SETTINGS_ACC_ID = self::SETTINGS_GENERAL_PATH . '/accountid';
  public const SETTINGS_AUTH_KEY = self::SETTINGS_GENERAL_PATH . '/auth_key';
  public const SETTINGS_CATALOG_KEY = self::SETTINGS_GENERAL_PATH . '/catalog_key';

  /**
   * Store config path public constants for settings under API URL
   */
  public const SETTINGS_APIURL_PATH = 'br_feed_section/api_url';
  public const SETTINGS_CATALOG_ENVIRONMENT = self::SETTINGS_APIURL_PATH . '/catalog_environment';

}
