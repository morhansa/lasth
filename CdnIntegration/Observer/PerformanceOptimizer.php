<?php
namespace MagoArab\CdnIntegration\Observer;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use MagoArab\CdnIntegration\Helper\Data;
use Magento\Framework\App\Config\ScopeConfigInterface;

class PerformanceOptimizer implements ObserverInterface
{
    /**
     * @var Data
     */
    protected $helper;
    
    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;
    
    /**
     * @param Data $helper
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        Data $helper,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->helper = $helper;
        $this->scopeConfig = $scopeConfig;
    }
    
    /**
     * Optimize page performance
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        if (!$this->helper->isEnabled() || !$this->helper->isPerformanceOptimizationEnabled() || $this->isCachedPage()) {
            return;
        }

        $response = $observer->getEvent()->getResponse();
        if (!$response) {
            return;
        }
// Check the content type (HTML processing only)
    $contentType = $response->getHeader('Content-Type');
    if ($contentType && !preg_match('/text\/html/', $contentType->getFieldValue())) {
        return;
    }
	
        $html = $response->getBody();
        if (empty($html)) {
            return;
        }

 if (strlen($html) > 1000000) { // 1MB
        // Applying only minor improvements
        $html = $this->applyLightweightOptimizations($html);
        $response->setBody($html);
        return;
    }

        // Log performance start time
        $startTime = microtime(true);
        $this->helper->log("Performance optimization started", 'info');

        // 1. Add script error handling first (always do this first)
        $html = $this->addScriptErrorHandling($html);
        $this->helper->log("Script error handling added", 'info');
        
        // 2. Fix Content Security Policy issues (security fixes should be early)
        $html = $this->fixContentSecurityPolicy($html);
        $this->helper->log("Content Security Policy issues fixed", 'info');

        // 3. Check if we should use extreme progressive loading
        $useProgressiveLoading = $this->helper->isProgressiveLoadingEnabled();
        
        if ($useProgressiveLoading) {
            // If using extreme optimization, just do that and skip other optimizations
            // that might conflict with it
            $html = $this->forceProgressiveLoading($html);
            $this->helper->log("Forced progressive loading applied", 'info');
        } else {
            // Standard optimizations when not using extreme progressive loading
            
            // 4. Optimize network payloads (large JS files)
            $html = $this->optimizeNetworkPayloads($html);
            $this->helper->log("Network payloads optimized", 'info');
            
            // 5. Implement HTML streaming for faster initial render
            $html = $this->implementHtmlStreaming($html);
            $this->helper->log("HTML streaming implemented", 'info');
            
            // 6. Optimize images - use either advanced or standard method, not both
            if ($this->helper->isImageOptimizationEnabled()) {
                // Use the more advanced method that works with any theme
                $html = $this->optimizeImagesAdvanced($html);
                $this->helper->log("Images optimized with advanced technique", 'info');
            }
            
            // 7. Optimize JavaScript if enabled
     if ($this->helper->isJsOptimizationEnabled()) {
        // Apply the enhanced JS optimization
        $html = $this->optimizeJavaScript($html);
        
        // Apply TBT reduction if enabled
        if ($this->helper->scopeConfig->isSetFlag(
            'magoarab_cdn/performance_optimization/web_worker_optimization',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        )) {
            $html = $this->reduceTBT($html);
        }
    }
            
            // 8. Optimize Google Tag Manager and analytics
            $html = $this->optimizeGtmAdvanced($html);
            $this->helper->log("GTM and analytics optimized", 'info');
            
            // 9. Optimize critical path if enabled
            if ($this->helper->isCriticalPathOptimizationEnabled()) {
                $html = $this->optimizeCriticalPath($html);
                $this->helper->log("Critical path optimization applied", 'info');
            }
            
            // 10. Optimize tracking scripts (always apply when performance optimization is enabled)
            $html = $this->optimizeTracking($html);
            $this->helper->log("Tracking scripts optimization applied", 'info');
            
            // 11. Fix layout shift issues (always apply)
            $html = $this->fixLayoutShift($html);
            $this->helper->log("Layout shift fixes applied", 'info');
            
            // 12. Standard progressive loading (if not using extreme version)
            $html = $this->prioritizeAboveTheFold($html);
            $this->helper->log("Above-the-fold content prioritized", 'info');
            
            $html = $this->implementProgressiveLoading($html);
            $this->helper->log("Progressive loading implemented", 'info');
        }

        // Set the modified HTML
        $response->setBody($html);
        
        // Log completion time
        $endTime = microtime(true);
        $executionTime = round(($endTime - $startTime) * 1000, 2);
        $this->helper->log("Performance optimization completed in {$executionTime}ms", 'info');
    }
	/**
 * Prioritize above-the-fold content loading
 *
 * @param string $html
 * @return string
 */
private function prioritizeAboveTheFold($html)
{
    // Extract the <head> section
    preg_match('/<head>(.*?)<\/head>/s', $html, $headMatches);
    $head = $headMatches[1] ?? '';
    // Extract critical CSS (first stylesheet)
    preg_match('/<link[^>]*rel=[\'"]stylesheet[\'"][^>]*href=[\'"]([^\'"]+)[\'"][^>]*>/i', $head, $cssMatch);
    $criticalCssUrl = $cssMatch[1] ?? '';
    // Create a script to inline critical CSS and defer everything else
    $criticalCssLoader = '
    <script>
    // Critical CSS loader
    (function() {
        // Inline critical CSS
        var criticalCssUrl = "' . $criticalCssUrl . '";
        if (criticalCssUrl) {
            var xhr = new XMLHttpRequest();
            xhr.open("GET", criticalCssUrl, true);
            xhr.onload = function() {
                if (xhr.status >= 200 && xhr.status < 400) {
                    var style = document.createElement("style");
                    style.textContent = xhr.responseText;
                    document.head.appendChild(style);
                }
            };
            xhr.send();
        }
        // Function to check if element is in viewport
        function isInViewport(el) {
            if (!el) return false;
            var rect = el.getBoundingClientRect();
            return (
                rect.top <= (window.innerHeight || document.documentElement.clientHeight) &&
                rect.left <= (window.innerWidth || document.documentElement.clientWidth)
            );
        }
        // Load images when they come into view
        function lazyLoadImages() {
            var lazyImages = [].slice.call(document.querySelectorAll("img[data-lazy-src]"));
            if ("IntersectionObserver" in window) {
                var imageObserver = new IntersectionObserver(function(entries, observer) {
                    entries.forEach(function(entry) {
                        if (entry.isIntersecting) {
                            var lazyImage = entry.target;
                            lazyImage.src = lazyImage.dataset.lazySrc;
                            if (lazyImage.dataset.lazySrcset) {
                                lazyImage.srcset = lazyImage.dataset.lazySrcset;
                            }
                            lazyImage.removeAttribute("data-lazy-src");
                            lazyImage.removeAttribute("data-lazy-srcset");
                            imageObserver.unobserve(lazyImage);
                        }
                    });
                });
                lazyImages.forEach(function(lazyImage) {
                    imageObserver.observe(lazyImage);
                });
            } else {
                // Fallback for browsers without intersection observer
                var active = false;
                function lazyLoad() {
                    if (active === false) {
                        active = true;
                        setTimeout(function() {
                            lazyImages.forEach(function(lazyImage) {
                                if (isInViewport(lazyImage)) {
                                    lazyImage.src = lazyImage.dataset.lazySrc;
                                    if (lazyImage.dataset.lazySrcset) {
                                        lazyImage.srcset = lazyImage.dataset.lazySrcset;
                                    }
                                    lazyImage.removeAttribute("data-lazy-src");
                                    lazyImage.removeAttribute("data-lazy-srcset");
                                    lazyImages = lazyImages.filter(function(image) {
                                        return image !== lazyImage;
                                    });
                                    if (lazyImages.length === 0) {
                                        document.removeEventListener("scroll", lazyLoad);
                                        window.removeEventListener("resize", lazyLoad);
                                        window.removeEventListener("orientationchange", lazyLoad);
                                    }
                                }
                            });
                            active = false;
                        }, 200);
                    }
                }
                document.addEventListener("scroll", lazyLoad);
                window.addEventListener("resize", lazyLoad);
                window.addEventListener("orientationchange", lazyLoad);
                lazyLoad();
            }
        }
        // Lazy load HTML elements
        function lazyLoadElements() {
            var lazyElements = [].slice.call(document.querySelectorAll("[data-lazy-html]"));
            if ("IntersectionObserver" in window) {
                var elementObserver = new IntersectionObserver(function(entries, observer) {
                    entries.forEach(function(entry) {
                        if (entry.isIntersecting) {
                            var lazyElement = entry.target;
                            lazyElement.innerHTML = lazyElement.dataset.lazyHtml;
                            lazyElement.removeAttribute("data-lazy-html");
                            elementObserver.unobserve(lazyElement);
                        }
                    });
                });
                lazyElements.forEach(function(lazyElement) {
                    elementObserver.observe(lazyElement);
                });
            } else {
                // Fallback for older browsers
                function checkElements() {
                    lazyElements.forEach(function(lazyElement) {
                        if (isInViewport(lazyElement)) {
                            lazyElement.innerHTML = lazyElement.dataset.lazyHtml;
                            lazyElement.removeAttribute("data-lazy-html");
                            lazyElements = lazyElements.filter(function(element) {
                                return element !== lazyElement;
                            });
                        }
                    });
                    if (lazyElements.length === 0) {
                        document.removeEventListener("scroll", checkElements);
                    }
                }
                document.addEventListener("scroll", checkElements);
                checkElements();
            }
        }
        // Run when DOM is loaded
        document.addEventListener("DOMContentLoaded", function() {
            lazyLoadImages();
            lazyLoadElements();
        });
    })();
    </script>
    ';
    // Add the critical CSS loader to head
    $html = str_replace('</head>', $criticalCssLoader . '</head>', $html);
    // Modify image tags for lazy loading
    $html = preg_replace_callback(
        '/<img([^>]*)src=[\'"]((?!data:)[^\'"]+)[\'"]((?!loading=|data-lazy-src)[^>]*)>/i',
        function($matches) {
            $before = $matches[1];
            $src = $matches[2];
            $after = $matches[3];
            // Skip images that are likely to be in the viewport
            if (strpos($before . $after, 'above-the-fold') !== false) {
                return $matches[0];
            }
            // Extract srcset if it exists
            $srcset = '';
            $srcsetAttr = '';
            if (preg_match('/srcset=[\'"](.*?)[\'"]/i', $before . $after, $srcsetMatch)) {
                $srcset = $srcsetMatch[1];
                $srcsetAttr = ' data-lazy-srcset="' . $srcset . '"';
                // Remove the original srcset
                $before = preg_replace('/srcset=[\'"](.*?)[\'"]/i', '', $before);
                $after = preg_replace('/srcset=[\'"](.*?)[\'"]/i', '', $after);
            }
            // Create placeholder
            $placeholder = 'data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 1 1\'%3E%3C/svg%3E';
            // Return lazy loading image
            return '<img' . $before . 'src="' . $placeholder . '" data-lazy-src="' . $src . '"' . $srcsetAttr . ' loading="lazy"' . $after . '>';
        },
        $html
    );
    // Lazy load non-critical HTML blocks
    $html = preg_replace_callback(
        '/<div([^>]*)class=[\'"](.*?footer|widget|sidebar|additional|block-bottom|newsletter|social-links|copyright|links|menu-footer|secondary.*?)[\'"](.*?)>(.*?)<\/div>/is',
        function($matches) {
            $before = $matches[1];
            $class = $matches[2];
            $after = $matches[3];
            $content = $matches[4];
            // Skip small content blocks
            if (strlen($content) < 500) {
                return $matches[0];
            }
            // Create a placeholder
            return '<div' . $before . 'class="' . $class . '"' . $after . ' data-lazy-html="' . htmlspecialchars($content, ENT_QUOTES) . '"></div>';
        },
        $html
    );
    return $html;
}
/**
 * Aggressively optimize JavaScript execution time
 *
 * @param string $html
 * @return string
 */
private function optimizeJavaScript($html)
{
// 1. Splitting large files - very important
    $html = preg_replace_callback(
        '/<script[^>]*src=[\'"]((?:https?:)?\/\/cdn\.jsdelivr\.net\/gh\/[^\/]+\/[^\/]+@[^\/]+\/_cache\/merged\/[^\'"]+\.min\.js)[\'"][^>]*><\/script>/i',
        function($matches) {
            $src = $matches[1];
            return '<script type="module">
                // Dynamic script loading with execution throttling
                (async () => {
                    try {
                        const response = await fetch("' . $src . '");
                        const text = await response.text();
                        // Split into smaller chunks (50KB per chunk)
                        const chunkSize = 50000;
                        const chunks = [];
                        for (let i = 0; i < text.length; i += chunkSize) {
                            chunks.push(text.slice(i, i + chunkSize));
                        }
                        // Execute chunks with yield to main thread
                        for (let i = 0; i < chunks.length; i++) {
                            await new Promise(resolve => {
                                setTimeout(() => {
                                    try {
                                        new Function(chunks[i])();
                                    } catch (e) {
                                        console.error("Error in chunk", i, e);
                                    }
                                    resolve();
                                }, 0);
                            });
                            // Yield to main thread every chunk
                            await new Promise(resolve => setTimeout(resolve, 1));
                        }
                        console.log("Loaded and executed: ' . $src . '");
                    } catch (e) {
                        console.error("Failed to load", e);
                        // Fallback
                        const script = document.createElement("script");
                        script.src = "' . $src . '";
                        document.head.appendChild(script);
                    }
                })();
            </script>';
        },
        $html
    );

    // 2. Radically improve RequireJS loading
    $requireOptimizer = '
    <script>
    // RequireJS performance booster
    (function() {
        if (window.require) {
            // Cache original require
            var originalRequire = window.require;
            var originalDefine = window.define;
            var moduleCache = {};
            var loadingModules = {};
            
            // Throttle module executions
            window.require = function() {
                var args = Array.prototype.slice.call(arguments);
                // For require calls with dependencies
                if (Array.isArray(args[0])) {
                    var dependencies = args[0];
                    // Divide dependencies into critical and non-critical
                    var criticalDeps = dependencies.filter(function(dep) {
                        return dep.indexOf("jquery") !== -1 || 
                               dep.indexOf("mage/") !== -1 || 
                               dep.indexOf("Magento_") !== -1;
                    });
                    var nonCriticalDeps = dependencies.filter(function(dep) {
                        return criticalDeps.indexOf(dep) === -1;
                    });
                    
                    // Load critical dependencies immediately
                    if (criticalDeps.length > 0) {
                        return originalRequire.apply(window, [criticalDeps, function() {
                            var criticalResults = arguments;
                            // Load non-critical after a delay
                            if (nonCriticalDeps.length > 0) {
                                setTimeout(function() {
                                    originalRequire.call(window, nonCriticalDeps, function() {
                                        // Combine results and call original callback
                                        var nonCriticalResults = arguments;
                                        var allResults = [];
                                        for (var i = 0; i < criticalResults.length; i++) {
                                            allResults.push(criticalResults[i]);
                                        }
                                        for (var j = 0; j < nonCriticalResults.length; j++) {
                                            allResults.push(nonCriticalResults[j]);
                                        }
                                        if (typeof args[1] === "function") {
                                            args[1].apply(window, allResults);
                                        }
                                    });
                                }, 100);
                            } else if (typeof args[1] === "function") {
                                // Call callback with critical results only
                                args[1].apply(window, criticalResults);
                            }
                        }]);
                    } else if (nonCriticalDeps.length > 0) {
                        // Only non-critical deps, delay loading
                        return setTimeout(function() {
                            originalRequire.apply(window, args);
                        }, 200);
                    }
                }
                // Default require behavior
                return originalRequire.apply(window, args);
            };
            
            // Copy all properties from original require
            for (var prop in originalRequire) {
                if (originalRequire.hasOwnProperty(prop)) {
                    window.require[prop] = originalRequire[prop];
                }
            }
        }
    })();
    </script>
    ';
    
    // Add RequireJS optimizer after loading RequireJS library directly
    $html = preg_replace(
        '/(<script[^>]*src=[\'"][^\'"]*require\.js[\'"][^>]*><\/script>)/',
        '$1' . $requireOptimizer,
        $html
    );

   // 3. Postponing early script analysis
    $parseDelayer = '
    <script>
    // Delay parsing of non-critical scripts
    document.addEventListener("DOMContentLoaded", function() {
        var scripts = document.querySelectorAll("script[src]:not([async]):not([defer]):not([type=\'module\'])");
        scripts.forEach(function(script) {
            if (script.src.indexOf("require.js") === -1 && 
                script.src.indexOf("jquery") === -1) {
                script.setAttribute("defer", "");
            }
        });
    });
    </script>
    ';
    
    $html = str_replace('<head>', '<head>' . $parseDelayer, $html);

    // 4. Reduce GTM and analytics script interference
    $html = preg_replace_callback(
        '/<script[^>]*src=[\'"]((?:https?:)?\/\/(?:www\.)?googletagmanager\.com\/[^\'"]+)[\'"][^>]*><\/script>/i',
        function($matches) {
            $src = $matches[1];
            return '<script>
            // Delayed GTM loading
            window.addEventListener("load", function() {
                setTimeout(function() {
                    var gtmScript = document.createElement("script");
                    gtmScript.src = "' . $src . '";
                    gtmScript.async = true;
                    document.head.appendChild(gtmScript);
                }, 2000);
            });
            </script>';
        },
        $html
    );

    return $html;
}
private function reduceTBT($html)
{
    // Add a Web Worker for heavy operations
    $workerScript = '
    <script>
    // Web Worker for heavy computations
    (function() {
        // Create a background worker for heavy tasks
        var workerBlob = new Blob([`
            self.onmessage = function(e) {
                var data = e.data;
                switch (data.cmd) {
                    case "processData":
                        // Process any heavy computation here
                        var result = processLargeData(data.payload);
                        self.postMessage({id: data.id, result: result});
                        break;
                }
            };
            
            function processLargeData(data) {
                // This would contain CPU intensive work
                return data;
            }
        `], {type: "application/javascript"});
        
        var workerUrl = URL.createObjectURL(workerBlob);
        window.backgroundWorker = new Worker(workerUrl);
        
        // Task queue management
        var taskQueue = [];
        var taskIdCounter = 0;
        
        window.scheduleTask = function(payload) {
            return new Promise(function(resolve) {
                var taskId = "task_" + (taskIdCounter++);
                
                // Add task to queue
                taskQueue.push({
                    id: taskId,
                    resolve: resolve,
                    payload: payload
                });
                
                // Setup listener for this task
                backgroundWorker.addEventListener("message", function handler(e) {
                    if (e.data.id === taskId) {
                        backgroundWorker.removeEventListener("message", handler);
                        resolve(e.data.result);
                    }
                });
                
                // Schedule processing if not already started
                if (taskQueue.length === 1) {
                    processNextTask();
                }
            });
        };
        
        function processNextTask() {
            if (taskQueue.length === 0) return;
            
            var task = taskQueue.shift();
            backgroundWorker.postMessage({
                cmd: "processData",
                id: task.id,
                payload: task.payload
            });
            
            // Continue with next task after this one completes
            backgroundWorker.addEventListener("message", function handler(e) {
                if (e.data.id === task.id) {
                    backgroundWorker.removeEventListener("message", handler);
                    processNextTask();
                }
            });
        }
        
        // Cleanup
        window.addEventListener("beforeunload", function() {
            URL.revokeObjectURL(workerUrl);
        });
    })();
    </script>
    ';
    
    $html = str_replace('</body>', $workerScript . '</body>', $html);
    return $html;
}
/**
 * Advanced image optimization
 *
 * @param string $html
 * @return string
 */
private function optimizeImagesAdvanced($html)
{
    // Create LQIP (Low Quality Image Placeholders) system
    $lqipSystem = '
    <script>
    // Progressive image loading system
    (function() {
        var imgObserver;
        var lazyImages = [];
        function setupObserver() {
            if ("IntersectionObserver" in window) {
                imgObserver = new IntersectionObserver(function(entries) {
                    entries.forEach(function(entry) {
                        if (entry.isIntersecting) {
                            loadImage(entry.target);
                            imgObserver.unobserve(entry.target);
                        }
                    });
                }, {
                    rootMargin: "200px" // Load images 200px before they appear
                });
            }
        }
        function loadImage(img) {
            var src = img.dataset.src;
            if (!src) return;
            // Create a temporary image element for preloading
            var tempImg = new Image();
            tempImg.onload = function() {
                // Update the actual image after loading the temporary image
                img.src = src;
                img.removeAttribute("data-src");
                if (img.dataset.srcset) {
                    img.srcset = img.dataset.srcset;
                    img.removeAttribute("data-srcset");
                }
                // Add a class for visual effect
                img.classList.add("img-loaded");
            };
            tempImg.onerror = function() {
                console.error("Failed to load image:", src);
                // Update the image anyway to prevent it from being stuck in loading state
                img.src = src;
                img.removeAttribute("data-src");
            };
            // Start loading the image
            tempImg.src = src;
        }
        function processPendingImages() {
            if (imgObserver) {
                lazyImages.forEach(function(img) {
                    imgObserver.observe(img);
                });
            } else {
                // Alternative plan for browsers that do not support IntersectionObserver
                lazyImages.forEach(function(img) {
                    loadImage(img);
                });
            }
        }
        // Setting up the Thumbnails system to reduce CLS
        function setupThumbnailSystem() {
            var style = document.createElement("style");
            style.textContent = `
                .lazy-image-container {
                    position: relative;
                    overflow: hidden;
                    background-color: #f0f0f0;
                }
                .lazy-image-container img {
                    transition: opacity 0.3s ease;
                }
                .lazy-image-container img:not(.img-loaded) {
                    opacity: 0;
                }
                .lazy-image-container .img-placeholder {
                    position: absolute;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    filter: blur(8px);
                    transform: scale(1.05);
                    transition: opacity 0.3s ease;
                }
                .lazy-image-container .img-loaded + .img-placeholder {
                    opacity: 0;
                }
            `;
            document.head.appendChild(style);
        }
        // Register images for delayed loading
        function registerLazyImages() {
            lazyImages = Array.from(document.querySelectorAll("img[data-src]"));
            // Convert images to LQIP
            lazyImages.forEach(function(img) {
                if (img.complete && img.naturalWidth !== 0) {
                    // Image already loaded - skip
                    return;
                }
                // If the image is not already inside a container
                if (!img.parentNode.classList.contains("lazy-image-container")) {
                    // Save original dimensions
                    var width = img.width || 0;
                    var height = img.height || 0;
                    var aspectRatio = "";
                    if (width && height) {
                        aspectRatio = "padding-bottom: " + (height / width * 100) + "%;";
                    } else {
                        aspectRatio = "padding-bottom: 56.25%;"; // 16:9 ratio as fallback
                    }
                    // Create a container
                    var container = document.createElement("div");
                    container.className = "lazy-image-container";
                    container.style = aspectRatio;
                    // Create a placeholder image
                    var placeholder = document.createElement("div");
                    placeholder.className = "img-placeholder";
                    // Move image to container
                    img.parentNode.insertBefore(container, img);
                    container.appendChild(img);
                    container.appendChild(placeholder);
                }
            });
            processPendingImages();
        }
        // System configuration
        function init() {
            setupThumbnailSystem();
            setupObserver();
            registerLazyImages();
            // Rescan after page load
            window.addEventListener("load", registerLazyImages);
            // Rescan when new content is added
            var observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    if (mutation.addedNodes.length) {
                        registerLazyImages();
                    }
                });
            });
            observer.observe(document.body, { childList: true, subtree: true });
        }
        // Start when document is ready
        if (document.readyState !== "loading") {
            init();
        } else {
            document.addEventListener("DOMContentLoaded", init);
        }
    })();
    </script>
    ';
    // Add LQIP system
    $html = str_replace('</head>', $lqipSystem . '</head>', $html);
    
    // Convert images to delayed loading
    $html = preg_replace_callback(
        '/<img([^>]*)src=[\'"]((?!data:)[^\'"]+)[\'"]([^>]*)>/i',
        function($matches) {
            $beforeAttrs = $matches[1];
            $src = $matches[2];
            $afterAttrs = $matches[3];
            // Skip small images like logos and icons
            if (strpos($beforeAttrs . $afterAttrs, 'logo') !== false || 
                strpos($beforeAttrs . $afterAttrs, 'icon') !== false ||
                strpos($src, 'icon') !== false || 
                strpos($src, 'logo') !== false) {
                return $matches[0];
            }
            // Extract srcset if it exists
            $srcsetAttr = '';
            if (preg_match('/srcset=[\'"](.*?)[\'"]/i', $beforeAttrs . $afterAttrs, $srcsetMatch)) {
                $srcset = $srcsetMatch[1];
                $srcsetAttr = ' data-srcset="' . $srcset . '"';
                // Remove the original srcset
                $beforeAttrs = preg_replace('/srcset=[\'"](.*?)[\'"]/i', '', $beforeAttrs);
                $afterAttrs = preg_replace('/srcset=[\'"](.*?)[\'"]/i', '', $afterAttrs);
            }
            // Create a simple placeholder
            $placeholder = 'data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 1 1\'%3E%3C/svg%3E';
            // Create an image with delayed loading
            return '<img' . $beforeAttrs . 'src="' . $placeholder . '" data-src="' . $src . '"' . $srcsetAttr . ' loading="lazy"' . $afterAttrs . '>';
        },
        $html
    );
    
    // Special handling for Magento Fotorama product images
    $html = preg_replace_callback(
        '/<img([^>]*)data-src=[\'"]([^\'"]+\.(jpg|jpeg|png|gif))[\'"]([^>]*)class=[\'"]([^\'"]*fotorama__img[^\'"]*)[\'"]([^>]*)>/i',
        function($matches) {
            $beforeSrc = $matches[1];
            $src = $matches[2];
            $ext = $matches[3];
            $afterSrc = $matches[4];
            $class = $matches[5];
            $afterClass = $matches[6];
            
            // Add important attributes for critical images
            return '<img' . $beforeSrc . 'src="' . $src . '" data-large="' . $src . '"' . $afterSrc . 
                   'class="' . $class . ' product-critical-image"' . $afterClass . ' fetchpriority="high">';
        },
        $html
    );
    
    return $html;
}
/**
 * Advanced payload optimization with code splitting
 *
 * @param string $html
 * @return string
 */
