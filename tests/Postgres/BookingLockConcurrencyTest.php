<?php

use App\Models\Tenant;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Proves lockForUpdate() actually locks on the production driver
|--------------------------------------------------------------------------
|
| architecture-review H3: lockForUpdate() (used by
| BookingService::lockAndValidate() to prevent double-booking) is a no-op
| on SQLite, and the whole suite runs on SQLite — so this invariant has
| never been exercised on the driver that actually enforces it. This test
| opens a second, independent Postgres connection and proves the row lock
| genuinely blocks it until released.
|
*/

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Postgres-only concurrency test; current driver is not pgsql.');
    }

    config(['database.connections.pgsql_secondary' => config('database.connections.pgsql')]);
});

afterEach(function () {
    DB::purge('pgsql_secondary');
});

it('blocks a second Postgres connection from locking the same vehicle row until the first releases it', function () {
    $tenant = Tenant::factory()->create();
    tenancy()->initialize($tenant);
    $vehicle = Vehicle::factory()->create();
    tenancy()->end();

    try {
        // Connection A: lock the row, same call BookingService::lockAndValidate() makes,
        // and hold the transaction open (don't commit yet).
        DB::beginTransaction();
        Vehicle::whereKey($vehicle->id)->lockForUpdate()->first();

        // Connection B: NOWAIT means it fails immediately instead of blocking forever
        // if the row is locked — that's what we're proving.
        $blockedWhileLocked = false;
        try {
            DB::connection('pgsql_secondary')
                ->select('select * from vehicles where id = ? for update nowait', [$vehicle->id]);
        } catch (Throwable) {
            $blockedWhileLocked = true;
        }

        DB::commit();

        expect($blockedWhileLocked)->toBeTrue(
            'Expected the second connection to be blocked by the row lock while connection A held '.
            'it uncommitted — lockForUpdate() is not enforcing the invariant on this driver.'
        );

        // After the first connection commits, the lock is released — the second
        // connection's NOWAIT lock attempt should now succeed.
        $rowsAfterRelease = DB::connection('pgsql_secondary')
            ->select('select * from vehicles where id = ? for update nowait', [$vehicle->id]);

        expect($rowsAfterRelease)->toHaveCount(1);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        tenancy()->initialize($tenant);
        $vehicle->forceDelete();
        tenancy()->end();
        $tenant->delete();
    }
});
