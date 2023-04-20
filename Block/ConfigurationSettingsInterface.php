<?php
/**
 * Bloomreach Connector extension
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Bloomreach Proprietary License
 * that is bundled with this package in the file LICENSE.txt.
 *
 * @category       Bloomreach
 * @package        Connector
 * @copyright      Copyright (c) 2021-current Bloomreach Inc.
 */
namespace Bloomreach\Feed\Block;

/**
 * Interface ConfigurationSettingsInterface
 * package Bloomreach\Feed\Block
 */
interface ConfigurationSettingsInterface
{
    /**
     * Store config path public constants for settings tab
     */
    public const SETTINGS_GENERAL_PATH = 'bloomreach_settings/general';
    public const SETTINGS_CURRENCY_SYMBOL = self::SETTINGS_GENERAL_PATH . '/currency_symbol';
    public const SETTINGS_ACC_ID = self::SETTINGS_GENERAL_PATH . '/accountid';
    public const SETTINGS_AUTH_KEY = self::SETTINGS_GENERAL_PATH . '/auth_key';
    public const SETTINGS_DOMAIN_KEY = self::SETTINGS_GENERAL_PATH . '/domain_key';
    public const SETTINGS_TRACKING_COOKIE = self::SETTINGS_GENERAL_PATH . '/tracking_cookie';
    public const SETTINGS_SEARCH_ENDPOINT = self::SETTINGS_GENERAL_PATH . '/search_endpoint';
    public const SETTINGS_AUTOSUGGEST_ENDPOINT = self::SETTINGS_GENERAL_PATH . '/autosuggest_endpoint';

    /**
     * Store config path public constants for settings endpoint tab
     */
    public const SETTINGS_APIURL_PATH = 'bloomreach_settings/api_url';
    public const SETTINGS_ENDPOINT_ENVIRONMENT = self::SETTINGS_ENVIRONMENT_PATH . '/environment';

    public const STAGING_API_ENDPOINT = 'https://pathways-staging.dxpapi.com/api/v2/widgets/';
    public const PRODUCTION_API_ENDPOINT = 'https://pathways.dxpapi.com/api/v2/widgets/';
}
