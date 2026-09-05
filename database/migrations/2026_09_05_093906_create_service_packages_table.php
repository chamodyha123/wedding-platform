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
        Schema::create('service_packages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('service_id')
                ->constrained('services')
                ->cascadeOnDelete();

            $table->string('name');

            $table->string('slug');

            $table->text('description')
                ->nullable();

            $table->decimal(
                'price',
                12,
                2
            );

            $table->unsignedInteger(
                'duration_minutes'
            )->nullable();

            $table->string('status')
                ->default('draft');

            $table->boolean('is_featured')
                ->default(false);

            $table->timestamps();

            $table->softDeletes();

            $table->unique([
                'service_id',
                'slug',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_packages');
    }
};