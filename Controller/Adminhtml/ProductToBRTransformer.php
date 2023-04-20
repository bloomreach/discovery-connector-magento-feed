<?php

namespace Bloomreach\Feed\Controller\Adminhtml;

use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\GroupedProduct\Model\Product\Type\Grouped;
use Magento\Catalog\Api\CategoryLinkManagementInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Psr\Log\LoggerInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\FilterBuilder;
use \Magento\Framework\UrlInterface;

class ProductToBRTransformer
{
  private $configurable;
  private $grouped;
  private $categoryLinkManagement;
  private $productRepository;
  private $debugLogger;
  private $_categoryCollectionFactory;
  private $storeManager;
  private $categoryRepository;
  private $searchCriteriaBuilder;
  private $filterBuilder;

  public function __construct(
    Configurable $configurable,
    Grouped $grouped,
    CategoryLinkManagementInterface $categoryLinkManagement,
    \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory $categoryCollectionFactory,
    ProductRepositoryInterface $productRepository,
    LoggerInterface $debugLogger,
    \Magento\Store\Model\StoreManagerInterface $storeManager,
    \Magento\Catalog\Api\CategoryRepositoryInterface $categoryRepository,
    SearchCriteriaBuilder $searchCriteriaBuilder,
    FilterBuilder $filterBuilder
  ) {
    $this->configurable = $configurable;
    $this->grouped = $grouped;
    $this->categoryLinkManagement = $categoryLinkManagement;
    $this->productRepository = $productRepository;
    $this->debugLogger = $debugLogger;
    $this->_categoryCollectionFactory = $categoryCollectionFactory;
    $this->storeManager = $storeManager;
    $this->categoryRepository = $categoryRepository;
    $this->searchCriteriaBuilder = $searchCriteriaBuilder;
    $this->filterBuilder = $filterBuilder;
  }

