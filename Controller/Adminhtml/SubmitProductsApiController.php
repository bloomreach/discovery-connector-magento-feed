<?php

namespace Bloomreach\Feed\Controller\Adminhtml;

use Bloomreach\Feed\Api\SubmitProductsInterface;
use Bloomreach\Feed\Model\Configuration;
use Bloomreach\Feed\Model\Transform\BrProductTransformerFactory;
use Magento\AsynchronousOperations\Api\Data\OperationInterface;
use Magento\AsynchronousOperations\Api\OperationRepositoryInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Archive\Gz;
use Magento\Framework\Bulk\OperationInterface as OperationFlags;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Webapi\Exception as WebApiException;
use Psr\Log\LoggerInterface;


class SubmitProductsApiController implements SubmitProductsInterface
{
  /**
   * @var LoggerInterface
   */
  private $debugLogger;

  /**
   * @var \Magento\AsynchronousOperations\Api\OperationRepositoryInterface
   */
  private $operationRepository;

  /**
   * @var \Magento\Framework\Api\SearchCriteriaBuilder
   */
  private $searchCriteriaBuilder;

  /**
   * @var \Magento\Framework\Api\SortOrderBuilder
   */
  private $sortOrderBuilder;

  /**
   * @var CollectionFactory
   */
  private $productCollectionFactory;

  /**
   * @var DirectoryList
   */
  private $directoryList;

  /**
   * @var ResultFactory
   */
  private $resultFactory;

  /**
   * @var Filesystem
   */
  private $fs;

  /**
   * @var Gz
   */
  private $gz;

  /**
   * @var \Bloomreach\Feed\Model\Transform\BrProductTransformerFactory
   */
  private $transformerFactory;

  /**
   * @var Configuration
   */
  private $brFeedConfig;

  const TOPIC_NAME = 'async.bloomreach.feed.api.submitproductsinterface.execute.post';

  /**
   * ApiController constructor.
   *
   * @param LoggerInterface $debugLogger
   * @param OperationRepositoryInterface $operationRepository
   * @param SearchCriteriaBuilder $searchCriteriaBuilder
   * @param SortOrderBuilder $sortOrderBuilder
   * @param CollectionFactory $productCollectionFactory
   * @param BrProductTransformerFactory $transformerFactory
   * @param DirectoryList $directoryList
   * @param Filesystem $fs
   * @param ResultFactory $resultFactory
   * @param Gz $gz
   * @param Configuration $brFeedConfig
   */
  public function __construct(
    LoggerInterface $debugLogger,
    \Magento\AsynchronousOperations\Api\OperationRepositoryInterface $operationRepository,
    \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder,
    \Magento\Framework\Api\SortOrderBuilder $sortOrderBuilder,
    CollectionFactory $productCollectionFactory,
    \Bloomreach\Feed\Model\Transform\BrProductTransformerFactory $transformerFactory,
    DirectoryList $directoryList,
    FileSystem $fs,
    ResultFactory $resultFactory,
    Gz $gz,
    Configuration $brFeedConfig
  ) {
    $this->debugLogger = $debugLogger;
    $this->operationRepository = $operationRepository;
    $this->searchCriteriaBuilder = $searchCriteriaBuilder;
    $this->sortOrderBuilder = $sortOrderBuilder;
    $this->productCollectionFactory = $productCollectionFactory;
    $this->transformerFactory = $transformerFactory;
    $this->directoryList = $directoryList;
    $this->resultFactory = $resultFactory;
    $this->fs = $fs;
    $this->gz = $gz;
    $this->brFeedConfig = $brFeedConfig;
  }

  /**
   * When called from execute, should return this processes current running
   * process. When called from getStatus, should return the current running
   * process if it exists.
   */
  private function getRunningOperations(int $limit = 1)
  {
    $sortOrder = $this->sortOrderBuilder
      ->setField('started_at') // Replace 'field_name' with the actual field name you want to sort by
      ->setDirection(SortOrder::SORT_DESC) // Use SORT_ASC for ascending or SORT_DESC for descending
      ->create();

    $operations = $this->operationRepository->getList(
      $this->searchCriteriaBuilder
        ->addFilter('topic_name', self::TOPIC_NAME, 'eq')
        ->addFilter('status', [OperationFlags::STATUS_TYPE_OPEN], 'in')
        ->addFilter('started_at', null, 'notnull')
        ->addSortOrder($sortOrder)
        ->setPageSize($limit)
        ->setCurrentPage(1)
        ->create()
    )->getItems();

    return $operations;
  }

