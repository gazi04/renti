<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\VehicleStatus;
use App\Models\BlockedDate;
use App\Models\ServiceRecord;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;

/**
 * Undoes what the maintenance sweep did to a vehicle once the reason for it is
 * gone. The sweep (ProcessVehicleMaintenanceJob::autoBlock) links its block to
 * the overdue record via blocked_date_id and flips the vehicle to
 * UnderMaintenance; it is the only writer of blocked_date_id, so a linked record
 * is exactly "a block the sweep created".
 *
 * A linked block is resolved when its record is no longer the newest of its
 * service type (a fresh service was logged — ServiceRecord::latestOfItsType())
 * or is no longer overdue (its due date was cleared or moved forward).
 */
class VehicleMaintenanceService
{
    public function releaseResolvedBlocks(int $vehicleId): void
    {
        $released = DB::transaction(function () use ($vehicleId): bool {
            $linked = ServiceRecord::query()
                ->where('vehicle_id', $vehicleId)
                ->whereNotNull('blocked_date_id')
                ->get();

            if ($linked->isEmpty()) {
                return false;
            }

            $currentIds = ServiceRecord::query()
                ->where('vehicle_id', $vehicleId)
                ->latestOfItsType()
                ->pluck('id');

            $isResolved = fn (ServiceRecord $record): bool => ! $currentIds->contains($record->id) || ! $record->isOverdue();

            $resolved = $linked->filter($isResolved);
            $stillBlocking = $linked->reject($isResolved);

            if ($resolved->isEmpty()) {
                return false;
            }

            BlockedDate::query()->whereIn('id', $resolved->pluck('blocked_date_id'))->delete();
            ServiceRecord::query()->whereIn('id', $resolved->modelKeys())->update(['blocked_date_id' => null]);

            // Another service type is still overdue and holding its own block —
            // the vehicle stays off the road until that one is resolved too.
            return $stillBlocking->isEmpty();
        });

        // Only ever reached through a resolved sweep block, so a vehicle the
        // operator set to UnderMaintenance by hand (no linked record) is never
        // touched. Deliberately after the transaction: the status change fires
        // VehicleBecameAvailable's queued stock-alert listener, which must not
        // be able to run before this commit is visible.
        if (! $released) {
            return;
        }

        $vehicle = Vehicle::query()->find($vehicleId);

        if ($vehicle?->status === VehicleStatus::UnderMaintenance) {
            $vehicle->update(['status' => VehicleStatus::Available]);
        }
    }
}
