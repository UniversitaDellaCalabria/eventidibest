// sw.js - Service Worker PWA EventiDiBEST
// Fase 4: Stale-While-Revalidate + Cache-First per asset statici + fallback offline
// =========================================================================

const SW_VERSION = 'dibest-v4.1';

// ─── Cache buckets ────────────────────────────────────────────────────────────
const CACHE_STATIC   = SW_VERSION + '-static';   // CSS, JS, font, icone
const CACHE_PAGES    = SW_VERSION + '-pages';     // Pagine PHP pubbliche (SWR)
const CACHE_OFFLINE  = SW_VERSION + '-offline';   // Solo offline.html

// Risorse statiche pre-cachate al momento dell'install (Cache-First)
const STATIC_ASSETS = [
    'https://cdn.jsdelivr.net/npm/bootstrap-italia@2.8.3/dist/css/bootstrap-italia.min.css',
    'https://cdn.jsdelivr.net/npm/bootstrap-italia@2.8.3/dist/js/bootstrap-italia.bundle.min.js',
    'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css',
];

// Pagine pubbliche da pre-cachare (Stale-While-Revalidate)
const PUBLIC_PAGES = [
    './',
    './index.php',
    './offline.html',
];

// ─── INSTALL: precaching ──────────────────────────────────────────────────────
self.addEventListener('install', event => {
    event.waitUntil(
        Promise.all([
            caches.open(CACHE_STATIC).then(cache => cache.addAll(STATIC_ASSETS).catch(() => {})),
            caches.open(CACHE_OFFLINE).then(cache => cache.addAll(['./offline.html']).catch(() => {})),
            caches.open(CACHE_PAGES).then(cache => cache.addAll(PUBLIC_PAGES).catch(() => {})),
        ]).then(() => self.skipWaiting())
    );
});

// ─── ACTIVATE: pulizia vecchie cache ─────────────────────────────────────────
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys =>
            Promise.all(
                keys.filter(k => k !== CACHE_STATIC && k !== CACHE_PAGES && k !== CACHE_OFFLINE)
                    .map(k => caches.delete(k))
            )
        ).then(() => clients.claim())
    );
});

// ─── MESSAGE: gestione aggiornamenti dal client ───────────────────────────────
self.addEventListener('message', event => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});

// ─── FETCH: routing per strategia ────────────────────────────────────────────
self.addEventListener('fetch', event => {
    const req  = event.request;
    const url  = new URL(req.url);

    // 1. Ignora completamente: metodi non-GET, admin, API, pagine autenticate, SSO
    if (
        req.method !== 'GET' ||
        url.pathname.includes('/admin/') ||
        url.pathname.includes('/area_personale') ||
        url.pathname.includes('/checkin') ||
        url.pathname.includes('/stampa_') ||
        url.pathname.includes('/saml') ||
        url.pathname.includes('/debug_') ||
        url.pathname.includes('/setup_') ||
        url.pathname.includes('/install') ||
        url.pathname.includes('/update_db') ||
        url.pathname.includes('/cache/') ||
        url.pathname.includes('/uploads/') ||
        // Non cachare richieste cross-origin tranne i CDN statici
        (url.origin !== self.location.origin && !isStaticCDN(url.href))
    ) {
        return; // Network-Only (pass-through)
    }

    // 2. Asset statici da CDN → Cache-First
    if (isStaticCDN(url.href)) {
        event.respondWith(cacheFirst(req, CACHE_STATIC));
        return;
    }

    // 3. Pagine PHP pubbliche (GET, stesso origin) → Stale-While-Revalidate
    if (url.origin === self.location.origin) {
        event.respondWith(staleWhileRevalidate(req, CACHE_PAGES));
        return;
    }
});

// ─── Strategie ────────────────────────────────────────────────────────────────

/**
 * Cache-First: servi dalla cache; se manca vai in rete e aggiorna la cache.
 * Ideale per asset statici che cambiano raramente.
 */
async function cacheFirst(req, cacheName) {
    const cache    = await caches.open(cacheName);
    const cached   = await cache.match(req);
    if (cached) return cached;
    try {
        const fresh = await fetch(req);
        if (fresh.ok) cache.put(req, fresh.clone());
        return fresh;
    } catch {
        return offlineFallback();
    }
}

/**
 * Stale-While-Revalidate: servi immediatamente dalla cache (se disponibile)
 * e in background aggiorna la cache con la risposta di rete.
 * Ideale per pagine PHP dinamiche pubbliche: risposta istantanea + dati freschi.
 */
async function staleWhileRevalidate(req, cacheName) {
    const cache  = await caches.open(cacheName);
    const cached = await cache.match(req);

    // Kick off network request in background
    const networkFetch = fetch(req).then(fresh => {
        if (fresh && fresh.ok) {
            cache.put(req, fresh.clone());
        }
        return fresh;
    }).catch(() => null);

    // Servi subito la cache se disponibile, altrimenti aspetta la rete
    if (cached) return cached;

    const fresh = await networkFetch;
    if (fresh) return fresh;

    return offlineFallback();
}

/**
 * Fallback offline: restituisce offline.html dalla cache dedicata.
 */
async function offlineFallback() {
    const cache = await caches.open(CACHE_OFFLINE);
    return cache.match('./offline.html') || new Response(
        '<h1>Sei offline</h1><p>Connettiti per usare EventiDiBEST.</p>',
        { status: 503, headers: { 'Content-Type': 'text/html' } }
    );
}

/**
 * Controlla se l'URL è uno degli asset CDN statici che vogliamo cachare.
 */
function isStaticCDN(href) {
    return href.includes('cdn.jsdelivr.net') ||
           href.includes('cdnjs.cloudflare.com') ||
           href.includes('fonts.googleapis.com') ||
           href.includes('fonts.gstatic.com');
}
