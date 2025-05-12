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
use Magento\Framework\Event\Observer;
use MagoArab\CdnIntegration\Helper\Data as Helper;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\App\State;
use Magento\Framework\App\Area;
use MagoArab\CdnIntegration\Model\Config as CdnConfig;

class ReplaceStaticUrls implements ObserverInterface
{
    /**
     * @var Helper
     */
    protected $helper;
    
    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;
    
    /**
     * @var State
     */
    protected $appState;
    
    /**
     * @var CdnConfig
     */
    protected $cdnConfig;
    
    /**
     * @var array Cache for replaced URLs
     */
    protected $replacedUrlsCache = [];
    
    /**
     * @var array Cache for skipped URLs
     */
    protected $skippedUrlsCache = [];
    
		/**
	 * @var array Cache for split JS files
	 */
   protected $splitJsFiles = []; 
    /**
     * @param Helper $helper
     * @param ScopeConfigInterface $scopeConfig
     * @param State $appState
     * @param CdnConfig $cdnConfig
     */
    public function __construct(
        Helper $helper,
        ScopeConfigInterface $scopeConfig,
        State $appState,
        CdnConfig $cdnConfig
    ) {
        $this->helper = $helper;
        $this->scopeConfig = $scopeConfig;
        $this->appState = $appState;
        $this->cdnConfig = $cdnConfig;
    }
    
    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        if (!$this->helper->isEnabled()) {
            return;
        }
        
        // Check if custom URLs are defined
        $customUrls = $this->helper->getCustomUrls();
        if (empty($customUrls)) {
            $this->helper->log("No custom URLs defined. Skipping replacement.", 'debug');
            return;
        }
        
        // Skip admin area
        try {
            $areaCode = $this->appState->getAreaCode();
            if ($areaCode === Area::AREA_ADMINHTML) {
                $this->helper->log("Skipping admin area", 'debug');
                return;
            }
        } catch (\Exception $e) {
            // Check URL for admin path as a fallback
            $requestUri = $_SERVER['REQUEST_URI'] ?? '';
            if (strpos($requestUri, '/admin/') !== false) {
                $this->helper->log("Skipping admin path: {$requestUri}", 'debug');
                return;
            }
        }
        
        $response = $observer->getEvent()->getResponse();
        if (!$response) {
            return;
        }
        
        $html = $response->getBody();
        if (empty($html)) {
            return;
        }
        
        // Get the CDN base URL
        $cdnBaseUrl = $this->helper->getCdnBaseUrl();
        if (empty($cdnBaseUrl)) {
            $this->helper->log("CDN base URL is empty", 'warning');
            return;
        }
		
        // Get information about split JS files - this could come from a cache or config
         $this->loadSplitJsFiles();
        // Get the base URLs
        $baseUrl = rtrim($this->scopeConfig->getValue(
            \Magento\Store\Model\Store::XML_PATH_UNSECURE_BASE_URL,
            ScopeInterface::SCOPE_STORE
        ), '/');
        
        $secureBaseUrl = rtrim($this->scopeConfig->getValue(
            \Magento\Store\Model\Store::XML_PATH_SECURE_BASE_URL,
            ScopeInterface::SCOPE_STORE
        ), '/');
        
        // Safe file types to use with CDN
        $safeFileTypes = ['css', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'js', 'woff', 'woff2', 'ttf', 'eot'];
        
        // Files to always exclude from CDN
        $criticalFiles = [
            'requirejs/require.js',
            'requirejs-config.js', 
            'mage/requirejs/mixins.js',
            'mage/polyfill.js',
            'mage/bootstrap.js',
            'jquery.js',
            'jquery.min.js',
            'jquery-migrate.js',
            'jquery-migrate.min.js',
            'jquery-ui.js',
            'jquery-ui.min.js',
            'require.js',
            'underscore.js',
            'knockout.js',
            'mage/translate.js',
            'mage/common.js',
            'mage/mage.js',
            'Magento_Ui/js/core/app.js',
            'Magento_Customer/js/customer-data.js',
            'Magento_Customer/js/section-config.js',
            'Magento_Checkout/js/sidebar.js'
        ];

        // Initialize replacement counter and arrays for debugging
        $replacementCount = 0;
        $replacedUrls = [];
        $failedUrls = [];
        
