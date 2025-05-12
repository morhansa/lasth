<?php
namespace MagoArab\CdnIntegration\Controller\Adminhtml\Database;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use MagoArab\CdnIntegration\Helper\Data as Helper;
use MagoArab\CdnIntegration\Model\DatabaseAnalyzer;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\App\ResourceConnection;

class Analyze extends Action
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
     * @var ResourceConnection
     */
    protected $resourceConnection;

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param Helper $helper
     * @param DatabaseAnalyzer $databaseAnalyzer
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        Helper $helper,
        DatabaseAnalyzer $databaseAnalyzer,
        ResourceConnection $resourceConnection
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->helper = $helper;
        $this->databaseAnalyzer = $databaseAnalyzer;
        $this->resourceConnection = $resourceConnection;
    }

    /**
 * Analyze database tables
 *
 * @return \Magento\Framework\Controller\Result\Json
 */
public function execute()
{
    $resultJson = $this->resultJsonFactory->create();
    
    try {
        $this->helper->log("Starting database analysis", 'info');
        
        // Get top largest tables
  // Get top largest tables
$largestTables = $this->databaseAnalyzer->getLargestTables();

// Add some simulated fragmentation for demonstration if needed
foreach ($largestTables as &$table) {
    // If fragmented space is very small or zero, add simulated fragmentation
    // for demonstration purposes - remove this in production
    $simulateFragmentation = false;
    
    if ($simulateFragmentation && (float)$table['fragmented_size'] < 0.5) {
        // Add fragmentation that's about 5-10% of table size
        $tableSize = (float)$table['size'];
        if ($tableSize > 0) {
            $fragSize = $tableSize * (mt_rand(5, 10) / 100);
            $table['fragmented_space'] = $fragSize * 1024 * 1024; // Convert back to bytes
            $table['fragmented_size'] = $fragSize;
        }
    }
}
        
        if (empty($largestTables)) {
            $this->helper->log("No tables found in database analysis", 'warning');
            return $resultJson->setData([
                'success' => true, // تغيير هذا إلى true بدلاً من false
                'tables' => [],
                'message' => __('No database tables found that need optimization.')
            ]);
        }
        
        $this->helper->log("Database analysis complete, found " . count($largestTables) . " tables", 'info');
        
        $this->messageManager->addSuccessMessage(
            __('Database analysis found %1 tables to optimize.', count($largestTables))
        );
        
        return $resultJson->setData([
            'success' => true,
            'tables' => $largestTables,
            'message' => __('Database analysis completed successfully.')
        ]);
    } catch (\Exception $e) {
        $this->helper->log('Error in Analyze::execute: ' . $e->getMessage(), 'error');
        $this->messageManager->addExceptionMessage($e, __('An error occurred during database analysis.'));
        
        return $resultJson->setData([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}
}