<?php

namespace Bloomreach\Feed\Model\Transform;

use Bloomreach\Feed\Model\Configuration;
use Magento\Framework\UrlInterface;
use Psr\Log\LoggerInterface;

class AttributeProcessor
{
  /**
   * @var \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory
   */
  private $categoryColFactory;
  /**
   * @var \Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory
   */
  private $attributeColFactory;
  /**
   * @var \Magento\Store\Model\StoreManagerInterface
   */
  private $storeManager;
  /**
   * Categories ID to text-path hash.
   *
   * @var array
   */
  private $categories = [];
  /**
   * Attribute codes to its values. Only attributes with options and only default store values used.
   *
   * @var array
   */
  private $attributeValues = [];
  /**
   * Attributes types
   *
   * @var array
   */
  private $attributeTypes = [];
  /**
   * Link field of Product entity
   *
   * @var string
   */
  private $productEntityLinkField;
  /**
   * @var \Magento\Framework\EntityManager\MetadataPool
   */
  private $metadataPool;
  /**
   * @var array
   */
  private $collectedMultiselectsData = [];
  /**
   * @var string
   */
  private $baseMediaUrl;
  /**
   * @var Configuration
   */
  private $brFeedConfig;
  /**
   * @var LoggerInterface
   */
  private $debugLogger;

  public function __construct(
    \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory $categoryColFactory,
    \Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory $attributeColFactory,
    \Magento\Store\Model\StoreManagerInterface $storeManager,
    Configuration $brFeedConfig,
    LoggerInterface $debugLogger
  )
  {
    $this->categoryColFactory = $categoryColFactory;
    $this->attributeColFactory = $attributeColFactory;
    $this->storeManager = $storeManager;
    $this->brFeedConfig = $brFeedConfig;
    $this->debugLogger = $debugLogger;

    $this->initCategories()
        ->initAttributes();
  }

  /**
   * Initialize categories ID to text-path hash.
   *
   * @return $this
   */
  protected function initCategories()
  {
    $collection = $this->categoryColFactory->create()->addNameToResult();
    /* @var $collection \Magento\Catalog\Model\ResourceModel\Category\Collection */
    /* @var $category \Magento\Catalog\Model\Category */
    foreach ($collection as $category) {
      $structure = preg_split('#/+#', $category->getPath());
      $pathSize = count($structure);
      // We exclude the root category and the default category from the hierarchy
      if ($pathSize > 2) {
        $path = [];
        for ($i = 2; $i < $pathSize; $i++) {
          /* @var $childCategory \Magento\Catalog\Model\Category */
          $childCategory = $collection->getItemById($structure[$i]);
          if ($childCategory) {
            $name = $childCategory->getName();
            $path[] = ["id" => $childCategory->getId(), "name" => $name !== null ? $name : ''];
          }
        }
        $this->categories[$category->getId()] = $path;
      }
    }
    return $this;
  }

  /**
   * Initialize attribute option values and types.
   *
   * @return $this
   */
  protected function initAttributes()
  {
    $collection = $this->attributeColFactory->create();
    /* @var $attribute \Magento\Eav\Model\Entity\Attribute\AbstractAttribute */
    foreach ($collection as $attribute) {
      $this->attributeValues[$attribute->getAttributeCode()] = $this->getAttributeOptions($attribute);
      $this->attributeTypes[$attribute->getAttributeCode()] =
        \Magento\ImportExport\Model\Import::getAttributeType($attribute);
    }
    $this->debugLogger->debug(__('initAttribute values: %1', json_encode($this->attributeValues)), ['Bloomreach Feed']);
    $this->debugLogger->debug(__('initAttribute types: %1', json_encode($this->attributeTypes)), ['Bloomreach Feed']);
    return $this;
  }

