<?php

namespace Bloomreach\Feed\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class EndPoint implements OptionSourceInterface
{

    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [['value' => 'stage', 'label' => __('Staging')], ['value' => 'prod', 'label' => __('Production')]];
    }

    /**
     * Get options in "key-value" format
     *
     * @return array
     */
    public function toArray()
    {
        return ['stage' => __('Staging'), 'prod' => __('Production')];
    }
}
