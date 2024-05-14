<?php

namespace Bloomreach\Feed\Model\Transform;

use Magento\Catalog\Api\CategoryLinkManagementInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\GroupedProduct\Model\Product\Type\Grouped;
use Psr\Log\LoggerInterface;

class BrProductTransformer
{
  private $configurable;
  private $grouped;
  private $categoryLinkManagement;
  private $productRepository;
  private $debugLogger;
  private $searchCriteriaBuilder;
  private $filterBuilder;
  private $attributeProcessor;

  public function __construct(
    Configurable $configurable,
    Grouped $grouped,
    CategoryLinkManagementInterface $categoryLinkManagement,
    ProductRepositoryInterface $productRepository,
    LoggerInterface $debugLogger,
    SearchCriteriaBuilder $searchCriteriaBuilder,
    FilterBuilder $filterBuilder,
    AttributeProcessor $attributeProcessor
  ) {
    $this->configurable = $configurable;
    $this->grouped = $grouped;
    $this->categoryLinkManagement = $categoryLinkManagement;
    $this->productRepository = $productRepository;
    $this->debugLogger = $debugLogger;
    $this->searchCriteriaBuilder = $searchCriteriaBuilder;
    $this->filterBuilder = $filterBuilder;
    $this->attributeProcessor = $attributeProcessor;
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

  public function toVariant($product)
  {
    $attributes = $this->attributeProcessor->getBRAttributes($product, true);

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
  public function getTopProductId($productId)
  {
    $parentIds = $this->configurable->getParentIdsByChild($productId);

    if (empty($parentIds)) {
      $parentIds = $this->grouped->getParentIdsByChild($productId);
    }

    if (empty($parentIds)) {
      return $productId;
    }

    return $this->getTopProductId($parentIds[0]);
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
   * This will transform a product into a format that is compatible with the
   * Bloomreach Data feed. It is important to note that this method looks for
   * the top most level product and generates a product item for that product
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
      $topProductId = $this->getTopProductId($product->getId());
      $topProduct = $this->productRepository->getById($topProductId);
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
        "attributes" => $this->attributeProcessor->getBRAttributes($topProduct),
        "variants" => $variants
      ];

      return $brProduct;
    } catch (\Exception $e) {
      $this->debugLogger->debug("Error transforming product:\n" . $e->getMessage(), ['Bloomreach Feed']);
      // log the stack trace
      $this->debugLogger->debug($e->getTraceAsString());

      throw $e;
    }
  }
}
