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
        Schema::table('wess_configs', function (Blueprint $table) {
            $table->string('calendar_id')->nullable()->after('branch_name');
            $table->string('calendar_name')->nullable()->after('calendar_id');
            $table->string('calendar_user_id')->nullable()->after('calendar_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wess_configs', function (Blueprint $table) {
            $table->dropColumn(['calendar_id', 'calendar_name', 'calendar_user_id']);
        });
    }
};
