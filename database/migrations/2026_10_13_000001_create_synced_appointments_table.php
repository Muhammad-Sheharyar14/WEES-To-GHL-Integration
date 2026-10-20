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
        Schema::create('synced_appointments', function (Blueprint $table) {
            $table->id();
            $table->string('location_id')->index();
            $table->string('wess_appointment_id')->index();
            $table->string('ghl_appointment_id')->nullable()->index();
            $table->string('ghl_contact_id')->nullable();
            $table->string('calendar_id')->nullable();
            $table->string('assigned_user_id')->nullable();
            $table->string('title')->nullable();
            $table->string('status')->nullable();
            $table->string('start_time')->nullable();
            $table->string('end_time')->nullable();
            $table->string('sync_hash', 64)->nullable()->index();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            // Strict unique compound constraint to permanently prevent duplicate records
            $table->unique(['location_id', 'wess_appointment_id'], 'loc_wess_appt_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('synced_appointments');
    }
};
