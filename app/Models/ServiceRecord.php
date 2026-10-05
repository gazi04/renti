<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ServiceRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * A tenant-scoped service history entry for a vehicle (operator feature #10).
 * Logging is free on every plan; `next_due_on` only drives a reminder/auto-block
 * when the tenant's plan enables PlanFeature::MaintenanceReminders.
 *
 * @property string $service_type
 * @property Carbon $performed_on
 * @property int|null $odometer
 * @property string|null $cost
 * @property Carbon|null $next_due_on
 * @property int|null $next_due_odometer
 * @property Carbon|null $reminder_sent_at
 * @property int|null $blocked_date_id
 */
#[Fillable(['vehicle_id', 'service_type', 'performed_on', 'odometer', 'cost', 'notes', 'next_due_on', 'next_due_odometer', 'reminder_sent_at', 'blocked_date_id'])]
class ServiceRecord extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ServiceRecordFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'performed_on' => 'date',
            'odometer' => 'integer',
            'cost' => 'decimal:2',
            'next_due_on' => 'date',
            'next_due_odometer' => 'integer',
            'reminder_sent_at' => 'datetime',
        ];
    }

    /**
     * Only the newest record per vehicle + service type is a live reminder;
     * every older one is history. Without this, a record superseded by a
     * fresh service kept its past next_due_on and the maintenance sweep
     * re-blocked the car every day after it was serviced.
     *
     * "Newest" is by performed_on (when the work was done, not when it was
     * typed in, so back-filling old history never overrides a newer service),
     * with the higher id breaking a same-day tie. Derived at query time rather
     * than stored as a flag, so it stays correct across edits and deletes.
     *
     * The correlated subquery reads the raw table, so the tenant global scope
     * does not apply inside it — safe, because vehicle_id equality already pins
     * the tenant (a vehicle belongs to exactly one).
     *
     * @param  Builder<ServiceRecord>  $query
     * @return Builder<ServiceRecord>
     */
    #[Scope]
    protected function latestOfItsType(Builder $query): Builder
    {
        return $query->whereNotExists(fn (QueryBuilder $newer) => $newer
            ->selectRaw('1')
            ->from('service_records as newer')
            ->whereColumn('newer.vehicle_id', 'service_records.vehicle_id')
            ->whereColumn('newer.service_type', 'service_records.service_type')
            ->where(fn (QueryBuilder $later) => $later
                ->whereColumn('newer.performed_on', '>', 'service_records.performed_on')
                ->orWhere(fn (QueryBuilder $sameDay) => $sameDay
                    ->whereColumn('newer.performed_on', 'service_records.performed_on')
                    ->whereColumn('newer.id', '>', 'service_records.id'))));
    }

    /** Due today or earlier — the point the sweep takes the vehicle off the road. */
    public function isOverdue(): bool
    {
        return $this->next_due_on !== null
            && ($this->next_due_on->isPast() || $this->next_due_on->isToday());
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** @return BelongsTo<BlockedDate, $this> */
    public function blockedDate(): BelongsTo
    {
        return $this->belongsTo(BlockedDate::class);
    }
}