  public function getBaseMediaUrl()
  {
    return $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);
  }

  public function isParentProduct($product)
  {
    if ($product) {
      $childIds = $this->configurable->getChildrenIds($product->getId());

      if (empty($childIds)) {
        $childIds = $this->grouped->getChildrenIds($product->getId());
      }

      if (empty($childIds)) {
        return true;
      }
    }
    return false;
  }

  /**
   * Checks if this is a child product by seeing if this product has any
   * parents.
   */
  public function isChildProduct($product)
  {
    if ($product) {
      $parentIds = $this->configurable->getParentIdsByChild($product->getId());

      if (empty($parentIds)) {
        $parentIds = $this->grouped->getParentIdsByChild($product->getId());
      }

      if (empty($parentIds)) {
        return true;
      }
    }
    return false;
  }

  /**
   * Retrieves all of the categories a product is listed under
   */
  public function getCategories($product)
  {
    $categoryIds = $product->getCategoryIds();
    $categories = [];

    foreach ($categoryIds as $id) {
      $category = $this->getCategoryById($id);
      if ($category) {
        $categories[] = $category;
      }
    }

    return $categories;
  }

  public function getCategoryById($id)
  {
    if ($id == 0 || $id == '0') {
      return null;
    }

    $category = $this->categoryRepository->get($id);

    if ($category) {
      return $category;
    }

    return null;
  }

  public function dfs_paths($node, $path = null)
  {
    if ($node == null) {
      return [];
    }

    if ($path === null) {
      $path = [];
    }

    // Add the current node to the path
    $path[] = $node;
    $parent = null;

    try {
      // We exclude the root category and the default category from the
      // hierarchy
      if ($node->getParentCategoryId() > 2) {
        $parent = $node->getParentCategory();
      }
    } catch (\Exception $e) {
      // Failure to get parent category means there is no parent
    }

    $parents = [];

    if ($parent) {
      $parents[] = $parent;
    }

    // If the current node is a leaf, add its path to the result
    if (empty($parents)) {
      return [$path];
    }

    // If the current node is not a leaf, recursively find paths for its children
    $paths = [];
    foreach ($parents as $child) {
      $childPaths = $this->dfs_paths($child, array_slice($path, 0)); // array_slice is used to create a copy of the array
      $paths = array_merge($paths, $childPaths);
    }

    return $paths;
  }

  /**
   * Retrieves the complete path hierarchy of a category representing that drill
   * down path to reach the category.
   */
  public function getCategoryHierarchies($category)
  {
    if (!$category) {
      return [];
    }

    $allPaths = $this->dfs_paths($category);

    // Filter out Default Category and root as an extra precaution for some edge cases
    $allPaths = array_map(function ($paths) {
      return array_filter($paths, function ($path) {
        return $path->getId() > 2;
      });
    }, $allPaths);

    // Clear out any empty paths
    $allPaths = array_filter($allPaths, function ($paths) {
      return !empty($paths);
    });

    return $allPaths;
  }

  /**
   * This takes the hierarchy of categories and produces an array with the
   * following format:
   *
   * [{id, name}, {id, name}, {id, name}, ...]
   */
  public function categoryHierarchyToBRPath($categories)
  {
    $categoryPaths = [];

    foreach ($categories as $category) {
      $categoryPaths[] = [
        "id" => $category->getId(),
        "name" => $category->getName()
      ];
    }

    return array_reverse($categoryPaths);
  }

  /**
   * Calculates the category_paths for a product that BR expects.
   */
  public function getCategoryPaths($product)
  {
    $categoryPaths = [];
    $categories = $this->getCategories($product);

    foreach ($categories as $category) {
      $hierarchies = $this->getCategoryHierarchies($category);

      foreach ($hierarchies as $hierarchy) {
        $categoryPaths[] = $this->categoryHierarchyToBRPath($hierarchy);
      }
    }

    return $categoryPaths;
  }

  public function getProductAttributes($product)
  {
    $attributes = [];
    $productAttributes = $product->getAttributes();

    foreach ($productAttributes as $attribute) {
      $attributes[$attribute->getAttributeCode()] = $attribute->getFrontendLabel();
    }

    return $attributes;
  }

  public function toVariant($product)
  {
    $attributes = $this->getBRAttributes($product, true);

    // Remove title
    if (isset($attributes["title"])) {
      unset($attributes["title"]);
    }

    return [
      "attributes" => $attributes,
    ];
  }

  /**
   * This finds the top level product associated with the input product. This
   * can either be the product itself if the product has no parents, or this
   * will continue up the hierarchy till it finds a product with no parent.
   */
  public function getTopProduct($product)
  {
    $parentIds = $this->configurable->getParentIdsByChild($product->getId());

    if (empty($parentIds)) {
      $parentIds = $this->grouped->getParentIdsByChild($product->getId());
    }

    if (empty($parentIds)) {
      return $product;
    }

    return $this->getTopProduct($this->productRepository->getById($parentIds[0]));
  }

  public function flattenArray($inputList)
  {
    $result = [];

    foreach ($inputList as $item) {
      if (is_array($item)) {
        $result = array_merge($result, $this->flattenArray($item));
      } else {
        $result[] = $item;
      }
    }

    return $result;
  }

  /**
   * Gets a flattened list of all child products down the hierarchy of products
   * available to the input product.
   */
  public function getChildProducts($product)
  {
    $instance = $product->getTypeInstance();
    $children = [];

    if (method_exists($instance, 'getUsedProducts')) {
      $children = $instance->getUsedProducts($product);
    }

    if (empty($children)) {
      if (method_exists($instance, 'getAssociatedProducts')) {
        $children = $instance->getAssociatedProducts($product);
      }
    }

    $children = $this->flattenArray($children);
    $childrenCount = count($children);

    $this->debugLogger->debug("Children: $childrenCount", ["Bloomreach Feed"]);

    return $children;
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
   */
  public function getBRAttributes($product, $variantAttributes = false)
  {
    $baseMediaURL = $this->getBaseMediaUrl();

    $removeSlashes = function ($str) {
      return str_replace('\/', '/', $str);
    };

    $mediaFile = function ($url) use ($removeSlashes, $baseMediaURL) {
      return $baseMediaURL . "catalog/product" . $removeSlashes($url);
    };

    $productName = $product->getName();

    if (!$productName) {
      $productName = $product->getSku();
    }

    // Important attributes for Bloomreach. These are the base attributes
    // important to bloomreach
    $attributes = [
      "title" => $productName,
      "url" => $product->getProductUrl(),
      "price" => $product->getPrice(),
      "sale_price" => $product->getSpecialPrice(),
      "availability" => $product->isAvailable(),
      // Only needed on top level product. Variants do not need this.
      "category_paths" => $variantAttributes ? null : $this->getCategoryPaths($product),
    ];

    // Some name mappings for attribute names
    // Use a list to map the same property to multiple attributes
    $nameMapping = [
      "Size" => ["size"],
      "Color" => ["color"],
      "Price" => ["price"],
      "Thumbnail" => ["thumb_image", "swatch_image"],
    ];

    // These are attributes that will use the getAttributeText instead of
    // getAttributeData
    $useAttributeText = [
      "size",
      "color",
    ];

    // Runs a function on the value of an attribute
    $fixUrl = [
      "url" => $removeSlashes,
      "thumb_image" => $mediaFile,
      "swatch_image" => $mediaFile,
    ];

    // These are attributes that will be removed UNLESS $variantAttributes is true
    $onlyVariantAttributes = [
      "price",
      "sale_price",
      "thumb_image",
      "swatch_image",
    ];

    // Attributes to never include
    $removeAttributes = [
      "Media Gallery",
    ];

    // Null sale_price only if it's different than price
    if ($attributes["sale_price"] == $attributes["price"]) {
      $attributes["sale_price"] = null;
    }

    // Populate the remaining attribute properties, but we should prioritize the
    // values we specified above.
    $productAttributes = $product->getAttributes();

    foreach ($productAttributes as $attribute) {
      $attributeCode = $attribute->getAttributeCode();
      $attributeFrontLabel = $attribute->getFrontendLabel();
      $attributeValue = $product->getData($attributeCode);

      // Transform the attribute name if one exists
      $attributeLabels = array_key_exists($attributeFrontLabel, $nameMapping) ?
        $nameMapping[$attributeFrontLabel] : [$attributeFrontLabel];

      foreach ($attributeLabels as $attributeLabel) {
        // Use the attribute text if it's in the list and if it's not null
        if (in_array($attributeLabel, $useAttributeText)) {
          $text = $product->getAttributeText($attributeCode);

          if ($text != null) {
            $attributeValue = $text;
          }
        }

        // Skip if the attribute is already set
        if (array_key_exists($attributeLabel, $attributes)) {
          continue;
        }

        // Skip if the attribute value is null
        if ($attributeValue == null) {
          continue;
        }

        // Skip if the attribute is in the removeAttributes list
        if (in_array($attributeLabel, $removeAttributes)) {
          continue;
        }

        $attributes[$attributeLabel] = $attributeValue;
      }
    }

    // Remove any attributes that are in the onlyAllAttributes list when
    // allAttributes is false
    if (!$variantAttributes) {
      foreach ($onlyVariantAttributes as $toRemove) {
        if (in_array($toRemove, $attributes)) {
          unset($attributes[$toRemove]);
        }
      }
    }

    // Run any fix functions on the attributes
    foreach ($fixUrl as $key => $func) {
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

  /**
   * This will transform a product into a format that is compatible with the
   * Bloomreach Data feed. It is important to note that this method looks for
   * the top most level product and generates a product item  for that product
   * and all of it's children.
   *
   * The child products are specifically submitted as variants of the parent
   * product so a child product does not need to be submitted on it's own.
   *
   * It is up to the caller to determine if this transform will result in the
   * same product object that has already been generated.
   *
   * It is recommended that the operations be deduped based on the top most
   * product id as all children will reference the same top level product.
   *
   * If somehow child products are a tree structure, then the entire tree
   * beneath the top product will be flattened into the variants.
   */
  public function transform($product)
  {
    try {
      $variants = [];
      $topProduct = $this->getTopProduct($product);
      $childProducts = $this->getChildProducts($topProduct);

      $this->debugLogger->debug("Found top level product: " . $topProduct->getSku() . " for " . $product->getSku(), ['Bloomreach Feed']);

      // If no child products, we add the product as a variant to itself. This is
      // necessary for bloomreach specifically.
      if (count($childProducts) == 0) {
        $variants[$topProduct->getSku()] = $this->toVariant($topProduct);
      }

      // Otherwise, we need to add each child product as a variant
      else {
        foreach ($childProducts as $childProduct) {
          $variants[$childProduct->getSku()] = $this->toVariant($childProduct);
        }
      }

      $brProduct = [
        "id" => $topProduct->getSku(),
        "attributes" => $this->getBRAttributes($topProduct),
        "variants" => $variants
      ];

      return $brProduct;
    } catch (\Exception $e) {
      $this->debugLogger->debug("Error transforming product:\n" . $e->getMessage(), ['Bloomreach Feed']);
      // log the stack trace
      $this->debugLogger->debug($e->getTraceAsString());

      return null;
    }
  }
}
