<?php

namespace App\Models\UserProfile;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserProfileLink extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function userProfile(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class);
    }

    /**
     * We want to compare parts of the URL case-insensitively
     * so https://EXAMPLE.COM/profile is the same as https://example.com/profile
     */
    public static function normalizeUrl(string $url): string
    {
        $candidate = trim($url);

        if ($candidate !== '' && ! self::hasScheme($candidate)) {
            // Protocol-relative ("//example.com/x") only needs the scheme.
            $candidate = str_starts_with($candidate, '//')
                ? 'https:'.$candidate
                : 'https://'.$candidate;
        }

        $parts = parse_url($candidate);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        return strtolower($parts['scheme']).'://'
            .strtolower($parts['host'])
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .($parts['path'] ?? '')
            .(isset($parts['query']) ? '?'.$parts['query'] : '')
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }

    /**
     * Whether the input already carries a URL scheme.
     */
    private static function hasScheme(string $url): bool
    {
        return preg_match('#^[a-z][a-z0-9+.\-]*://#i', $url) === 1
            || preg_match('#^[a-z][a-z0-9+.\-]*:(?![0-9])#i', $url) === 1;
    }

    /**
     * Send the log to the user profile
     */
    protected static function booted(): void
    {
        static::created(fn (UserProfileLink $link) => $link->notifyProfile(
            'link_added',
            'Profile link added',
            ['url' => $link->url],
        ));

        static::updated(function (UserProfileLink $link) {
            if (! $link->wasChanged('url')) {
                return;
            }

            $link->notifyProfile('link_updated', 'Profile link updated', [
                'old' => ['url' => $link->getOriginal('url')],
                'attributes' => ['url' => $link->url],
            ]);
        });

        static::deleted(fn (UserProfileLink $link) => $link->notifyProfile(
            'link_removed',
            'Profile link removed',
            ['url' => $link->url],
        ));
    }

    /**
     * Logs the change against the user profile and flags it for re-review.
     */
    protected function notifyProfile(string $event, string $description, array $properties): void
    {
        $profile = $this->userProfile()->first();

        if (! $profile) {
            return;
        }

        activity()
            ->performedOn($profile)
            ->event($event)
            ->withProperties($properties)
            ->log($description);

        $profile->requiresReapproval();
    }
}
