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
        Schema::create('ghl_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('location_id')->nullable()->index();
            $table->string('company_id')->nullable()->index();
            $table->string('user_id')->nullable();
            $table->string('user_type')->default('Location'); // Location or Company
            $table->text('access_token');
            $table->text('refresh_token');
            $table->timestamp('expires_at')->nullable();
            $table->text('scope')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ghl_tokens');
    }
};
