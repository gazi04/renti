<?php

namespace App\Filament\Operator\Resources\Bookings\Tables;

use App\Enums\BookingStatus;
use App\Exceptions\InvalidBookingWindowException;
use App\Exceptions\VehicleNotAvailableException;
use App\Models\Booking;
use App\Services\BookingService;
use App\Services\RentalAgreementService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class BookingsTable
{
    public static function configure(Table $table): Table
    {
        // No tenant filter — the BelongsToTenant global scope already limits
        // rows to the current operator's tenant.
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label(__('panel.reference'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('customer_name')
                    ->label(__('panel.customer_name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('vehicle.name')
                    ->label(__('panel.vehicle'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('start_date')
                    ->label(__('panel.start_date'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('end_date')
                    ->label(__('panel.end_date'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('total')
                    ->label(__('panel.total'))
                    ->money('eur')
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('panel.status'))
                    ->badge(),
                TextColumn::make('created_at')
                    ->label(__('panel.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('panel.status'))
                    ->options(BookingStatus::class),
                SelectFilter::make('vehicle_id')
                    ->relationship('vehicle', 'name')
                    ->label(__('panel.vehicle')),
                Filter::make('date_range')
                    ->label(__('panel.date_range'))
                    ->form([
                        DateTimePicker::make('from')
                            ->label(__('panel.from'))
                            ->seconds(false),
                        DateTimePicker::make('until')
                            ->label(__('panel.until'))
                            ->seconds(false),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'], fn (Builder $q, mixed $v) => $q->where('start_date', '>=', $v))
                        ->when($data['until'], fn (Builder $q, mixed $v) => $q->where('start_date', '<=', $v))),
            ])
            ->recordActions([
                self::confirmAction(),
                self::rejectAction(),
                self::markActiveAction(),
                self::completeAction(),
                self::cancelAction(),
                self::moveAction(),
                self::downloadAgreementAction(),
                ViewAction::make(),
            ]);
    }

    /**
     * Pending → Confirmed.
     */
    protected static function confirmAction(): Action
    {
        return Action::make('confirm')
            ->label(__('panel.action_confirm'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (Booking $record): bool => $record->status === BookingStatus::Pending)
            ->action(function (Booking $record): void {
                resolve(BookingService::class)->confirm($record);
            });
    }

    /**
     * Pending → Cancelled.
     */
    protected static function rejectAction(): Action
    {
        return Action::make('reject')
            ->label(__('panel.action_reject'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (Booking $record): bool => $record->status === BookingStatus::Pending)
            ->schema([
                Textarea::make('reason')
                    ->label(__('panel.cancellation_reason')),
            ])
            ->action(function (Booking $record, array $data): void {
                resolve(BookingService::class)->reject(
                    $record,
                    reason: filled($data['reason'] ?? null) ? $data['reason'] : null,
                );
            });
    }

    /**
     * Confirmed → Active. Collects optional start odometer + timestamp.
     */
    protected static function markActiveAction(): Action
    {
        return Action::make('mark_active')
            ->label(__('panel.action_mark_active'))
            ->icon(Heroicon::OutlinedPlayCircle)
            ->color('info')
            ->visible(fn (Booking $record): bool => $record->status === BookingStatus::Confirmed)
            ->schema([
                DateTimePicker::make('started_at')
                    ->label(__('panel.pickup_time'))
                    ->default(now())
                    ->seconds(false),
                TextInput::make('start_odometer')
                    ->label(__('panel.start_odometer'))
                    ->numeric()
                    ->minValue(0),
            ])
            ->action(function (Booking $record, array $data): void {
                resolve(BookingService::class)->markActive(
                    $record,
                    filled($data['started_at']) ? Date::parse($data['started_at']) : null,
                    filled($data['start_odometer']) ? (int) $data['start_odometer'] : null,
                );
            });
    }

    /**
     * Active → Completed. Collects optional return odometer + timestamp.
     */
    protected static function completeAction(): Action
    {
        return Action::make('complete')
            ->label(__('panel.action_complete'))
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('success')
            ->visible(fn (Booking $record): bool => $record->status === BookingStatus::Active)
            ->schema([
                DateTimePicker::make('completed_at')
                    ->label(__('panel.return_time'))
                    ->default(now())
                    ->seconds(false),
                TextInput::make('end_odometer')
                    ->label(__('panel.end_odometer'))
                    ->numeric()
                    ->minValue(0),
            ])
            ->action(function (Booking $record, array $data): void {
                resolve(BookingService::class)->complete(
                    $record,
                    filled($data['completed_at']) ? Date::parse($data['completed_at']) : null,
                    filled($data['end_odometer']) ? (int) $data['end_odometer'] : null,
                );
            });
    }

    /**
     * Pending/Confirmed → same status, new vehicle/dates. Keeps the reference,
     * re-checks availability under the vehicle lock, re-prices — see
     * BookingService::move() (deep-audit finding 08).
     */
    protected static function moveAction(): Action
    {
        return Action::make('move')
            ->label(__('panel.action_move'))
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->color('warning')
            ->requiresConfirmation()
            ->visible(fn (Booking $record): bool => $record->isMovable())
            ->schema([
                Select::make('vehicle_id')
                    ->label(__('panel.vehicle'))
                    ->relationship('vehicle', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->default(fn (Booking $record): int => $record->vehicle_id),
                DateTimePicker::make('start_date')
                    ->label(__('panel.start_date'))
                    ->required()
                    ->seconds(false)
                    ->default(fn (Booking $record) => $record->start_date),
                DateTimePicker::make('end_date')
                    ->label(__('panel.end_date'))
                    ->required()
                    ->seconds(false)
                    ->after('start_date')
                    ->default(fn (Booking $record) => $record->end_date)
                    ->maxDate(function (Get $get): ?string {
                        $start = $get('start_date');

                        if (! is_string($start) || $start === '') {
                            return null;
                        }

                        return Date::parse($start)
                            ->addDays(Config::integer('bookings.max_rental_days'))
                            ->toDateTimeString();
                    }),
            ])
            ->action(function (Booking $record, array $data): void {
                try {
                    // An explicit shaped literal, not the raw form $data — move()
                    // only ever needs these 3 fields, and building it this way
                    // (rather than passing $data through untyped) gives it a real
                    // array<string, mixed> shape instead of Filament's untyped array.
                    resolve(BookingService::class)->move($record, [
                        'vehicle_id' => $data['vehicle_id'] ?? null,
                        'start_date' => $data['start_date'] ?? null,
                        'end_date' => $data['end_date'] ?? null,
                    ]);
                } catch (VehicleNotAvailableException|InvalidBookingWindowException $e) {
                    Notification::make()
                        ->title($e instanceof InvalidBookingWindowException
                            ? __('panel.invalid_booking_window_title')
                            : __('panel.vehicle_not_available_title'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                } catch (InvalidArgumentException $invalidArgumentException) {
                    Notification::make()->title($invalidArgumentException->getMessage())->danger()->send();
                }
            });
    }

    protected static function downloadAgreementAction(): Action
    {
        return Action::make('agreement')
            ->label(__('panel.action_agreement'))
            ->icon(Heroicon::OutlinedDocumentText)
            ->color('gray')
            ->visible(fn (Booking $record): bool => in_array($record->status, [
                BookingStatus::Confirmed,
                BookingStatus::Active,
                BookingStatus::Completed,
            ], true))
            ->action(function (Booking $record): mixed {
                $contract = resolve(RentalAgreementService::class)->generate($record);

                return Storage::download($contract->path, sprintf('agreement-%s.pdf', $record->reference));
            });
    }

    /**
     * Any non-Completed status → Cancelled.
     */
    protected static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label(__('panel.action_cancel'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('gray')
            ->requiresConfirmation()
            ->visible(fn (Booking $record): bool => ! in_array($record->status, [
                BookingStatus::Completed,
                BookingStatus::Cancelled,
            ], true))
            ->schema([
                Textarea::make('reason')
                    ->label(__('panel.cancellation_reason')),
            ])
            ->action(function (Booking $record, array $data): void {
                try {
                    resolve(BookingService::class)->cancel(
                        $record,
                        reason: filled($data['reason'] ?? null) ? $data['reason'] : null,
                    );
                } catch (InvalidArgumentException $invalidArgumentException) {
                    Notification::make()->title($invalidArgumentException->getMessage())->danger()->send();
                }
            });
    }
}
