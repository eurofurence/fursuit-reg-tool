<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Where a visitor lands after coming back from the identity provider.
 *
 * The OAuth round trip leaves the app entirely, so the page somebody was reading when
 * they pressed "Sign in" is only recoverable if we write it down first. `remember()`
 * does that on the way out, `resolve()` reads it back in the callback, and everything
 * else falls through to the caller's default.
 *
 * ALLOWLIST, NOT SANITIZER. The candidate URL can come from a query string or a
 * `Referer`, both of which an attacker controls, so a "does it look local" string check
 * is not enough. The candidate is matched against the router and kept only if the route
 * it lands on is named in the list below. Anything unroutable, POST-only, on another
 * host, or simply not listed is dropped and the fallback wins - a login must never be
 * able to end on an attacker's page, and it must never end on an endpoint that a GET
 * was not meant to reach.
 *
 * The two lists are separate because the two front ends are separate hosts: the game
 * lives on `config('fcea.domain')` and the public site on `APP_URL`, and a stored URL is
 * only ever offered back on the host it came from.
 */
final class LoginRedirect
{
    /** The key Laravel's own `redirect()->guest()` already writes, so both paths agree. */
    private const SESSION_KEY = 'url.intended';

    /**
     * Route names a finished login may land on. A trailing `.*` allows a whole prefix.
     *
     * @var list<string>
     */
    private const ALLOWED = [
        // Catch-Em-All game (catch subdomain)
        'catch-em-all.catch',
        'catch-em-all.leaderboard',
        'catch-em-all.achievements',
        'catch-em-all.collection',
        'catch-em-all.profile',
        'catch-em-all.profiles.show',
        'catch-em-all.introduction',

        // Public site
        'welcome',
        'info.catch-em-all',
        'info.faq',
        'info.pickup',
        'badges.index',
        'badges.create',
        'badges.show',
        'badges.edit',
        'gallery.index',
        'gallery.all',
        'gallery.event',

        // The admin panel is behind `auth` + `can:access-manage`, so a deep link here is
        // only ever reached by somebody who passes both checks afterwards anyway.
        'admin.*',
    ];

    /**
     * Note where to return to, before handing the visitor to the identity provider.
     *
     * An explicit `?redirect=` wins, then anything the `auth` middleware already stored
     * when it bounced the request, then the page the browser came from.
     */
    public static function remember(Request $request): void
    {
        $candidate = $request->query('redirect')
            ?: $request->session()->get(self::SESSION_KEY)
            ?: $request->headers->get('referer');

        $target = self::sanitize($request, is_string($candidate) ? $candidate : null);

        if ($target === null) {
            $request->session()->forget(self::SESSION_KEY);

            return;
        }

        $request->session()->put(self::SESSION_KEY, $target);
    }

    /**
     * The URL to send a freshly authenticated visitor to, consuming what was remembered.
     */
    public static function resolve(Request $request, string $fallback): string
    {
        $stored = $request->session()->pull(self::SESSION_KEY);

        return self::sanitize($request, is_string($stored) ? $stored : null) ?? $fallback;
    }

    /**
     * Keep the current URL for after the detour the caller is about to make.
     */
    public static function keep(Request $request): void
    {
        $target = self::sanitize($request, $request->fullUrl());

        if ($target !== null) {
            $request->session()->put(self::SESSION_KEY, $target);
        }
    }

    /**
     * The candidate as an absolute URL, or null if it is not one we are willing to serve.
     */
    private static function sanitize(Request $request, ?string $candidate): ?string
    {
        $candidate = trim((string) $candidate);

        if ($candidate === '') {
            return null;
        }

        // A protocol-relative `//evil.example` parses with no scheme but is very much
        // off-host once a browser follows it.
        if (str_starts_with($candidate, '//')) {
            return null;
        }

        $absolute = str_starts_with($candidate, '/')
            ? $request->getSchemeAndHttpHost().$candidate
            : $candidate;

        $parts = parse_url($absolute);

        if ($parts === false || ! isset($parts['host'])) {
            return null;
        }

        if (! in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
            return null;
        }

        if (strcasecmp($parts['host'], $request->getHost()) !== 0) {
            return null;
        }

        return self::isAllowedRoute($absolute) ? $absolute : null;
    }

    /**
     * Does this URL resolve to a GET route we are happy to end a login on?
     */
    private static function isAllowedRoute(string $absolute): bool
    {
        try {
            $route = Route::getRoutes()->match(Request::create($absolute, 'GET'));
        } catch (\Throwable) {
            return false;
        }

        $name = $route->getName();

        if ($name === null) {
            return false;
        }

        foreach (self::ALLOWED as $allowed) {
            if ($allowed === $name) {
                return true;
            }

            if (str_ends_with($allowed, '.*') && str_starts_with($name, substr($allowed, 0, -1))) {
                return true;
            }
        }

        return false;
    }
}
