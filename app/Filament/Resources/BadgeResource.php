<?php

namespace App\Filament\Resources;

use App\Domain\Printing\Models\Printer;
use App\Enum\PrintJobTypeEnum;
use App\Filament\Resources\BadgeResource\Pages;
use App\Filament\Traits\HasEventFilter;
use App\Jobs\Printing\PrintBadgeJob;
use App\Models\Badge\Badge;
use App\Models\Event;
use App\Models\Badge\State_Fulfillment\BadgeFulfillmentStatusState;
use App\Models\Badge\State_Fulfillment\Processing;
use App\Models\Badge\State_Payment\BadgePaymentStatusState;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;

class BadgeResource extends Resource
{
    use HasEventFilter;

    protected static ?string $model = Badge::class;

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationGroup = 'Events & Registration';

    protected static ?int $navigationSort = 2;

    public static function getNavigationBadge(): ?string
    {
        $eventId = static::getSelectedEventId();
        if (! $eventId) {
            return null;
        }

        return (string) Badge::whereHas('fursuit', fn ($q) => $q->where('event_id', $eventId))->count();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Badge Information')
                    ->description('Basic badge details and associated fursuit')
                    ->icon('heroicon-o-identification')
                    ->schema([
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\Select::make('fursuit_id')
                                    ->label('Fursuit')
                                    ->disabled()
                                    ->relationship('fursuit', 'name')
                                    ->required()
                                    ->helperText('The fursuit this badge belongs to (cannot be changed)')
                                    ->columnSpan(1),

                                Forms\Components\TextInput::make('custom_id')
                                    ->label('Badge ID')
                                    ->disabled()
                                    ->helperText('Unique badge identifier (auto-generated)')
                                    ->columnSpan(1),
                            ]),

                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('species_name')
                                    ->label('Species')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->formatStateUsing(fn ($record) => $record?->fursuit?->species?->name)
                                    ->helperText('The fursuit species')
                                    ->columnSpan(1),

                                Forms\Components\TextInput::make('owner_name')
                                    ->label('Owner')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->formatStateUsing(fn ($record) => $record?->fursuit?->user?->name)
                                    ->helperText('The fursuit owner')
                                    ->columnSpan(1),
                            ]),
                    ]),

                Forms\Components\Section::make('Status Management')
                    ->description('Current fulfillment and payment status of the badge')
                    ->icon('heroicon-o-flag')
                    ->schema([
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\Select::make('status_fulfillment')
                                    ->label('Fulfillment Status')
                                    ->options(BadgeFulfillmentStatusState::getStateMapping()->keys()->mapWithKeys(fn ($key
                                    ) => [$key => match ($key) {
                                        'pending' => 'Pending',
                                        'processing' => 'Processing',
                                        'ready_for_pickup' => 'Ready for Pickup',
                                        'picked_up' => 'Picked Up',
                                        default => ucfirst($key)
                                    }]))
                                    ->required()
                                    ->helperText('Current fulfillment stage of the badge')
                                    ->columnSpan(1),

                                Forms\Components\Select::make('status_payment')
                                    ->label('Payment Status')
                                    ->options(BadgePaymentStatusState::getStateMapping()->keys()->mapWithKeys(fn ($key
                                    ) => [$key => ucfirst($key)]))
                                    ->required()
                                    ->helperText('Current payment status')
                                    ->columnSpan(1),
                            ]),
                    ]),

                Forms\Components\Section::make('Pricing Details')
                    ->description('Badge pricing breakdown and financial information')
                    ->icon('heroicon-o-banknotes')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('total')
                                    ->label('Total (€)')
                                    ->prefix('€')
                                    ->numeric()
                                    ->step(0.01)
                                    ->formatStateUsing(fn ($state) => number_format($state / 100, 2))
                                    ->helperText('Total amount in euros')
                                    ->columnSpan(1),

                                Forms\Components\TextInput::make('subtotal')
                                    ->label('Subtotal (€)')
                                    ->prefix('€')
                                    ->disabled()
                                    ->formatStateUsing(fn ($state) => number_format($state / 100, 2))
                                    ->helperText('Amount before tax')
                                    ->columnSpan(1),

                                Forms\Components\TextInput::make('tax')
                                    ->label('Tax (€)')
                                    ->prefix('€')
                                    ->disabled()
                                    ->formatStateUsing(fn ($state) => number_format($state / 100, 2))
                                    ->helperText('Tax amount')
                                    ->columnSpan(1),
                            ]),

                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\Toggle::make('is_free_badge')
                                    ->label('Free Badge')
                                    ->disabled()
                                    ->helperText('Whether this badge was provided for free')
                                    ->inline(false),

                                Forms\Components\Toggle::make('extra_copy')
                                    ->label('Extra Copy')
                                    ->disabled()
                                    ->helperText('Whether this is an additional copy of another badge')
                                    ->inline(false),
                            ]),
                    ]),

                Forms\Components\Section::make('Badge Features & Upgrades')
                    ->description('Special features and upgrade options applied to this badge')
                    ->icon('heroicon-o-star')
                    ->collapsed()
                    ->schema([
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\Toggle::make('dual_side_print')
                                    ->label('Double-Sided Print')
                                    ->disabled()
                                    ->helperText('Whether the badge is printed on both sides')
                                    ->inline(false),

                                Forms\Components\Toggle::make('apply_late_fee')
                                    ->label('Late Fee Applied')
                                    ->disabled()
                                    ->helperText('Whether a late fee was applied to this badge')
                                    ->inline(false),
                            ]),
                    ]),

                Forms\Components\Section::make('Timeline & Processing')
                    ->description('Key dates and processing timestamps')
                    ->icon('heroicon-o-clock')
                    ->collapsed()
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\DateTimePicker::make('created_at')
                                    ->label('Created At')
                                    ->disabled()
                                    ->helperText('When the badge was initially created')
                                    ->columnSpan(1),

                                Forms\Components\DateTimePicker::make('printed_at')
                                    ->label('Printed At')
                                    ->disabled()
                                    ->helperText('When the badge was printed')
                                    ->columnSpan(1),

                                Forms\Components\DateTimePicker::make('picked_up_at')
                                    ->label('Picked Up At')
                                    ->disabled()
                                    ->helperText('When the badge was collected by the owner')
                                    ->columnSpan(1),
                            ]),
                    ]),
            ])
            ->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function ($query) {
                $query = static::applyEventFilter($query, 'fursuit');

                // Add joins for attendee_id sorting but select only badges columns to avoid conflicts
                return $query->leftJoin('fursuits', 'badges.fursuit_id', '=', 'fursuits.id')
                    ->leftJoin('event_users', function ($join) {
                        $join->on('fursuits.user_id', '=', 'event_users.user_id')
                            ->on('fursuits.event_id', '=', 'event_users.event_id');
                    })
                    ->select('badges.*')
                    ->addSelect('event_users.attendee_id as sort_attendee_id');
            })
            ->columns([
                // Fursuit Image as first column
                Tables\Columns\ImageColumn::make('fursuit.image')
                    ->label('Image')
                    ->disk('s3')
                    ->visibility('private')
                    ->circular()
                    ->size(40)
                    ->defaultImageUrl(url('/images/placeholder.png'))
                    ->checkFileExistence(false),

                // Fursuit Name
                Tables\Columns\TextColumn::make('fursuit.name')
                    ->searchable()
                    ->label('Fursuit')
                    ->sortable()
                    ->url(fn (Badge $record): string => route('filament.admin.resources.fursuits.view', ['record' => $record->fursuit->id])),

                // Species
                Tables\Columns\TextColumn::make('fursuit.species.name')
                    ->label('Species')
                    ->color('gray')
                    ->toggleable(),

                // User Info
                Tables\Columns\TextColumn::make('fursuit.user.name')
                    ->label('Owner')
                    ->searchable()
                    ->toggleable()
                    ->url(fn (Badge $record): string => '/admin/users?tableSearch='.urlencode($record->fursuit->user->name)),

                // Badge ID
                Tables\Columns\TextColumn::make('custom_id')
                    ->label('Badge ID')
                    ->searchable()
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: false),

                // Attendee ID
                Tables\Columns\TextColumn::make('sort_attendee_id')
                    ->label('Attendee ID')
                    ->formatStateUsing(fn ($state) => $state ?? 'N/A')
                    ->sortable(query: function ($query, string $direction) {
                        return $query->orderByRaw("CAST(sort_attendee_id AS UNSIGNED) $direction");
                    })
                    ->toggleable(isToggledHiddenByDefault: false),

                // Print Jobs Column
                Tables\Columns\TextColumn::make('print_jobs_count')
                    ->label('Print Jobs')
                    ->badge()
                    ->url(fn (Badge $record): string => route('filament.admin.resources.print-jobs.index', [
                        'tableFilters[printable_id][value]' => $record->id,
                        'tableFilters[printable_type][value]' => get_class($record),
                    ]))
                    ->getStateUsing(function (Badge $record): string {
                        $jobs = $record->printJobs()->get();
                        $total = $jobs->count();
                        $pending = $jobs->whereIn('status', ['pending', 'queued', 'printing', 'retrying'])->count();
                        $failed = $jobs->where('status', 'failed')->count();
                        $printed = $jobs->where('status', 'printed')->count();

                        if ($total === 0) return '0';
                        if ($failed > 0) return "{$total} ({$failed} failed)";
                        if ($pending > 0) return "{$total} ({$pending} pending)";
                        return "{$total}";
                    })
                    ->color(function (Badge $record): string {
                        $jobs = $record->printJobs()->get();
                        if ($jobs->count() === 0) return 'gray';

                        $hasFailed = $jobs->where('status', 'failed')->count() > 0;
                        $hasPending = $jobs->whereIn('status', ['pending', 'queued', 'printing', 'retrying'])->count() > 0;

                        if ($hasFailed) return 'warning';
                        if ($hasPending) return 'info';
                        return 'success';
                    })
                    ->alignCenter(),

                // Status Badges
                Tables\Columns\TextColumn::make('status_fulfillment')
                    ->label('Fulfillment')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending' => 'Pending',
                        'processing' => 'Processing',
                        'ready_for_pickup' => 'Ready for Pickup',
                        'picked_up' => 'Picked Up',
                        default => ucfirst($state)
                    }),

                Tables\Columns\TextColumn::make('status_payment')
                    ->label('Payment')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),

                // Badge Features
                Tables\Columns\IconColumn::make('extra_copy')
                    ->label('Extra Copy')
                    ->boolean()
                    ->trueIcon('heroicon-o-document-plus')
                    ->falseIcon(null)
                    ->toggleable(isToggledHiddenByDefault: true),

                // Pricing Info
                Tables\Columns\TextColumn::make('total')
                    ->label('Total')
                    ->money('EUR')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                // Timestamps
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('M j, Y')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('printed_at')
                    ->label('Printed At')
                    ->dateTime('M j, Y H:i')
                    ->placeholder('Not printed')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('picked_up_at')
                    ->label('Picked Up')
                    ->dateTime('M j, Y H:i')
                    ->placeholder('Not picked up')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status_fulfillment')
                    ->options([
                        'pending' => 'Pending',
                        'processing' => 'Processing',
                        'ready_for_pickup' => 'Ready for Pickup',
                        'picked_up' => 'Picked Up',
                    ])
                    ->label('Fulfillment Status')
                    ->multiple()
                    ->placeholder('All Statuses'),

                Tables\Filters\SelectFilter::make('status_payment')
                    ->options([
                        'unpaid' => 'Unpaid',
                        'paid' => 'Paid',
                    ])
                    ->label('Payment Status')
                    ->multiple()
                    ->placeholder('All Payments'),

                Tables\Filters\TernaryFilter::make('is_free_badge')
                    ->label('Free Badge')
                    ->placeholder('All Badges')
                    ->trueLabel('Free Badges Only')
                    ->falseLabel('Paid Badges Only'),

                Tables\Filters\Filter::make('attendee_id_range')
                    ->form([
                        Forms\Components\TextInput::make('from_attendee_id')
                            ->label('From Attendee ID')
                            ->numeric()
                            ->placeholder('e.g., 1'),
                        Forms\Components\TextInput::make('to_attendee_id')
                            ->label('To Attendee ID')
                            ->numeric()
                            ->placeholder('e.g., 1000'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from_attendee_id'], function ($query, $fromAttendeeId) {
                                return $query->whereHas('fursuit.user.eventUsers', function ($q) use ($fromAttendeeId) {
                                    $q->where('event_id', static::getSelectedEventId())
                                        ->whereRaw('CAST(attendee_id AS UNSIGNED) >= ?', [$fromAttendeeId]);
                                });
                            })
                            ->when($data['to_attendee_id'], function ($query, $toAttendeeId) {
                                return $query->whereHas('fursuit.user.eventUsers', function ($q) use ($toAttendeeId) {
                                    $q->where('event_id', static::getSelectedEventId())
                                        ->whereRaw('CAST(attendee_id AS UNSIGNED) <= ?', [$toAttendeeId]);
                                });
                            });
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['from_attendee_id']) {
                            $indicators[] = 'From attendee #'.$data['from_attendee_id'];
                        }
                        if ($data['to_attendee_id']) {
                            $indicators[] = 'To attendee #'.$data['to_attendee_id'];
                        }

                        return $indicators;
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('printBadge')
                    ->color('warning')
                    ->icon('heroicon-o-printer')
                    ->requiresConfirmation(true)
                    ->label('Print Badge')
                    ->action(function (Badge $record) {
                        return static::printBadge($record);
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('printBadgeBulk')
                    ->label('Print Badges')
                    ->icon('heroicon-o-printer')
                    ->color('warning')
                    ->form([
                        Forms\Components\Select::make('printer_id')
                            ->label('Select Printer')
                            ->options(
                                Printer::where('type', PrintJobTypeEnum::Badge)
                                    ->where('is_active', true)
                                    ->pluck('name', 'id')
                            )
                            ->required()
                            ->helperText('Select a specific printer for all selected badges.'),
                    ])
                    ->requiresConfirmation()
                    ->modalHeading('Print Selected Badges')
                    ->modalDescription('This will print all selected badges to the specified printer.')
                    ->action(function (Collection $records, array $data) {
                        $printerId = $data['printer_id'];
                        // sort by attendee id numerically
                        $sortedRecords = $records->sortBy(fn (Badge $badge) => (int) $badge->sort_attendee_id);

                        // Update badge states to mark them as sent for printing
                        $sortedRecords->each(function (Badge $record) {
                            if ($record->status_fulfillment->canTransitionTo(Processing::class)) {
                                $record->status_fulfillment->transitionTo(Processing::class);
                            }
                        });

                        // Create individual print jobs for batching in the correct order
                        $printJobs = $sortedRecords->map(function (Badge $badge) use ($printerId) {
                            return new PrintBadgeJob($badge, $printerId);
                        })->toArray();

                        // Create a Laravel batch with proper chaining
                        Bus::batch([
                            // wrap in array to chain!
                            $printJobs
                        ])
                            ->name("Badge Bulk Print - {$records->count()} badges")
                            ->onQueue('batch-print')
                            ->allowFailures()
                            ->dispatch();

                        return true;
                    }),
            ])
            ->selectCurrentPageOnly()
            ->paginationPageOptions([10, 25, 50, 100])
            ->defaultSort('sort_attendee_id', 'asc')
            ->poll('5s');
    }

    public static function printBadge(Badge $badge, $mass = 0, ?int $printerId = null): Badge
    {
        \Log::info('printBadge called', [
            'badge_id' => $badge->id,
            'before_fulfillment' => $badge->status_fulfillment->getValue(),
            'before_payment' => $badge->status_payment->getValue(),
            'can_transition' => $badge->status_fulfillment->canTransitionTo(Processing::class),
        ]);

        if ($badge->status_fulfillment->canTransitionTo(Processing::class)) {
            $badge->status_fulfillment->transitionTo(Processing::class);
        }

        $badge->refresh();

        \Log::info('printBadge after transition', [
            'badge_id' => $badge->id,
            'after_fulfillment' => $badge->status_fulfillment->getValue(),
            'after_payment' => $badge->status_payment->getValue(),
        ]);

        // Always use PrintBadgeJob for consistency - it handles PDF generation and file storage
        PrintBadgeJob::dispatch($badge)->delay(now()->addSeconds($mass * 15));

        return $badge;
    }

    public static function printBadgeWithPrinter(Badge $badge, int $printerId, int $delaySeconds = 0): Badge
    {
        if ($badge->status_fulfillment->canTransitionTo(Processing::class)) {
            $badge->status_fulfillment->transitionTo(Processing::class);
        }

        // Generate PDF content synchronously (like PrintBadgeJob does)
        $badgeClass = $badge->fursuit->event->badge_class ?? 'EF28_Badge';

        $printer = match ($badgeClass) {
            'EF29_Badge' => new \App\Badges\EF29_Badge,
            'EF28_Badge' => new \App\Badges\EF28_Badge,
            default => new \App\Badges\EF28_Badge,
        };

        // Generate PDF content
        $pdfContent = $printer->getPdf($badge);

        // Store PDF Content in PrintJobs Storage
        $filePath = 'badges/'.$badge->id.'.pdf';
        \Illuminate\Support\Facades\Storage::put($filePath, $pdfContent);

        // Create PrintJob with the specified printer and file
        $badge->printJobs()->create([
            'printer_id' => $printerId,
            'type' => PrintJobTypeEnum::Badge,
            'status' => \App\Enum\PrintJobStatusEnum::Pending,
            'file' => $filePath,
            'queued_at' => now(),
            'priority' => 1,
        ]);

        \Illuminate\Support\Facades\Log::info('Badge print job created with specific printer', [
            'badge_id' => $badge->id,
            'printer_id' => $printerId,
            'delay' => $delaySeconds,
        ]);

        return $badge;
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBadges::route('/'),
            'create' => Pages\CreateBadge::route('/create'),
            'edit' => Pages\EditBadge::route('/{record}/edit'),
        ];
    }
}
