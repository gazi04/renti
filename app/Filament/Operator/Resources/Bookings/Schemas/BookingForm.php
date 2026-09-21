<?php

namespace App\Filament\Operator\Resources\Bookings\Schemas;

use App\Enums\BookingStatus;
use App\Enums\PlanFeature;
use App\Models\Booking;
use App\Models\Tenant;
use App\Models\Vehicle;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;

class BookingForm
{
    public static function configure(Schema $schema): Schema
    {
        // tenant_id and totals never appear here — BelongsToTenant fills tenant_id,
        // and BookingService::createManual() computes pricing under the vehicle lock.
        return $schema
            ->components([
                Section::make(__('panel.section_vehicle_dates'))
                    ->columns(2)
                    ->components([
                        // Last-move snapshot (BookingService::move(), deep-audit
                        // finding 08) — only the most recent prior state, not a
                        // full history. Read-only, shown only after a move.
                        Placeholder::make('moved_from')
                            ->label(__('panel.moved_from_label'))
                            ->columnSpanFull()
                            ->visible(fn (?Booking $record): bool => $record?->moved_at !== null)
                            ->content(function (?Booking $record): string {
                                if ($record === null) {
                                    return '';
                                }

                                // Vehicle::find() (not the previousVehicle() magic
                                // relation property) so the nullability here is a
                                // real, unambiguous Vehicle|null from Eloquent's own
                                // typed base method.
                                $vehicleName = $record->previous_vehicle_id === null
                                    ? null
                                    : Vehicle::query()->withTrashed()->find($record->previous_vehicle_id)?->name;

                                return __('panel.moved_from_value', [
                                    'vehicle' => $vehicleName ?? '—',
                                    'start' => $record->previous_start_date?->format('d M Y') ?? '—',
                                    'end' => $record->previous_end_date?->format('d M Y') ?? '—',
                                ]);
                            }),
                        Select::make('vehicle_id')
                            ->label(__('panel.vehicle'))
                            ->relationship('vehicle', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        DateTimePicker::make('start_date')
                            ->label(__('panel.start_date'))
                            ->required()
                            ->seconds(false),
                        // No minDate: an operator may legitimately record a rental
                        // that already started. The duration cap still applies, and
                        // BookingService::createManual() is what enforces it.
                        DateTimePicker::make('end_date')
                            ->label(__('panel.end_date'))
                            ->required()
                            ->seconds(false)
                            ->after('start_date')
                            ->maxDate(function (Get $get): ?string {
                                $start = $get('start_date');

                                if (! is_string($start) || $start === '') {
                                    return null;
                                }

                                return Date::parse($start)
                                    ->addDays(Config::integer('bookings.max_rental_days'))
                                    ->toDateTimeString();
                            }),
                        TextInput::make('pickup_location')
                            ->label(__('panel.pickup_location'))
                            ->maxLength(255),
                        // Transient field — not a Booking column; createManual()
                        // reads it from the form data and applies the discount.
                        TextInput::make('promo_code')
                            ->label(__('panel.promo_code'))
                            ->maxLength(50)
                            ->visible(fn (): bool => Tenant::current()?->allowsFeature(PlanFeature::PromoCodes) ?? (bool) PlanFeature::PromoCodes->default()),
                    ]),

                Section::make(__('panel.section_customer'))
                    ->columns(2)
                    ->components([
                        TextInput::make('customer_name')
                            ->label(__('panel.customer_name'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('customer_phone')
                            ->label(__('panel.customer_phone'))
                            ->required()
                            ->tel()
                            ->maxLength(50),
                        TextInput::make('customer_email')
                            ->label(__('panel.customer_email'))
                            ->email()
                            ->maxLength(255),
                        Textarea::make('notes')
                            ->label(__('panel.notes'))
                            ->columnSpanFull(),
                        Textarea::make('cancellation_reason')
                            ->label(__('panel.cancellation_reason'))
                            ->visible(fn (?Booking $record): bool => $record?->status === BookingStatus::Cancelled)
                            ->disabled()
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
