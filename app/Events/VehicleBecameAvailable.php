<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Vehicle;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A vehicle is public and bookable again after being unavailable (backlog #3).
 *
 * Unlike the Booking events, this is dispatched from the model rather than a
 * service — see Vehicle::booted() for why.
 */
class VehicleBecameAvailable implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Vehicle $vehicle,
    ) {}
}
