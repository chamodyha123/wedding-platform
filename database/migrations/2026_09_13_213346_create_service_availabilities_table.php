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
        Schema::create('service_availabilities', function (Blueprint $table) {
            $table->id();

            $table->foreignId('service_id')
                ->constrained('services')
                ->cascadeOnDelete();

            $table->date('date');

            $table->time('start_time')
                ->nullable();

            $table->time('end_time')
                ->nullable();

            $table->string('status')
                ->default('available');

            $table->text('notes')
                ->nullable();

            $table->timestamps();

            /*
             * Prevent duplicate availability records
             * for the exact same service/date/time range.
             */
            $table->unique([
                'service_id',
                'date',
                'start_time',
                'end_time',
            ], 'service_availability_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_availabilities');
    }
};