        // 1. First, use a more aggressive replacement for CSS and JS files
        // This is especially important for .min.js and .min.css files
        if (preg_match_all('/(href|src)=[\'"](\/[^"\']+\.(js|css)(\?[^\'"]*)?)[\'"]/', $html, $matches)) {
            foreach ($matches[2] as $urlIndex => $url) {
                $url = $matches[2][$urlIndex];
                
                // Skip URLs that are not static or media
                if (strpos($url, '/static/') !== 0 && strpos($url, '/media/') !== 0) {
                    continue;
                }
                
                // Skip URLs already in cache
                $cacheKey = hash('sha256', $url);
                if (isset($this->replacedUrlsCache[$cacheKey]) || isset($this->skippedUrlsCache[$cacheKey])) {
                    continue;
                }
                
                // Skip critical files
                $shouldSkip = false;
                foreach ($criticalFiles as $criticalFile) {
                    if (strpos($url, $criticalFile) !== false) {
                        $shouldSkip = true;
                        break;
                    }
                }
                
                if ($shouldSkip) {
                    $this->skippedUrlsCache[$cacheKey] = true;
                    continue;
                }
                
                // Determine path to use in CDN URL
                $cdnPath = '';
                if (strpos($url, '/static/') === 0) {
                    $cdnPath = substr($url, 8); // Remove '/static/'
                } elseif (strpos($url, '/media/') === 0) {
                    $cdnPath = substr($url, 7); // Remove '/media/'
                }
                
                if (empty($cdnPath)) {
                    $this->skippedUrlsCache[$cacheKey] = true;
                    continue;
                }
                
                // Create full CDN URL - make sure there's no double slash
                $cdnUrl = rtrim($cdnBaseUrl, '/') . '/' . ltrim($cdnPath, '/');
                
                // Determine if this JS file should be deferred
                $jsLoadPriority = '';
                if (strpos($url, '.js') !== false) {
                    $jsLoadPriority = $this->getJsFileLoadPriority($url);
                }
                
                // Extract full tag to replace
                $fullTag = $matches[0][$urlIndex];
                
                // Handle different replacements based on file type and priority
                if ($jsLoadPriority === 'defer' && strpos($fullTag, 'src=') !== false) {
                    // Add 'defer' attribute to script tag
                    $newTag = str_replace($url, $cdnUrl, $fullTag);
                    if (strpos($newTag, ' defer') === false && strpos($newTag, ' async') === false) {
                        $newTag = str_replace('></script>', ' defer></script>', $newTag);
                    }
                } else {
                    // Regular replacement
                    $newTag = str_replace($url, $cdnUrl, $fullTag);
                }
                
                // Replace just this exact instance
                $pos = strpos($html, $fullTag);
                if ($pos !== false) {
                    $html = substr_replace($html, $newTag, $pos, strlen($fullTag));
                    $replacementCount++;
                    $replacedUrls[$url] = $cdnUrl;
                    $this->replacedUrlsCache[$cacheKey] = true;
                } else {
                    $failedUrls[] = $url;
                }
            }
        }
        
        // 2. Process each custom URL in the exact order they were defined
        // to maintain CSS and JavaScript loading order
        foreach ($customUrls as $url) {
            $cacheKey = hash('sha256', $url);
            
            // Skip if already processed
            if (isset($this->replacedUrlsCache[$cacheKey])) {
                continue;
            }
            
            $html = $this->processUrl($html, $url, $cdnBaseUrl, $baseUrl, $secureBaseUrl, $safeFileTypes, $criticalFiles, $replacementCount, $replacedUrls);
        }
        
        // 3. Handle specific problematic patterns that might be missed
        // This particularly helps with dynamically inserted scripts and inline styles
        if (preg_match_all('/[\'"]([\/][^"\']+\.(js|css)(\?[^\'"]*)?)[\'"]/', $html, $quotedMatches)) {
            foreach ($quotedMatches[1] as $url) {
                // Skip already processed URLs
                $cacheKey = hash('sha256', $url);
                if (isset($this->replacedUrlsCache[$cacheKey]) || isset($this->skippedUrlsCache[$cacheKey])) {
                    continue;
                }
                
                // Process this URL
                $html = $this->processUrl($html, $url, $cdnBaseUrl, $baseUrl, $secureBaseUrl, $safeFileTypes, $criticalFiles, $replacementCount, $replacedUrls);
            }
        }
        