  /**
   * Returns all bulk operations in the last 30 days
   *
   * @return OperationInterface[]
   */
  private function getOperations()
  {
    $startDate = date_create();
    $startDate->sub(\DateInterval::createFromDateString('30 days'));

    $sortOrder = $this->sortOrderBuilder
      ->setField('started_at') // Replace 'field_name' with the actual field name you want to sort by
      ->setDirection(SortOrder::SORT_DESC) // Use SORT_ASC for ascending or SORT_DESC for descending
      ->create();

    $operations = $this->operationRepository->getList(
      $this->searchCriteriaBuilder
        ->addFilter('topic_name', self::TOPIC_NAME, 'eq')
        ->addFilter('started_at', $startDate->format('Y-m-d H:i:s'), 'gteq')
        ->addSortOrder($sortOrder)
        ->create()
    )->getItems();

    return $operations;
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
    $exportDirectory = $this->fs->getDirectoryRead(DirectoryList::VAR_EXPORT);
    if (!($exportDirectory->isExist($filePath) && $exportDirectory->isFile($filePath))) {
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
      $account_id = $this->brFeedConfig->getStoreConfigValue(Configuration::SETTINGS_ACC_ID);
      $catalog_name = $this->brFeedConfig->getStoreConfigValue(Configuration::SETTINGS_CATALOG_KEY);
      $hostname = $hostnames[$this->brFeedConfig->getStoreConfigValue(Configuration::SETTINGS_CATALOG_ENVIRONMENT)];
      $token = $this->brFeedConfig->getStoreConfigValue(Configuration::SETTINGS_API_KEY);

      if (!$account_id || !$catalog_name || !$hostname || !$token) {
        throw new \Exception('Missing configuration values.');
      }
    } catch (\Exception $e) {
      $this->debugLogger->debug("Missing configuration values:\n" . json_encode([
        'account_id' => $this->brFeedConfig->getStoreConfigValue(Configuration::SETTINGS_ACC_ID),
        'catalog_name' => $this->brFeedConfig->getStoreConfigValue(Configuration::SETTINGS_CATALOG_KEY),
        'hostname' => $this->brFeedConfig->getStoreConfigValue(Configuration::SETTINGS_CATALOG_ENVIRONMENT),
        // Output token as asterisks to prevent logging sensitive data
        'token' => str_repeat('*', strlen($token == null ? '' : $token))
      ], JSON_PRETTY_PRINT), ['Bloomreach Feed']);

      return [
        'success' => false,
        'message' => 'Missing configuration values.'
      ];
    }

    $this->debugLogger->debug("Submitting feed with configuration values:\n" . json_encode([
      'account_id' => $this->brFeedConfig->getStoreConfigValue(Configuration::SETTINGS_ACC_ID),
      'catalog_name' => $this->brFeedConfig->getStoreConfigValue(Configuration::SETTINGS_CATALOG_KEY),
      'hostname' => $this->brFeedConfig->getStoreConfigValue(Configuration::SETTINGS_CATALOG_ENVIRONMENT),
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
    $payload = gzencode($exportDirectory->readFile($filePath));

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
  private function batchProcessProducts($filePath)
  {
    $startTime = date_create();
    $batchSize = 100;
    $page = 1;
    $productCollection = $this->productCollectionFactory->create();
    $productCollection->setPageSize($batchSize);
    // Get an appropriate file path to write our patch JSON objects to.
    $varDirectory = $this->fs->getDirectoryWrite(DirectoryList::VAR_EXPORT);

    /**
     * @var \Bloomreach\Feed\Model\Transform\BrProductTransformer $brProductTransform
     */
    $brProductTransform = $this->transformerFactory->create();

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
          $result = $brProductTransform->transform($product);

          if (!$result) {
            $this->debugLogger->debug('Product ' . $product->getSku() . ' failed the transform. Skipping.', ["Bloomreach", "Feed"]);
            continue;
          }

          // Add the product ID to the processed products array so we can
          // ensure we don't add it again.
          $processedProducts[] = $result['id'];
          $productSku = $result['id'];
          $totalProducts++;

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

    if (empty($failureReasons)) {
      $failureReasons[] = "No failures.";
    }

    $endTime = date_create();
    $elapsedTime = $startTime->diff($endTime)->format('%h hours, %i minutes, %s seconds');

    return [
      'total_products' => $totalProducts,
      'failed_products' => $failedProducts,
      'failure_reasons' => array_values($failureReasons),
      'file_path' => $filePath,
      'elapsed_time' => $elapsedTime
    ];
  }

  /**
   * POST
   * Triggers a complete product feed submission to the bloomreach network.
   *
   * @api
   *
   * @return array
   */
  public function execute()
  {
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
    $operations = $this->getRunningOperations(2);
    $thisOperation = current($operations);
    $runningOperation = next($operations);

    if ($thisOperation && $runningOperation && $thisOperation->getData('id') !== $runningOperation->getData('id')) {
      $runningId = $runningOperation->getData('id');
      throw new WebApiException(__("Products are already being processed. Operation ID -> $runningId"));
    }

    $this->debugLogger->debug('Bulk Product processing began.', ['Bloomreach Feed']);
    $additionalError = '';
    $batchInfo = [];
    $submitInfo = [];

    try {
      $timestamp = date('Ymd_His');
      $filePath = self::FEED_PATH . '/feed_' . $timestamp . '.jsonl';
      $batchInfo = $this->batchProcessProducts($filePath);
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

    return [$response];
  }

  /**
   * GET
   * Returns the status of the last or current product feed submission.
   *
   * @api
   *
   * @return array
   */
  public function getStatus()
  {
    $operations = $this->getOperations();

    $statusMessage = [
      OperationInterface::STATUS_TYPE_OPEN => 'Open',
      OperationInterface::STATUS_TYPE_RETRIABLY_FAILED => 'Retriably Failed',
      OperationInterface::STATUS_TYPE_NOT_RETRIABLY_FAILED => 'Not Retriably Failed',
      OperationInterface::STATUS_TYPE_COMPLETE => 'Complete',
    ];

    $result = [];
    foreach ($operations as $operation) {
      $resultMessage = $operation->getResultSerializedData();
      if (empty($resultMessage)) {
        if ($operation->getStatus() == OperationInterface::STATUS_TYPE_OPEN) {
          $resultMessage = "Products are being processed.";
        } else {
          $resultMessage = $operation->getResultMessage();
        }
      } else {
        try {
          $resultMessage = json_decode($resultMessage, true);
        } catch (JsonException $e) {
          $this->debugLogger->warning("Error decoding operation result data: " . $e->getMessage(), $e->getTrace());
          $resultMessage = "Failed to obtain status";
        }
      }
      $result[] = [
        'start_date' => $operation->getData('started_at'),
        'status' => $statusMessage[$operation->getStatus()],
        'result_message' => $resultMessage,
      ];
    }

    return $result;
  }
}
