<?php
namespace MagoArab\CdnIntegration\Model;

use Magento\Framework\App\ResourceConnection;
use MagoArab\CdnIntegration\Helper\Data as Helper;

class DatabaseAnalyzer
{
    /**
     * Default number of tables to retrieve
     */
    const DEFAULT_TABLE_LIMIT = 20;

    /**
     * @var ResourceConnection
     */
    protected $resourceConnection;
    
    /**
     * @var Helper
     */
    protected $helper;

    /**
     * @param ResourceConnection $resourceConnection
     * @param Helper $helper
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        Helper $helper
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->helper = $helper;
    }

/**
 * Get largest tables in the database
 *
 * @param int $limit Number of tables to return
 * @return array
 */
public function getLargestTables($limit = self::DEFAULT_TABLE_LIMIT)
{
    try {
        $connection = $this->resourceConnection->getConnection();
        $dbName = $this->getDatabaseName();
        
        $this->helper->log("Getting largest tables from database: {$dbName}", 'debug');
        
        $query = "
            SELECT 
                TABLE_NAME AS 'table', 
                ROUND(((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024), 2) AS 'size',
                TABLE_ROWS AS 'rows',
                DATA_FREE AS 'fragmented_space',
                ROUND((DATA_FREE / 1024 / 1024), 2) AS 'fragmented_size',
                ENGINE AS 'engine',
                CREATE_TIME AS 'created',
                UPDATE_TIME AS 'updated'
            FROM information_schema.TABLES 
            WHERE TABLE_SCHEMA = '{$dbName}'
            ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC
            LIMIT {$limit}
        ";
        
        try {
            $results = $connection->fetchAll($query);
            $this->helper->log("Found " . count($results) . " largest tables", 'debug');
            
            // Ensure all results have the necessary fields
            foreach ($results as $key => $table) {
                $results[$key]['size'] = isset($table['size']) ? (float)$table['size'] : 0;
                $results[$key]['rows'] = isset($table['rows']) ? (int)$table['rows'] : 0;
                $results[$key]['fragmented_space'] = isset($table['fragmented_space']) ? (float)$table['fragmented_space'] : 0;
                $results[$key]['fragmented_size'] = isset($table['fragmented_size']) ? (float)$table['fragmented_size'] : 0;
            }
            
            return $results;
        } catch (\Exception $e) {
            $this->helper->log("Error getting largest tables: " . $e->getMessage(), 'error');
            return [];
        }
    } catch (\Exception $e) {
        $this->helper->log("Error in getLargestTables: " . $e->getMessage(), 'error');
        return [];
    }
}
    
/**
 * Optimize database tables
 *
 * @param array $tables Tables to optimize
 * @return array Results of optimization
 */
public function optimizeTables($tables)
{
    $connection = $this->resourceConnection->getConnection();
    $results = [
        'optimized' => [],
        'failed' => [],
        'before' => [],
        'after' => []
    ];
    
    foreach ($tables as $table) {
        try {
            // Get table stats before optimization
            $statsBefore = $this->getTableStats($table);
            $results['before'][$table] = $statsBefore;
            
            // Run OPTIMIZE TABLE command
            $query = "OPTIMIZE TABLE " . $connection->quoteIdentifier($table);
            $connection->query($query);
            
            // For demonstration: simulate some space saving even if fragmented space is zero
            // In production, you would remove this simulation
            $simulateSaving = false;
            
            // Get table stats after optimization
            $statsAfter = $this->getTableStats($table);
            
            // If there was no change and we're simulating, add a small improvement
            if ($simulateSaving && (
                !isset($statsBefore['fragmented_size']) || 
                !isset($statsAfter['fragmented_size']) || 
                (float)$statsAfter['fragmented_size'] == (float)$statsBefore['fragmented_size']
                )) {
                // Add a small saving (about 1-3% of table size)
                $savedSpace = 0;
                if (isset($statsBefore['size']) && (float)$statsBefore['size'] > 0) {
                    $savedSpace = (float)$statsBefore['size'] * (mt_rand(10, 30) / 1000);
                    $statsAfter['size'] = (float)$statsBefore['size'] - $savedSpace;
                    if (!isset($statsAfter['fragmented_size'])) {
                        $statsAfter['fragmented_size'] = 0;
                    }
                }
            } else {
                // Calculate actual saving
                $savedSpace = 0;
                if (isset($statsBefore['fragmented_size']) && isset($statsAfter['fragmented_size'])) {
                    $savedSpace = (float)$statsBefore['fragmented_size'] - (float)$statsAfter['fragmented_size'];
                }
            }
            
            $results['after'][$table] = $statsAfter;
            
            $this->helper->log("Successfully optimized table: {$table}", 'info');
            $results['optimized'][] = [
                'table' => $table,
                'before' => $statsBefore,
                'after' => $statsAfter,
                'saved' => $savedSpace
            ];
        } catch (\Exception $e) {
            $this->helper->log("Error optimizing table {$table}: " . $e->getMessage(), 'error');
            $results['failed'][] = [
                'table' => $table,
                'error' => $e->getMessage()
            ];
        }
    }
    
    return $results;
}
    
    /**
     * Get table statistics
     *
     * @param string $table Table name
     * @return array Table statistics
     */
    protected function getTableStats($table)
    {
        $connection = $this->resourceConnection->getConnection();
        $dbName = $this->getDatabaseName();
        
        $query = "
            SELECT 
                TABLE_NAME AS 'table', 
                ROUND(((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024), 2) AS 'size',
                TABLE_ROWS AS 'rows',
                DATA_FREE AS 'fragmented_space',
                ROUND((DATA_FREE / 1024 / 1024), 2) AS 'fragmented_size',
                ENGINE AS 'engine'
            FROM information_schema.TABLES 
            WHERE TABLE_SCHEMA = '{$dbName}'
            AND TABLE_NAME = '{$table}'
        ";
        
        try {
            $result = $connection->fetchRow($query);
            return $result ?: [];
        } catch (\Exception $e) {
            $this->helper->log("Error getting table stats for {$table}: " . $e->getMessage(), 'error');
            return [];
        }
    }
    
    /**
     * Get current database name
     *
     * @return string
     */
    protected function getDatabaseName()
    {
        $connection = $this->resourceConnection->getConnection();
        return $connection->getConfig()['dbname'];
    }
}