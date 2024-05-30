<?php
namespace ShipStream\Sync\Block\Adminhtml\System\Config\Fieldset;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class ActivationButton extends Field
{
    /**
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        $this->setElement($element);
        $url = $this->getUrl('custommodule/controller/action'); // Specify the URL to the action controller

        $html = $this->getLayout()->createBlock('Magento\Backend\Block\Widget\Button')
            ->setType('button')
            ->setClass('custom-button')
            ->setLabel(__('Activate'))
            ->setOnClick("setLocation('$url')")
            ->toHtml();

        return $html;
    }
}