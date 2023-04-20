<?php

namespace Bloomreach\Feed\Controller\Adminhtml;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Bloomreach\Feed\Api\SubmitProductsInterface;
use Magento\Backend\Model\Auth\Session;
use Magento\User\Model\User;
use Psr\Log\LoggerInterface;
use Magento\Framework\Webapi\Exception as WebApiException;
use Magento\AsynchronousOperations\Api\Data\OperationInterface;
use Magento\Framework\Api\Filter;
use Magento\Framework\Api\Search\FilterGroup;
use Magento\Framework\Bulk\OperationInterface as OperationFlags;
use Magento\Framework\Api\SortOrder;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Bloomreach\Feed\Controller\Adminhtml\ProductToBRTransformer;
use Bloomreach\Feed\Controller\Adminhtml\SubmitProductsApiInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Filesystem\Io\File;
use Magento\Framework\Filesystem;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Archive\Gz;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;


class SubmitProductsApiController implements SubmitProductsInterface, SubmitProductsApiInterface
{
  private $debugLogger;
  private $authSession;
  private $userModel;
  private $aclRetriever;
  private $policy;
  private $operationRepository;
  private $searchCriteriaBuilder;
  private $sortOrderBuilder;
  private $productCollectionFactory;
  private $brProductTransform;
  private $directoryList;
  private $resultFactory;
  private $fs;
  private $gz;
  private $scopeConfig;
  private $storeManager;


  /**
   * ApiController constructor.
   * @param Context $context
   * @param JsonFactory $jsonFactory
   */
  public function __construct(
    LoggerInterface $debugLogger,
    \Magento\Authorization\Model\Acl\AclRetriever $aclRetriever,
    \Magento\Framework\Authorization\PolicyInterface $policy,
    \Magento\AsynchronousOperations\Api\OperationRepositoryInterface $operationRepository,
    \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder,
    \Magento\Framework\Api\SortOrderBuilder $sortOrderBuilder,
    CollectionFactory $productCollectionFactory,
    ProductToBRTransformer $brProductTransform,
    DirectoryList $directoryList,
    FileSystem $fs,
    User $userModel,
    Session $authSession,
    ResultFactory $resultFactory,
    Gz $gz,
    ScopeConfigInterface $scopeConfig,
    StoreManagerInterface $storeManager
  ) {
    $this->debugLogger = $debugLogger;
    $this->authSession = $authSession;
    $this->userModel = $userModel;
    $this->aclRetriever = $aclRetriever;
    $this->policy = $policy;
    $this->operationRepository = $operationRepository;
    $this->searchCriteriaBuilder = $searchCriteriaBuilder;
    $this->sortOrderBuilder = $sortOrderBuilder;
    $this->productCollectionFactory = $productCollectionFactory;
    $this->brProductTransform = $brProductTransform;
    $this->directoryList = $directoryList;
    $this->resultFactory = $resultFactory;
    $this->fs = $fs;
    $this->gz = $gz;
    $this->scopeConfig = $scopeConfig;
    $this->storeManager = $storeManager;
  }

  /**
   * When called from execute, should return this processes current running
   * process. When called fro getStatus, should return the current running
   * process if it exists.
   */
  private function getRunningOperation()
  {
    $sortOrder = $this->sortOrderBuilder
      ->setField('started_at') // Replace 'field_name' with the actual field name you want to sort by
      ->setDirection(SortOrder::SORT_DESC) // Use SORT_ASC for ascending or SORT_DESC for descending
      ->create();

    $operations = $this->operationRepository->getList(
      $this->searchCriteriaBuilder
        ->addFilter('topic_name', 'async.bloomreach.feed.api.submitproductsinterface.execute.post', 'eq')
        ->addFilter('status', [OperationFlags::STATUS_TYPE_OPEN], 'in')
        ->addFilter('started_at', null, 'notnull')
        ->addSortOrder($sortOrder)
        ->setPageSize(1)
        ->setCurrentPage(1)
        ->create()
    )->getItems();

    if (count($operations) > 0) {
      return $operations[0];
    }

    return null;
  }

