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
namespace MagoArab\CdnIntegration\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;

class Data extends AbstractHelper
{
    const XML_PATH_ENABLED = 'magoarab_cdn/general/enabled';
    const XML_PATH_DEBUG_MODE = 'magoarab_cdn/general/debug_mode';
    const XML_PATH_GITHUB_USERNAME = 'magoarab_cdn/github_settings/username';
    const XML_PATH_GITHUB_REPOSITORY = 'magoarab_cdn/github_settings/repository';
    const XML_PATH_GITHUB_BRANCH = 'magoarab_cdn/github_settings/branch';
    const XML_PATH_GITHUB_TOKEN = 'magoarab_cdn/github_settings/token';
    const XML_PATH_FILE_TYPES = 'magoarab_cdn/cdn_settings/file_types';
    const XML_PATH_EXCLUDED_PATHS = 'magoarab_cdn/cdn_settings/excluded_paths';
    
    /**
     * @var LoggerInterface
     */
    protected $logger;
    
    /**
     * URL cache for better performance 
     */
    protected $urlCache = [];

    /**
     * @var StoreManagerInterface
     */
    protected $_storeManager;

    /**
     * @var ScopeConfigInterface
     */
    public $scopeConfig;

    /**
     * @param Context $context
     * @param LoggerInterface $logger
     * @param StoreManagerInterface|null $storeManager
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        Context $context,
        LoggerInterface $logger,
        StoreManagerInterface $storeManager = null,
        ScopeConfigInterface $scopeConfig = null
    ) {
        $this->logger = $logger;
        $this->_storeManager = $storeManager ?: \Magento\Framework\App\ObjectManager::getInstance()->get(StoreManagerInterface::class);
        $this->scopeConfig = $scopeConfig ?: $context->getScopeConfig();
        parent::__construct($context);
    }

    /**
     * Check if the module is enabled
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isEnabled($storeId = null)
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if debug mode is enabled
     *
     * @return bool
     */
    public function isDebugEnabled()
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_DEBUG_MODE,
            ScopeInterface::SCOPE_STORE
        );
    }
	/**
     * Get GitHub username
     *
     * @return string
     */
    public function getGithubUsername()
    {
        return trim($this->scopeConfig->getValue(
            self::XML_PATH_GITHUB_USERNAME,
            ScopeInterface::SCOPE_STORE
        ));
    }

    /**
     * Get GitHub repository name
     *
     * @return string
     */
    public function getGithubRepository()
    {
        return trim($this->scopeConfig->getValue(
            self::XML_PATH_GITHUB_REPOSITORY,
            ScopeInterface::SCOPE_STORE
        ));
    }

    /**
     * Get GitHub branch name
     *
     * @return string
     */
    public function getGithubBranch()
    {
        $branch = trim($this->scopeConfig->getValue(
            self::XML_PATH_GITHUB_BRANCH,
            ScopeInterface::SCOPE_STORE
        ));
        
        return $branch ?: 'main';
    }

    /**
     * Get GitHub token
     *
     * @return string
     */
    public function getGithubToken()
    {
        return trim($this->scopeConfig->getValue(
            self::XML_PATH_GITHUB_TOKEN,
            ScopeInterface::SCOPE_STORE
        ));
    }

    /**
     * Get file types to be served via CDN
     *
     * @return array
     */
    public function getFileTypes()
    {
        $types = $this->scopeConfig->getValue(
            self::XML_PATH_FILE_TYPES,
            ScopeInterface::SCOPE_STORE
        );
        
        if (empty($types)) {
            return ['css', 'js'];
        }
        
        if (is_string($types)) {
            return explode(',', $types);
        }
        
        return $types;
    }

    /**
     * Get excluded paths
     *
     * @return array
     */
    public function getExcludedPaths()
    {
        $paths = $this->scopeConfig->getValue(
            self::XML_PATH_EXCLUDED_PATHS,
            ScopeInterface::SCOPE_STORE
        );
        
        if (empty($paths)) {
            return [];
        }
        
        return array_map('trim', explode("\n", $paths));
    }

    /**
     * Get CDN base URL
     *
     * @return string
     */
    public function getCdnBaseUrl()
    {
        $username = $this->getGithubUsername();
        $repository = $this->getGithubRepository();
        $branch = $this->getGithubBranch();
        
        if (empty($username) || empty($repository) || empty($branch)) {
            return '';
        }
        
        return sprintf(
            'https://cdn.jsdelivr.net/gh/%s/%s@%s/',
            $username,
            $repository,
            $branch
        );
    }

    /**
     * Get custom URLs to serve via CDN
     * This implementation preserves the exact order of URLs as defined in the config
     *
     * @return array
     */
    public function getCustomUrls()
    {
        $urlString = $this->scopeConfig->getValue(
            'magoarab_cdn/custom_urls/custom_url_list',
            ScopeInterface::SCOPE_STORE
        );
        
        if (empty($urlString)) {
            return [];
        }
        
        // Split by newlines and preserve exact order
        $urls = preg_split('/\r\n|\r|\n/', $urlString);
        if (!is_array($urls)) {
            return [];
        }
        
        // Clean up the array while preserving order
        $result = [];
        foreach ($urls as $url) {
            $url = trim($url);
            if (!empty($url)) {
                $result[] = $url;
            }
        }
        
        return $result;
    }
	/**
     * Check if performance optimization is enabled
     *
     * @return bool
     */
    public function isPerformanceOptimizationEnabled()
    {
        return $this->isEnabled() && $this->scopeConfig->isSetFlag(
            'magoarab_cdn/performance_optimization/enabled',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Check if lazy loading for images is enabled
     *
     * @return bool
     */
    public function isLazyLoadImagesEnabled()
    {
        return $this->isPerformanceOptimizationEnabled() && $this->scopeConfig->isSetFlag(
            'magoarab_cdn/performance_optimization/lazy_load_images',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Check if JS defer is enabled
     *
     * @return bool
     */
    public function isDeferJsEnabled()
    {
        return $this->isPerformanceOptimizationEnabled() && $this->scopeConfig->isSetFlag(
            'magoarab_cdn/performance_optimization/defer_js',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Check if HTML minification is enabled
     *
     * @return bool
     */
    public function isHtmlMinifyEnabled()
    {
        return $this->isPerformanceOptimizationEnabled() && $this->scopeConfig->isSetFlag(
            'magoarab_cdn/performance_optimization/minify_html',
            ScopeInterface::SCOPE_STORE
        );
    }
    
    /**
     * Check if WebP conversion is enabled
     *
     * @return bool
     */
    public function isWebpConversionEnabled()
    {
        return $this->isPerformanceOptimizationEnabled() && $this->scopeConfig->isSetFlag(
            'magoarab_cdn/performance_optimization/convert_to_webp',
            ScopeInterface::SCOPE_STORE
        );
    }
    
    /**
     * Check if image optimization is enabled
     *
     * @return bool
     */
    public function isImageOptimizationEnabled()
    {
        return $this->isPerformanceOptimizationEnabled() && $this->scopeConfig->isSetFlag(
            'magoarab_cdn/performance_optimization/optimize_images',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Check if JavaScript optimization is enabled
     *
     * @return bool
     */
    public function isJsOptimizationEnabled()
    {
        return $this->isPerformanceOptimizationEnabled() && $this->scopeConfig->isSetFlag(
            'magoarab_cdn/performance_optimization/optimize_javascript',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Check if inline JS optimization is enabled
     *
     * @return bool
     */
    public function isInlineJsOptimizationEnabled()
    {
        return $this->isJsOptimizationEnabled() && $this->scopeConfig->isSetFlag(
            'magoarab_cdn/performance_optimization/optimize_inline_js',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Check if critical path optimization is enabled
     *
     * @return bool
     */
    public function isCriticalPathOptimizationEnabled()
    {
        return $this->isPerformanceOptimizationEnabled() && $this->scopeConfig->isSetFlag(
            'magoarab_cdn/performance_optimization/optimize_critical_path',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Check if HTTP/2 Server Push is enabled
     *
     * @return bool
     */
    public function isHttp2PushEnabled()
    {
        return $this->isCriticalPathOptimizationEnabled() && $this->scopeConfig->isSetFlag(
            'magoarab_cdn/performance_optimization/enable_http2_push',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Check if progressive loading is enabled
     *
     * @return bool
     */
    public function isProgressiveLoadingEnabled()
    {
        return $this->isPerformanceOptimizationEnabled() && $this->scopeConfig->isSetFlag(
            'magoarab_cdn/performance_optimization/use_progressive_loading',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Check if full page cache enhancement is enabled
     *
     * @return bool
     */
    public function isFullPageCacheEnhancementEnabled()
    {
        return $this->isPerformanceOptimizationEnabled() && $this->scopeConfig->isSetFlag(
            'magoarab_cdn/performance_optimization/enhance_full_page_cache',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Get full page cache TTL (Time To Live)
     *
     * @return int
     */
    public function getFullPageCacheTtl()
    {
        $ttl = (int) $this->scopeConfig->getValue(
            'magoarab_cdn/performance_optimization/page_cache_ttl',
            ScopeInterface::SCOPE_STORE
        );
        
        // Minimum is 3600 seconds (1 hour)
        return $ttl < 3600 ? 3600 : $ttl;
    }

    /**
     * Check if Varnish should be used instead of built-in cache
     *
     * @return bool
     */
    public function shouldUseVarnish()
    {
        return $this->isFullPageCacheEnhancementEnabled() && $this->scopeConfig->isSetFlag(
            'magoarab_cdn/performance_optimization/use_varnish',
            ScopeInterface::SCOPE_STORE
        );
    }
	/**
     * Log messages if debug mode is enabled
     *
     * @param string $message
     * @param string $level
     * @return void
     */
    public function log($message, $level = 'info')
    {
        if ($this->isDebugEnabled() || $level === 'error') {
            switch ($level) {
                case 'error':
                    $this->logger->error($message);
                    break;
                case 'warning':
                    $this->logger->warning($message);
                    break;
                case 'debug':
                    $this->logger->debug($message);
                    break;
                case 'info':
                default:
                    $this->logger->info($message);
                    break;
            }
        }
    }

    /**
     * Create and get merged CSS file URL
     * 
     * @param array $cssFiles
     * @return string
     */
    public function getMergedCssUrl($cssFiles = [])
    {
        if (empty($cssFiles)) {
            return '';
        }
        
        // Create unique filename based on content
        $fileHash = md5(implode('', $cssFiles));
        $mergedFileName = 'merged_' . $fileHash . '.css';
        $mergedFilePath = 'pub/static/merged/css/';
        $fullPath = BP . '/' . $mergedFilePath . $mergedFileName;
        
        // Check if merged file already exists
        if (!file_exists($fullPath)) {
            // Create directory if it doesn't exist
            if (!file_exists(BP . '/' . $mergedFilePath)) {
                mkdir(BP . '/' . $mergedFilePath, 0755, true);
            }
            
            // Fetch and concatenate file contents
            $mergedContent = $this->fetchAndConcatenateFiles($cssFiles);
            
            // Save merged file
            file_put_contents($fullPath, $mergedContent);
        }
        
        // Return relative URL
        return $this->getBaseUrl() . 'static/merged/css/' . $mergedFileName;
    }

    /**
     * Create and get merged JS file URL
     * 
     * @param array $jsFiles
     * @return string
     */
    public function getMergedJsUrl($jsFiles = [])
    {
        if (empty($jsFiles)) {
            return '';
        }
        
        // Create unique filename based on content
        $fileHash = md5(implode('', $jsFiles));
        $mergedFileName = 'merged_' . $fileHash . '.js';
        $mergedFilePath = 'pub/static/merged/js/';
        $fullPath = BP . '/' . $mergedFilePath . $mergedFileName;
        
        // Check if merged file already exists
        if (!file_exists($fullPath)) {
            // Create directory if it doesn't exist
            if (!file_exists(BP . '/' . $mergedFilePath)) {
                mkdir(BP . '/' . $mergedFilePath, 0755, true);
            }
            
            // Fetch and concatenate file contents
            $mergedContent = $this->fetchAndConcatenateFiles($jsFiles);
            
            // Save merged file
            file_put_contents($fullPath, $mergedContent);
        }
        
        // Return relative URL
        return $this->getBaseUrl() . 'static/merged/js/' . $mergedFileName;
    }

    /**
     * Fetch and concatenate file contents
     * 
     * @param array $files
     * @return string
     */
    private function fetchAndConcatenateFiles($files)
    {
        $content = '';
        
        foreach ($files as $file) {
            // Remove query parameters from URL
            $fileUrl = preg_replace('/\?.*$/', '', $file);
            
            try {
                // Get file content
                $fileContent = $this->fetchFileContent($fileUrl);
                
                if ($fileContent) {
                    // Add comment to identify source
                    $content .= "/* Source: {$fileUrl} */\n";
                    $content .= $fileContent . "\n";
                }
            } catch (\Exception $e) {
                // Log error if file fetch fails
                $this->logger->error('Failed to fetch file: ' . $fileUrl . '. Error: ' . $e->getMessage());
            }
        }
        
        return $content;
    }

    /**
     * Fetch file content from URL
     * 
     * @param string $url
     * @return string|bool
     */
    private function fetchFileContent($url)
    {
        // Check if URL is external or local
        if (strpos($url, '//') === 0 || strpos($url, 'http') === 0) {
            // Use cURL for external files
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
            
            $content = curl_exec($ch);
            $error = curl_error($ch);
            
            curl_close($ch);
            
            if ($error) {
                $this->logger->error('cURL Error: ' . $error . ' for URL: ' . $url);
                return false;
            }
            
            return $content;
        } else {
            // For local files
            $localPath = BP . '/' . ltrim($url, '/');
            if (file_exists($localPath)) {
                return file_get_contents($localPath);
            }
        }
        
        return false;
    }
/**
 * Check if JS file splitting is enabled
 *
 * @return bool
 */
public function isSplitLargeJsEnabled()
{
    return $this->isJsOptimizationEnabled() && $this->scopeConfig->isSetFlag(
        'magoarab_cdn/performance_optimization/split_large_js',
        \Magento\Store\Model\ScopeInterface::SCOPE_STORE
    );
}

/**
 * Get JS split threshold in KB
 *
 * @return int
 */
public function getSplitJsThreshold()
{
    $threshold = (int)$this->scopeConfig->getValue(
        'magoarab_cdn/performance_optimization/split_js_threshold',
        \Magento\Store\Model\ScopeInterface::SCOPE_STORE
    );
    
    return $threshold ?: 1000; // Default to 1000KB (1MB)
}

/**
 * Get number of chunks to split JS file into
 *
 * @return int
 */
public function getSplitJsChunks()
{
    $chunks = (int)$this->scopeConfig->getValue(
        'magoarab_cdn/performance_optimization/split_js_chunks',
        \Magento\Store\Model\ScopeInterface::SCOPE_STORE
    );
    
    return $chunks ?: 5; // Default to 5 chunks
}
/**
 * Check if critical JS files optimization is enabled
 *
 * @return bool
 */
public function isOptimizeCriticalJsEnabled()
{
    return $this->isJsOptimizationEnabled() && $this->scopeConfig->isSetFlag(
        'magoarab_cdn/performance_optimization/optimize_critical_js',
        \Magento\Store\Model\ScopeInterface::SCOPE_STORE
    );
}

/**
 * Get list of critical JS files that need special handling
 *
 * @return array
 */
public function getCriticalJsFiles()
{
    return [
        'requirejs/require.min.js',
        'requirejs/require.js',
        'requirejs-config.min.js',
        'requirejs-config.js',
        'mage/requirejs/mixins.min.js',
        'mage/requirejs/mixins.js'
    ];
}

/**
 * Check if a file is a critical JS file
 *
 * @param string $path
 * @return bool
 */
public function isCriticalJsFile($path)
{
    if (!$this->isOptimizeCriticalJsEnabled()) {
        return false;
    }
    
    $criticalFiles = $this->getCriticalJsFiles();
    foreach ($criticalFiles as $criticalFile) {
        if (strpos($path, $criticalFile) !== false) {
            return true;
        }
    }
    
    return false;
}
    /**
     * Get base URL of the store
     * 
     * @return string
     */
    public function getBaseUrl()
    {
        return $this->_storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB);
    }
}