private function optimizeNetworkPayloads($html)
{
    // 1. Identify large files (JS, CSS, images)
    $largeFiles = [];
    // 1.1 Searching for large JS files
    preg_match_all('/<script[^>]*src=[\'"]((?:https?:)?\/\/(?:[^\/]*(?:jsdelivr|cdn|static|_cache)[^\/]*)[^\'"]+)[\'"][^>]*><\/script>/i', $html, $jsMatches);
    foreach ($jsMatches[1] as $src) {
        $largeFiles[] = [
            'type' => 'js',
            'url' => $src
        ];
    }
    // 1.2 Finding large CSS files
    preg_match_all('/<link[^>]*rel=[\'"]stylesheet[\'"][^>]*href=[\'"]([^\'"]+)[\'"][^>]*>/i', $html, $cssMatches);
    foreach ($cssMatches[1] as $index => $src) {
        // The first CSS is considered critical, so we skip it.
        if ($index === 0) continue;
        $largeFiles[] = [
            'type' => 'css',
            'url' => $src
        ];
    }
    // Remove large files from HTML
    foreach ($largeFiles as $file) {
        if ($file['type'] === 'js') {
            $html = preg_replace('/<script[^>]*src=[\'"]' . preg_quote($file['url'], '/') . '[\'"][^>]*><\/script>/i', '', $html);
        } else if ($file['type'] === 'css' && strpos($file['url'], 'critical') === false) {
            $html = preg_replace('/<link[^>]*href=[\'"]' . preg_quote($file['url'], '/') . '[\'"][^>]*>/i', '', $html);
        }
    }
    // Convert data to JSON
    $largeFilesJson = json_encode($largeFiles);
    // Create an advanced download system
    $codeLoader = '
    <script>
    // Advanced resource loader for large files
    (function() {
        // List of files to load
        var filesToLoad = ' . $largeFilesJson . ';
        var loadedFiles = {};
        // Function to log timing
        function logTiming(label, url) {
            if (window.performance && window.performance.mark) {
                window.performance.mark(label + "-" + url.substring(0, 40));
            }
        }
        // Function to split and load a large JS file
        function loadAndSplitJsFile(url) {
            logTiming("start-load", url);
            return fetch(url)
                .then(response => {
                    logTiming("response-received", url);
                    return response.text();
                })
                .then(content => {
                    logTiming("content-parsed", url);
                    // Split the code into small chunks (about 100KB each)
                    var chunkSize = 100000;
                    var chunks = [];
                    for (var i = 0; i < content.length; i += chunkSize) {
                        chunks.push(content.slice(i, i + chunkSize));
                    }
                    console.log("🔄 File " + url + " split into " + chunks.length + " chunks");
                    // Execute chunks with a small delay between them
                    return new Promise((resolve) => {
                        var index = 0;
                        function executeNextChunk() {
                            if (index < chunks.length) {
                                try {
                                    // Use Function constructor to execute the code
                                    var scriptContent = chunks[index];
                                    new Function(scriptContent)();
                                    index++;
                                    // Small delay between chunks to prevent UI blocking
                                    setTimeout(executeNextChunk, 10);
                                } catch (e) {
                                    console.error("Error executing chunk " + index + " of " + url, e);
                                    index++;
                                    setTimeout(executeNextChunk, 10);
                                }
                            } else {
                                logTiming("execution-complete", url);
                                console.log("✅ Successfully loaded and executed: " + url);
                                resolve();
                            }
                        }
                        // Start executing chunks
                        executeNextChunk();
                    });
                })
                .catch(error => {
                    console.error("❌ Error loading file: " + url, error);
                    // Use traditional loading method as fallback
                    return new Promise((resolve) => {
                        var script = document.createElement("script");
                        script.src = url;
                        script.onload = function() {
                            console.log("✅ Loaded via fallback method: " + url);
                            resolve();
                        };
                        script.onerror = function() {
                            console.error("❌ Failed to load via fallback: " + url);
                            resolve(); // Continue even with error
                        };
                        document.head.appendChild(script);
                    });
                });
        }
        // Function to load CSS file
        function loadCssFile(url) {
            return new Promise((resolve) => {
                var link = document.createElement("link");
                link.rel = "stylesheet";
                link.href = url;
                link.onload = function() {
                    console.log("✅ CSS loaded: " + url);
                    resolve();
                };
                link.onerror = function() {
                    console.error("❌ Failed to load CSS: " + url);
                    resolve();
                };
                document.head.appendChild(link);
            });
        }
        // Function to load files by priority
        function loadFilesByPriority() {
            // Sort files by priority
            var cssFiles = filesToLoad.filter(file => file.type === "css");
            var jsFiles = filesToLoad.filter(file => file.type === "js");
            // Load CSS files first (because they affect visual presentation)
            Promise.all(cssFiles.map(file => loadCssFile(file.url)))
                .then(() => {
                    console.log("CSS files loaded, now loading JS files sequentially");
                    // Load JS files sequentially (one by one)
                    return jsFiles.reduce((promise, file) => {
                        return promise.then(() => {
                            // Skip already loaded files
                            if (loadedFiles[file.url]) {
                                console.log("⏩ Already loaded: " + file.url);
                                return Promise.resolve();
                            }
                            console.log("🔄 Now loading: " + file.url);
                            loadedFiles[file.url] = true;
                            return loadAndSplitJsFile(file.url);
                        });
                    }, Promise.resolve());
                })
                .then(() => {
                    console.log("✨ All files loaded successfully!");
                })
                .catch(error => {
                    console.error("Error in loading sequence:", error);
                });
        }
        // Log start of loading
        console.log("🚀 Initializing advanced resource loader");
        // Determine best time to load files
        function scheduleLoading() {
            // 1. Load after initial paint finishes
            if (document.readyState === "complete") {
                setTimeout(loadFilesByPriority, 500);
            } else {
                window.addEventListener("load", function() {
                    setTimeout(loadFilesByPriority, 500);
                });
            }
            // 2. Or when user interacts
            var hasInteracted = false;
            function onUserInteraction() {
                if (!hasInteracted) {
                    hasInteracted = true;
                    loadFilesByPriority();
                }
            }
            ["mousemove", "click", "keydown", "scroll", "touchstart"].forEach(function(eventType) {
                document.addEventListener(eventType, onUserInteraction, {once: true, passive: true});
            });
            // 3. Or when browser is idle
            if ("requestIdleCallback" in window) {
                requestIdleCallback(function() {
                    loadFilesByPriority();
                }, {timeout: 3000});
            } else {
                setTimeout(loadFilesByPriority, 3000);
            }
        }
        // Start loading scheduling
        scheduleLoading();
    })();
    </script>
    ';
    // Add loading system before closing head tag
    $html = str_replace('</head>', $codeLoader . '</head>', $html);
    return $html;
}
/**
 * Advanced analytics and GTM optimization
 *
 * @param string $html
 * @return string
 */