        // Log stats
        if ($replacementCount > 0) {
            $this->helper->log("Replaced {$replacementCount} URLs with CDN URLs", 'info');
            
            // Detailed debug log if debug mode is enabled
            if ($this->helper->isDebugEnabled()) {
                $this->helper->log("Replaced URLs: " . json_encode($replacedUrls), 'debug');
                if (!empty($failedUrls)) {
                    $this->helper->log("Failed to replace URLs: " . json_encode($failedUrls), 'debug');
                }
            }
        }
// Additional scan for product images in JavaScript objects
if (preg_match_all('/var\s+(?:config|gallery)(?:Data)?\s*=\s*(\{.*?\});/s', $html, $jsConfigMatches)) {
    foreach ($jsConfigMatches[1] as $jsConfig) {
        if (preg_match_all('/"(?:img|image|thumbnail|full|large)"\s*:\s*"([^"]+\.(jpg|jpeg|png|gif))"/', $jsConfig, $imgMatches)) {
            foreach ($imgMatches[1] as $imgUrl) {
                $html = $this->processUrl($html, $imgUrl, $cdnBaseUrl, $baseUrl, $secureBaseUrl, $safeFileTypes, $criticalFiles, $replacementCount, $replacedUrls);
            }
        }
    }
}

// Enhance images with WebP format if available
if ($this->helper->isWebpConversionEnabled()) {
    $html = $this->enhanceImagesWithWebp($html);
}
   // Add cache TTL for all static assets
    $html = $this->addCacheTtlToAssets($html);
        $response->setBody($html);
    }
    
    /**
     * Categorize JavaScript files by load priority
     * 
     * @param string $url
     * @return string
     */
    private function getJsFileLoadPriority($url)
    {
        // Files that can be deferred (loaded later)
        $deferCandidates = [
            'js-translation.json',
            'Magento_Ui/js/grid/',
            'Magento_Ui/js/form/',
            'js/theme',
            'Magento_Swatches/js/',
            'Magento_Catalog/js/price-box.js',
            'Magento_Catalog/js/catalog-add-to-cart',
            'Magento_Review/js/',
            'Magento_Theme/js/view/breadcrumbs',
            'Magento_Theme/js/responsive',
            'Magento_Search/js/form-mini'
        ];
        
        // Check if URL matches any defer candidate pattern
        foreach ($deferCandidates as $pattern) {
            if (stripos($url, $pattern) !== false) {
                return 'defer';
            }
        }
        
        // Default to normal loading
        return 'normal';
    }
    
    /**
     * Replace a specific URL in HTML content
     * This method ensures exact replacements to maintain file order
     *
     * @param string $html
     * @param string $url
     * @param string $cdnBaseUrl
     * @param string $baseUrl
     * @param string $secureBaseUrl
     * @param array $safeFileTypes
     * @param array $criticalFiles
     * @param int &$replacementCount
     * @param array &$replacedUrls
     * @return string
     */
    private function processUrl($html, $url, $cdnBaseUrl, $baseUrl, $secureBaseUrl, $safeFileTypes, $criticalFiles, &$replacementCount, &$replacedUrls)
    {
        try {
            // Skip if URL is empty
            if (empty($url)) {
                return $html;
            }
            
            // Normalize URL (remove domain if present)
            $normalizedUrl = $url;
            if (strpos($url, 'http') === 0) {
                $parsedUrl = parse_url($url);
                if (isset($parsedUrl['path'])) {
                    $normalizedUrl = $parsedUrl['path'];
                }
            }
            
            // Ensure URL starts with a slash
            if (strpos($normalizedUrl, '/') !== 0) {
                $normalizedUrl = '/' . $normalizedUrl;
            }
            
            // Skip if not a static or media URL
            if (strpos($normalizedUrl, '/static/') !== 0 && strpos($normalizedUrl, '/media/') !== 0) {
                return $html;
            }
            
            // Skip URLs already in cache
            $cacheKey = hash('sha256', $normalizedUrl);
            if (isset($this->replacedUrlsCache[$cacheKey]) || isset($this->skippedUrlsCache[$cacheKey])) {
                return $html;
            }
            
            // Special handling for merged files - these should always be included
            $isMergedFile = false;
            if (strpos($normalizedUrl, '/_cache/merged/') !== false || 
                strpos($normalizedUrl, '/_cache/minified/') !== false) {
                $isMergedFile = true;
            }
            
            // Skip critical files
            foreach ($criticalFiles as $criticalFile) {
                if (strpos($normalizedUrl, $criticalFile) !== false) {
                    $this->skippedUrlsCache[$cacheKey] = true;
                    return $html;
                }
            }
            
            // Check file type (skip for merged files)
            if (!$isMergedFile) {
                $ext = strtolower(pathinfo($normalizedUrl, PATHINFO_EXTENSION));
                if (!in_array($ext, $safeFileTypes)) {
                    $this->skippedUrlsCache[$cacheKey] = true;
                    return $html;
                }
            }
            
            // Determine path to use in CDN URL
            $cdnPath = '';
            if (strpos($normalizedUrl, '/static/') === 0) {
                $cdnPath = substr($normalizedUrl, 8); // Remove '/static/'
            } elseif (strpos($normalizedUrl, '/media/') === 0) {
                $cdnPath = substr($normalizedUrl, 7); // Remove '/media/'
            }
            
            if (empty($cdnPath)) {
                $this->skippedUrlsCache[$cacheKey] = true;
                return $html;
            }
            
            // Create full CDN URL - make sure there's no double slash
            $cdnUrl = rtrim($cdnBaseUrl, '/') . '/' . ltrim($cdnPath, '/');
            
            // Store original HTML for comparison
            $originalHtml = $html;
            
            // Process absolute URLs with domain - full URL replacements
            if (!empty($baseUrl)) {
                $absoluteUrl = $baseUrl . $normalizedUrl;
                if (strpos($html, $absoluteUrl) !== false) {
                    $html = str_replace($absoluteUrl, $cdnUrl, $html);
                }
            }
            
            if (!empty($secureBaseUrl)) {
                $secureAbsoluteUrl = $secureBaseUrl . $normalizedUrl;
                if (strpos($html, $secureAbsoluteUrl) !== false) {
                    $html = str_replace($secureAbsoluteUrl, $cdnUrl, $html);
                }
            }
            
            // Four precise replacement patterns for different contexts
            
            // 1. href attribute
            $pattern = '/(\shref=["\'])(' . preg_quote($normalizedUrl, '/') . ')(["\'])/';
            $html = preg_replace_callback(
                $pattern,
                function($matches) use ($cdnUrl, &$replacementCount) {
                    $replacementCount++;
                    return $matches[1] . $cdnUrl . $matches[3];
                },
                $html
            );
            
            // 2. src attribute - with defer for applicable JS files
            $pattern = '/(\ssrc=["\'])(' . preg_quote($normalizedUrl, '/') . ')(["\'])/';
            
            // Determine if this is a JS file that can be deferred
            $jsLoadPriority = '';
            $ext = strtolower(pathinfo($normalizedUrl, PATHINFO_EXTENSION));
            if ($ext === 'js') {
                $jsLoadPriority = $this->getJsFileLoadPriority($normalizedUrl);
            }
            
            if ($jsLoadPriority === 'defer') {
                $html = preg_replace_callback(
                    $pattern,
                    function($matches) use ($cdnUrl, &$replacementCount, $html) {
                        $replacementCount++;
                        
                        // Check if this tag already has defer or async attribute
                        $tagStart = strpos($html, $matches[0]);
                        if ($tagStart !== false) {
                            $tagEnd = strpos($html, '>', $tagStart);
                            $tag = substr($html, $tagStart, $tagEnd - $tagStart + 1);
                            
                            if (strpos($tag, ' defer') !== false || strpos($tag, ' async') !== false) {
                                return $matches[1] . $cdnUrl . $matches[3];
                            } else if (strpos($tag, '<script') !== false) {
                                return $matches[1] . $cdnUrl . $matches[3] . ' defer';
                            }
                        }
                        
                        return $matches[1] . $cdnUrl . $matches[3];
                    },
                    $html
                );
            } else {
                $html = preg_replace_callback(
                    $pattern,
                    function($matches) use ($cdnUrl, &$replacementCount) {
                        $replacementCount++;
                        return $matches[1] . $cdnUrl . $matches[3];
                    },
                    $html
                );
            }
            
            // 3. url() in CSS - try various formats
            $patterns = [
                '/url\([\'"]?' . preg_quote($normalizedUrl, '/') . '[\'"]?\)/',
                '/url\([\'"]' . preg_quote($normalizedUrl, '/') . '[\'"]?\)/',
                '/url\(' . preg_quote($normalizedUrl, '/') . '\)/'
            ];
            
            foreach ($patterns as $pattern) {
                $html = preg_replace_callback(
                    $pattern,
                    function($matches) use ($cdnUrl, &$replacementCount) {
                        $replacementCount++;
                        return 'url(' . $cdnUrl . ')';
                    },
                    $html
                );
            }
            
            // 4. Quoted URLs in JavaScript
            $html = preg_replace_callback(
                '/(["\'])(' . preg_quote($normalizedUrl, '/') . ')(["\'])/',
                function($matches) use ($cdnUrl, &$replacementCount, $normalizedUrl) {
                    // Only replace if it's not inside an HTML tag - this is to avoid duplicate replacements
                    $leftChar = substr($matches[0], 0, 1);
                    $rightChar = substr($matches[0], -1);
                    
                    // Only replace if matched quotes are the same
                    if ($leftChar === $rightChar) {
                        $replacementCount++;
                        return $leftChar . $cdnUrl . $rightChar;
                    }
                    
                    return $matches[0];
                },
                $html
            );
            // Handle product images specifically - focus on JSON data with image references
$jsonPattern = '/"(?:img|image|thumbnail|small_image|large_image|full)":\s*"([^"]*?' . preg_quote($normalizedUrl, '/') . '[^"]*?)"/i';
$html = preg_replace_callback(
    $jsonPattern,
    function($matches) use ($cdnUrl, &$replacementCount) {
        $replacementCount++;
        return str_replace($matches[1], $cdnUrl, $matches[0]);
    },
    $html
);

// Handle data attributes specific to product gallery
$dataAttributes = ['data-src', 'data-large-image', 'data-medium-image', 'data-thumb'];
foreach ($dataAttributes as $attr) {
    $pattern = '/' . $attr . '=(["\'])(' . preg_quote($normalizedUrl, '/') . ')(["\'])/i';
    $html = preg_replace_callback(
        $pattern,
        function($matches) use ($cdnUrl, &$replacementCount) {
            $replacementCount++;
            return $attr . '=' . $matches[1] . $cdnUrl . $matches[3];
        },
        $html
    );
}
	// Add a special style to preloaded fonts
	 $preloadPattern = '/(<link[^>]*rel=[\'"]preload[\'"][^>]*as=[\'"]font[\'"][^>]*href=[\'"])(' . preg_quote($normalizedUrl, '/') . ')([\'"][^>]*>)/i';
    $html = preg_replace_callback(
        $preloadPattern,
        function($matches) use ($cdnUrl, &$replacementCount) {
            $replacementCount++;
            return $matches[1] . $cdnUrl . $matches[3];
        },
        $html
    );
// Handle Magento Fotorama gallery specific replacements
$fotoramaPattern = '/data-(?:full|img|thumb|large_image)=(["\'])(' . preg_quote($normalizedUrl, '/') . ')(["\'])/i';
$html = preg_replace_callback(
    $fotoramaPattern,
    function($matches) use ($cdnUrl, &$replacementCount) {
        $replacementCount++;
        return 'data-' . $matches[1] . $cdnUrl . $matches[3];
    },
    $html
);

// Check if this is a split JS file that needs special handling
$extension = strtolower(pathinfo($normalizedUrl, PATHINFO_EXTENSION));
if ($extension === 'js' && !empty($this->splitJsFiles)) {
    // See if this URL matches any of our split JS files
    foreach ($this->splitJsFiles as $splitInfo) {
        if ($splitInfo['original'] === $normalizedUrl) {
            $html = $this->replaceSplitJsFile($normalizedUrl, $cdnUrl, $html);
            break;
        }
    }
}
            // If we changed anything, log it and update cache
            if ($html !== $originalHtml) {
                $this->helper->log("Replaced URL: {$normalizedUrl} with {$cdnUrl}", 'debug');
                $replacedUrls[$normalizedUrl] = $cdnUrl;
                $this->replacedUrlsCache[$cacheKey] = true;
            }
            
            return $html;
        } catch (\Exception $e) {
            $this->helper->log("Error processing URL {$url}: " . $e->getMessage(), 'error');
            return $html;
        }
    }
	
