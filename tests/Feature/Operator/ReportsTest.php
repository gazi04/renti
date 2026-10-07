<?php

use App\Filament\Operator\Pages\Reports;
use App\Models\Booking;
use App\Models\Tenant;
use App\Models\Vehicle;
use Filament\Facades\Filament;
use Livewire\Livewire;

// reportsOperatorFor() and reportRange() are global helpers in tests/Pest.php,
// shared with ReportsHeatmapTest.

afterEach(function () {
    tenancy()->end();
});

it('counts bookings and revenue for the selected range', function () {
    [, , $vehicle] = reportsOperatorFor('reports');

    Booking::factory()->forVehicle($vehicle)->completed()->create([
        'start_date' => '2030-06-05', 'end_date' => '2030-06-10', 'total' => 250,
    ]);
    Booking::factory()->forVehicle($vehicle)->create([
        'status' => 'pending', 'start_date' => '2030-06-12', 'end_date' => '2030-06-14', 'total' => 100,
    ]);
    // Cancelled: counted in totals, excluded from revenue.
    Booking::factory()->forVehicle($vehicle)->create([
        'status' => 'cancelled', 'start_date' => '2030-06-16', 'end_date' => '2030-06-18', 'total' => 100,
    ]);
    // Out of range entirely: excluded from everything.
    Booking::factory()->forVehicle($vehicle)->completed()->create([
        'start_date' => '2030-08-01', 'end_date' => '2030-08-05', 'total' => 999,
    ]);

    $component = Livewire::test(Reports::class)->fillForm(reportRange());
    $page = $component->instance();

    expect($page->bookingCounts()['total'])->toBe(3)
        ->and($page->bookingCounts()['completed'])->toBe(1)
        ->and($page->revenue())->toBe(250.0);
});

it('computes vehicle utilisation as booked days over range days', function () {
    [, , $vehicle] = reportsOperatorFor('reports-util');

    // 6 booked days in a 30-day June = 20%.
    Booking::factory()->forVehicle($vehicle)->confirmed()->create([
        'start_date' => '2030-06-04 09:00', 'end_date' => '2030-06-10 09:00',
    ]);
    // Cancelled bookings don't count toward utilisation.
    Booking::factory()->forVehicle($vehicle)->create([
        'status' => 'cancelled', 'start_date' => '2030-06-20', 'end_date' => '2030-06-25',
    ]);

    $page = Livewire::test(Reports::class)->fillForm(reportRange())->instance();
    $row = $page->utilisation()->firstWhere('vehicle', $vehicle->name);

    expect($row['booked_days'])->toBe(6)
        ->and($row['percent'])->toBe(20);
});

it('exports the range as CSV with only this tenant\'s bookings', function () {
    [, , $vehicle] = reportsOperatorFor('reports-csv');

    $mine = Booking::factory()->forVehicle($vehicle)->confirmed()->create([
        'start_date' => '2030-06-05', 'end_date' => '2030-06-10',
    ]);
    tenancy()->end();

    // Another tenant's booking in the same range must never leak into the CSV.
    $other = Tenant::factory()->withDomain('reports-other')->create();
    tenancy()->initialize($other);
    $otherBooking = Booking::factory()
        ->forVehicle(Vehicle::factory()->create())
        ->confirmed()
        ->create(['start_date' => '2030-06-05', 'end_date' => '2030-06-10']);
    tenancy()->end();

    $tenant = Tenant::query()->whereRelation('domains', 'domain', tenant_domain('reports-csv'))->firstOrFail();
    tenancy()->initialize($tenant);
    Filament::setCurrentPanel(Filament::getPanel('operator'));

    $page = Livewire::test(Reports::class)->fillForm(reportRange())->instance();

    ob_start();
    $page->exportCsv()->sendContent();
    $csv = ob_get_clean();

    expect($csv)->toContain($mine->reference)
        ->and($csv)->not->toContain($otherBooking->reference)
        ->and($csv)->toContain('reference,customer,vehicle,start,end,status,total');
});

it('neutralises spreadsheet formulas in the exported CSV', function () {
    [, , $vehicle] = reportsOperatorFor('reports-csv-injection');

    // customer_name reaches this export unfiltered from the public booking
    // wizard, so this payload is what an anonymous visitor can plant. Opened in
    // Excel or Sheets, an unguarded cell executes on the operator's machine.
    Booking::factory()->forVehicle($vehicle)->confirmed()->create([
        'customer_name' => '=cmd|\' /c calc\'!A1',
        'start_date' => '2030-06-05', 'end_date' => '2030-06-10',
    ]);

    // The operator-authored vehicle name lands in the same row.
    $hostile = Vehicle::factory()->create(['name' => '=HYPERLINK("https://evil.tld","refund")']);
    Booking::factory()->forVehicle($hostile)->confirmed()->create([
        'customer_name' => 'Arben Krasniqi',
        'start_date' => '2030-06-12', 'end_date' => '2030-06-14',
    ]);

    $page = Livewire::test(Reports::class)->fillForm(reportRange())->instance();

    ob_start();
    $page->exportCsv()->sendContent();
    $csv = ob_get_clean();

    expect($csv)->toContain('"\'=cmd|\' /c calc\'!A1"')
        ->and($csv)->toContain('"\'=HYPERLINK(""https://evil.tld"",""refund"")"')
        // A value with nothing to guard must come through byte-identical: the
        // fix cannot put an apostrophe in front of every real customer's name.
        ->and($csv)->toContain('Arben Krasniqi')
        ->and($csv)->not->toContain("'Arben");
})->group('security');

it('shows the reports page with current-month defaults', function () {
    reportsOperatorFor('reports-page');

    Livewire::test(Reports::class)
        ->assertFormSet([
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->endOfMonth()->toDateString(),
        ])
        ->assertOk();
});
