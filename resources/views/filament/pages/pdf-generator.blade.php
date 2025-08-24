<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">
                PDF Generator
            </x-slot>
            
            <x-slot name="description">
                Generate PDFs for badge management and box labeling
            </x-slot>
            
            <div class="space-y-4">
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                    <h3 class="font-semibold text-blue-900 mb-2">Badge List PDF</h3>
                    <p class="text-sm text-blue-800">
                        Generates a list of all free badges for the current event, grouped by ranges (0-999, 1000-1999, etc.) 
                        with 3 columns per page. Each row shows one attendee with all their badge numbers.
                    </p>
                </div>
                
                <div class="bg-green-50 border border-green-200 rounded-lg p-4">
                    <h3 class="font-semibold text-green-900 mb-2">Box Labels PDF</h3>
                    <p class="text-sm text-green-800">
                        Generates A4 pages with 3 labels per page for badge boxes. Each label takes 1/3 of the page 
                        and includes a configurable title and subtitle.
                    </p>
                </div>
            </div>
        </x-filament::section>
        
        {{ $this->form }}
    </div>
</x-filament-panels::page>