/**
 * Load information about split JS files
 */
private function loadSplitJsFiles()
{
    // This could be loaded from a cache, database, or config in a real implementation
    // For now, we'll use a simple approach to demonstrate the concept
    $splitJsInfo = $this->scopeConfig->getValue(
        'magoarab_cdn/performance_optimization/split_js_files',
        ScopeInterface::SCOPE_STORE
    );
    
    if ($splitJsInfo) {
        try {
            $decodedInfo = json_decode($splitJsInfo, true);
            if (is_array($decodedInfo)) {
                $this->splitJsFiles = $decodedInfo;
                $this->helper->log("Loaded split JS files information: " . count($this->splitJsFiles) . " files", 'debug');
            }
        } catch (\Exception $e) {
            $this->helper->log("Error decoding split JS files information: " . $e->getMessage(), 'error');
        }
    }
}

/**
 * Replace a script tag with split JS loader
 *
 * @param string $originalUrl
 * @param string $cdnUrl
 * @param string $html
 * @return string
 */
private function replaceSplitJsFile($originalUrl, $cdnUrl, $html)
{
    // Find the matching split JS file info
    $splitInfo = null;
    foreach ($this->splitJsFiles as $info) {
        if ($info['original'] === $originalUrl || $info['original'] === $cdnUrl) {
            $splitInfo = $info;
            break;
        }
    }
    
    if (!$splitInfo) {
        return $html;
    }
    
    $this->helper->log("Replacing script tag for split JS file: " . $originalUrl, 'debug');
    
    // Get the CDN base URL
    $cdnBaseUrl = $this->helper->getCdnBaseUrl();
    
    // Create loader URL
    $loaderUrl = $cdnBaseUrl . $splitInfo['loader'];
    
    // Find and replace the script tag
    $pattern = '/<script([^>]*)src=[\'"](?:' . preg_quote($originalUrl, '/') . '|' . preg_quote($cdnUrl, '/') . ')[\'"]([^>]*)><\/script>/i';
    
    return preg_replace_callback(
        $pattern,
        function($matches) use ($loaderUrl) {
            $beforeSrc = $matches[1];
            $afterSrc = $matches[2];
            
            // Replace with loader script
            return '<script' . $beforeSrc . 'src="' . $loaderUrl . '"' . $afterSrc . '></script>';
        },
        $html
    );
}
/**
 * Add Cache TTL parameter to static assets
 * 
 * @param string $html
 * @return string
 */