private function optimizeGtmAdvanced($html)
{
    // 1. Extract GTM codes
    preg_match_all('/<script[^>]*src=[\'"]((?:https?:)?\/\/(?:[^\/]*(?:google|gtag|gtm|analytics|facebook)[^\/]*)[^\'"]+)[\'"][^>]*><\/script>/i', $html, $matches, PREG_SET_ORDER);
    $analyticsScripts = [];
    foreach ($matches as $match) {
        $src = $match[1];
        $analyticsScripts[] = $src;
        // Remove original script
        $html = str_replace($match[0], '', $html);
    }
    // 2. Extract inline GTM scripts
    preg_match_all('/<script[^>]*>\s*(?:window\.dataLayer|window\.gtag|!function\(w,d,s,l,i\)|\(function\(w,d,s,l,i\)).*?<\/script>/s', $html, $inlineMatches);
    $inlineGtmScripts = [];
    foreach ($inlineMatches[0] as $script) {
        if (strpos($script, 'googletagmanager') !== false || 
            strpos($script, 'gtag') !== false || 
            strpos($script, 'dataLayer') !== false ||
            strpos($script, 'fbq') !== false ||
            strpos($script, 'google') !== false) {
            $inlineGtmScripts[] = $script;
            // Remove original script
            $html = str_replace($script, '', $html);
        }
    }
    // 3. Extract GTM ID
    $gtmId = '';
    foreach ($analyticsScripts as $script) {
        if (preg_match('/GTM-[A-Z0-9]+/i', $script, $idMatch)) {
            $gtmId = $idMatch[0];
            break;
        }
    }
    // 4. Create optimized loading system
    $gtmLoader = '
    <script>
    // Enhanced loading system for Google Tag Manager
    (function() {
        // Initialize dataLayer
        window.dataLayer = window.dataLayer || [];
        function gtag() {
            dataLayer.push(arguments);
        }
        // Configure Facebook Pixel
        window.fbq = window.fbq || function() {
            (window._fbq = window._fbq || []).push(arguments);
        };
        // List of analytics scripts
        var analyticsScripts = ' . json_encode($analyticsScripts) . ';
        var inlineScripts = ' . json_encode(array_map(function($script) {
            return preg_replace('/<\/?script[^>]*>/i', '', $script);
        }, $inlineGtmScripts)) . ';
        var gtmId = "' . $gtmId . '";
        var hasLoadedAnalytics = false;
        // Create stub gtag
        gtag("js", new Date());
        // Load analytics function
        function loadAnalytics() {
            if (hasLoadedAnalytics) return;
            hasLoadedAnalytics = true;
            console.log("📊 Loading analytics...");
            // 1. Load GTM in optimized way (if available)
            if (gtmId) {
                (function(w,d,s,l,i){
                    w[l]=w[l]||[];
                    w[l].push({"gtm.start":new Date().getTime(),event:"gtm.js"});
                    var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!="dataLayer"?"&l="+l:"";
                    j.async=true;
                    j.src="https://www.googletagmanager.com/gtm.js?id="+i+dl+"&gtm_auth=&gtm_preview=&gtm_cookies_win=x";
                    f.parentNode.insertBefore(j,f);
                })(window,document,"script","dataLayer",gtmId);
                console.log("✅ GTM loaded with ID:", gtmId);
            }
            // 2. Execute inline scripts
            inlineScripts.forEach(function(scriptText) {
                try {
                    new Function(scriptText)();
                } catch (e) {
                    console.error("Error executing inline script:", e);
                }
            });
            // 3. Load remaining external scripts
            analyticsScripts.forEach(function(src) {
                if (src.indexOf("gtm.js") !== -1 && gtmId) {
                    // Skip GTM if already loaded
                    return;
                }
                var script = document.createElement("script");
                script.async = true;
                script.src = src;
                document.head.appendChild(script);
                console.log("📊 Loading:", src);
            });
        }
        // Load after user interaction
        function onUserInteraction() {
            ["scroll", "click", "mousemove", "touchstart"].forEach(function(eventType) {
                document.removeEventListener(eventType, onUserInteraction, {passive: true});
            });
            setTimeout(loadAnalytics, 2000);
        }
        ["scroll", "click", "mousemove", "touchstart"].forEach(function(eventType) {
            document.addEventListener(eventType, onUserInteraction, {passive: true});
        });
        // Load after timeout regardless
        setTimeout(loadAnalytics, 5000);
        // Load when browser is idle
        if ("requestIdleCallback" in window) {
            requestIdleCallback(function() {
                loadAnalytics();
            }, {timeout: 5000});
        }
        // Handle events logged while waiting for GTM to load
        var originalPush = Array.prototype.push;
        dataLayer.push = function() {
            for (var i = 0; i < arguments.length; i++) {
                originalPush.call(this, arguments[i]);
                // Trigger immediate loading for purchase/conversion events
                var event = arguments[i] && arguments[i].event;
                if (event === "purchase" || event === "conversion" || event === "add_to_cart") {
                    loadAnalytics();
                }
            }
        };
    })();
    </script>
    ';
    // Add improved loading system
    $html = str_replace('</head>', $gtmLoader . '</head>', $html);
    return $html;
}

