<?php

namespace App\Filament\Resources;

use App\Domain\Printing\Models\Printer;
use App\Enum\PrintJobTypeEnum;
use App\Filament\Resources\BadgeResource\Pages;
use App\Filament\Traits\HasEventFilter;
use App\Jobs\Printing\PrintBadgeJob;
use App\Models\Badge\Badge;
use App\Models\Badge\State_Fulfillment\BadgeFulfillmentStatusState;
use App\Models\Badge\State_Fulfillment\Printed;
use App\Models\Badge\State_Payment\BadgePaymentStatusState;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

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
                                Forms\Components\TextInput::make('fursuit.species.name')
                                    ->label('Species')
                                    ->disabled()
                                    ->helperText('The fursuit species')
                                    ->columnSpan(1),

                                Forms\Components\TextInput::make('fursuit.user.name')
                                    ->label('Owner')
                                    ->disabled()
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
                                        'printed' => 'Printed',
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
            ->modifyQueryUsing(fn ($query) => static::applyEventFilter($query, 'fursuit'))
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
                Tables\Columns\TextColumn::make('attendee_id')
                    ->label('Attendee ID')
                    ->getStateUsing(function (Badge $record) {
                        $eventUser = $record->fursuit?->user?->eventUsers?->where('event_id', $record->fursuit->event_id)->first();

                        return $eventUser?->attendee_id ?? 'N/A';
                    })
                    ->searchable(query: function ($query, $search) {
                        return $query->whereHas('fursuit.user.eventUsers', function ($q) use ($search) {
                            $q->where('attendee_id', 'like', "%{$search}%");
                        });
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                // Status Badges
                Tables\Columns\TextColumn::make('status_fulfillment')
                    ->label('Fulfillment')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending' => 'Pending',
                        'printed' => 'Printed',
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
                        'printed' => 'Printed',
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

                Tables\Filters\Filter::make('badge_number_range')
                    ->form([
                        Forms\Components\TextInput::make('from_number')
                            ->label('From Badge Number')
                            ->numeric()
                            ->placeholder('e.g., 1'),
                        Forms\Components\TextInput::make('to_number')
                            ->label('To Badge Number')
                            ->numeric()
                            ->placeholder('e.g., 1000'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from_number'], function ($query, $fromNumber) {
                                return $query->whereRaw('CAST(SUBSTRING_INDEX(custom_id, "-", -1) AS UNSIGNED) >= ?', [$fromNumber]);
                            })
                            ->when($data['to_number'], function ($query, $toNumber) {
                                return $query->whereRaw('CAST(SUBSTRING_INDEX(custom_id, "-", -1) AS UNSIGNED) <= ?', [$toNumber]);
                            });
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['from_number']) {
                            $indicators[] = 'From badge #'.$data['from_number'];
                        }
                        if ($data['to_number']) {
                            $indicators[] = 'To badge #'.$data['to_number'];
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
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
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

                        return $records->reverse()->each(fn (Badge $record, $index) => static::printBadgeWithPrinter($record, $printerId, $index));
                    }),
            ])
            ->selectCurrentPageOnly()
            ->paginationPageOptions([10, 25, 50, 100])
            ->defaultSort('custom_id', 'asc');
    }

    public static function printBadge(Badge $badge, $mass = 0, ?int $printerId = null): Badge
    {
        if ($badge->status_fulfillment->canTransitionTo(Printed::class)) {
            $badge->status_fulfillment->transitionTo(Printed::class);
        }

        // Always use PrintBadgeJob for consistency - it handles PDF generation and file storage
        PrintBadgeJob::dispatch($badge)->delay(now()->addSeconds($mass * 15));

        return $badge;
    }

    public static function printBadgeWithPrinter(Badge $badge, int $printerId, int $delaySeconds = 0): Badge
    {
        if ($badge->status_fulfillment->canTransitionTo(Printed::class)) {
            $badge->status_fulfillment->transitionTo(Printed::class);
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
