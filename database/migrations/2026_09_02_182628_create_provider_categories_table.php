<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_categories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('service_provider_id')
                ->constrained('service_providers')
                ->cascadeOnDelete();

            $table->foreignId('service_category_id')
                ->constrained('service_categories')
                ->cascadeOnDelete();

            $table->timestamps();

            $table->unique([
                'service_provider_id',
                'service_category_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_categories');
    }
};