private function addCacheTtlToAssets($html)
{
    // Add TTL to JavaScript files
    $html = preg_replace_callback(
        '/<script([^>]*)src=[\'"]((?:https?:)?\/\/cdn\.jsdelivr\.net\/gh\/[^\/]+\/[^\/]+@[^\/]+\/[^"\']+\.(js))(?:\?[^\'"]*)?[\'"]([^>]*)><\/script>/i',
        function($matches) {
            $beforeSrc = $matches[1];
            $jsSrc = $matches[2];
            $afterSrc = $matches[4];
            
            // Add TTL parameter
            if (strpos($jsSrc, '?') === false) {
                $jsSrc .= '?ttl=31536000';
            } else {
                $jsSrc .= '&ttl=31536000';
            }
            
            return '<script' . $beforeSrc . 'src="' . $jsSrc . '"' . $afterSrc . '></script>';
        },
        $html
    );
    
    // Add TTL to CSS files
    $html = preg_replace_callback(
        '/<link([^>]*)href=[\'"]((?:https?:)?\/\/cdn\.jsdelivr\.net\/gh\/[^\/]+\/[^\/]+@[^\/]+\/[^"\']+\.(css))(?:\?[^\'"]*)?[\'"]([^>]*)>/i',
        function($matches) {
            $beforeHref = $matches[1];
            $cssHref = $matches[2];
            $afterHref = $matches[4];
            
            // Add TTL parameter
            if (strpos($cssHref, '?') === false) {
                $cssHref .= '?ttl=31536000';
            } else {
                $cssHref .= '&ttl=31536000';
            }
            
            return '<link' . $beforeHref . 'href="' . $cssHref . '"' . $afterHref . '>';
        },
        $html
    );
    
    // Add TTL to image files
    $html = preg_replace_callback(
        '/<img([^>]*)src=[\'"]((?:https?:)?\/\/cdn\.jsdelivr\.net\/gh\/[^\/]+\/[^\/]+@[^\/]+\/[^"\']+\.(jpg|jpeg|png|gif|webp))(?:\?[^\'"]*)?[\'"]([^>]*)>/i',
        function($matches) {
            $beforeSrc = $matches[1];
            $imgSrc = $matches[2];
            $afterSrc = $matches[4];
            
            // Add TTL parameter
            if (strpos($imgSrc, '?') === false) {
                $imgSrc .= '?ttl=31536000';
            } else {
                $imgSrc .= '&ttl=31536000';
            }
            
            return '<img' . $beforeSrc . 'src="' . $imgSrc . '"' . $afterSrc . '>';
        },
        $html
    );
    
    // Add Service Worker registration for caching
    $cacheHeaders = '
    <script>
    // Apply caching via Service Worker
    if ("serviceWorker" in navigator) {
        // Register Service Worker for cache control
        navigator.serviceWorker.register("/cdn-cache-sw.js", {scope: "/"})
        .then(function(registration) {
            console.log("CDN Service Worker registered with scope:", registration.scope);
        }).catch(function(error) {
            console.log("CDN Service Worker registration failed:", error);
        });
    }
    </script>
    ';

    $html = str_replace('</head>', $cacheHeaders . '</head>', $html);
    
    return $html;
}
/**
 * Transform image tags to use WebP with fallback when available
 *
 * @param string $html
 * @return string
 */
