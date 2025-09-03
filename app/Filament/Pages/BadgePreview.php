<?php

namespace App\Filament\Pages;

use App\Badges\EF28_Badge;
use App\Badges\EF29_Badge;
use App\Models\Badge\Badge;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Response;

class BadgePreview extends Page implements HasForms
{
    use InteractsWithForms;
    
    protected static ?string $navigationIcon = 'heroicon-o-identification';
    
    protected static string $view = 'filament.pages.badge-preview';
    
    protected static ?string $navigationLabel = 'Badge Preview';
    
    protected static ?string $navigationGroup = 'Debug Tools';
    
    protected static ?int $navigationSort = 100;
    
    public ?string $customId = null;
    
    public ?Badge $badge = null;
    
    public function mount(): void
    {
        $this->form->fill();
    }
    
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('customId')
                    ->label('Badge Custom ID')
                    ->placeholder('Enter badge custom ID (e.g., ABC123)')
                    ->required()
                    ->maxLength(255),
            ]);
    }
    
    public function loadBadge(): void
    {
        $this->validate();
        
        $this->badge = Badge::where('custom_id', $this->customId)->first();
        
        if (!$this->badge) {
            Notification::make()
                ->title('Badge not found')
                ->danger()
                ->body('No badge found with custom ID: ' . $this->customId)
                ->send();
            return;
        }
        
        Notification::make()
            ->title('Badge loaded')
            ->success()
            ->body('Badge found for: ' . $this->badge->fursuit->name)
            ->send();
    }
    
    public function downloadPdf()
    {
        if (!$this->badge) {
            Notification::make()
                ->title('No badge loaded')
                ->warning()
                ->body('Please load a badge first')
                ->send();
            return;
        }
        
        $badgeClass = $this->badge->fursuit->event->badge_class ?? 'EF28_Badge';
        
        $printer = match ($badgeClass) {
            'EF29_Badge' => new EF29_Badge,
            'EF28_Badge' => new EF28_Badge,
            default => new EF28_Badge,
        };
        
        $pdfContent = $printer->getPdf($this->badge);
        
        return Response::make($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="badge-' . $this->customId . '.pdf"',
        ]);
    }
    
    public function viewPdf()
    {
        if (!$this->badge) {
            Notification::make()
                ->title('No badge loaded')
                ->warning()
                ->body('Please load a badge first')
                ->send();
            return;
        }
        
        $badgeClass = $this->badge->fursuit->event->badge_class ?? 'EF28_Badge';
        
        $printer = match ($badgeClass) {
            'EF29_Badge' => new EF29_Badge,
            'EF28_Badge' => new EF28_Badge,
            default => new EF28_Badge,
        };
        
        $pdfContent = $printer->getPdf($this->badge);
        
        return Response::make($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="badge-' . $this->customId . '.pdf"',
        ]);
    }
}