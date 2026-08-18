<?php

use App\Models\Event;
use App\Models\EventUser;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\withSession;

function fakeIdentity(): void
{
    $socialiteUser = new SocialiteUser;
    $socialiteUser->map([
        'id' => 'remote-1',
        'name' => 'Test Attendee',
        'email' => 'attendee@example.com',
        'avatar' => null,
    ]);
    $socialiteUser->token = 'access-token';
    $socialiteUser->refreshToken = 'refresh-token';

    $provider = Mockery::mock(Provider::class);
    $provider->shouldReceive('scopes')->andReturnSelf();
    $provider->shouldReceive('redirectUrl')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn($socialiteUser);
    $provider->shouldReceive('redirect')->andReturn(redirect('https://identity.example/oauth2/auth'));

    Socialite::shouldReceive('driver')->with('identity')->andReturn($provider);

    Http::fake([
        '*/attendees' => Http::response(['ids' => [42]]),
        '*/attendees/42/status' => Http::response(['status' => 'paid']),
        '*/attendees/42/packages/*' => Http::response(['present' => false, 'count' => 0]),
    ]);
}

beforeEach(fn () => fakeIdentity());

it('notes the page the visitor came from when the login starts', function () {
    get('/auth/login', ['referer' => 'http://localhost/catch-em-all']);

    expect(session('url.intended'))->toBe('http://localhost/catch-em-all');
});

it('ignores a referer pointing off site', function () {
    get('/auth/login', ['referer' => 'https://evil.example/phish']);

    expect(session('url.intended'))->toBeNull();
});

it('returns the visitor to the page they signed in from', function () {
    withSession(['url.intended' => 'http://localhost/catch-em-all'])
        ->get('/auth/callback?code=abc&state=xyz')
        ->assertRedirect('http://localhost/catch-em-all');

    expect(User::where('remote_id', 'remote-1')->exists())->toBeTrue();
});

it('falls back to the dashboard when nothing was noted', function () {
    get('/auth/callback?code=abc&state=xyz')
        ->assertRedirect(route('dashboard'));
});

it('refuses to bounce a finished login off site', function () {
    withSession(['url.intended' => 'https://evil.example/phish'])
        ->get('/auth/callback?code=abc&state=xyz')
        ->assertRedirect(route('dashboard'));
});

it('returns a game visitor to the game page they signed in from', function () {
    withSession(['url.intended' => 'http://catch.localhost/leaderboard'])
        ->get('http://catch.localhost/auth/callback?code=abc&state=xyz')
        ->assertRedirect('http://catch.localhost/leaderboard');
});

it('falls back to the introduction on the game domain', function () {
    get('http://catch.localhost/auth/callback?code=abc&state=xyz')
        ->assertRedirect(route('catch-em-all.introduction'));
});

it('hands the visitor on after the introduction they were interrupted by', function () {
    $event = Event::factory()->create(['starts_at' => now()->subDay()]);
    $user = User::factory()->create();
    EventUser::factory()->create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'catch_em_all_introduced' => false,
    ]);

    actingAs($user)
        ->get('http://catch.localhost/collection')
        ->assertRedirect(route('catch-em-all.introduction'));

    expect(session('url.intended'))->toBe('http://catch.localhost/collection');

    actingAs($user)
        ->post('http://catch.localhost/introduction/complete')
        ->assertRedirect('http://catch.localhost/collection');
});
