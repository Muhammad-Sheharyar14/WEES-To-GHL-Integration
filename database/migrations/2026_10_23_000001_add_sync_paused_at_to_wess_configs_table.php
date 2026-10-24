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
            $table->timestamp('sync_paused_at')->nullable()->after('is_sync_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wess_configs', function (Blueprint $table) {
            $table->dropColumn('sync_paused_at');
        });
    }
};
