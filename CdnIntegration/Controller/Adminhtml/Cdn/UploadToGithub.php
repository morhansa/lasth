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
namespace MagoArab\CdnIntegration\Controller\Adminhtml\Cdn;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use MagoArab\CdnIntegration\Helper\Data as Helper;
use MagoArab\CdnIntegration\Model\Github\Api as GithubApi;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use MagoArab\CdnIntegration\Model\ImageProcessor;
use MagoArab\CdnIntegration\Model\JsSplitter;

class UploadToGithub extends Action
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
     * @var GithubApi
     */
    protected $githubApi;
    
    /**
     * @var Filesystem
     */
    protected $filesystem;
    
    /**
     * @var FileDriver
     */
    protected $fileDriver;

    /**
     * @var ImageProcessor
     */
    protected $imageProcessor;
    
    /**
     * @var JsSplitter
     */
    protected $jsSplitter;

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param Helper $helper
     * @param GithubApi $githubApi
     * @param Filesystem $filesystem
     * @param FileDriver $fileDriver
     * @param ImageProcessor $imageProcessor
     * @param JsSplitter $jsSplitter
     */
    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        Helper $helper,
        GithubApi $githubApi,
        Filesystem $filesystem,
        FileDriver $fileDriver,
        ImageProcessor $imageProcessor,
        JsSplitter $jsSplitter
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->helper = $helper;
        $this->githubApi = $githubApi;
        $this->filesystem = $filesystem;
        $this->fileDriver = $fileDriver;
        $this->imageProcessor = $imageProcessor;
        $this->jsSplitter = $jsSplitter;
    }

    /**
     * Upload files to GitHub
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $resultJson = $this->resultJsonFactory->create();
        
        try {
            if (!$this->helper->isEnabled()) {
                return $resultJson->setData([
                    'success' => false,
                    'message' => __('CDN Integration is disabled.')
                ]);
            }
            
            // Verify GitHub credentials
            $this->helper->log('Checking GitHub credentials', 'debug');
            if (!$this->checkGithubCredentials()) {
                $this->helper->log('GitHub credentials check failed', 'error');
                return $resultJson->setData([
                    'success' => false,
                    'message' => __('GitHub credentials are not properly configured. Please check your settings and test the connection first.')
                ]);
            }
            
            $this->helper->log('GitHub credentials check passed', 'debug');
            
            $urls = $this->getRequest()->getParam('urls');
            if (empty($urls)) {
                $this->helper->log('No URLs provided for upload', 'error');
                return $resultJson->setData([
                    'success' => false,
                    'message' => __('No URLs provided for upload.')
                ]);
            }
            
            $this->helper->log('Decoding URLs JSON', 'debug');
            $urls = json_decode($urls, true);
            if (!is_array($urls)) {
                $this->helper->log('Invalid URL format: not an array', 'error');
                return $resultJson->setData([
                    'success' => false,
                    'message' => __('Invalid URL format.')
                ]);
            }
            
            $this->helper->log('Getting file system directories', 'debug');
            $staticDir = $this->filesystem->getDirectoryRead(DirectoryList::STATIC_VIEW)->getAbsolutePath();
            $mediaDir = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA)->getAbsolutePath();
            
            $this->helper->log('Static directory: ' . $staticDir, 'debug');
            $this->helper->log('Media directory: ' . $mediaDir, 'debug');
            
            $results = [
                'total' => count($urls),
                'success' => 0,
                'failed' => 0,
                'webp_converted' => 0,
                'split_js' => [],
                'details' => []
            ];
            
            $this->helper->log('Starting to process ' . count($urls) . ' URLs', 'info');
            
            // Process each URL one by one
            foreach ($urls as $url) {
                $this->helper->log('Processing URL: ' . $url, 'debug');
                
                try {
                    // Determine local file path
                    $localPath = '';
                    $remotePath = '';
                    
                    if (strpos($url, '/static/') === 0) {
                        $path = substr($url, 8); // Remove '/static/'
                        $localPath = $staticDir . $path;
                        $remotePath = $path;
                        $this->helper->log('Static file detected. Local path: ' . $localPath, 'debug');
                    } elseif (strpos($url, '/media/') === 0) {
                        $path = substr($url, 7); // Remove '/media/'
                        $localPath = $mediaDir . $path;
                        $remotePath = $path;
                        $this->helper->log('Media file detected. Local path: ' . $localPath, 'debug');
                    } else {
                        // Skip unsupported URLs
                        $results['failed']++;
                        $results['details'][] = [
                            'url' => $url,
                            'success' => false,
                            'message' => __('Unsupported URL format.')
                        ];
                        continue;
                    }
                    
                    // Check if file exists
                    if (!$this->fileDriver->isExists($localPath)) {
                        $this->helper->log('File not found: ' . $localPath, 'error');
                        $results['failed']++;
                        $results['details'][] = [
                            'url' => $url,
                            'success' => false,
                            'message' => __('File not found: %1', $localPath)
                        ];
                        continue;
                    }
                    
                    $this->helper->log('File exists: ' . $localPath, 'debug');
                    
                    // Check file size and type
                    try {
                        $fileStats = $this->fileDriver->stat($localPath);
                        $fileSize = $fileStats['size'];
                        $this->helper->log('File size: ' . $fileSize . ' bytes', 'debug');
                        
                        if ($fileSize > 10 * 1024 * 1024) { // 10MB limit
                            $this->helper->log('File too large: ' . $localPath . ' (' . $fileSize . ' bytes)', 'warning');
                            $results['failed']++;
                            $results['details'][] = [
                                'url' => $url,
                                'success' => false,
                                'message' => __('File too large: %1 (max size: 10MB)', $localPath)
                            ];
                            continue;
                        }
                        
                        if ($fileSize === 0) {
                            $this->helper->log('File is empty: ' . $localPath, 'warning');
                            $results['failed']++;
                            $results['details'][] = [
                                'url' => $url,
                                'success' => false,
                                'message' => __('File is empty: %1', $localPath)
                            ];
                            continue;
                        }
                    } catch (\Exception $e) {
                        $this->helper->log('Error checking file stats: ' . $e->getMessage(), 'error');
                    }
                    
                    // Attempt to read the file content for validation
                    $fileContent = $this->fileDriver->fileGetContents($localPath);
                    if (empty($fileContent) && $fileSize > 0) {
                        $this->helper->log('Warning: File content is empty despite non-zero size', 'warning');
                    }
                    
                    $this->helper->log('Attempting to upload file to GitHub', 'debug');
                    
                    // Check if this is a large JS file that should be split
                    $shouldSplit = false;
                    $splitResult = false;

                    if ($this->helper->isSplitLargeJsEnabled() && pathinfo($localPath, PATHINFO_EXTENSION) === 'js') {
                        $shouldSplit = $this->jsSplitter->shouldSplitFile($localPath);
                        
                        if ($shouldSplit) {
                            $this->helper->log("Large JS file detected, splitting: {$localPath}", 'info');
                            
                            // Split and upload file
                            $splitResult = $this->jsSplitter->splitAndUploadFile($localPath, $remotePath);
                            
                            if ($splitResult) {
                                $success = true;
                                $results['split_js'][] = [
                                    'original' => $url,
                                    'loader' => $splitResult['loader'],
                                    'chunks' => count($splitResult['chunks']) - 1 // Excluding loader
                                ];
                            } else {
                                $success = false;
                            }
                        } else {
                            // Normal upload for regular JS files
                            $success = $this->githubApi->uploadFile($localPath, $remotePath);
                        }
                    } else {
                        // Regular upload for non-JS files
                        $success = $this->githubApi->uploadFile($localPath, $remotePath);
                    }
                    
                    if ($success) {
                        $this->helper->log("Successfully uploaded {$url} to GitHub" . ($shouldSplit ? " (split into chunks)" : ""), 'info');
                        $results['success']++;
                        
                        $message = $shouldSplit && $splitResult 
                            ? __('Successfully uploaded to GitHub (split into %1 chunks)', count($splitResult['chunks']) - 1)
                            : __('Successfully uploaded to GitHub');
                            
                        $results['details'][] = [
                            'url' => $url,
                            'success' => true,
                            'message' => $message,
                            'split_info' => $shouldSplit && $splitResult ? [
                                'loader' => $splitResult['loader'],
                                'chunks' => $splitResult['chunks']
                            ] : null
                        ];
                        
                        // WebP Conversion - if enabled and file is an image
                        if ($this->helper->isWebpConversionEnabled() && $this->imageProcessor->isImageFile($localPath)) {
                            // Convert image to WebP
                            $webpPath = $this->imageProcessor->convertToWebp($localPath);
                            
                            if ($webpPath) {
                                // Upload the WebP image to GitHub
                                $webpRemotePath = substr($remotePath, 0, strrpos($remotePath, '.')) . '.webp';
                                $uploadResult = $this->githubApi->uploadFile($webpPath, $webpRemotePath);
                                
                                if ($uploadResult) {
                                    $this->helper->log("Successfully uploaded WebP version to GitHub: " . $webpRemotePath, 'info');
                                    $results['webp_converted']++;
                                    $results['details'][] = [
                                        'url' => $url . ' (WebP)',
                                        'success' => true,
                                        'message' => __('Successfully converted and uploaded WebP version')
                                    ];
                                } else {
                                    $this->helper->log("Failed to upload WebP version to GitHub: " . $webpRemotePath, 'error');
                                    $results['details'][] = [
                                        'url' => $url . ' (WebP)',
                                        'success' => false,
                                        'message' => __('Failed to upload WebP version')
                                    ];
                                }
                            }
                        }
                    } else {
                        $this->helper->log('Failed to upload ' . $url . ' to GitHub', 'error');
                        $results['failed']++;
                        $results['details'][] = [
                            'url' => $url,
                            'success' => false,
                            'message' => __('Failed to upload to GitHub. Check logs for details.')
                        ];
                    }
                } catch (\Exception $e) {
                    $this->helper->log('Exception processing URL ' . $url . ': ' . $e->getMessage(), 'error');
                    $results['failed']++;
                    $results['details'][] = [
                        'url' => $url,
                        'success' => false,
                        'message' => $e->getMessage()
                    ];
                }
            }
            
            // Save split JS files information if any files were split
            if (!empty($results['split_js'])) {
                $this->helper->log("Saving information about " . count($results['split_js']) . " split JS files", 'info');
                
                // Get existing split JS files data
                $existingSplitJs = $this->helper->scopeConfig->getValue(
                    'magoarab_cdn/performance_optimization/split_js_files',
                    \Magento\Store\Model\ScopeInterface::SCOPE_STORE
                );
                
                $splitJsFiles = [];
                if ($existingSplitJs) {
                    try {
                        $decoded = json_decode($existingSplitJs, true);
                        if (is_array($decoded)) {
                            $splitJsFiles = $decoded;
                        }
                    } catch (\Exception $e) {
                        $this->helper->log("Error decoding existing split JS data: " . $e->getMessage(), 'error');
                    }
                }
                
                // Add new split JS files
                foreach ($results['split_js'] as $splitInfo) {
                    $found = false;
                    foreach ($splitJsFiles as &$existing) {
                        if ($existing['original'] === $splitInfo['original']) {
                            $existing = $splitInfo;
                            $found = true;
                            break;
                        }
                    }
                    
                    if (!$found) {
                        $splitJsFiles[] = $splitInfo;
                    }
                }
                
                // Save updated data
                $configWriter = \Magento\Framework\App\ObjectManager::getInstance()
                    ->get(\Magento\Framework\App\Config\Storage\WriterInterface::class);
                
                $configWriter->save(
                    'magoarab_cdn/performance_optimization/split_js_files',
                    json_encode($splitJsFiles),
                    \Magento\Store\Model\ScopeInterface::SCOPE_STORE
                );
            }
            
            // Create success or failure message
            if ($results['failed'] > 0 && $results['success'] > 0) {
                $message = __('Upload completed with issues: %1 successful, %2 failed, %3 total. %4 files converted to WebP.', 
                    $results['success'], 
                    $results['failed'], 
                    $results['total'],
                    $results['webp_converted']
                );
                $this->helper->log($message, 'info');
            } else if ($results['failed'] > 0 && $results['success'] === 0) {
                $message = __('Upload failed for all files. Check logs for details.');
                $this->helper->log($message, 'error');
            } else {
                $message = __('All %1 files were successfully uploaded to GitHub. %2 files converted to WebP.', 
                    $results['success'],
                    $results['webp_converted']
                );
                $this->helper->log($message, 'info');
            }
            
            $this->messageManager->addSuccessMessage($message);
            
            return $resultJson->setData([
                'success' => true,
                'results' => $results,
                'message' => $message
            ]);
        } catch (\Exception $e) {
            $this->helper->log('Error uploading files to GitHub: ' . $e->getMessage(), 'error');
            $this->messageManager->addExceptionMessage($e, __('An error occurred while uploading files to GitHub.'));
            
            return $resultJson->setData([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Check if GitHub credentials are properly configured
     *
     * @return bool
     */
    private function checkGithubCredentials()
    {
        $username = $this->helper->getGithubUsername();
        $repository = $this->helper->getGithubRepository();
        $token = $this->helper->getGithubToken();
        
        if (empty($username) || empty($repository) || empty($token)) {
            return false;
        }
        
        return true;
    }
}