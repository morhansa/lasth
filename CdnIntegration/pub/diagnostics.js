// JavaScript Performance Diagnostics
(function() {
    // Performance timing data collection
    const timings = [];
    const scriptTimings = {};
    
    // Measure script execution time
    const originalCreateElement = document.createElement;
    document.createElement = function(tagName) {
        const element = originalCreateElement.call(document, tagName);
        
        if (tagName.toLowerCase() === 'script') {
            const originalSetAttribute = element.setAttribute;
            element.setAttribute = function(name, value) {
                const result = originalSetAttribute.call(this, name, value);
                
                if (name === 'src') {
                    const startTime = performance.now();
                    element.addEventListener('load', function() {
                        const endTime = performance.now();
                        const duration = endTime - startTime;
                        scriptTimings[value] = duration;
                        console.log(`Script ${value} loaded in ${duration.toFixed(2)}ms`);
                    });
                }
                
                return result;
            };
        }
        
        return element;
    };
    
    // Record main thread blocking
    let lastRecordedTime = performance.now();
    const longTaskThreshold = 50; // 50ms
    
    function checkLongTask() {
        const now = performance.now();
        const elapsed = now - lastRecordedTime;
        
        if (elapsed > longTaskThreshold) {
            timings.push({
                timestamp: now,
                duration: elapsed,
                url: document.currentScript ? document.currentScript.src : 'unknown'
            });
            
            console.warn(`Long task detected: ${elapsed.toFixed(2)}ms at ${new Date(now).toISOString()}`);
        }
        
        lastRecordedTime = now;
        requestAnimationFrame(checkLongTask);
    }
    
    requestAnimationFrame(checkLongTask);
    
    // Report timings
    window.addEventListener('load', function() {
        setTimeout(function() {
            console.log('=== PERFORMANCE DIAGNOSTICS ===');
            console.log('Total long tasks detected:', timings.length);
            
            if (timings.length > 0) {
                console.log('Top 5 longest tasks:');
                timings.sort((a, b) => b.duration - a.duration)
                    .slice(0, 5)
                    .forEach(function(timing, index) {
                        console.log(`${index + 1}. ${timing.duration.toFixed(2)}ms - ${timing.url}`);
                    });
            }
            
            console.log('Script loading times:');
            Object.keys(scriptTimings)
                .sort((a, b) => scriptTimings[b] - scriptTimings[a])
                .forEach(function(url) {
                    console.log(`${url}: ${scriptTimings[url].toFixed(2)}ms`);
                });
            
            // Send report to server
            try {
                navigator.sendBeacon('/performance-log', JSON.stringify({
                    longTasks: timings,
                    scriptTimings: scriptTimings,
                    userAgent: navigator.userAgent,
                    timestamp: new Date().toISOString()
                }));
            } catch (e) {
                console.error('Failed to send performance data:', e);
            }
            
            console.log('=== END DIAGNOSTICS ===');
        }, 5000);
    });
})();