/**
 * Optimize tracking scripts
 *
 * @param string $html
 * @return string
 */
private function optimizeTracking($html)
{
    // Universal tracking scripts loader
    $trackingLoader = '
    <script>
    // Detect when page becomes idle or user interactions occur
    var idleTime = 0;
    function resetIdleTime() { idleTime = 0; }
    // Add all common user events to detect interaction
    ["mousemove", "keypress", "scroll", "click", "touchstart"].forEach(function(event) {
        document.addEventListener(event, resetIdleTime, { passive: true });
    });
    // Initialize tracking scripts only after page becomes idle or after user interaction
    function loadTracking() {
        if (window.trackingLoaded) return;
        window.trackingLoaded = true;
        // Find tracking script placeholders and replace with actual scripts
        document.querySelectorAll("[data-tracking-src]").forEach(function(placeholder) {
            var script = document.createElement("script");
            script.src = placeholder.getAttribute("data-tracking-src");
            script.async = true;
            document.head.appendChild(script);
            placeholder.parentNode.removeChild(placeholder);
        });
    }
    // Load tracking after 4 seconds of idle time or any user interaction
    setInterval(function() {
        idleTime += 1;
        if (idleTime >= 4) loadTracking();
    }, 1000);
    // Also load tracking on page idle or when user starts to leave page
    window.addEventListener("beforeunload", loadTracking);
    if ("requestIdleCallback" in window) {
        requestIdleCallback(loadTracking, { timeout: 5000 });
    } else {
        setTimeout(loadTracking, 5000);
    }
    </script>
    ';
    // Replace tracking scripts with placeholders
    $html = preg_replace_callback(
        '/<script([^>]*)src=[\'"]((?:https?:)?\/\/(?:[^\/]*(?:google-analytics|googletagmanager|facebook|fbcdn|analytics|pixel|gtm|tag)[^\/]*)[^\'"]+)[\'"]((?!noOptimize)[^>]*)><\/script>/i',
        function($matches) {
            $before = $matches[1];
            $src = $matches[2];
            $after = $matches[3];
            // Create placeholder for deferred loading
            return '<script data-tracking-src="' . $src . '" type="text/plain"></script>';
        },
        $html
    );
    // Add tracking loader before closing body
    $html = str_replace('</body>', $trackingLoader . '</body>', $html);
    return $html;
}
/**
 * Optimize critical path by adding preload directives
 *
 * @param string $html
 * @return string
 */