  /**
   * Returns attributes all values in label-value or value-value pairs form. Labels are lower-cased.
   *
   * @param \Magento\Eav\Model\Entity\Attribute\AbstractAttribute $attribute
   * @return array
   */
  public function getAttributeOptions(\Magento\Eav\Model\Entity\Attribute\AbstractAttribute $attribute)
  {
    $options = [];
    $indexValueAttributes = $this->brFeedConfig->getAttributesConfig(Configuration::SETTINGS_INDEX_ATTRS_PATH);

    if ($attribute->usesSource()) {
      // should attribute has index (option value) instead of a label?
      $index = in_array($attribute->getAttributeCode(), $indexValueAttributes) ? 'value' : 'label';

      // only default (admin) store values used
      $attribute->setStoreId(\Magento\Store\Model\Store::DEFAULT_STORE_ID);

      try {
        foreach ($attribute->getSource()->getAllOptions(false) as $option) {
          foreach (is_array($option['value']) ? $option['value'] : [$option] as $innerOption) {
            if (isset($innerOption['value']) && strlen($innerOption['value'])) {
              // skip ' -- Please Select -- ' option
              $options[$innerOption['value']] = (string)$innerOption[$index];
            }
          }
        }
        // phpcs:disable Magento2.CodeAnalysis.EmptyBlock.DetectedCatch
      } catch (\Exception $e) {
        // ignore exceptions connected with source models
      }
    }
    return $options;
  }

  /**
   * Get product metadata pool
   *
   * @return \Magento\Framework\EntityManager\MetadataPool
   */
  private function getMetadataPool()
  {
    if (!$this->metadataPool) {
      $this->metadataPool = \Magento\Framework\App\ObjectManager::getInstance()
        ->get(\Magento\Framework\EntityManager\MetadataPool::class);
    }
    return $this->metadataPool;
  }

  /**
   * Get product entity link field
   *
   * @return string
   */
  protected function getProductEntityLinkField()
  {
    if (!$this->productEntityLinkField) {
      $this->productEntityLinkField = $this->getMetadataPool()
        ->getMetadata(\Magento\Catalog\Api\Data\ProductInterface::class)
        ->getLinkField();
    }
    return $this->productEntityLinkField;
  }

  /**
   * Collect multiselect values based on value
   *
   * @param \Magento\Catalog\Api\Data\ProductInterface $product
   * @param string $attrCode
   * @return $this
   */
  protected function collectMultiselectValues(\Magento\Catalog\Api\Data\ProductInterface $product, string $attrCode)
  {
    $attrValue = $product->getData($attrCode);
    $optionIds = $attrValue !== null ? explode(',', $attrValue) : [];
    $options = array_intersect_key(
      $this->attributeValues[$attrCode],
      array_flip($optionIds)
    );
    $linkId = $product->getData($this->getProductEntityLinkField());
    if (!(isset($this->collectedMultiselectsData[$linkId][$attrCode])
      && $this->collectedMultiselectsData[$linkId][$attrCode] == $options)
    ) {
      $this->collectedMultiselectsData[$linkId][$attrCode] = $options;
    }

    return $this;
  }

  /**
   * Check attribute is valid.
   *
   * @param string $code
   * @param mixed $value
   * @return bool
   */
  protected function isValidAttributeValue(string $code, $value)
  {
    $isValid = true;
    if (!is_numeric($value) && empty($value)) {
      $isValid = false;
    }

    if (!isset($this->attributeValues[$code])) {
      $isValid = false;
    }

    if (is_array($value)) {
      $isValid = false;
    }

    return $isValid;
  }

