<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_verification_histories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('service_provider_id')
                ->constrained('service_providers')
                ->cascadeOnDelete();

            $table->foreignId('admin_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('previous_status')->nullable();

            $table->string('new_status');

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('service_provider_id');
            $table->index('new_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_verification_histories');
    }
};