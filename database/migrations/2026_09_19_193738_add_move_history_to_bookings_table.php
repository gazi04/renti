<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Last-move snapshot, not a full history table — deep-audit finding 08
        // only asks to "record the change"; a booking moved twice only remembers
        // the most recent prior state. previous_vehicle_id has no FK constraint
        // (unlike vehicle_id) so the snapshot survives the original vehicle being
        // deleted later.
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('previous_vehicle_id')->nullable()->after('vehicle_id');
            $table->dateTime('previous_start_date')->nullable()->after('previous_vehicle_id');
            $table->dateTime('previous_end_date')->nullable()->after('previous_start_date');
            $table->dateTime('moved_at')->nullable()->after('previous_end_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['previous_vehicle_id', 'previous_start_date', 'previous_end_date', 'moved_at']);
        });
    }
};
