<?php

namespace Bloomreach\Feed\Block\Adminhtml\System\Config;

/**
 * Adminhtml "Attribute Name Mappings" field
 */
class AttrNameMappings extends \Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray
{
  /**
   * Prepare to render
   *
   * @return void
   */
  protected function _prepareToRender()
  {
    $this->addColumn(
      'source_attribute',
      [
        'label' => __('Source Attribute'),
        'class' => 'required-entry admin__control-text'
      ]
    );
    $this->addColumn(
      'target_attributes',
      [
        'label' => __('Target Attributes'),
        'class' => 'required-entry admin__control-text'
      ]
    );
    $this->_addAfter = false;
  }
}
