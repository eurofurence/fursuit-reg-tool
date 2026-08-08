<?php

use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;

/*
 * The respond() callback in bootstrap/app.php. It is the only thing standing
 * between a visitor and a bare framework error page, and it has four separate
 * escape hatches (JSON, debug, sessionless, unhandled status) that are easy to
 * break without noticing.
 */
beforeEach(function () {
    config(['app.debug' => false]);
});

it('renders the Inertia error page for a 404', function () {
    // A URI no route claimed skips the web group entirely, so the page is told
    // to drop the site chrome it has no shared props for.
    $this->get('/this-route-does-not-exist')
        ->assertStatus(404)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Error')
            ->where('status', 404)
            ->where('context', 'bare')
        );
});

it('keeps the site chrome for a 404 raised inside the web group', function () {
    Route::middleware('web')->get('/test-missing-badge', fn () => abort(404));

    $this->get('/test-missing-badge')
        ->assertStatus(404)
        ->assertInertia(fn (AssertableInertia $page) => $page->where('context', 'public'));
});

it('leaves the 405 method check to the router', function () {
    Route::middleware('web')->post('/test-post-only', fn () => 'ok');

    $this->get('/test-post-only')->assertStatus(405);
});

it('renders the Inertia error page for a 500', function () {
    Route::middleware('web')->get('/test-boom', fn () => throw new RuntimeException('database exploded'));

    $this->get('/test-boom')
        ->assertStatus(500)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Error')
            ->where('status', 500)
            // The exception message is an internal detail and must never be shown.
            ->where('message', null)
        );
});

it('passes the 4xx abort message through to the page', function () {
    Route::middleware('web')->get('/test-forbidden', fn () => abort(403, 'Badge pickup is staff only.'));

    $this->get('/test-forbidden')
        ->assertStatus(403)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('status', 403)
            ->where('message', 'Badge pickup is staff only.')
        );
});

it('does not leak the framework wording for a 404', function () {
    // "The route foo could not be found" and "No query results for model [...]"
    // are both written for a developer reading a log, not for a visitor.
    $this->get('/this-route-does-not-exist')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('message', null));

    Route::middleware('web')->get('/test-missing-model', fn () => throw new Illuminate\Database\Eloquent\ModelNotFoundException);

    $this->get('/test-missing-model')
        ->assertStatus(404)
        ->assertInertia(fn (AssertableInertia $page) => $page->where('message', null));
});

it('tags the interface the error happened in', function (string $uri, string $context) {
    Route::middleware('web')->get($uri, fn () => abort(404));

    $this->get($uri)->assertInertia(fn (AssertableInertia $page) => $page->where('context', $context));
})->with([
    ['pos/broken', 'pos'],
    ['admin/broken', 'admin'],
    ['gallery/broken', 'gallery'],
]);

it('sends an expired session back with a flash instead of an error page', function () {
    Route::middleware('web')->get('/test-expired', fn () => abort(419));

    $this->from('/')
        ->get('/test-expired')
        ->assertRedirect('/')
        ->assertSessionHas('error');
});

it('leaves JSON clients alone', function () {
    $this->getJson('/api/does-not-exist')
        ->assertStatus(404)
        ->assertJsonStructure(['message']);
});

it('leaves the framework error page alone when debug is on', function () {
    config(['app.debug' => true]);

    $this->get('/this-route-does-not-exist')
        ->assertStatus(404)
        ->assertHeaderMissing('x-inertia');
});

it('renders the maintenance page without touching the container', function () {
    // `artisan down --render=errors::503` compiles this into
    // storage/framework/maintenance.php and serves it with the framework never
    // booted, so it must stand on its own: no Vite, no Inertia, no helpers.
    $html = view('errors.503')->render();

    expect($html)->toContain('Down for Maintenance')
        ->and($html)->not->toContain('@vite')
        ->and($html)->not->toContain('data-page');
});

it('renders the last-resort 500 page', function () {
    expect(view('errors.500')->render())->toContain('Server Error');
});

it('does not handle statuses that are not visitor facing', function () {
    Route::middleware('web')->get('/test-unprocessable', fn () => abort(422));

    $this->get('/test-unprocessable')
        ->assertStatus(422)
        ->assertHeaderMissing('x-inertia');
});
