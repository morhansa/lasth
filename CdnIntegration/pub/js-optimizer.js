/**
 * JavaScript Optimizer for Magento 2
 * Reduces TBT by optimizing require.js and other large scripts
 */
define([], function() {
    'use strict';
    
    return {
        init: function() {
            console.log('JavaScript Optimizer initialized');
            this.setupRequireJsOptimizer();
            this.setupLargeScriptOptimizer();
        },
        
        setupRequireJsOptimizer: function() {
            var self = this;
            
            // Find require.js script tags that haven't loaded yet
            var scripts = document.querySelectorAll('script[src*="require.min.js"]');
            
            if (scripts.length > 0) {
                scripts.forEach(function(script) {
                    // Skip if already processed
                    if (script.hasAttribute('data-optimized')) {
                        return;
                    }
                    
                    var src = script.getAttribute('src');
                    script.setAttribute('data-optimized', 'true');
                    
                    // Remove the original script tag to prevent normal loading
                    if (script.parentNode) {
                        script.parentNode.removeChild(script);
                    }
                    
                    // Create optimized version
                    self.loadAndChunkScript(src, true);
                });
            }
        },
        
        setupLargeScriptOptimizer: function() {
            var self = this;
            
            // Target other large scripts
            var largeScriptSelectors = [
                'script[src*="requirejs-config.min.js"]',
                'script[src*="mixins.min.js"]',
                'script[src*="jquery.min.js"]',
                'script[src*="merged"]',
                'script[src*="minified"]'
            ];
            
            var selector = largeScriptSelectors.join(',');
            var scripts = document.querySelectorAll(selector);
            
            if (scripts.length > 0) {
                scripts.forEach(function(script) {
                    // Skip if already processed
                    if (script.hasAttribute('data-optimized')) {
                        return;
                    }
                    
                    var src = script.getAttribute('src');
                    script.setAttribute('data-optimized', 'true');
                    
                    // Remove the original script tag
                    if (script.parentNode) {
                        script.parentNode.removeChild(script);
                    }
                    
                    // Create optimized version
                    self.loadAndChunkScript(src, false);
                });
            }
        },
        
        loadAndChunkScript: function(src, isRequireJs) {
            var self = this;
            
            // Save original require and define functions if this is require.js
            if (isRequireJs) {
                window._originalRequire = window.require;
                window._originalDefine = window.define;
                
                // Create stubs
                window.require = function() {
                    var args = arguments;
                    window._pendingRequires = window._pendingRequires || [];
                    window._pendingRequires.push(args);
                };
                
                window.define = function() {
                    var args = arguments;
                    window._pendingDefines = window._pendingDefines || [];
                    window._pendingDefines.push(args);
                };
            }
            
            // Fetch and process script
            fetch(src)
                .then(function(response) {
                    return response.text();
                })
                .then(function(scriptContent) {
                    console.log('Optimizing script: ' + src);
                    
                    // Split into manageable chunks (50KB each)
                    var chunkSize = 50000;
                    var chunks = [];
                    
                    for (var i = 0; i < scriptContent.length; i += chunkSize) {
                        chunks.push(scriptContent.slice(i, i + chunkSize));
                    }
                    
                    console.log('Split into ' + chunks.length + ' chunks');
                    
                    // Process chunks with yield to main thread
                    self.executeChunks(chunks, 0, function() {
                        console.log('All chunks executed for: ' + src);
                        
                        // If this was require.js, restore the original functions
                        if (isRequireJs && window._pendingRequires) {
                            self.restoreRequireJs();
                        }
                    });
                })
                .catch(function(error) {
                    console.error('Failed to optimize script: ' + src, error);
                    
                    // Fallback to normal script loading
                    var fallbackScript = document.createElement('script');
                    fallbackScript.src = src;
                    document.head.appendChild(fallbackScript);
                });
        },
        
        executeChunks: function(chunks, index, callback) {
            var self = this;
            
            if (index >= chunks.length) {
                if (typeof callback === 'function') {
                    callback();
                }
                return;
            }
            
            try {
                // Execute the current chunk
                new Function(chunks[index])();
                
                // Schedule next chunk with a small delay
                setTimeout(function() {
                    self.executeChunks(chunks, index + 1, callback);
                }, 1);
            } catch (e) {
                console.error('Error executing chunk ' + index, e);
                
                // Try to continue with next chunk
                setTimeout(function() {
                    self.executeChunks(chunks, index + 1, callback);
                }, 1);
            }
        },
        
        restoreRequireJs: function() {
            // Check if require.js has been properly loaded
            if (typeof requirejs !== 'undefined') {
                console.log('Restoring require.js functionality');
                
                // Process any pending require calls
                if (window._pendingRequires && window._pendingRequires.length) {
                    window._pendingRequires.forEach(function(args) {
                        requirejs.apply(window, args);
                    });
                }
                
                // Process any pending define calls
                if (window._pendingDefines && window._pendingDefines.length) {
                    window._pendingDefines.forEach(function(args) {
                        define.apply(window, args);
                    });
                }
                
                // Clean up
                delete window._pendingRequires;
                delete window._pendingDefines;
                delete window._originalRequire;
                delete window._originalDefine;
            }
        }
    };
});