<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): string|null
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => $this->getAuthContent($request),
            'flash' => [
                'message' => fn() => $request->session()->get('message'),
                'success' => fn() => $request->session()->get('success'),
                'error' => fn() => $request->session()->get('error'),
            ],
            // Get event that did not end yet and is the next one
            'event' => \App\Models\Event::latest('starts_at')->first(),
        ];
    }

    private function getAuthContent(Request $request): array
    {
        if($request->routeIs('pos.*')) {
            return [
                'user' => $request->user('machine-user')?->only(['id', 'name','is_admin']),
                'machine' => $request->user('machine'),
            ];
        }
        return [
            'user' => $request->user()?->load('badges'),
            'balance' => $request->user()?->balanceInt,
        ];
    }
}
