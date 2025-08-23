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
const CACHE_NAME = 'catch-em-all-v1';
const urlsToCache = [
    '/',
    '/auth/login',
    '/leaderboard',
    '/collection',
    '/achievements'
];

self.addEventListener('install', function(event) {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(function(cache) {
                return cache.addAll(urlsToCache);
            })
    );
});

self.addEventListener('fetch', function(event) {
    event.respondWith(
        caches.match(event.request)
            .then(function(response) {
                // Return cached version or fetch from network
                return response || fetch(event.request);
            }
        )
    );
});

self.addEventListener('activate', function(event) {
    event.waitUntil(
        caches.keys().then(function(cacheNames) {
            return Promise.all(
                cacheNames.map(function(cacheName) {
                    if (cacheName !== CACHE_NAME) {
                        return caches.delete(cacheName);
                    }
                })
            );
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
