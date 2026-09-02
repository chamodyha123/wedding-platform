<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_providers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();

            $table->string('business_name');

            $table->string('business_slug')->unique();

            $table->text('description')->nullable();

            $table->string('phone', 30)->nullable();

            $table->string('whatsapp', 30)->nullable();

            $table->string('email')->nullable();

            $table->string('website')->nullable();

            $table->string('address')->nullable();

            $table->string('city')->nullable();

            $table->string('district')->nullable();

            $table->decimal('latitude', 10, 7)->nullable();

            $table->decimal('longitude', 10, 7)->nullable();

            $table->string('logo')->nullable();

            $table->string('cover_image')->nullable();

            $table->enum('verification_status', [
                'pending',
                'under_review',
                'verified',
                'rejected',
                'changes_requested',
                'suspended',
            ])->default('pending');

            $table->text('verification_notes')->nullable();

            $table->timestamp('verified_at')->nullable();

            $table->foreignId('verified_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index('verification_status');
            $table->index('city');
            $table->index('district');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_providers');
    }
};