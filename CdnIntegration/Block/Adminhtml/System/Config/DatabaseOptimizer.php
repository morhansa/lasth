<?php
namespace MagoArab\CdnIntegration\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Data\Form\Element\AbstractElement;

class DatabaseOptimizer extends Field
{
    /**
     * @var string
     */
    protected $_template = 'MagoArab_CdnIntegration::system/config/database_optimizer.phtml';

    /**
     * @param Context $context
     * @param array $data
     */
    public function __construct(
        Context $context,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Remove scope label
     *
     * @param AbstractElement $element
     * @return string
     */
    public function render(AbstractElement $element)
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    /**
     * Return element html
     *
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        return $this->_toHtml();
    }

    /**
     * Return ajax url for analyzer button
     *
     * @return string
     */
    public function getAjaxUrl()
    {
        return $this->getUrl('magoarab_cdn/database/analyze');
    }

    /**
     * Return ajax url for optimize button
     *
     * @return string
     */
    public function getOptimizeUrl()
    {
        return $this->getUrl('magoarab_cdn/database/optimize');
    }

    /**
     * Generate button html
     *
     * @return string
     */
    public function getButtonHtml()
    {
        $button = $this->getLayout()->createBlock(
            \Magento\Backend\Block\Widget\Button::class
        )->setData(
            [
                'id' => 'analyze_database_button',
                'label' => __('Analyze Database'),
            ]
        );

        return $button->toHtml();
    }

    /**
     * Generate optimize button html
     *
     * @return string
     */
    public function getOptimizeButtonHtml()
    {
        $button = $this->getLayout()->createBlock(
            \Magento\Backend\Block\Widget\Button::class
        )->setData(
            [
                'id' => 'optimize_database_button',
                'label' => __('Optimize Tables'),
                'class' => 'action-primary',
            //    'disabled' => 'disabled'
            ]
        );

        return $button->toHtml();
    }
}