private function optimizeCriticalPath($html)
{
    // Extract the most important CSS and JavaScript resources
    $criticalResources = [];
    // Get main CSS files - first 2 are typically most critical
    preg_match_all('/<link[^>]*rel=[\'"]stylesheet[\'"][^>]*href=[\'"]([^\'"]+)[\'"][^>]*>/i', $html, $cssMatches);
    if (!empty($cssMatches[1])) {
        $criticalCss = array_slice($cssMatches[1], 0, 2);
        foreach ($criticalCss as $css) {
            if (strpos($css, 'print') === false) {  // Exclude print styles
                $criticalResources[] = [
                    'type' => 'style',
                    'href' => $css
                ];
            }
        }
    }
    // Get essential JS - jQuery and require.js are most critical
    preg_match_all('/<script[^>]*src=[\'"]([^\'"]+(?:require\.js|jquery[^\/]*\.js))[\'"][^>]*>/i', $html, $jsMatches);
    if (!empty($jsMatches[1])) {
        foreach ($jsMatches[1] as $js) {
            $criticalResources[] = [
                'type' => 'script',
                'href' => $js
            ];
        }
    }
    // Create preload tags
    $preloadTags = '';
    foreach ($criticalResources as $resource) {
        $type = $resource['type'];
        $href = $resource['href'];
        $preloadTags .= '<link rel="preload" href="' . $href . '" as="' . $type . '" crossorigin="anonymous">' . PHP_EOL;
    }
    
    // Add HTTP/2 Server Push hints if enabled
    if ($this->helper->isHttp2PushEnabled()) {
        $linkHeaderValues = [];
        foreach ($criticalResources as $resource) {
            $type = $resource['type'];
            $href = $resource['href'];
            $linkHeaderValues[] = '<' . $href . '>; rel=preload; as=' . $type;
        }
        
        if (!empty($linkHeaderValues)) {
            // Add Link header for HTTP/2 Server Push
            $linkHeader = implode(', ', $linkHeaderValues);
            header('Link: ' . $linkHeader);
        }
    }
    
    // Add preload tags to head
    $html = str_replace('</head>', $preloadTags . '</head>', $html);
    return $html;
}

