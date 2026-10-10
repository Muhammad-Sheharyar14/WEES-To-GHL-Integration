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
        Schema::table('ghl_workflow_triggers', function (Blueprint $table) {
            $table->string('trigger_id')->nullable()->after('workflow_id')->index();
            $table->string('company_id')->nullable()->after('location_id')->index();
            $table->string('event_type')->default('CREATED')->after('trigger_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ghl_workflow_triggers', function (Blueprint $table) {
            $table->dropColumn(['trigger_id', 'company_id', 'event_type']);
        });
    }
};
