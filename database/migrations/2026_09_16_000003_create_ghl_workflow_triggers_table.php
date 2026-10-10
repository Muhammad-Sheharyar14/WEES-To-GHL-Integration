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
        Schema::create('ghl_workflow_triggers', function (Blueprint $table) {
            $table->id();
            $table->string('location_id')->index();
            $table->string('workflow_id')->nullable()->index();
            $table->string('trigger_type')->default('customer_created')->index();
            $table->text('target_url');
            $table->json('raw_subscription_payload')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ghl_workflow_triggers');
    }
};