/**
 * Implement HTML streaming for faster initial render
 *
 * @param string $html
 * @return string
 */
private function implementHtmlStreaming($html)
{
    // Extract head content
    preg_match('/<head>(.*?)<\/head>/s', $html, $headMatches);
    $headContent = $headMatches[1] ?? '';
    // Extract critical CSS
    preg_match('/<style[^>]*>(.*?)<\/style>/s', $headContent, $styleMatches);
    $criticalCss = $styleMatches[1] ?? '';
    // Extract critical parts of the page (above the fold)
    preg_match('/<body[^>]*>(.*?)<div[^>]*class=[\'"](?:footer|sidebar|secondary)/is', $html, $bodyMatches);
    $aboveTheFold = $bodyMatches[1] ?? '';
    // Create streaming HTML template
    $streamingTemplate = '
    <script>
    // HTML Streaming for faster initial render
    (function() {
        // Store original HTML rendering
        var originalRender = window.requestAnimationFrame;
        // Speed up first paint
        window.requestAnimationFrame = function(callback) {
            setTimeout(callback, 0);
        };
        // Restore original rendering after first paint
        setTimeout(function() {
            window.requestAnimationFrame = originalRender;
        }, 100);
        // Add flush hint for browsers
        document.documentElement.style.display = "block";
    })();
    </script>
    ';
    // Add streaming template at the top of head
    $html = str_replace('<head>', '<head>' . $streamingTemplate, $html);
    // Add flushing hint for Magento
    $flushingHint = '
    <!-- FLUSH BUFFER HERE FOR FASTER INITIAL RENDER -->
    <script>
    // Tell browser we\'re ready to render
    document.documentElement.style.visibility = "visible";
    </script>
    ';
    // Add flushing hint after key elements
    $html = str_replace('</head>', '</head>' . $flushingHint, $html);
    return $html;
}
/**
 * Fix layout shift (CLS) issues
 *
 * @param string $html
 * @return string
 */
private function fixLayoutShift($html)
{
    // Add CSS to fix layout shift issues
    $clsFixStyles = '
    <style>
        /* Fix CLS for rows and columns */
        [data-content-type="row"][data-appearance="contained"][data-element="main"] {
            overflow: hidden;
            box-sizing: border-box;
            contain: layout style;
        }
        /* Set aspect ratios for images that may cause shifts */
        .image-cls-fix, .pagebuilder-mobile-hidden {
            aspect-ratio: 16/9; /* Default aspect ratio */
            max-width: 100%;
            height: auto;
            contain: strict;
        }
        /* Fix for banners */
        .porto-ibanner a {
            position: relative;
            display: block;
            overflow: hidden;
            contain: layout;
        }
        /* Fix for specific images */
        img[alt="WhatsApp Chat"] {
            width: 60px !important;
            height: 60px !important;
        }
        /* Fix for product images */
        .product-image-container {
            min-height: 150px;
            position: relative;
            height: 0;
            overflow: hidden;
        }
        /* CLS fixes for Magento default gallery */
        .gallery-placeholder {
            position: relative;
            min-height: 300px;
        }
        /* Fix for Fotorama slider */
        .fotorama__stage {
            min-height: 300px;
        }
        /* Fix for loading indicators */
        .loading-mask {
            position: fixed;
        }
    </style>
    ';
    // Add CSS to head
    $html = str_replace('</head>', $clsFixStyles . '</head>', $html);
    
    // Fix sizes on product images to prevent layout shift
    $html = preg_replace_callback(
        '/<img([^>]*)class=[\'"](.*?(?:product-image|category-image|gallery-placeholder)[^\'"]*)[\'"](.*?)>/i',
        function($matches) {
            $before = $matches[1];
            $class = $matches[2];
            $after = $matches[3];
            
            // Don't add if already has width/height
            if (strpos($before . $after, 'width=') !== false && strpos($before . $after, 'height=') !== false) {
                return $matches[0];
            }
            
            // Add width and height to prevent CLS
            if (strpos($class, 'product-image') !== false) {
                return '<img' . $before . 'class="' . $class . ' image-cls-fix"' . $after . ' width="300" height="300">';
            } else {
                return '<img' . $before . 'class="' . $class . ' image-cls-fix"' . $after . '>';
            }
        },
        $html
    );
    
    // Add placeholders for images to reduce CLS
    $html = preg_replace_callback(
        '/<div[^>]*class=[\'"](.*?(?:product-image-wrapper|image-container)[^\'"]*)[\'"](.*?)>/i',
        function($matches) {
            $before = $matches[1];
            $after = $matches[2];
            
            // Add attributes to prevent layout shift
            return '<div class="' . $before . '" style="min-height:200px; position:relative;"' . $after . '>';
        },
        $html
    );
    
    return $html;
}

/**
 * Add error handling for scripts
 *
 * @param string $html
 * @return string
 */
private function addScriptErrorHandling($html)
{
    $errorHandlingScript = '
    <script>
    // Fix common JavaScript errors
    (function() {
        // Create a safer querySelector/querySelectorAll that doesn\'t throw when element not found
        var originalQuerySelector = Document.prototype.querySelector;
        var originalQuerySelectorAll = Document.prototype.querySelectorAll;
        Document.prototype.querySelector = function(selector) {
            try {
                return originalQuerySelector.call(this, selector);
            } catch(e) {
                console.warn("Error in querySelector for: " + selector);
                return null;
            }
        };
        Document.prototype.querySelectorAll = function(selector) {
            try {
                return originalQuerySelectorAll.call(this, selector);
            } catch(e) {
                console.warn("Error in querySelectorAll for: " + selector);
                return [];
            }
        };
        // Fix addEventListener on null elements
        var originalAddEventListener = EventTarget.prototype.addEventListener;
        EventTarget.prototype.addEventListener = function(type, listener, options) {
            if (this === null || this === undefined) {
                console.warn("Cannot add event listener to null/undefined element");
                return;
            }
            return originalAddEventListener.call(this, type, listener, options);
        };
        // Polyfill for missing functions that might cause errors
        window.fbq = window.fbq || function() { 
            console.log("Facebook pixel not loaded yet"); 
        };
        // Fix common tracking script errors
        window.ga = window.ga || function() {};
        window.gtag = window.gtag || function() {};
        window._fbq = window._fbq || function() {};
    })();
    </script>
    ';
    // Add script to head (before other scripts)
    $html = str_replace('<head>', '<head>' . $errorHandlingScript, $html);
    return $html;
}
/**
 * Fix Content Security Policy issues
 *
 * @param string $html
 * @return string
 */
