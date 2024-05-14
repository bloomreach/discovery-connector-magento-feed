<?php

namespace Bloomreach\Feed\Helper;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\Store;

/**
 * AttrNameMappings value manipulation helper
 */
class AttrNameMappings
{
  const SOURCE_FIELD_NAME = 'source_attribute';
  const TARGET_FIELD_NAME = 'target_attributes';

  /**
   * Core store config
   *
   * @var \Magento\Framework\App\Config\ScopeConfigInterface
   */
  protected $scopeConfig;

  /**
   * @var \Magento\Framework\Math\Random
   */
  protected $mathRandom;

  /**
   * @var Json
   */
  private $serializer;

  public function __construct(
    \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
    \Magento\Framework\Math\Random $mathRandom,
    Json $serializer = null
  ) {
    $this->scopeConfig = $scopeConfig;
    $this->mathRandom = $mathRandom;
    $this->serializer = $serializer ?: ObjectManager::getInstance()->get(Json::class);
  }

  /**
   * Generate a storable representation of a value
   *
   * @param int|float|string|array $value
   * @return string
   */
  protected function serializeValue($value)
  {
    if (is_array($value)) {
      $data = [];
      foreach ($value as $srcAttr => $tgtAttrs) {
        if (!array_key_exists($srcAttr, $data)) {
          $data[$srcAttr] = $tgtAttrs;
        }
      }
      return $this->serializer->serialize($data);
    } else {
      return $value;
    }
  }

  /**
   * Create a value from a storable representation
   *
   * @param string $value
   * @return array
   */
  protected function unserializeValue($value)
  {
    if (is_string($value) && !empty($value)) {
      return $this->serializer->unserialize($value);
    } else {
      return [];
    }
  }

  /**
   * Check whether value is in form retrieved by _encodeArrayFieldValue()
   *
   * @param string|array $value
   * @return bool
   */
  protected function isEncodedArrayFieldValue($value)
  {
    if (!is_array($value)) {
      return false;
    }
    unset($value['__empty']);
    foreach ($value as $row) {
      if (!is_array($row)
        || !array_key_exists(self::SOURCE_FIELD_NAME, $row)
        || !array_key_exists(self::TARGET_FIELD_NAME, $row)
      ) {
        return false;
      }
    }
    return true;
  }

  /**
   * Encode value to be used in \Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray
   *
   * @param array $value
   * @return array
   */
  protected function encodeArrayFieldValue(array $value)
  {
    $result = [];
    foreach ($value as $srcAttr => $tgtAttrs) {
      $resultId = $this->mathRandom->getUniqueHash('_');
      $result[$resultId] = [self::SOURCE_FIELD_NAME => $srcAttr, self::TARGET_FIELD_NAME => $tgtAttrs];
    }
    return $result;
  }

  /**
   * Decode value from used in \Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray
   *
   * @param array $value
   * @return array
   */
  protected function decodeArrayFieldValue(array $value)
  {
    $result = [];
    unset($value['__empty']);
    foreach ($value as $row) {
      if (!is_array($row)
        || !array_key_exists(self::SOURCE_FIELD_NAME, $row)
        || !array_key_exists(self::TARGET_FIELD_NAME, $row)
      ) {
        continue;
      }
      $srcAttr = $row[self::SOURCE_FIELD_NAME];
      $tgtAttrs = $row[self::TARGET_FIELD_NAME];
      $result[$srcAttr] = $tgtAttrs;
    }
    return $result;
  }

  /**
   * Retrieve name_mappings value from config
   *
   * @param string $value
   * @return array
   */
  public function getConfigValue($value)
  {
    $value = $this->unserializeValue($value);
    if ($this->isEncodedArrayFieldValue($value)) {
      $value = $this->decodeArrayFieldValue($value);
    }

    return $value;
  }

  /**
   * Make value readable by \Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray
   *
   * @param string|array $value
   * @return array
   */
  public function makeArrayFieldValue($value)
  {
    $value = $this->unserializeValue($value);
    if (!$this->isEncodedArrayFieldValue($value)) {
      $value = $this->encodeArrayFieldValue($value);
    }
    return $value;
  }

  /**
   * Make value ready for store
   *
   * @param string|array $value
   * @return string
   */
  public function makeStorableArrayFieldValue($value)
  {
    if ($this->isEncodedArrayFieldValue($value)) {
      $value = $this->decodeArrayFieldValue($value);
    }
    $value = $this->serializeValue($value);
    return $value;
  }
}
