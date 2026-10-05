<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\ServiceRecords\Pages;

use App\Filament\Operator\Resources\ServiceRecords\ServiceRecordResource;
use App\Models\ServiceRecord;
use App\Services\VehicleMaintenanceService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditServiceRecord extends EditRecord
{
    protected static string $resource = ServiceRecordResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * An operator may correct the existing record (clear or push back its due
     * date) instead of logging a new one — that resolves the sweep block too.
     */
    protected function afterSave(): void
    {
        /** @var ServiceRecord $record */
        $record = $this->record;

        resolve(VehicleMaintenanceService::class)->releaseResolvedBlocks($record->vehicle_id);
    }
}