private function fixContentSecurityPolicy($html)
{
    // Create a meta tag to extend the CSP
    $cspMeta = '<meta http-equiv="Content-Security-Policy" content="' .
        'connect-src ' . $this->getCSPConnectSrcDomains() . ' \'self\'; ' .
        'img-src ' . $this->getCSPImgSrcDomains() . ' data: \'self\'; ' .
        'script-src ' . $this->getCSPScriptSrcDomains() . ' \'unsafe-inline\' \'unsafe-eval\' \'self\'; ' .
        'style-src ' . $this->getCSPStyleSrcDomains() . ' \'unsafe-inline\' \'self\'; ' .
        'frame-src ' . $this->getCSPFrameSrcDomains() . ' \'self\'; ' .
        'worker-src blob: \'self\'; ' .
        'child-src blob: \'self\'; ' .
        'font-src * data: \'self\'' .
        '">';
    // Add meta tag to head
    $html = str_replace('<head>', '<head>' . $cspMeta, $html);
    return $html;
}

/**
 * Get domains for connect-src CSP directive
 *
 * @return string
 */
private function getCSPConnectSrcDomains()
{
    return '*.google.com *.google-analytics.com *.analytics.google.com *.googletagmanager.com ' .
           '*.doubleclick.net *.facebook.com *.facebook.net *.fbcdn.net connect.facebook.net ' . 
           '*.googleapis.com *.gstatic.com *.ccm.collect *.nr-data.net *.newrelic.com';
}

/**
 * Get domains for img-src CSP directive
 *
 * @return string
 */
private function getCSPImgSrcDomains()
{
    return '*.google.com *.google-analytics.com *.googletagmanager.com *.google.com.eg ' .
           '*.googleapis.com *.gstatic.com *.doubleclick.net *.google.com ' .
           '*.facebook.com *.facebook.net *.fbcdn.net';
}

/**
 * Get domains for script-src CSP directive
 * 
 * @return string
 */
private function getCSPScriptSrcDomains()
{
    return '*.google.com *.google-analytics.com *.googletagmanager.com *.googleapis.com ' .
           '*.gstatic.com *.doubleclick.net *.facebook.com *.facebook.net connect.facebook.net ' .
           '*.fbcdn.net *.tabby.ai *.jsdelivr.net';
}

/**
 * Get domains for style-src CSP directive
 *
 * @return string
 */
private function getCSPStyleSrcDomains()
{
    return '*.googleapis.com *.gstatic.com *.jsdelivr.net';
}

/**
 * Get domains for frame-src CSP directive
 *
 * @return string
 */
private function getCSPFrameSrcDomains()
{
    return '*.doubleclick.net *.google.com *.facebook.com *.facebook.net';
}

/**
 * Force progressive loading with direct DOM manipulation
 *
 * @param string $html
 * @return string
 */
private function forceProgressiveLoading($html)
{
    // 1. Extract above-the-fold content
    preg_match('/<body[^>]*>(.*?)(?:<div[^>]*(?:class|id)=[\'"](?:footer|sidebar|secondary|menu-footer|newsletter))/is', $html, $aboveMatches);
    $aboveFold = $aboveMatches[1] ?? '';
    // 2. Create a minimal shell page
    $shellOpen = '<!DOCTYPE html><html><head>';
    // 3. Extract critical CSS
    preg_match_all('/<link[^>]*rel=[\'"]stylesheet[\'"][^>]*href=[\'"]([^\'"]+)[\'"][^>]*>/i', $html, $cssMatches);
    $firstCssUrl = $cssMatches[1][0] ?? '';
    // 4. Create critical CSS loader
    $criticalCssLoader = '
    <style>
    /* Basic styles for shell rendering */
    body { opacity: 1; transition: opacity 0.2s; display: block; }
    .lazy-content { opacity: 0; transition: opacity 0.5s; }
    .lazy-content.loaded { opacity: 1; }
    /* Placeholder styles */
    .image-placeholder {
        background-color: #f0f0f0;
        display: inline-block;
        position: relative;
    }
    /* Spinner for loading state */
    .loading-spinner {
        width: 40px;
        height: 40px;
        margin: 20px auto;
        border: 4px solid rgba(0, 0, 0, 0.1);
        border-left-color: #7986cb;
        border-radius: 50%;
        animation: spin 1s linear infinite;
        position: absolute;
        top: 50%;
        left: 50%;
        margin-top: -20px;
        margin-left: -20px;
    }
    @keyframes spin {
        to { transform: rotate(360deg); }
    }
    </style>
    <!-- Fetch and apply critical CSS -->
    <script>
    (function() {
        // Critical CSS URL
        var cssUrl = "' . $firstCssUrl . '";
        // Fetch critical CSS
        if (cssUrl) {
            fetch(cssUrl)
                .then(function(response) {
                    return response.text();
                })
                .then(function(css) {
                    // Extract only critical selectors (header, banner, first visible elements)
                    var criticalSelectors = [
                        "body", "header", ".logo", ".navigation", ".banner", 
                        ".block-search", ".minicart-wrapper", ".page-wrapper",
                        ".page-header", ".navbar", ".main", "h1", "h2", "p", "a"
                    ];
                    var criticalRules = "";
                    // Very simple CSS parser
                    css.replace(/([^{]+)({[^}]*})/g, function(match, selector, rules) {
                        selector = selector.trim();
                        // Check if this selector contains any critical parts
                        if (criticalSelectors.some(function(criticalSelector) {
                            return selector.indexOf(criticalSelector) !== -1;
                        })) {
                            criticalRules += selector + rules + "\n";
                        }
                    });
                    // Apply critical CSS
                    var style = document.createElement("style");
                    style.textContent = criticalRules;
                    document.head.appendChild(style);
                });
        }
    })();
    </script>
    ';
    // 5. Add progressive loading script
    $progressiveLoader = '
    <script>
    // Progressive content loader
    (function() {
        // Main loader function
        function initProgressiveLoading() {
            console.log("🚀 Progressive loader initialized");
            // 1. Load remaining CSS files non-blocking
            document.querySelectorAll("link[rel=\'stylesheet\']").forEach(function(link, index) {
                if (index > 0) { // Skip the first (critical) CSS
                    link.setAttribute("media", "print");
                    link.setAttribute("onload", "this.media=\'all\'");
                }
            });
            // 2. Lazy load images
            var lazyImages = Array.from(document.querySelectorAll("img"));
            var loadedImages = 0;
            function lazyLoadImage(img) {
                if (img.dataset.src) {
                    var src = img.dataset.src;
                    var temp = new Image();
                    temp.onload = function() {
                        img.src = src;
                        img.classList.add("loaded");
                        loadedImages++;
                    };
                    temp.src = src;
                }
            }
            // 3. Load full page content
            function loadFullContent() {
                console.log("📄 Loading full page content");
                var contentPlaceholder = document.getElementById("remaining-content");
                if (contentPlaceholder && window.fullPageContent) {
                    contentPlaceholder.innerHTML = window.fullPageContent;
                    contentPlaceholder.classList.add("loaded");
                    // Initialize lazy loading for newly added images
                    initLazyImages(contentPlaceholder);
                }
            }
            // 4. Initialize lazy loading for a container
            function initLazyImages(container) {
                var images = container.querySelectorAll("img[data-src]");
                images.forEach(function(img) {
                    if (isInViewport(img)) {
                        lazyLoadImage(img);
                    } else {
                        lazyImagesObserver.observe(img);
                    }
                });
            }
            // 5. Check if element is in viewport
            function isInViewport(el) {
                var rect = el.getBoundingClientRect();
                return (
                    rect.top <= (window.innerHeight || document.documentElement.clientHeight) + 200 &&
                    rect.bottom >= 0 &&
                    rect.left <= (window.innerWidth || document.documentElement.clientWidth) + 200 &&
                    rect.right >= 0
                );
            }
            // 6. Set up intersection observer for lazy loading
            var lazyImagesObserver;
            if ("IntersectionObserver" in window) {
                lazyImagesObserver = new IntersectionObserver(function(entries) {
                    entries.forEach(function(entry) {
                        if (entry.isIntersecting) {
                            lazyLoadImage(entry.target);
                            lazyImagesObserver.unobserve(entry.target);
                        }
                    });
                });
                lazyImages.forEach(function(image) {
                    if (image.dataset.src) {
                        lazyImagesObserver.observe(image);
                    }
                });
            } else {
                // Fallback for browsers without intersection observer
                lazyImages.forEach(function(image) {
                    if (image.dataset.src && isInViewport(image)) {
                        lazyLoadImage(image);
                    }
                });
                // Check on scroll
                window.addEventListener("scroll", function() {
                    lazyImages.forEach(function(image) {
                        if (image.dataset.src && !image.classList.contains("loaded") && isInViewport(image)) {
                            lazyLoadImage(image);
                        }
                    });
                }, {passive: true});
            }
            // 7. Load full content when:
            // After initial content is visible
            setTimeout(loadFullContent, 1000);
            // On user interaction
            ["mousemove", "click", "keydown", "touchstart", "scroll"].forEach(function(event) {
                document.addEventListener(event, function() {
                    loadFullContent();
                }, {once: true, passive: true});
            });
        }
        // Initialize when DOM is loaded
        if (document.readyState !== "loading") {
            initProgressiveLoading();
        } else {
            document.addEventListener("DOMContentLoaded", initProgressiveLoading);
        }
    })();
    </script>
    ';
    // 6. Combine all parts
    $shellHead = $shellOpen . $criticalCssLoader . $progressiveLoader;
    // 7. Extract all HEAD content
    preg_match('/<head>(.*?)<\/head>/s', $html, $headMatches);
    $headContent = $headMatches[1] ?? '';
    // 8. Modify image tags in above-fold content
    $aboveFold = preg_replace_callback(
        '/<img[^>]*src=[\'"]((?!data:)[^\'"]+)[\'"][^>]*>/i',
        function($matches) {
            $fullTag = $matches[0];
            $src = $matches[1];
            // Skip small icons or logos
            if (strpos($fullTag, 'logo') !== false || 
                strpos($fullTag, 'icon') !== false || 
                strpos($fullTag, 'width="') !== false && preg_match('/width="(\d+)"/', $fullTag, $widthMatch) && $widthMatch[1] < 60) {
                return $fullTag;
            }
            // Replace src with data-src
            $newTag = str_replace('src="' . $src . '"', 'src="data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 1 1\'%3E%3C/svg%3E" data-src="' . $src . '"', $fullTag);
            return $newTag;
        },
        $aboveFold
    );
    // 9. Store remaining content
    $remainingContent = preg_replace('/^.*?<body[^>]*>(.*?)(?:<div[^>]*(?:class|id)=[\'"](?:footer|sidebar|secondary|menu-footer|newsletter))/is', '$1', $html, 1);
    $remainingContent = '<div class="lazy-content" id="remaining-content"><div class="loading-spinner"></div></div>';
    $remainingContentScript = '
    <script>
    // Store full page content for later loading
    window.fullPageContent = ' . json_encode(str_replace(['<script', '</script>'], ['<scr" + "ipt', '</scr" + "ipt>'], substr($html, strpos($html, '<body') + 6, strpos($html, '</body>') - strpos($html, '<body') - 6))) . ';
    </script>
    ';
    // 10. Build the final shell page
    $shellPage = $shellHead . '</head><body>' . $aboveFold . $remainingContent . $remainingContentScript . '</body></html>';
    return $shellPage;
}
/**
 * Implement progressive page loading
 *
 * @param string $html
 * @return string
 */
