<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\ServiceRecords\Pages;

use App\Filament\Operator\Resources\ServiceRecords\ServiceRecordResource;
use App\Models\ServiceRecord;
use App\Services\VehicleMaintenanceService;
use Filament\Resources\Pages\CreateRecord;

class CreateServiceRecord extends CreateRecord
{
    protected static string $resource = ServiceRecordResource::class;

    /**
     * Logging a fresh service closes the maintenance loop: a sweep block whose
     * overdue record this entry supersedes (same vehicle + service type) is
     * lifted, and the vehicle returns to Available once nothing else holds it.
     * A different service type, or a block the operator made by hand in the
     * calendar, is left alone.
     */
    protected function afterCreate(): void
    {
        /** @var ServiceRecord $record */
        $record = $this->record;

        resolve(VehicleMaintenanceService::class)->releaseResolvedBlocks($record->vehicle_id);
    }
}
