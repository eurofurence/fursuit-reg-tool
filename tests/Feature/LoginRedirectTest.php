<?php

use App\Support\LoginRedirect;
use Illuminate\Http\Request;

function loginRedirectRequest(string $url): Request
{
    $request = Request::create($url);
    $request->setLaravelSession(app('session.store'));

    return $request;
}

it('keeps an allowlisted page on the public site', function () {
    $request = loginRedirectRequest('http://localhost/auth/login?redirect=/faq');

    LoginRedirect::remember($request);

    expect(LoginRedirect::resolve($request, 'http://localhost/fallback'))
        ->toBe('http://localhost/faq');
});

it('keeps an allowlisted page on the game domain', function () {
    $request = loginRedirectRequest('http://catch.localhost/auth/login?redirect=/leaderboard');

    LoginRedirect::remember($request);

    expect(LoginRedirect::resolve($request, 'http://catch.localhost/fallback'))
        ->toBe('http://catch.localhost/leaderboard');
});

it('falls back to the referer when no redirect is given', function () {
    $request = loginRedirectRequest('http://localhost/auth/login');
    $request->headers->set('referer', 'http://localhost/catch-em-all');

    LoginRedirect::remember($request);

    expect(LoginRedirect::resolve($request, 'http://localhost/fallback'))
        ->toBe('http://localhost/catch-em-all');
});

it('consumes the stored target so a later login does not reuse it', function () {
    $request = loginRedirectRequest('http://localhost/auth/login?redirect=/faq');

    LoginRedirect::remember($request);
    LoginRedirect::resolve($request, 'http://localhost/fallback');

    expect(LoginRedirect::resolve($request, 'http://localhost/fallback'))
        ->toBe('http://localhost/fallback');
});

it('records the current page for a detour', function () {
    $request = loginRedirectRequest('http://catch.localhost/collection');

    LoginRedirect::keep($request);

    expect(LoginRedirect::resolve($request, 'http://catch.localhost/fallback'))
        ->toBe('http://catch.localhost/collection');
});

dataset('rejected targets', [
    'another host' => 'https://evil.example/phish',
    'protocol relative' => '//evil.example/phish',
    'non http scheme' => 'javascript:alert(1)',
    'a route nobody may land on' => '/pos',
    'the login route itself' => '/auth/login',
    'an unrouted path' => '/nope-not-a-route',
    'a route the game owns, from the public site' => 'http://catch.localhost/leaderboard',
]);

it('refuses a target that is not allowlisted', function (string $target) {
    $request = loginRedirectRequest('http://localhost/auth/login?redirect='.urlencode($target));

    LoginRedirect::remember($request);

    expect(LoginRedirect::resolve($request, 'http://localhost/fallback'))
        ->toBe('http://localhost/fallback');
})->with('rejected targets');

it('refuses a target smuggled straight into the session', function () {
    $request = loginRedirectRequest('http://localhost/auth/login');
    $request->session()->put('url.intended', 'https://evil.example/phish');

    expect(LoginRedirect::resolve($request, 'http://localhost/fallback'))
        ->toBe('http://localhost/fallback');
});

it('refuses a POST only route', function () {
    $request = loginRedirectRequest('http://catch.localhost/auth/login?redirect=/catch');

    LoginRedirect::remember($request);

    expect(LoginRedirect::resolve($request, 'http://catch.localhost/fallback'))
        ->toBe('http://catch.localhost/fallback');
});