private function implementProgressiveLoading($html)
{
    // Progressive loading script
    $progressiveLoader = '
    <script>
    // Progressive page loading
    (function() {
        // Priorities for resource loading
        var priorities = {
            critical: [], // Load immediately
            high: [],     // Load during idle time in first 2 seconds
            medium: [],   // Load after first paint
            low: []       // Load after page is interactive
        };
        // Classify resources by priority
        function classifyResources() {
            // Process stylesheets
            document.querySelectorAll("link[rel=\'stylesheet\']").forEach(function(link) {
                // First stylesheet is critical
                if (priorities.critical.length === 0) {
                    priorities.critical.push(link);
                } else {
                    priorities.medium.push(link);
                }
            });
            // Process scripts
            document.querySelectorAll("script[src]").forEach(function(script) {
                if (script.src.includes("jquery") || script.src.includes("require")) {
                    priorities.high.push(script);
                } else if (script.src.includes("google") || script.src.includes("facebook") || 
                           script.src.includes("analytics") || script.src.includes("pixel")) {
                    priorities.low.push(script);
                } else {
                    priorities.medium.push(script);
                }
            });
            // Process images
            document.querySelectorAll("img").forEach(function(img) {
                if (isInViewport(img) || img.classList.contains("logo") || 
                    img.closest("[data-content-type=\'banner\']")) {
                    priorities.high.push(img);
                } else {
                    priorities.medium.push(img);
                }
            });
        }
        // Function to check if element is in viewport
        function isInViewport(el) {
            if (!el) return false;
            var rect = el.getBoundingClientRect();
            return (
                rect.top <= (window.innerHeight || document.documentElement.clientHeight) &&
                rect.left <= (window.innerWidth || document.documentElement.clientWidth)
            );
        }
        // Load resources by priority
        function loadByPriority() {
            // Load critical resources immediately
            priorities.critical.forEach(loadResource);
            // Load high priority after 100ms
            setTimeout(function() {
                priorities.high.forEach(loadResource);
            }, 100);
            // Load medium priority after first paint (500ms)
            setTimeout(function() {
                priorities.medium.forEach(loadResource);
            }, 500);
            // Load low priority resources after page is interactive (3s)
            setTimeout(function() {
                priorities.low.forEach(loadResource);
            }, 3000);
        }
        // Load a specific resource
        function loadResource(resource) {
            if (resource.tagName === "LINK") {
                // Load stylesheet
                resource.media = "all";
            } else if (resource.tagName === "SCRIPT") {
                // Clone and replace script to force loading
                var newScript = document.createElement("script");
                newScript.src = resource.src;
                newScript.async = true;
                resource.parentNode.replaceChild(newScript, resource);
            } else if (resource.tagName === "IMG") {
                // Load image if it has data-lazy-src
                if (resource.dataset.lazySrc) {
                    resource.src = resource.dataset.lazySrc;
                    if (resource.dataset.lazySrcset) {
                        resource.srcset = resource.dataset.lazySrcset;
                    }
                }
            }
        }
        // Initialize when DOM is ready
        if (document.readyState !== "loading") {
            classifyResources();
            loadByPriority();
        } else {
            document.addEventListener("DOMContentLoaded", function() {
                classifyResources();
                loadByPriority();
            });
        }
        // Progressive enhancement for interactions
        window.addEventListener("scroll", function() {
            // Load all medium priority resources on first scroll
            priorities.medium.forEach(loadResource);
        }, {once: true, passive: true});
        // Load all resources on user interaction
        ["mousedown", "keydown", "touchstart"].forEach(function(event) {
            window.addEventListener(event, function() {
                // Load all remaining resources on user interaction
                priorities.medium.forEach(loadResource);
                priorities.low.forEach(loadResource);
            }, {once: true, passive: true});
        });
    })();
    </script>
    ';
    // Add progressive loader after opening body tag
    $html = preg_replace('/<body([^>]*)>/', '<body$1>' . $progressiveLoader, $html);
    // Add non-blocking CSS loading
    $html = preg_replace_callback(
        '/<link([^>]*)rel=[\'"]stylesheet[\'"]([^>]*)href=[\'"]((?!print)[^\'"]+)[\'"]((?!media=")[^>]*)>/i',
        function($matches) {
            $before = $matches[1];
            $rel = $matches[2];
            $href = $matches[3];
            $after = $matches[4];
            // Skip the first CSS file (treat as critical)
            static $firstCss = true;
            if ($firstCss) {
                $firstCss = false;
                return $matches[0];
            }
            // Non-blocking CSS loading
            return '<link' . $before . 'rel="stylesheet"' . $rel . 'href="' . $href . '" media="print" onload="this.media=\'all\'"' . $after . '>';
        },
        $html
    );
    return $html;
}
}