  /**
   * Get base media URL
   *
   * @return string
   */
  public function getBaseMediaUrl()
  {
    if (!isset($this->baseMediaUrl)) {
      $this->baseMediaUrl = $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);
    }
    return $this->baseMediaUrl;
  }

  /**
   * Calculates the category_paths for a product that BR expects.
   *
   * @param $product \Magento\Catalog\Api\Data\ProductInterface
   * @return array with the following format:
   *
   * [{id, name}, {id, name}, {id, name}, ...]
   */
  public function getCategoryPaths(\Magento\Catalog\Api\Data\ProductInterface $product)
  {
    $categoryPaths = [];
    $categoryIds = $product->getCategoryIds();

    foreach ($categoryIds as $categoryId) {
      // Ignore top and default category
      if (array_key_exists($categoryId, $this->categories)) {
        $categoryPaths[] = $this->categories[$categoryId];
      }
    }

    return $categoryPaths;
  }

  /**
   * Converts all attributes associated with a product to an attributes
   * configuration for the product. Some attributes are Bloomreach specific
   * while the rest will be considered custom attributes.
   *
   * These are some of the required BR attributes:
   *
   * "pid": "p123",
   * "title": "my cool title 4",
   * "url": "http://example.com/p123",
   * "display title": "my cool title 4",
   * "price": 123.45,
   * "size": "M",
   * "sale_price": 123.45,
   * "availability": "in stock",
   * "category_paths": [{id, name}, {id, name}, {id, name}, ...]
   *
   * @param \Magento\Catalog\Api\Data\ProductInterface $product
   * @param bool $variantAttributes
   */
  public function getBRAttributes(\Magento\Catalog\Api\Data\ProductInterface $product, bool $variantAttributes = false)
  {
    $removeSlashes = function (string $str) {
      return str_replace('\/', '/', $str);
    };

    $mediaFile = function (string $url) use ($removeSlashes) {
      return $this->getBaseMediaUrl() . "catalog/product" . $removeSlashes($url);
    };

    /**
     * Runs a custom function on the value of an attribute
     *
     * @var string[]
     */
    $customMappings = [
      "url" => $removeSlashes,
      "thumb_image" => $mediaFile,
      "swatch_image" => $mediaFile,
    ];

    $nameMappings = $this->brFeedConfig->getAttributeNameMappings();
    $variantOnlyAttributes = $this->brFeedConfig->getAttributesConfig(Configuration::SETTINGS_VAR_ONLY_PATH);
    $skipAttributes = $this->brFeedConfig->getAttributesConfig(Configuration::SETTINGS_SKIP_ATTRS_PATH);

    $productLinkId = $product->getData($this->getProductEntityLinkField());

    $productName = $product->getName();

    if (!$productName) {
      $productName = $product->getSku();
    }

    /**
     * Important attributes for Bloomreach. These are the base attributes important to Bloomreach
     */
    $attributes = [
      "title" => $productName,
      "url" => $product->getProductUrl(),
      "price" => $product->getPrice(),
      "sale_price" => $product->getSpecialPrice(),
      "availability" => $product->isAvailable(),
      // Only needed on top level product. Variants do not need this.
      "category_paths" => $variantAttributes ? null : $this->getCategoryPaths($product),
    ];

    // Null sale_price only if it's different than price
    if ($attributes["sale_price"] == $attributes["price"]) {
      $attributes["sale_price"] = null;
    }

    // Populate the remaining attribute properties, but we should prioritize the
    // values we specified above.
    $productAttributes = $product->getAttributes();

    /** @var  $attribute \Magento\Eav\Model\Entity\Attribute\AbstractAttribute */
    foreach ($productAttributes as $attribute) {
      $attributeCode = $attribute->getAttributeCode();
      $attributeValue = $product->getData($attributeCode);

      if (!$this->isValidAttributeValue($attributeCode, $attributeValue)) {
        continue;
      }

      if (isset($this->attributeValues[$attributeCode][$attributeValue])
        && !empty($this->attributeValues[$attributeCode])) {
        $attributeValue = $this->attributeValues[$attributeCode][$attributeValue];
      }

      if ($this->attributeTypes[$attributeCode] !== 'multiselect') {
        if (is_scalar($attributeValue)) {
          $attributeValue = htmlspecialchars_decode($attributeValue);
        }
      } else {
        $this->collectMultiselectValues($product, $attributeCode);
        if (!empty($this->collectedMultiselectsData[$productLinkId][$attributeCode])) {
          $attributeValue = array_values($this->collectedMultiselectsData[$productLinkId][$attributeCode]);
        }
      }

      // Transform the attribute name if one exists
      $attributeLabels = $nameMappings[$attributeCode] ?? [$attributeCode];

      foreach ($attributeLabels as $attributeLabel) {
        // Skip if the attribute is already set
        if (array_key_exists($attributeLabel, $attributes)) {
          continue;
        }

        // Skip if the attribute is in the skipAttributes list
        if (in_array($attributeLabel, $skipAttributes)) {
          continue;
        }

        $attributes[$attributeLabel] = $attributeValue;
      }
    }

    //TODO: Add any other custom attributes here

    // Remove any attributes that are in the onlyVariantAttributes list when
    // variantAttributes is false
    if (!$variantAttributes) {
      foreach ($variantOnlyAttributes as $toRemove) {
        if (in_array($toRemove, $attributes)) {
          unset($attributes[$toRemove]);
        }
      }
    }

    // Run any fix functions on the attributes
    foreach ($customMappings as $key => $func) {
      if (array_key_exists($key, $attributes)) {
        $attributes[$key] = $func($attributes[$key]);
      }
    }

    // Remove any null value attributes
    $attributes = array_filter($attributes, function ($value) {
      return $value !== null;
    });

    // Remove any attribute with a "" key value
    if (array_key_exists("", $attributes)) {
      unset($attributes[""]);
    }

    return $attributes;
  }
}