  /**
   * Returns the last process that executed properly.
   */
  private function getLastOperation()
  {
    $sortOrder = $this->sortOrderBuilder
      ->setField('started_at') // Replace 'field_name' with the actual field name you want to sort by
      ->setDirection(SortOrder::SORT_DESC) // Use SORT_ASC for ascending or SORT_DESC for descending
      ->create();

    $operations = $this->operationRepository->getList(
      $this->searchCriteriaBuilder
        ->addFilter('topic_name', 'async.bloomreach.feed.api.submitproductsinterface.execute.post', 'eq')
        ->addFilter('status', [OperationFlags::STATUS_TYPE_OPEN], 'nin')
        ->addFilter('started_at', null, 'notnull')
        ->addSortOrder($sortOrder)
        ->setPageSize(1)
        ->setCurrentPage(1)
        ->create()
    )->getItems();

    if (count($operations) > 0) {
      return $operations[0];
    }

    return null;
  }

  private function hasPendingQueries()
  {
    $operations = $this->operationRepository->getList(
      $this->searchCriteriaBuilder
        ->addFilter('topic_name', 'async.bloomreach.feed.api.submitproductsinterface.execute.post', 'eq')
        ->addFilter('status', [OperationFlags::STATUS_TYPE_OPEN], 'in')
        ->addFilter('started_at', null, 'null')
        ->setPageSize(1)
        ->setCurrentPage(1)
        ->create()
    )->getItems();

    return count($operations) > 0;
  }

  /**
   * This can only be called from the execute method. This will return whether
   * or not there is another running operation other than itself.
   */
  private function getSecondRunningOperation()
  {
    $sortOrder = $this->sortOrderBuilder
      ->setField('started_at') // Replace 'field_name' with the actual field name you want to sort by
      ->setDirection(SortOrder::SORT_DESC) // Use SORT_ASC for ascending or SORT_DESC for descending
      ->create();

    $operations = $this->operationRepository->getList(
      $this->searchCriteriaBuilder
        ->addFilter('topic_name', 'async.bloomreach.feed.api.submitproductsinterface.execute.post', 'eq')
        ->addFilter('status', [OperationFlags::STATUS_TYPE_OPEN], 'in')
        ->addFilter('started_at', null, 'neq')
        ->addSortOrder($sortOrder)
        ->setPageSize(1)
        ->setCurrentPage(2)
        ->create()
    )->getItems();

    if (count($operations) > 0) {
      return $operations[0];
    }

    return null;
  }

  /**
   * Returning store config value
   *
   * @param string $path
   **/
  private function getStoreConfigValue($path)
  {
    // Check all potential scope ranges the values can be set
    $scope = ScopeInterface::SCOPE_STORES;
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
      $scope = ScopeInterface::SCOPE_STORE;
      $scopeId = 0;
      $value = $this->scopeConfig->getValue($path, $scope, $scopeId);
    }

