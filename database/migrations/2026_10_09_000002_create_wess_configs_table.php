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
        Schema::create('wess_configs', function (Blueprint $table) {
            $table->id();
            $table->string('location_id')->unique()->index();
            $table->string('company_id')->nullable()->index();
            $table->text('api_token')->nullable();
            $table->string('base_url')->default('https://api.prelive.wessconnect.net/api/v1/online');
            $table->string('branch_id')->nullable();
            $table->string('branch_name')->nullable();
            $table->boolean('is_sync_enabled')->default(true); // Master Toggle Switch
            $table->boolean('is_connected')->default(false);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wess_configs');
    }
};
