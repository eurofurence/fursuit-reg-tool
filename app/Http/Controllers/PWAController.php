<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PWAController extends Controller
{
    public function manifest(Request $request): JsonResponse
    {
        $domain = config('fcea.domain');
        $protocol = str_contains($domain, 'localhost') ? 'http' : 'https';
        $baseUrl = $protocol.'://'.$domain;

        $manifest = [
            'name' => 'Catch-Em-All',
            'short_name' => 'CatchEm',
            'description' => 'Fursuiter hunting game for Eurofurence',
            'start_url' => $baseUrl.'/',
            'scope' => $baseUrl.'/',
            'display' => 'standalone',
            'orientation' => 'portrait',
            'theme_color' => '#1f2937',
            'background_color' => '#ffffff',
            'categories' => ['games', 'social'],
            'lang' => 'en',
            'icons' => [
                [
                    'src' => '/icons/icon-72x72.png',
                    'sizes' => '72x72',
                    'type' => 'image/png',
                    'purpose' => 'maskable any',
                ],
                [
                    'src' => '/icons/icon-96x96.png',
                    'sizes' => '96x96',
                    'type' => 'image/png',
                    'purpose' => 'maskable any',
                ],
                [
                    'src' => '/icons/icon-128x128.png',
                    'sizes' => '128x128',
                    'type' => 'image/png',
                    'purpose' => 'maskable any',
                ],
                [
                    'src' => '/icons/icon-144x144.png',
                    'sizes' => '144x144',
                    'type' => 'image/png',
                    'purpose' => 'maskable any',
                ],
                [
                    'src' => '/icons/icon-152x152.png',
                    'sizes' => '152x152',
                    'type' => 'image/png',
                    'purpose' => 'maskable any',
                ],
                [
                    'src' => '/icons/icon-192x192.png',
                    'sizes' => '192x192',
                    'type' => 'image/png',
                    'purpose' => 'maskable any',
                ],
                [
                    'src' => '/icons/icon-384x384.png',
                    'sizes' => '384x384',
                    'type' => 'image/png',
                    'purpose' => 'maskable any',
                ],
                [
                    'src' => '/icons/icon-512x512.png',
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'maskable any',
                ],
            ],
            'shortcuts' => [
                [
                    'name' => 'Leaderboard',
                    'short_name' => 'Leaders',
                    'description' => 'View the leaderboard',
                    'url' => $baseUrl.'/leaderboard',
                    'icons' => [
                        [
                            'src' => '/icons/icon-96x96.png',
                            'sizes' => '96x96',
                        ],
                    ],
                ],
                [
                    'name' => 'Collection',
                    'short_name' => 'Collection',
                    'description' => 'View your collection',
                    'url' => $baseUrl.'/collection',
                    'icons' => [
                        [
                            'src' => '/icons/icon-96x96.png',
                            'sizes' => '96x96',
                        ],
                    ],
                ],
            ],
        ];

        return response()->json($manifest, 200, [
            'Content-Type' => 'application/manifest+json',
        ]);
    }

    public function serviceWorker(): \Illuminate\Http\Response
    {
        $serviceWorkerContent = "
// Service Worker for Catch-Em-All PWA
const CACHE_VERSION = 'v1';
const CACHE_NAME = `catch-em-all-\${CACHE_VERSION}`;
const STATIC_CACHE_NAME = `catch-em-all-static-\${CACHE_VERSION}`;

// Only cache static assets that don't change often
const STATIC_ASSETS = [
    '/manifest.json',
    '/icons/icon-72x72.png',
    '/icons/icon-96x96.png',
    '/icons/icon-128x128.png',
    '/icons/icon-144x144.png',
    '/icons/icon-152x152.png',
    '/icons/icon-192x192.png',
    '/icons/icon-384x384.png',
    '/icons/icon-512x512.png'
];

self.addEventListener('install', function(event) {
    // Pre-cache static assets only
    event.waitUntil(
        caches.open(STATIC_CACHE_NAME)
            .then(function(cache) {
                return cache.addAll(STATIC_ASSETS);
            })
    );
    // Force the new service worker to activate
    self.skipWaiting();
});

self.addEventListener('activate', function(event) {
    event.waitUntil(
        caches.keys().then(function(cacheNames) {
            return Promise.all(
                cacheNames.map(function(cacheName) {
                    // Delete old cache versions
                    if (cacheName.startsWith('catch-em-all-') && 
                        cacheName !== CACHE_NAME && 
                        cacheName !== STATIC_CACHE_NAME) {
                        return caches.delete(cacheName);
                    }
                })
            );
        })
    );
    // Take control of all pages immediately
    self.clients.claim();
});

self.addEventListener('fetch', function(event) {
    const { request } = event;
    const url = new URL(request.url);
    
    // Skip non-GET requests
    if (request.method !== 'GET') {
        return;
    }
    
    // Skip cross-origin requests
    if (url.origin !== location.origin) {
        return;
    }
    
    // Network-first strategy for API and dynamic content
    if (url.pathname.startsWith('/api/') || 
        url.pathname.startsWith('/catch') ||
        url.pathname.startsWith('/auth/') ||
        request.headers.get('accept')?.includes('application/json')) {
        event.respondWith(
            fetch(request)
                .catch(() => {
                    // If network fails, try cache as fallback
                    return caches.match(request);
                })
        );
        return;
    }
    
    // Cache-first strategy for static assets
    if (url.pathname.startsWith('/icons/') || 
        url.pathname.startsWith('/build/') ||
        url.pathname === '/manifest.json') {
        event.respondWith(
            caches.match(request)
                .then(function(response) {
                    return response || fetch(request).then(function(response) {
                        // Cache successful responses
                        if (response.status === 200) {
                            const responseClone = response.clone();
                            caches.open(STATIC_CACHE_NAME).then(function(cache) {
                                cache.put(request, responseClone);
                            });
                        }
                        return response;
                    });
                })
        );
        return;
    }
    
    // Default: network-first for everything else
    event.respondWith(
        fetch(request)
            .catch(() => {
                return caches.match(request);
            })
    );
});
";

        return response($serviceWorkerContent, 200, [
            'Content-Type' => 'application/javascript',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }
}