private function enhanceImagesWithWebp($html)
{
    // Only process if WebP conversion is enabled
    if (!$this->helper->isWebpConversionEnabled()) {
        return $html;
    }
    
    // Get CDN base URL
    $cdnBaseUrl = $this->helper->getCdnBaseUrl();
    if (empty($cdnBaseUrl)) {
        return $html;
    }
    
    // First, inject a CSS fix for CLS at the beginning of <head>
    $clsFixCSS = '<style>.image-cls-fix { aspect-ratio: attr(width) / attr(height); } ' . 
                'img:not([width]):not([height]) { aspect-ratio: 16/9; min-height: 1px; } ' .
                'picture { display: inline-block; } ' .
                'picture img { width: 100%; height: auto; }</style>';
    
    $html = preg_replace('/<head>/i', '<head>' . $clsFixCSS, $html, 1);
    
    // Keep track of the first main image on the page (likely LCP)
    $foundFirstMainImage = false;
    
    // Process images
    $html = preg_replace_callback(
        '/<img([^>]*)src=[\'"]((?:https?:)?\/\/cdn\.jsdelivr\.net\/gh\/[^\/]+\/[^\/]+@[^\/]+\/[^"\']+\.(jpg|jpeg|png|webp))(?:\?[^\'"]*)?[\'"]([^>]*)>/i',
        function($matches) use (&$foundFirstMainImage) {
            $beforeSrc = $matches[1];
            $imgSrc = $matches[2];
            $extension = strtolower($matches[3]);
            $afterSrc = $matches[4];
            
            // Add cache parameter for better caching
            if (strpos($imgSrc, '?') === false) {
                $cachedImgSrc = $imgSrc . '?ttl=31536000'; // 1 year cache
            } else {
                $cachedImgSrc = $imgSrc . '&ttl=31536000';
            }
            
            // Check if this could be an LCP image
            $isPossiblyLCP = false;
            
            // Check for common LCP image patterns
            if (
                strpos($imgSrc, 'home-main') !== false || 
                strpos($imgSrc, 'hero') !== false ||
                strpos($imgSrc, 'banner') !== false ||
                strpos($imgSrc, 'slider') !== false ||
                (strpos($beforeSrc . $afterSrc, 'home-') !== false && !$foundFirstMainImage)
            ) {
                $isPossiblyLCP = true;
                $foundFirstMainImage = true;
            }
            
            // Add loading=lazy ONLY if not a potential LCP image and not already present
            $lazyLoading = '';
            if (!$isPossiblyLCP && strpos($beforeSrc . $afterSrc, 'loading=') === false) {
                $lazyLoading = ' loading="lazy"';
            }
            
            // Add image-cls-fix class to prevent CLS
            $clsFixClass = ' class="' . (strpos($beforeSrc . $afterSrc, 'class=') !== false ? 
                preg_replace('/class=[\'"](.*?)[\'"]/i', '$1 image-cls-fix', $beforeSrc . $afterSrc) : 
                'image-cls-fix') . '"';
            
            // If class attribute already exists, don't add a new one
            if (strpos($beforeSrc . $afterSrc, 'class=') !== false) {
                $beforeSrc = preg_replace('/class=[\'"](.*?)[\'"]/i', 'class="$1 image-cls-fix"', $beforeSrc);
                $clsFixClass = '';
            }
            
            // Create WebP URL if not already WebP
            if ($extension !== 'webp') {
                $webpSrc = substr($imgSrc, 0, strrpos($imgSrc, '.')) . '.webp';
                // Add cache parameter to WebP URL
                $webpSrc .= '?ttl=31536000';
                
                // Create picture element with WebP and fallback
                return '<picture>' .
                    '<source srcset="' . $webpSrc . '" type="image/webp">' .
                    '<img' . $beforeSrc . 'src="' . $cachedImgSrc . '"' . $afterSrc . $clsFixClass . $lazyLoading . '>' .
                    '</picture>';
            } else {
                // It's already WebP, just add caching, cls fix and lazy loading if needed
                return '<img' . $beforeSrc . 'src="' . $cachedImgSrc . '"' . $afterSrc . $clsFixClass . $lazyLoading . '>';
            }
        },
        $html
    );
    
    return $html;
}
/**
 * Get image dimensions (width and height)
 *
 * @param string $imageUrl
 * @return array|null Array with width and height or null if dimensions can't be determined
 */
