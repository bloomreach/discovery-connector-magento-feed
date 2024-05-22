<?php

namespace Bloomreach\Feed\Controller\Adminhtml\System\Config;

use Bloomreach\Feed\Api\SubmitProductsInterface;
use Magento\Config\Controller\Adminhtml\System\AbstractConfig;
use Magento\Config\Controller\Adminhtml\System\ConfigSectionChecker;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\Filesystem;
use Magento\ImportExport\Model\LocalizedFileName;
use Psr\Log\LoggerInterface;

class BrFeedDownload extends AbstractConfig implements HttpGetActionInterface
{
  /**
   * Authorization level of a basic admin session
   *
   * @see _isAllowed()
   */
  const ADMIN_RESOURCE = 'Magento_ImportExport::export';
  /**
   * Url to this controller
   */
  const URL = '*/*/brFeedDownload/';

  /**
   * @var LoggerInterface
   */
  private $debugLogger;
  /**
   * @var FileFactory
   */
  private $fileFactory;
  /**
   * @var Filesystem
   */
  private $filesystem;
  /**
   * @var LocalizedFileName|mixed
   */
  private $localizedFileName;

  /**
   * DownloadFile constructor.
   * @param LoggerInterface $debugLogger
   * @param \Magento\Backend\App\Action\Context $context
   * @param \Magento\Config\Model\Config\Structure $configStructure
   * @param ConfigSectionChecker $sectionChecker
   * @param FileFactory $fileFactory
   * @param Filesystem $filesystem
   * @param LocalizedFileName|null $localizedFileName
   */
  public function __construct(
    LoggerInterface $debugLogger,
    \Magento\Backend\App\Action\Context $context,
    \Magento\Config\Model\Config\Structure $configStructure,
    ConfigSectionChecker $sectionChecker,
    FileFactory $fileFactory,
    Filesystem $filesystem,
    ?LocalizedFileName $localizedFileName = null
  ) {
    $this->debugLogger = $debugLogger;
    $this->fileFactory = $fileFactory;
    $this->filesystem = $filesystem;
    parent::__construct($context, $configStructure, $sectionChecker);
    $this->localizedFileName = $localizedFileName ?? ObjectManager::getInstance()->get(LocalizedFileName::class);
  }

  /**
   * Controller basic method implementation.
   *
   * @return \Magento\Framework\Controller\Result\Redirect | \Magento\Framework\App\ResponseInterface
   */
  public function execute()
  {
    $resultRedirect = $this->resultRedirectFactory->create();
    $resultRedirect->setPath('adminhtml/system_config/edit', ['_secure' => true, 'section' => 'bloomreach_feed_submit']);
    $filename = $this->getRequest()->getParam('filename');
    $exportDirectory = $this->filesystem->getDirectoryRead(DirectoryList::VAR_EXPORT);
    $path = SubmitProductsInterface::FEED_PATH . $filename;
    $this->debugLogger->debug(__('Feed file path: %1', $exportDirectory->getAbsolutePath($path)), ['Bloomreach Feed']);

    try {
      $fileExist = $exportDirectory->isExist($path);
    } catch (Throwable $e) {
      $fileExist = false;
    }

    if (empty($filename) || !$fileExist) {
      $this->messageManager->addErrorMessage(__('Please provide valid export file name'));

      return $resultRedirect;
    }

    try {
      if ($exportDirectory->isFile($path)) {
        return $this->fileFactory->create(
          $this->localizedFileName->getFileDisplayName($path),
          ['type' => 'filename', 'value' => $path],
          DirectoryList::VAR_EXPORT
        );
      }
      $this->messageManager->addErrorMessage(__('%1 is not a valid file', $filename));
    } catch (\Exception $exception) {
      $this->messageManager->addErrorMessage($exception->getMessage());
    }

    return $resultRedirect;
  }
}
