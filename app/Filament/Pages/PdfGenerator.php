<?php

namespace App\Filament\Pages;

use App\Models\Badge\Badge;
use App\Models\Badge\State_Payment\Paid;
use App\Models\Badge\State_Payment\Unpaid;
use App\Models\Event;
use Mpdf\Mpdf;
use Filament\Actions\Action;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;

class PdfGenerator extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static string $view = 'filament.pages.pdf-generator';

    protected static ?string $navigationGroup = 'Tools';

    protected static ?string $title = 'PDF Generator';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'pdf_type' => 'badge_list',
            'payment_status' => 'all',
            'badge_ranges' => '0-999,1000-1999,2000-2999,3000-3999,4000-4999',
            'title' => '',
            'subtitle' => '',
            'rows_per_column' => 50,
            'columns' => 12,
            'font_size' => 6,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('PDF Generation Options')
                    ->description('Generate PDFs for badge management')
                    ->schema([
                        Select::make('pdf_type')
                            ->label('PDF Type')
                            ->options([
                                'badge_list' => 'Badge List (Badges by Range)',
                                'box_labels' => 'Box Labels (3 per A4 page)',
                            ])
                            ->required()
                            ->default('badge_list')
                            ->reactive(),

                        Select::make('payment_status')
                            ->label('Payment Status Filter')
                            ->options([
                                'all' => 'All Badges',
                                'paid' => 'Paid Badges Only',
                                'unpaid' => 'Unpaid Badges Only',
                            ])
                            ->required()
                            ->default('all')
                            ->visible(fn ($get) => $get('pdf_type') === 'badge_list'),

                        Textarea::make('badge_ranges')
                            ->label('Badge Ranges')
                            ->placeholder('e.g., 1-1699,1700-2400,2401-3000')
                            ->helperText('Enter comma-separated ranges (e.g., 1-1699,1700-2400). Each range will be on a separate page.')
                            ->rows(3)
                            ->required()
                            ->visible(fn ($get) => $get('pdf_type') === 'badge_list'),

                        Grid::make(2)
                            ->schema([
                                TextInput::make('title')
                                    ->label('Title')
                                    ->placeholder('e.g., "Badge Range 1-999"')
                                    ->visible(fn ($get) => $get('pdf_type') === 'box_labels'),

                                TextInput::make('subtitle')
                                    ->label('Subtitle')
                                    ->placeholder('e.g., "Free Badges"')
                                    ->visible(fn ($get) => $get('pdf_type') === 'box_labels'),
                            ]),

                        Grid::make(3)
                            ->schema([
                                TextInput::make('rows_per_column')
                                    ->label('Rows per Column')
                                    ->numeric()
                                    ->default(50)
                                    ->placeholder('50')
                                    ->visible(fn ($get) => $get('pdf_type') === 'badge_list'),

                                TextInput::make('columns')
                                    ->label('Number of Columns')
                                    ->numeric()
                                    ->default(12)
                                    ->placeholder('12')
                                    ->visible(fn ($get) => $get('pdf_type') === 'badge_list'),

                                TextInput::make('font_size')
                                    ->label('Font Size (px)')
                                    ->numeric()
                                    ->default(6)
                                    ->placeholder('6')
                                    ->visible(fn ($get) => $get('pdf_type') === 'badge_list'),
                            ]),
                    ]),
            ])
            ->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate_badge_list')
                ->label('Generate Badge List PDF')
                ->icon('heroicon-o-document-text')
                ->color('primary')
                ->visible(fn () => $this->data['pdf_type'] === 'badge_list')
                ->action('generateBadgeListPdf'),

            Action::make('generate_box_labels')
                ->label('Generate Box Labels PDF')
                ->icon('heroicon-o-tag')
                ->color('success')
                ->visible(fn () => $this->data['pdf_type'] === 'box_labels')
                ->action('generateBoxLabelsPdf'),
        ];
    }

    public function generateBadgeListPdf()
    {
        $selectedEvent = $this->getSelectedEvent();

        if (!$selectedEvent) {
            Notification::make()
                ->title('Error')
                ->body('No event selected in the header.')
                ->danger()
                ->send();
            return;
        }

        // Build the query based on payment status filter
        $query = Badge::whereHas('fursuit', function ($query) use ($selectedEvent) {
                $query->where('event_id', $selectedEvent->id);
            })
            ->with(['fursuit.user.eventUsers' => function ($query) use ($selectedEvent) {
                $query->where('event_id', $selectedEvent->id);
            }]);

        // Apply payment status filter
        $paymentStatus = $this->data['payment_status'] ?? 'all';
        if ($paymentStatus === 'paid') {
            $query->whereState('status_payment', Paid::class);
        } elseif ($paymentStatus === 'unpaid') {
            $query->whereState('status_payment', Unpaid::class);
        }
        // 'all' - no additional filter needed

        $badges = $query->get()
            ->sortBy(function ($badge) {
                // Sort by custom_id (badge number)
                if (empty($badge->custom_id)) {
                    return [999999, 999999]; // Put badges without custom_id at the end
                }
                return $this->parseCustomId($badge->custom_id);
            })
            ->values();

        if ($badges->isEmpty()) {
            $filterText = match($paymentStatus) {
                'paid' => 'paid badges',
                'unpaid' => 'unpaid badges',
                default => 'badges'
            };
            
            Notification::make()
                ->title('No Data')
                ->body("No {$filterText} found for the current event.")
                ->warning()
                ->send();
            return;
        }

        // Parse custom ranges if provided
        $customRanges = [];
        if (!empty($this->data['badge_ranges'])) {
            $customRanges = $this->parseRanges($this->data['badge_ranges']);
            
            // Validate that we have at least one valid range
            if (empty($customRanges)) {
                Notification::make()
                    ->title('Invalid Range Format')
                    ->body('Please enter valid badge ranges in the format: 1-1699,1700-2400')
                    ->danger()
                    ->send();
                return;
            }
        }

        // Group badges by ranges and attendees
        $groupedBadges = $this->groupBadgesByRangeAndAttendee($badges, $customRanges);
        
        // Check if we have any badges in the defined ranges
        if (empty($groupedBadges)) {
            Notification::make()
                ->title('No Badges in Ranges')
                ->body('No badges found within the specified ranges. Please check your range settings.')
                ->warning()
                ->send();
            return;
        }

        $mpdf = new Mpdf([
            'format' => 'A4',
            'orientation' => 'P',
            'margin_left' => 5,
            'margin_right' => 5,
            'margin_top' => 5,
            'margin_bottom' => 5,
            'mode' => 'utf-8',
            'default_font' => 'helvetica',
        ]);

        // Write CSS first
        $css = view('pdfs.badge-list-css')->render();
        $css = mb_convert_encoding($css, 'UTF-8', 'auto');
        $mpdf->WriteHTML($css, \Mpdf\HTMLParserMode::HEADER_CSS);

        // Write header
        $header = view('pdfs.badge-list-header', [
            'event' => $selectedEvent,
        ])->render();
        $header = mb_convert_encoding($header, 'UTF-8', 'auto');
        $mpdf->WriteHTML($header, \Mpdf\HTMLParserMode::HTML_BODY);

        // Sort ranges by their numeric start value
        $sortedRanges = [];
        if (!empty($customRanges)) {
            // If using custom ranges, maintain the order from the input
            foreach ($customRanges as $range) {
                $rangeKey = $range['key'];
                if (isset($groupedBadges[$rangeKey])) {
                    $sortedRanges[] = ['range' => $rangeKey, 'attendees' => $groupedBadges[$rangeKey]];
                }
            }
        } else {
            // For default ranges, sort by numeric start value
            foreach ($groupedBadges as $range => $attendees) {
                $parts = explode('-', $range);
                $sortKey = (int) $parts[0];
                $sortedRanges[$sortKey] = ['range' => $range, 'attendees' => $attendees];
            }
            ksort($sortedRanges); // Sort by numeric key
            $sortedRanges = array_values($sortedRanges); // Reset array keys
        }

        // Write each range section on its own page
        $isFirst = true;
        foreach ($sortedRanges as $data) {
            if (!$isFirst) {
                $mpdf->AddPage();
            }
            $isFirst = false;

            $rangeHtml = view('pdfs.badge-list-range', [
                'range' => $data['range'],
                'attendees' => $data['attendees'],
                'rowsPerColumn' => $this->data['rows_per_column'] ?? 50,
                'columns' => $this->data['columns'] ?? 12,
                'fontSize' => $this->data['font_size'] ?? 6,
            ])->render();
            $rangeHtml = mb_convert_encoding($rangeHtml, 'UTF-8', 'auto');

            $mpdf->WriteHTML($rangeHtml, \Mpdf\HTMLParserMode::HTML_BODY);
        }

        $paymentStatusSuffix = match($paymentStatus) {
            'paid' => '-paid',
            'unpaid' => '-unpaid',
            default => ''
        };
        
        return response()->streamDownload(function () use ($mpdf) {
            echo $mpdf->Output('', 'S');
        }, "badge-list-{$selectedEvent->name}{$paymentStatusSuffix}-" . now()->format('Y-m-d') . '.pdf');
    }

    public function generateBoxLabelsPdf()
    {
        $title = $this->data['title'] ?? '';
        $subtitle = $this->data['subtitle'] ?? '';

        if (empty($title)) {
            Notification::make()
                ->title('Error')
                ->body('Title is required for box labels.')
                ->danger()
                ->send();
            return;
        }

        // Generate 3 labels for one page - user can customize what goes in each
        // For now, just create 3 identical labels with the title/subtitle

        // Safely encode title and subtitle, handling empty values
        $safeTitle = $title ? mb_convert_encoding($title, 'UTF-8', 'UTF-8') : '';
        $safeSubtitle = $subtitle ? mb_convert_encoding($subtitle, 'UTF-8', 'UTF-8') : '';

        $html = view('pdfs.box-labels', [
            'title' => $safeTitle,
            'subtitle' => $safeSubtitle,
        ])->render();

        // Ensure HTML is properly UTF-8 encoded
        if (!mb_check_encoding($html, 'UTF-8')) {
            $html = mb_convert_encoding($html, 'UTF-8', 'auto');
        }

        // Custom page size for endless paper printer - exactly 1/3 of A4 minus 5mm
        $mpdf = new Mpdf([
            'format' => [210, 94], // 210mm wide (A4 width), 94mm tall (99mm - 5mm)
            'orientation' => 'P',
            'margin_left' => 5,
            'margin_right' => 5,
            'margin_top' => 5,
            'margin_bottom' => 5,
            'mode' => 'utf-8',
            'default_font' => 'helvetica',
        ]);

        $mpdf->WriteHTML($html);

        return response()->streamDownload(function () use ($mpdf) {
            echo $mpdf->Output('', 'S');
        }, "box-label-" . \Str::slug($title) . "-" . now()->format('Y-m-d') . '.pdf');
    }

    private function getSelectedEvent(): ?Event
    {
        // Get the selected event from Filament's event filter
        $eventId = session('filament.admin.selected_event_id');
        if (!$eventId) {
            return Event::latest('starts_at')->first();
        }
        return Event::find($eventId);
    }

    private function parseCustomId(?string $customId): array
    {
        // Handle null or empty custom_id
        if (empty($customId)) {
            return [0, 0];
        }

        // Parse custom_id like "10-1" or "104-1" into sortable array [10, 1] or [104, 1]
        $parts = explode('-', $customId);
        $result = [];
        foreach ($parts as $part) {
            $result[] = (int) $part;
        }
        // Pad with zeros if needed to ensure consistent comparison
        while (count($result) < 2) {
            $result[] = 0;
        }
        return $result;
    }

    private function parseRanges(string $rangesString): array
    {
        $ranges = [];
        $rangeParts = explode(',', $rangesString);
        
        foreach ($rangeParts as $range) {
            $range = trim($range);
            if (empty($range)) {
                continue;
            }
            
            // Parse range like "1-1699" into [1, 1699]
            $parts = explode('-', $range);
            if (count($parts) === 2) {
                $start = (int) trim($parts[0]);
                $end = (int) trim($parts[1]);
                
                if ($start <= $end) {
                    $ranges[] = [
                        'start' => $start,
                        'end' => $end,
                        'key' => "{$start}-{$end}"
                    ];
                }
            }
        }
        
        // Sort ranges by start value
        usort($ranges, function ($a, $b) {
            return $a['start'] <=> $b['start'];
        });
        
        return $ranges;
    }

    private function groupBadgesByRangeAndAttendee(Collection $badges, array $customRanges = []): array
    {
        $grouped = [];

        // Use custom ranges if provided, otherwise fall back to default 1000-badge ranges
        $useCustomRanges = !empty($customRanges);

        foreach ($badges as $badge) {
            // Only include badges with custom_ids
            if (!empty($badge->custom_id)) {
                // Parse the custom_id to get the main badge number (e.g., "104-1" -> 104)
                $parsedId = $this->parseCustomId($badge->custom_id);
                $mainBadgeNumber = $parsedId[0]; // Get the first part of the custom_id

                $rangeKey = null;

                if ($useCustomRanges) {
                    // Find which custom range this badge belongs to
                    foreach ($customRanges as $range) {
                        if ($mainBadgeNumber >= $range['start'] && $mainBadgeNumber <= $range['end']) {
                            $rangeKey = $range['key'];
                            break;
                        }
                    }
                } else {
                    // Use default 1000-badge ranges
                    $rangeStart = intval($mainBadgeNumber / 1000) * 1000;
                    $rangeEnd = $rangeStart + 999;
                    $rangeKey = "{$rangeStart}-{$rangeEnd}";
                }

                // Only add badge if it falls within a range
                if ($rangeKey !== null) {
                    if (!isset($grouped[$rangeKey])) {
                        $grouped[$rangeKey] = [];
                    }

                    $grouped[$rangeKey][] = [
                        'attendee_id' => $badge->custom_id, // Display custom_id in the PDF
                        'sort_key' => $parsedId, // Parse for proper numeric sorting
                    ];
                }
            }
        }

        // Sort custom_ids within each range by parsed numeric values
        foreach ($grouped as &$rangeData) {
            usort($rangeData, function ($a, $b) {
                return $a['sort_key'] <=> $b['sort_key'];
            });
        }

        return $grouped;
    }
}
