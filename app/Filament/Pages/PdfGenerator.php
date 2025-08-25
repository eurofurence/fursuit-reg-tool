<?php

namespace App\Filament\Pages;

use App\Models\Badge\Badge;
use App\Models\Event;
use Mpdf\Mpdf;
use Filament\Actions\Action;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
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
                                'badge_list' => 'Badge List (Free Badges by Range)',
                                'box_labels' => 'Box Labels (3 per A4 page)',
                            ])
                            ->required()
                            ->default('badge_list')
                            ->reactive(),

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

        // Get all free badges for the current event, sorted by attendee_id
        $badges = Badge::whereHas('fursuit', function ($query) use ($selectedEvent) {
                $query->where('event_id', $selectedEvent->id);
            })
            ->where('is_free_badge', true)
            ->with(['fursuit.user.eventUsers' => function ($query) use ($selectedEvent) {
                $query->where('event_id', $selectedEvent->id);
            }])
            ->get()
            ->sortBy(function ($badge) {
                // Sort by attendee_id first
                return $badge->fursuit?->user?->eventUsers?->first()?->attendee_id ?? 999999;
            })
            ->values();

        if ($badges->isEmpty()) {
            Notification::make()
                ->title('No Data')
                ->body('No free badges found for the current event.')
                ->warning()
                ->send();
            return;
        }

        // Group badges by ranges and attendees
        $groupedBadges = $this->groupBadgesByRangeAndAttendee($badges);

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

        // Sort ranges by their numeric start value (0-999, 1000-1999, etc.)
        $sortedRanges = [];
        foreach ($groupedBadges as $range => $attendees) {
            $parts = explode('-', $range);
            $sortKey = (int) $parts[0];
            $sortedRanges[$sortKey] = ['range' => $range, 'attendees' => $attendees];
        }
        ksort($sortedRanges); // Sort by numeric key

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

        return response()->streamDownload(function () use ($mpdf) {
            echo $mpdf->Output('', 'S');
        }, "badge-list-{$selectedEvent->name}-" . now()->format('Y-m-d') . '.pdf');
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

    private function groupBadgesByRangeAndAttendee(Collection $badges): array
    {
        $grouped = [];

        foreach ($badges as $badge) {
            // Get attendee_id for grouping by range
            $attendeeId = $badge->fursuit?->user?->eventUsers?->first()?->attendee_id ?? 0;
            $attendeeIdNum = (int) $attendeeId;
            
            // Group by attendee_id ranges (0-999, 1000-1999, etc.)
            $rangeStart = intval($attendeeIdNum / 1000) * 1000;
            $rangeEnd = $rangeStart + 999;
            $rangeKey = "{$rangeStart}-{$rangeEnd}";

            if (!isset($grouped[$rangeKey])) {
                $grouped[$rangeKey] = [];
            }

            // Only include badges with custom_ids
            if (!empty($badge->custom_id)) {
                // Store custom_id for display (but we've already sorted by attendee_id)
                $grouped[$rangeKey][] = [
                    'attendee_id' => $badge->custom_id, // Display custom_id in the PDF
                    'sort_key' => $this->parseCustomId($badge->custom_id), // Parse for proper numeric sorting
                ];
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
