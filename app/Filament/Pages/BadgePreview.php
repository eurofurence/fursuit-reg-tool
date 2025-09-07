<?php

namespace App\Filament\Pages;

use App\Models\Badge\Badge;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

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
        
        $this->badge = Badge::with(['fursuit.user', 'fursuit.species', 'fursuit.event'])
            ->where('custom_id', $this->customId)
            ->first();
        
        if (!$this->badge) {
            Notification::make()
                ->title('Badge not found')
                ->danger()
                ->body('No badge found with custom ID: ' . $this->customId)
                ->send();
            return;
        }
        
        // Sanitize the fursuit name for display
        $fursuitName = mb_convert_encoding($this->badge->fursuit->name, 'UTF-8', 'UTF-8');
        
        Notification::make()
            ->title('Badge loaded')
            ->success()
            ->body('Badge found for: ' . $fursuitName)
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
        
        return redirect()->route('admin.badge-pdf.download', ['customId' => $this->customId]);
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
        
        return redirect()->route('admin.badge-pdf.view', ['customId' => $this->customId]);
    }
}