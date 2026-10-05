<?php

namespace App\Jobs;

use App\Enums\VehicleStatus;
use App\Exceptions\VehicleNotAvailableException;
use App\Mail\ServiceDueMail;
use App\Models\ServiceRecord;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BlockedDateService;
use App\Services\VehicleMaintenanceService;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Processes one tenant's due service records (operator feature #10): sends a
 * reminder once per record as its due date approaches, then auto-blocks the
 * vehicle (via the existing blocked_dates mechanism) once it is actually due.
 *
 * Dispatched from the central maintenance:process-due command, so the
 * QueueTenancyBootstrapper does NOT re-initialize tenancy for us — the job
 * initializes (and always ends) tenancy itself.
 */
class ProcessVehicleMaintenanceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(private readonly Tenant $tenant) {}

    public function handle(BlockedDateService $blockedDates): void
    {
        tenancy()->initialize($this->tenant);

        try {
            $owners = User::query()
                ->where('tenant_id', $this->tenant->id)
                ->where('role', 'operator')
                ->get();

            // each() chunks, so a tenant with two or more due records hydrates them
            // in one multi-row result — which is the only case where Laravel arms
            // preventLazyLoading(). Both branches below read $record->vehicle, so
            // without this eager load that is an implicit lazy load: a hard failure
            // outside production, an N+1 inside it. whereHas('vehicle') already
            // excludes trashed vehicles and the eager load applies the same scopes,
            // so every surviving row still has a non-null vehicle.
            // Self-heal first: release any block whose record has since been
            // superseded or rescheduled. The panel pages release on save, but
            // this also catches vehicles left stuck before that existed, and any
            // future write path that forgets to. Runs before blocking so a
            // vehicle still overdue for another service type is re-checked below.
            $maintenance = resolve(VehicleMaintenanceService::class);

            ServiceRecord::query()
                ->whereNotNull('blocked_date_id')
                ->select('vehicle_id')
                ->distinct()
                ->get()
                ->each(fn (ServiceRecord $linked) => $maintenance->releaseResolvedBlocks($linked->vehicle_id));

            // latestOfItsType(): an older record of the same service type is
            // history, not a reminder — it must neither block nor remind.
            ServiceRecord::query()
                ->whereNotNull('next_due_on')
                ->latestOfItsType()
                ->whereHas('vehicle')
                ->with('vehicle')
                ->each(function (ServiceRecord $record) use ($owners, $blockedDates): void {
                    if ($record->isOverdue()) {
                        $this->autoBlock($record, $owners, $blockedDates);

                        return;
                    }

                    $this->remindIfDue($record, $owners);
                });
        } finally {
            tenancy()->end();
        }
    }

    /** @param  Collection<int, User>  $owners */
    private function remindIfDue(ServiceRecord $record, Collection $owners): void
    {
        if ($record->reminder_sent_at !== null) {
            return;
        }

        /** @var array<int, int> $reminderDays */
        $reminderDays = config('maintenance.reminder_days');
        $isDue = collect($reminderDays)->contains(fn (int $days): bool => $record->next_due_on->isSameDay(now()->addDays($days)));

        if (! $isDue) {
            return;
        }

        foreach ($owners as $owner) {
            Mail::to($owner->email)
                ->locale($this->tenant->operatorLocale())
                ->queue(new ServiceDueMail($record));

            Notification::make()
                ->title(__('panel.service_due_bell_title'))
                ->body($record->vehicle->name)
                ->warning()
                ->sendToDatabase($owner);
        }

        $record->update(['reminder_sent_at' => now()]);
    }

    /** @param  Collection<int, User>  $owners */
    private function autoBlock(ServiceRecord $record, Collection $owners, BlockedDateService $blockedDates): void
    {
        if ($record->blocked_date_id !== null) {
            return;
        }

        $blockDays = (int) config('maintenance.block_days');

        try {
            $blockedDate = $blockedDates->create([
                'vehicle_id' => $record->vehicle_id,
                'start_date' => today(),
                'end_date' => now()->addDays($blockDays)->startOfDay(),
                'reason' => 'maintenance',
            ]);
        } catch (VehicleNotAvailableException) {
            // The vehicle already has an occupying booking through the due
            // window. Leave blocked_date_id null so tomorrow's sweep retries
            // once the booking clears, rather than blocking over it and
            // leaving the vehicle silently booked *and* out of service.
            foreach ($owners as $owner) {
                Notification::make()
                    ->title(__('panel.service_overdue_conflict_title'))
                    ->body($record->vehicle->name)
                    ->danger()
                    ->sendToDatabase($owner);
            }

            return;
        }

        $record->update(['blocked_date_id' => $blockedDate->id]);
        $record->vehicle->update(['status' => VehicleStatus::UnderMaintenance]);

        foreach ($owners as $owner) {
            Notification::make()
                ->title(__('panel.service_overdue_bell_title'))
                ->body($record->vehicle->name)
                ->danger()
                ->sendToDatabase($owner);
        }
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Vehicle maintenance sweep failed', [
            'tenant_id' => $this->tenant->id,
            'exception' => $exception->getMessage(),
        ]);
    }
}
