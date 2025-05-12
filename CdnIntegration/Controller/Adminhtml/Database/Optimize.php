<?php
namespace MagoArab\CdnIntegration\Controller\Adminhtml\Database;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use MagoArab\CdnIntegration\Helper\Data as Helper;
use MagoArab\CdnIntegration\Model\DatabaseAnalyzer;

class Optimize extends Action
{
    /**
     * Authorization level
     */
    const ADMIN_RESOURCE = 'MagoArab_CdnIntegration::config';

    /**
     * @var JsonFactory
     */
    protected $resultJsonFactory;

    /**
     * @var Helper
     */
    protected $helper;
    
    /**
     * @var DatabaseAnalyzer
     */
    protected $databaseAnalyzer;

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param Helper $helper
     * @param DatabaseAnalyzer $databaseAnalyzer
     */
    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        Helper $helper,
        DatabaseAnalyzer $databaseAnalyzer
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->helper = $helper;
        $this->databaseAnalyzer = $databaseAnalyzer;
    }

/**
 * Optimize database tables
 *
 * @return \Magento\Framework\Controller\Result\Json
 */
public function execute()
{
    $resultJson = $this->resultJsonFactory->create();
    
    try {
        $tables = $this->getRequest()->getParam('tables', []);
        
        $this->helper->log('Received tables for optimization: ' . print_r($tables, true), 'debug');
        
        if (empty($tables)) {
            $this->helper->log('No tables provided for optimization', 'warning');
            return $resultJson->setData([
                'success' => false,
                'message' => __('No tables provided for optimization.')
            ]);
        }
        
        $this->helper->log("Starting database tables optimization for: " . implode(', ', $tables), 'info');
        
        // Optimize selected tables
        $result = $this->databaseAnalyzer->optimizeTables($tables);
        
        $this->helper->log("Database optimization complete with results: " . print_r($result, true), 'debug');
        
        // Calculate total savings
        $totalSaved = isset($result['total_saved']) ? $result['total_saved'] : 0;
        $optimizedCount = count($result['optimized'] ?? []);
        
        $this->messageManager->addSuccessMessage(
            __('Successfully optimized %1 database tables, saving %2 MB of database space.', 
                $optimizedCount, 
                number_format($totalSaved, 2)
            )
        );
        
        // Add debug info
        $this->helper->log("Sending result data back to browser", 'debug');
        
        return $resultJson->setData([
            'success' => true,
            'result' => $result,
            'message' => __('Database optimization completed successfully.')
        ]);
    } catch (\Exception $e) {
        $this->helper->log('Error in Optimize::execute: ' . $e->getMessage() . "\n" . $e->getTraceAsString(), 'error');
        $this->messageManager->addExceptionMessage($e, __('An error occurred during database optimization.'));
        
        return $resultJson->setData([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}
}