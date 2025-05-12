// CDN Cache Service Worker
const CACHE_NAME = 'cdn-cache-v1';
const CACHE_DURATION = 86400; // 24 hours in seconds

self.addEventListener('install', event => {
  self.skipWaiting();
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(cacheNames => {
      return Promise.all(
        cacheNames.filter(cacheName => {
          return cacheName !== CACHE_NAME;
        }).map(cacheName => {
          return caches.delete(cacheName);
        })
      );
    }).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', event => {
  // Only cache GET requests from CDN
  if (event.request.method !== 'GET') return;
  
  const url = new URL(event.request.url);
  
  // Only cache CDN resources (jsdelivr or your own CDN)
  if (url.hostname.includes('jsdelivr.net') || 
      url.pathname.startsWith('/static/') || 
      url.pathname.startsWith('/media/')) {
    
    event.respondWith(
      caches.open(CACHE_NAME).then(cache => {
        return cache.match(event.request).then(cachedResponse => {
          // Return from cache if available and not expired
          if (cachedResponse) {
            const cachedDate = new Date(cachedResponse.headers.get('date'));
            const now = new Date();
            const ageInSeconds = (now - cachedDate) / 1000;
            
            if (ageInSeconds < CACHE_DURATION) {
              return cachedResponse;
            }
          }
          
          // Otherwise fetch new response
          return fetch(event.request).then(response => {
            // Clone the response to store in cache
            const clonedResponse = response.clone();
            
            if (response.status === 200) {
              cache.put(event.request, clonedResponse);
            }
            
            return response;
          }).catch(error => {
            // Return cached response even if expired on network error
            if (cachedResponse) {
              return cachedResponse;
            }
            throw error;
          });
        });
      })
    );
  }
});