private function getImageDimensions($imageUrl)
{
    // Try to get from cache first
    $cacheKey = md5($imageUrl);
    $dimensionsCache = $this->getCache($cacheKey);
    
    if ($dimensionsCache) {
        return json_decode($dimensionsCache, true);
    }
    
    // Download the image temporarily to get dimensions
    try {
        // Download image to temporary file
        $tempFile = tempnam(sys_get_temp_dir(), 'img_');
        $ch = curl_init($imageUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $imgData = curl_exec($ch);
        curl_close($ch);
        
        if (!$imgData) {
            return null;
        }
        
        file_put_contents($tempFile, $imgData);
        
        // Get dimensions
        list($width, $height) = getimagesize($tempFile);
        
        // Clean up
        @unlink($tempFile);
        
        if ($width && $height) {
            $dimensions = [
                'width' => $width,
                'height' => $height
            ];
            
            // Cache the result
            $this->setCache($cacheKey, json_encode($dimensions), 86400); // Cache for 24 hours
            
            return $dimensions;
        }
        
    } catch (\Exception $e) {
        $this->helper->log('Error getting image dimensions: ' . $e->getMessage(), 'error');
    }
    
    return null;
}

/**
 * Get value from cache
 *
 * @param string $key
 * @return string|false
 */
private function getCache($key)
{
    // Simple file cache implementation
    $cacheDir = BP . '/var/cache/cdn_images/';
    $cacheFile = $cacheDir . $key;
    
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0777, true);
    }
    
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 86400)) {
        return file_get_contents($cacheFile);
    }
    
    return false;
}

/**
 * Set value in cache
 *
 * @param string $key
 * @param string $value
 * @param int $ttl Time to live in seconds
 * @return bool
 */
private function setCache($key, $value, $ttl = 3600)
{
    $cacheDir = BP . '/var/cache/cdn_images/';
    $cacheFile = $cacheDir . $key;
    
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0777, true);
    }
    
    return (bool)file_put_contents($cacheFile, $value);
}
}