<?php

namespace Bloomreach\Feed\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Integration\Model\Oauth\TokenFactory;
use Magento\Backend\Model\Auth\Session as AdminSession;

class SubmitProductsHistory extends Field
{
  /**
   * @var string
   */
  protected $_template = 'Bloomreach_Feed::system/config/history.phtml';

  /** @var TokenFactory */
  private $tokenFactory;

  /** @var AdminSession */
  private $adminSession;


  /**
   * Button constructor.
   * @param Context $context
   * @param array $data
   */
  public function __construct(
    Context $context,
    TokenFactory $tokenFactory,
    AdminSession $adminSession,
    array $data = []
  ) {
    parent::__construct($context, $data);

    $this->tokenFactory = $tokenFactory;
    $this->adminSession = $adminSession;
  }

  /**
   * Remove scope label
   *
   * @param  AbstractElement $element
   * @return string
   */
  public function render(AbstractElement $element)
  {
    $element->setData('scope', null);
    return parent::render($element);
  }

  /**
   * Render button HTML
   *
   * @param AbstractElement $element
   * @return string
   */
  public function _getElementHtml(AbstractElement $element)
  {
    return $this->_toHtml();
  }

  public function getToken()
  {
    $token = $this->tokenFactory->create()
      ->createAdminToken($this->adminSession->getUser()->getId());

    return $token->getToken();
  }

}
