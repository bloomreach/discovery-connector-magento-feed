<?php

namespace Bloomreach\Feed\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\UrlInterface;
use Magento\Backend\Model\Auth\Session;

class SubmitProductsButton extends Field
{
  private $urlBuilder;
  private $authSession;

  /**
   * Button constructor.
   * @param Context $context
   * @param array $data
   */
  public function __construct(
    Context $context,
    UrlInterface $urlBuilder,
    Session $authSession,
    array $data = []
  ) {
    parent::__construct($context, $data);
    $this->urlBuilder = $urlBuilder;
    $this->authSession = $authSession;
  }

  /**
   * Render button HTML
   *
   * @param AbstractElement $element
   * @return string
   */
  public function _getElementHtml(AbstractElement $element)
  {
    $value = $element->setData('style', 'display:none;');
    $html = $element->getElementHtml();
    $url = $this->urlBuilder->getUrl('/rest/V1/bloomreach/feed');
    $btnId = "__br_submit_products_btn__";
    $message = 'Are you sure you wish to submit your entire product catalog?\n(This operation can take a long time to complete depending on the size of your product listing)';
    $authToken = $this->authSession->getUser()->getAuthToken();
    $isLoggedIn = $this->authSession->isLoggedIn();
    $userName = $this->authSession->getUser()->getUserName();

    $html .= "
<div style='display: flex; flex-direction: column; width: 100%'>
  <button type='button' id='$btnId'>
    <span>Submit Products</span>
  </button>
  <input type='password' id='__br_submit_products_pass__' placeholder='Enter your password to confirm' />
  <div id='__br_submit_products_message__' style=\"color: red; white-space: pre; max-width: 300px\"></div>
</div>
<script>
  (function() {
    // Get rid of the annoying jquery migration logs.
    require(['jquery'], function (jQuery) {
      if (jQuery) {
        jQuery.migrateMute = true;
        jQuery.migrateTrace = false;
      }
    });
    const url = new URL(BASE_URL);
    const baseURL = `\${url.protocol}//\${url.hostname}`;
    window.__br_poll_feed_interval__ = window.__br_poll_feed_interval__ || null;

    function jsonToYaml(json, message) {
      try {
        let obj = json;

        if (typeof json === 'string') {
          obj = JSON.parse(json);
        }

        const yamlStr = [];

        const convertToYaml = (obj, indent) => {
          if (typeof obj === 'string') {
            obj = JSON.parse(obj);
          }

          for (const prop in obj) {
            if (obj.hasOwnProperty(prop)) {
              const val = obj[prop];
              if (typeof val === 'object') {
                yamlStr.push(`\${'  '.repeat(indent)}\${prop}:`);
                convertToYaml(val, indent + 1);
              } else {
                yamlStr.push(`\${'  '.repeat(indent)}\${prop}: \${val}`);
              }
            }
          }
        }

        convertToYaml({ [message]: obj }, 0);
        return yamlStr.join('\\n');
      } catch (e) {
        console.error(e);
      }
    }

    // Poll the status of the async operation
    window.clearInterval(window.__br_poll_feed_interval__);
    window.__br_poll_feed_interval__ = window.setInterval(async () => {
      const status = await fetch(`\${baseURL}/rest/V1/bloomreach/feed/status`);
      const response = await status.json();

      try {
        if (typeof response === 'string') {
          const statusResult = JSON.parse(response);

          try {
            const message = JSON.parse(statusResult.result_message);
            document.querySelector('#__br_submit_products_message__').innerHTML = `\${jsonToYaml(message, 'Previous Run')}\nPending Submissions: \${statusResult.pending}`;
          }

          catch (err) {
            document.querySelector('#__br_submit_products_message__').innerHTML = `\${statusResult.result_message}\nPending Submissions: \${statusResult.pending}`;
          }
        }

        else {
          document.querySelector('#__br_submit_products_message__').innerHTML = `\${response.message}`;
        }
      }
      catch (e) {
        document.querySelector('#__br_submit_products_message__').innerHTML = (
          `Failed to parse response from server:\n\${JSON.stringify(response)}`
        );
      }
    }, 1000);

    document.querySelector('#$btnId').addEventListener('click', async function(e) {
      e.preventDefault();
      e.stopPropagation();

      if (confirm('$message')) {
        const formData = new FormData();
        formData.append('username', '$userName');
        formData.append('password', document.querySelector('#__br_submit_products_pass__').value);

        // Begin an async operation for the polling service
        const result = await fetch(
          `\${baseURL}/rest/all/async/V1/bloomreach/feed`,
          {
            method: 'POST',
            body: formData,
          }
        ).then(async (response) => {
          let result;
          const res = await response.json();

          try {
            result = res;

            if (result.request_items[0].status !== 'accepted') {
              alert('Request for submission failed. Please try again or look for existing processes in the queue.');
            }
          }

          catch (e) {
            document.querySelector('#__br_submit_products_message__').innerHTML = (
              `Failed to parse response from server:\n\${JSON.stringify(res)}`
            );
          }

          return result;
        });
      }
    });
  })();
</script>
    ";

    return $html;
  }
}
