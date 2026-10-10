const CACHE_NAME = 'teediewjob-assets-v1';

// Add URLs of static assets you want to cache
const STATIC_ASSETS = [
    './assets/css/style.css',
    './assets/js/main.js',
    './assets/images/school_logo.png',
    './iconAPP.png',
    './manifest.json'
];

// 1. Install event: Cache static assets
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            console.log('[Service Worker] Caching static assets');
            return cache.addAll(STATIC_ASSETS.map(url => new Request(url, { cache: 'reload' })));
        }).catch(err => {
            console.log('[Service Worker] Cache addAll failed:', err);
        })
    );
    self.skipWaiting(); // Force the waiting service worker to become the active service worker
});

// 2. Activate event: Clean up old caches
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames.map((cacheName) => {
                    if (cacheName !== CACHE_NAME) {
                        console.log('[Service Worker] Deleting old cache:', cacheName);
                        return caches.delete(cacheName);
                    }
                })
            );
        })
    );
    self.clients.claim(); // Take control of all clients immediately
});

// 3. Fetch event: Stale-while-revalidate for static assets, Network-first for everything else
self.addEventListener('fetch', (event) => {
    const requestUrl = new URL(event.request.url);

    // Skip cross-origin requests and non-GET requests
    if (event.request.method !== 'GET') {
        return;
    }

    // Check if the request is for a static asset
    const isStaticAsset = STATIC_ASSETS.some(asset => requestUrl.pathname.endsWith(asset.replace('./', ''))) || 
                          requestUrl.pathname.endsWith('.css') || 
                          requestUrl.pathname.endsWith('.js') || 
                          requestUrl.pathname.endsWith('.png') || 
                          requestUrl.pathname.endsWith('.jpg') || 
                          requestUrl.pathname.endsWith('.jpeg') || 
                          requestUrl.pathname.endsWith('.svg') || 
                          requestUrl.host === 'fonts.googleapis.com' || 
                          requestUrl.host === 'fonts.gstatic.com';

    if (isStaticAsset) {
        // Strategy: Stale-While-Revalidate
        event.respondWith(
            caches.match(event.request).then((cachedResponse) => {
                const fetchPromise = fetch(event.request).then((networkResponse) => {
                    // Update the cache with the new response
                    if (networkResponse && networkResponse.status === 200) {
                        const responseToCache = networkResponse.clone();
                        caches.open(CACHE_NAME).then((cache) => {
                            cache.put(event.request, responseToCache);
                        });
                    }
                    return networkResponse;
                }).catch(() => {
                    // Ignore network errors for static assets if we have them in cache
                });

                // Return cached response immediately, if available, while fetching in background
                return cachedResponse || fetchPromise;
            })
        );
    } else {
        // Strategy: Network-First for HTML/PHP pages (dynamic content)
        event.respondWith(
            fetch(event.request).catch(() => {
                return caches.match(event.request);
            })
        );
    }
});
