<?php
/**
 * MagoArab CdnIntegration
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL-3.0
 *
 * @category   MagoArab
 * @package    MagoArab_CdnIntegration
 * @copyright  Copyright (c) 2025 MagoArab (https://www.mago-ar.com/)
 * @license    https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */
namespace MagoArab\CdnIntegration\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
class DisableSignStaticFiles implements ObserverInterface
{
    /**
     * @var WriterInterface
     */
    protected $configWriter;
    /**
     * @var ManagerInterface
     */
    protected $messageManager;
    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;
    /**
     * @param WriterInterface $configWriter
     * @param ManagerInterface $messageManager
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        WriterInterface $configWriter,
        ManagerInterface $messageManager,
        StoreManagerInterface $storeManager
    ) {
        $this->configWriter = $configWriter;
        $this->messageManager = $messageManager;
        $this->storeManager = $storeManager;
    }
    /**
     * @param \Magento\Framework\Event\Observer $observer
     * @return void
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $signStaticFilesPath = 'dev/static/sign';
        
        // We will check if the event or configuration data does not exist
        $configData = $observer->getEvent() ? $observer->getEvent()->getConfigData() : null;
        if (!$configData) {
            return; // Exit safely if data is not available
        }
        
        // Get the current config value for sign static files
        $currentValue = $configData->getData('groups/static/fields/sign/value');
        
        // If Sign Static Files was set to 'Yes', automatically set it back to 'No'
        if ($currentValue == 1) {
            // Set the config value to 'No' for all scopes
            $this->configWriter->save($signStaticFilesPath, 0);
            
            // Show a message explaining why this setting must remain disabled
            $this->messageManager->addWarningMessage(
                'Sign Static Files setting has been automatically disabled because it is incompatible with CDN Integration. 
                This setting must remain "No" for the CDN to function properly.'
            );
        }
    }
}