    return $value;
  }

  /**
   * Examines a file on the system for it's byte size
   */
  public function getFileSize($filePath)
  {
    // Get the media directory instance
    $mediaDirectory = $this->fs->getDirectoryRead(DirectoryList::MEDIA);

    // Check if the file exists
    if (!$mediaDirectory->isFile($filePath)) {
      return false;
    }

    // Get the file size
    $fileSize = $mediaDirectory->stat($filePath)['size'];

    return $fileSize;
  }

  /**
   * Takes the generated
   */
  private function submitPatchFile($filePath)
  {
    if (!file_exists($filePath)) {
      $this->debugLogger->debug('[Bloomreach_Feed] No feed file found.');
      return [
        'success' => false,
        'message' => 'No feed file found.'
      ];
    }

    $hostnames = [
      "stage" => "api-staging.connect.bloomreach.com",
      "prod" => "api.connect.bloomreach.com"
    ];

    try {
      $account_id = $this->getStoreConfigValue(self::SETTINGS_ACC_ID);
      $catalog_name = $this->getStoreConfigValue(self::SETTINGS_CATALOG_KEY);
      $hostname = $hostnames[$this->getStoreConfigValue(self::SETTINGS_CATALOG_ENVIRONMENT)];
      $token = $this->getStoreConfigValue(self::SETTINGS_AUTH_KEY);

      if (!$account_id || !$catalog_name || !$hostname || !$token) {
        throw new \Exception('Missing configuration values.');
      }
    } catch (\Exception $e) {
      $this->debugLogger->debug("Missing configuration values:\n" . json_encode([
        'account_id' => $this->getStoreConfigValue(self::SETTINGS_ACC_ID),
        'catalog_name' => $this->getStoreConfigValue(self::SETTINGS_CATALOG_KEY),
        'hostname' => $this->getStoreConfigValue(self::SETTINGS_CATALOG_ENVIRONMENT),
        // Output token as asterisks to prevent logging sensitive data
        'token' => str_repeat('*', strlen($token == null ? '' : $token))
      ], JSON_PRETTY_PRINT), ['Bloomreach Feed']);

      return [
        'success' => false,
        'message' => 'Missing configuration values.'
      ];
    }

    $this->debugLogger->debug("Submitting feed with configuration values:\n" . json_encode([
      'account_id' => $this->getStoreConfigValue(self::SETTINGS_ACC_ID),
      'catalog_name' => $this->getStoreConfigValue(self::SETTINGS_CATALOG_KEY),
      'hostname' => $this->getStoreConfigValue(self::SETTINGS_CATALOG_ENVIRONMENT),
      // Output token as asterisks to prevent logging sensitive data
      'token' => str_repeat('*', strlen($token == null ? '' : $token))
    ], JSON_PRETTY_PRINT), ['Bloomreach Feed']);

    $dc_endpoint = "dataconnect/api/v1";
    $account_endpoint = "accounts/{$account_id}";
    $catalog_endpoint = "catalogs/{$catalog_name}";

    $url = "https://{$hostname}/{$dc_endpoint}/{$account_endpoint}/{$catalog_endpoint}/products";
    $headers = [
      "Content-Type: " . "application/json-patch+jsonlines",
      "Content-Encoding: " . "gzip",
      "Authorization: Bearer " . $token
    ];

    // Now submit the file gzip encoded
    $payload = gzencode(file_get_contents($filePath));

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $result = curl_exec($ch);

    if ($result === false) {
      return [
        'success' => false,
        'message' => curl_error($ch),
        'curl_info' => curl_getinfo($ch)
      ];
    }

    $responseInfo = curl_getinfo($ch);

    return [
      'success' => $responseInfo['http_code'] == 200,
      'request headers' => $headers,
      'response' => $responseInfo,
      'response body' => ["" => $result]
    ];
  }

  /**
   * Write content to text file
   *
   * @param WriteInterface $writeDirectory
   * @param $filePath
   * @return bool
   * @throws FileSystemException
   */
  public function write(WriteInterface $writeDirectory, string $filePath, string $data)
  {
    $stream = $writeDirectory->openFile($filePath, 'w+');
    $stream->lock();
    $stream->write($data);
    $stream->unlock();
    $stream->close();

    return true;
  }

  /**
   * This loops through all products, applies a transform function to them to
   * make them compatible with the Bloomreach submission process, saves those
   * results to a file as a JSON list file, and then submits that file to the
   * Bloomreach network.
   *
   * This takes a batch approach to processing so we can limit the amount of
   * resources this operation utilizes. We do NOT want to load all products as
   * that can bring the server down. This operation should be able to run
   * alongside other operations without causing any issues and keep the server
   * operational to customers.
   */
  private function batchProcessProducts($dirPath, $filePath)
  {
    $batchSize = 100;
    $page = 1;
    $productCollection = $this->productCollectionFactory->create();
    $productCollection->setPageSize($batchSize);
    // Get an appropriate file path to write our patch JSON objects to.
    $varDirectory = $this->fs->getDirectoryWrite(DirectoryList::VAR_DIR);

    // Initialize our file, or ensure it is empty
    $this->debugLogger->debug('Initializing feed file: ' . $filePath, ['Bloomreach Feed']);
    $this->write($varDirectory, $filePath, '');

    // Write the results as a JSON object to a single line in the file using
    // an "append" mode.
    $totalProducts = 0;
    $failedProducts = 0;
    $failureReasons = [];
    $processedProducts = [];

    // Open the file in append mode so we can stream updates to it
    $this->debugLogger->debug('Writing products to feed file: ' . $filePath, ['Bloomreach Feed']);
    $stream = $varDirectory->openFile($filePath, 'a');
    $stream->lock();

    do {
      $productCollection->setCurPage($page);
      $productCollection->load();
      $products = $productCollection->getItems();

      foreach ($products as $product) {
        try {
          // Quick exit check. This product will be included here if it was
          // counted as a variant of any other product.
          if (in_array($product->getSku(), $processedProducts)) {
            $this->debugLogger->debug("Page: $page" . ' with Product ' . $product->getSku() . ' has already been processed. Skipping.', ["Bloomreach", "Feed"]);
            continue;
          }

          // Get the product convertted to a bloomreach patch operation
          $result = $this->brProductTransform->transform($product);

          if (!$result) {
            $this->debugLogger->debug('Product ' . $product->getSku() . ' failed the transform. Skipping.', ["Bloomreach", "Feed"]);
            continue;
          }

          // Add the product ID to the processed products array so we can
          // ensure we don't add it again.
          $processedProducts[] = $result['id'];
          $productSku = $result['id'];

          // Remove the id property from the result as it is not needed in the
          // patch operation.
          unset($result['id']);

          // Add all of the variants to the processProducts as well. The
          // variants object is structured:
          // variants = [
          //  variant_id => [ ... ],
          // ]
          // We want to add variant_id to the processedProducts array so we
          // don't do ANY extra processing for the product in the batch analysis
          if (isset($result['variants'])) {
            foreach ($result['variants'] as $variantId => $variant) {
              if (!in_array($variantId, $processedProducts)) {
                $processedProducts[] = $variantId;
                $totalProducts++;
              }
            }
          }

          $patch = [
            'op' => 'add',
            'path' => "/products/$productSku",
            'value' => $result
          ];

          // Write the single line with a newline to ensure each line added is on
          // a new line and the file will end with an empty line.
          $this->debugLogger->debug('Transformed Product:\n' . json_encode($result, JSON_UNESCAPED_SLASHES), ["Bloomreach", "Feed"]);
          $stream->write(json_encode($patch, JSON_UNESCAPED_SLASHES) . "\n");
        } catch (\Exception $e) {
          $this->debugLogger->error($e->getMessage());
          $failedProducts++;

          // Add to the failure reasons but dedup the reasons
          $failureReasons[$e->getMessage()] = $e->getMessage();
        }
      }

      $page++;
      $productCollection->clear();
    } while (count($products) == $batchSize);

    // Close the stream as we have completed the operation
    $stream->unlock();
    $stream->close();

    $feedSize = $varDirectory->stat($filePath)['size'];
    $this->debugLogger->debug("Finished writing feed file.\nTotal products $totalProducts.\nFailed products $failedProducts\nFeed File Size: $feedSize", ['Bloomreach Feed']);

    if (count($failureReasons) <= 0) {
      $failureReasons[] = "No failures.";
    }

    return [
      'total_products' => $totalProducts,
      'failed_products' => $failedProducts,
      'failure_reasons' => array_values($failureReasons),
      'file_path' => $filePath
    ];
  }

  /**
   * POST
   * Triggers a complete product feed submission to the bloomreach network.
   *
   * @api
   * @param string $username
   * @param string $password
   *
   * @return string
   */
  public function execute($username, $password)
  {
    if (!$username || !$password) {
      throw new WebApiException(__('Please provide a username and password.'));
    }

    $user = $this->userModel->login($username, $password);

    if (!$user) {
      throw new WebApiException(__('Invalid login credentials.'));
    }

    if (!$this->policy->isAllowed($user->getRole()->getId(), 'Magento_Backend::admin')) {
      throw new WebApiException(__('You do not have permission to submit products.'));
    }

    // TODO: Note, this does not fully solve the "only one process" issue fully.
    // We should be using a database lock pattern to guarantee this. However,
    // this is a simple resource so we don't need such robustness for the time
    // being. There is also a chance the message queue will be using lock
    // patterns that provides the same functionality, but that is unknown at
    // this point of development.
    //
    // When this operation runs, it has created an async operation for itself.
    // So if there is more than one operation in play + itself we know a
    // secondary operation is an existing operation running already.
    $thisOperation = $this->getRunningOperation();
    $runningOperation = $this->getSecondRunningOperation();

    if ($runningOperation && $thisOperation->getId() !== $runningOperation->getId()) {
      $runningId = $runningOperation->getId();
      throw new WebApiException(__("Products are already being processed. Operation ID -> $runningId"));
    }

    $this->debugLogger->debug('Bulk Product processing began.', ['Bloomreach Feed']);
    $additionalError = '';
    $batchInfo = [];
    $submitInfo = [];

    try {
      $dirPath = $this->directoryList->getPath('var');
      $filePath = $dirPath . '/feed.json';
      $batchInfo = $this->batchProcessProducts($dirPath, $filePath);
      $this->debugLogger->debug('Should submit the file now to BR.', ['Bloomreach Feed']);
      $submitInfo = $this->submitPatchFile($filePath);
    } catch (\Exception $e) {
      $additionalError = $e->getMessage();
      $this->debugLogger->debug($e->getMessage());
    }

    $this->debugLogger->debug('Bulk Product processing complete.', ['Bloomreach Feed']);

    // Your API logic goes here
    $response = [
      'batch' => $batchInfo,
      'submit' => $submitInfo,
      'additionalError' => $additionalError == '' ? "None" : $additionalError,
    ];

    return json_encode($response);
  }

  /**
   * GET
   * Returns the status of the last or current product feed submission.
   *
   * @api
   *
   * @return string
   */
  public function getStatus()
  {
    $operation = $this->getRunningOperation();
    $pending = $this->hasPendingQueries();

    // Send the current operation status queued up
    if ($operation) {
      $statusMessage = [
        OperationInterface::STATUS_TYPE_OPEN => 'Open',
        OperationInterface::STATUS_TYPE_RETRIABLY_FAILED => 'Retriably Failed',
        OperationInterface::STATUS_TYPE_NOT_RETRIABLY_FAILED => 'Not Retriably Failed',
        OperationInterface::STATUS_TYPE_COMPLETE => 'Complete',
      ];

      return json_encode([
        'status' => $statusMessage[$operation->getStatus()],
        'result_message' => "Products are being processed.",
        'pending' => $pending,
      ]);
    }

    $lastOperation = $this->getLastOperation();

    if ($lastOperation) {
      $result = $lastOperation->getResultSerializedData();

      return json_encode([
        'status' => 'complete',
        'result_message' => $result,
        'pending' => $pending,
      ]);
    }

    // If no operation present, we are ready for a new process to be queued up.
    return json_encode([
      'status' => 'ready',
      'result_message' => "Products not yet processed.",
      'pending' => $pending,
    ]);
  }
}
