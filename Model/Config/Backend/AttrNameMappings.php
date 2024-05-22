<?php

namespace Bloomreach\Feed\Model\Config\Backend;

/**
 * Backend for serialized Attribute Name Mappings data
 */
class AttrNameMappings extends \Magento\Framework\App\Config\Value
{
  /**
   * @var \Bloomreach\Feed\Helper\AttrNameMappings
   */
  protected $attrNameMappingsHelper = null;

  /**
   * @param \Magento\Framework\Model\Context $context
   * @param \Magento\Framework\Registry $registry
   * @param \Magento\Framework\App\Config\ScopeConfigInterface $config
   * @param \Magento\Framework\App\Cache\TypeListInterface $cacheTypeList
   * @param \Bloomreach\Feed\Helper\AttrNameMappings $attrNameMappingsHelper
   * @param \Magento\Framework\Model\ResourceModel\AbstractResource|null $resource
   * @param \Magento\Framework\Data\Collection\AbstractDb|null $resourceCollection
   * @param array $data
   */
  public function __construct(
    \Magento\Framework\Model\Context $context,
    \Magento\Framework\Registry $registry,
    \Magento\Framework\App\Config\ScopeConfigInterface $config,
    \Magento\Framework\App\Cache\TypeListInterface $cacheTypeList,
    \Bloomreach\Feed\Helper\AttrNameMappings $attrNameMappingsHelper,
    \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
    \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
    array $data = []
  ) {
    parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);

    $this->attrNameMappingsHelper = $attrNameMappingsHelper;
  }

  /**
   * Process data after load
   *
   * @return void
   */
  protected function _afterLoad()
  {
    $value = $this->getValue();
    $value = $this->attrNameMappingsHelper->makeArrayFieldValue($value);
    $this->setValue($value);
  }

  /**
   * Prepare data before save
   *
   * @return void
   */
  public function beforeSave()
  {
    $value = $this->getValue();
    $value = $this->attrNameMappingsHelper->makeStorableArrayFieldValue($value);
    $this->setValue($value);
  }
}
