<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class CatchEmAllCache extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-circle-stack';

    protected static ?string $navigationGroup = 'Catch Em All';

    protected static ?string $navigationLabel = 'Cache';

    protected static ?string $title = 'Catch Em All Cache';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.catch-em-all-cache';

    public function mount(): void
    {
        redirect()->route('admin.tools.catch-em-all-cache')->send();
    }

    public static function canAccess(): bool
    {
        return (bool) (auth()->user()?->is_admin);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }
}
