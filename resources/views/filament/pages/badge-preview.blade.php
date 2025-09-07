<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">
                Load Badge
            </x-slot>
            
            {{ $this->form }}
            
            <div class="mt-4">
                <x-filament::button wire:click="loadBadge">
                    Load Badge
                </x-filament::button>
            </div>
        </x-filament::section>
        
        @if($badge)
            <x-filament::section>
                <x-slot name="heading">
                    Badge Details
                </x-slot>
                
                <div class="space-y-2">
                    <div><strong>Custom ID:</strong> {{ $badge->custom_id }}</div>
                    <div><strong>Fursuit Name:</strong> {{ $badge->fursuit->name }}</div>
                    <div><strong>Species:</strong> {{ $badge->fursuit->species->name }}</div>
                    <div><strong>Owner:</strong> {{ $badge->fursuit->user->name }}</div>
                    <div><strong>Event:</strong> {{ $badge->fursuit->event->name }}</div>
                    <div><strong>Badge Type:</strong> {{ $badge->fursuit->event->badge_class ?? 'EF28_Badge' }}</div>
                </div>
                
                <div class="mt-6 flex gap-4">
                    <x-filament::button 
                        wire:click="viewPdf" 
                        target="_blank"
                        icon="heroicon-o-eye"
                    >
                        View PDF in Browser
                    </x-filament::button>
                    
                    <x-filament::button 
                        wire:click="downloadPdf" 
                        color="success"
                        icon="heroicon-o-arrow-down-tray"
                    >
                        Download PDF
                    </x-filament::button>
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>