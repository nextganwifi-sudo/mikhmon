/* NextGan WiFi PWA Service Worker — Fast Static Caching + Secure Network-First Navigation */
const CACHE_VERSION = 'ngw-pwa-v9-20261125h';
const STATIC_CACHE = CACHE_VERSION + '-static';
const SHELL_CACHE = CACHE_VERSION + '-shell';
const OFFLINE_URL = '/offline.html';

const PRECACHE_CORE = [
  OFFLINE_URL,
  '/manifest.webmanifest',
  '/img/ngw-brand-mark.png?v=20261125h',
  '/img/ngw-login-round.png?v=20261125h',
  '/img/pwa/pwa-192x192.png?v=20261125h',
  '/img/pwa/pwa-512x512.png?v=20261125h',
  '/img/pwa/pwa-maskable-512x512.png?v=20261125h',
  '/img/pwa/apple-touch-icon.png?v=20261125h',
  '/img/pwa/icon.svg?v=20261125h',
  '/img/favicon.png?v=20261125h',
  '/css/font-awesome/css/font-awesome.min.css',
  '/css/font-awesome/fonts/fontawesome-webfont.woff2?v=4.7.0',
  '/css/nextgan-responsive.css?v=20261122j',
  '/js/jquery.min.js',
  '/js/pace.min.js',
  '/js/ngw-pwa.js?v=20261125h'
];

const PRECACHE_OPTIONAL = [
  '/css/mikhmon-ui.dark.min.css',
  '/css/mikhmon-ui.light.min.css',
  '/css/nextgan-modern-ui.css?v=20261125e',
  '/css/nextgan-aurora-ui.css?v=20261125e',
  '/js/nextgan-aurora-layout.js',
  '/img/login/bg-classic.jpg?v=20261122k',
  '/img/login/bg-modern.jpg?v=20261122k',
  '/img/login/bg-compact.jpg?v=20261122k',
  '/img/login/bg-aurora.jpg?v=20261122k'
];

function isCacheableStaticRequest(url) {
  if (url.origin === self.location.origin) {
    const p = url.pathname.toLowerCase();
    if (p.endsWith('/sw.js') || p === '/sw.js') return false;
    if (p.endsWith('.php')) return false;
    return (
      p === '/manifest.webmanifest' ||
      p === '/offline.html' ||
      /\.(css|js|png|jpe?g|gif|webp|svg|ico|woff2?|ttf|eot|otf|webmanifest)$/.test(p)
    );
  }
  if (
    url.hostname === 'fonts.googleapis.com' ||
    url.hostname === 'fonts.gstatic.com'
  ) {
    return true;
  }
  return false;
}

self.addEventListener('install', (event) => {
  event.waitUntil(
    (async () => {
      const shell = await caches.open(SHELL_CACHE);
      await Promise.allSettled(
        PRECACHE_CORE.map(async (assetUrl) => {
          const res = await fetch(assetUrl, { cache: 'reload' });
          if (res && res.ok) {
            await shell.put(assetUrl, res);
          }
        })
      );
      const staticCache = await caches.open(STATIC_CACHE);
      await Promise.allSettled(
        PRECACHE_OPTIONAL.map(async (assetUrl) => {
          const res = await fetch(assetUrl);
          if (res && res.ok) {
            await staticCache.put(assetUrl, res);
          }
        })
      );
      await self.skipWaiting();
    })()
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    (async () => {
      const keys = await caches.keys();
      await Promise.all(
        keys
          .filter((k) => k.startsWith('ngw-pwa-') && k !== STATIC_CACHE && k !== SHELL_CACHE)
          .map((k) => caches.delete(k))
      );
      if (self.registration && self.registration.navigationPreload) {
        try {
          await self.registration.navigationPreload.enable();
        } catch (e) {}
      }
      await self.clients.claim();
    })()
  );
});

self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') {
    return;
  }

  let url;
  try {
    url = new URL(req.url);
  } catch (e) {
    return;
  }

  if (url.protocol !== 'http:' && url.protocol !== 'https:') {
    return;
  }

  // Never cache or intercept authenticated PHP documents / JSON endpoints; serve network-first and fall back to offline shell on navigation failure.
  if (req.mode === 'navigate') {
    event.respondWith(
      (async () => {
        try {
          const preload = event.preloadResponse ? await event.preloadResponse : null;
          if (preload) {
            return preload;
          }
          return await fetch(req);
        } catch (err) {
          const shell = await caches.open(SHELL_CACHE);
          const offlineRes = await shell.match(OFFLINE_URL);
          if (offlineRes) {
            return offlineRes;
          }
          return new Response(
            '<h1>NextGan WiFi Offline</h1><p>Please check your internet connection and retry.</p>',
            { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } }
          );
        }
      })()
    );
    return;
  }

  if (isCacheableStaticRequest(url)) {
    event.respondWith(
      (async () => {
        const cache = await caches.open(STATIC_CACHE);
        const cached = await cache.match(req) || (await (await caches.open(SHELL_CACHE)).match(req));
        const networkPromise = fetch(req)
          .then((networkRes) => {
            if (networkRes && (networkRes.ok || networkRes.type === 'opaque')) {
              cache.put(req, networkRes.clone()).catch(() => {});
            }
            return networkRes;
          })
          .catch(() => null);

        if (cached) {
          event.waitUntil(networkPromise);
          return cached;
        }
        const net = await networkPromise;
        if (net) {
          return net;
        }
        return Response.error();
      })()
    );
  }
});
