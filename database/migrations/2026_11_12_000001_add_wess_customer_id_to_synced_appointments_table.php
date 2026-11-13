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
        Schema::table('synced_appointments', function (Blueprint $table) {
            $table->string('wess_customer_id')->nullable()->after('wess_appointment_id')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('synced_appointments', function (Blueprint $table) {
            $table->dropColumn('wess_customer_id');
        });
    }
};

