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
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();

            /*
             * Human-readable unique booking reference.
             *
             * Example:
             * BK-20260913-AB12CD
             */
            $table->string('booking_reference')
                ->unique();

            /*
             * Customer who created the booking.
             */
            $table->foreignId('customer_id')
                ->constrained('users')
                ->restrictOnDelete();

            /*
             * Provider receiving the booking.
             *
             * We store this directly even though it can be reached
             * through the service. This makes provider booking
             * queries simpler and preserves booking ownership.
             */
            $table->foreignId('service_provider_id')
                ->constrained('service_providers')
                ->restrictOnDelete();

            /*
             * Service selected by the customer.
             */
            $table->foreignId('service_id')
                ->constrained('services')
                ->restrictOnDelete();

            /*
             * Package selected by the customer.
             *
             * Nullable so that later we can support bookings
             * for services that do not require a package.
             */
            $table->foreignId('service_package_id')
                ->nullable()
                ->constrained('service_packages')
                ->nullOnDelete();

            /*
             * Event scheduling information.
             */
            $table->date('event_date');

            $table->time('start_time')
                ->nullable();

            $table->time('end_time')
                ->nullable();

            /*
             * Customer event information.
             */
            $table->string('event_location')
                ->nullable();

            $table->text('customer_notes')
                ->nullable();

            /*
             * Snapshot of the agreed package/service amount.
             *
             * Never trust a total_amount supplied by the frontend.
             * The API will calculate this from the selected package.
             */
            $table->decimal(
                'total_amount',
                12,
                2
            );

            /*
             * Booking lifecycle.
             *
             * pending
             * accepted
             * rejected
             * confirmed
             * completed
             * cancelled
             */
            $table->string('booking_status')
                ->default('pending');

            /*
             * Payment lifecycle.
             *
             * unpaid
             * pending
             * paid
             * refunded
             * failed
             */
            $table->string('payment_status')
                ->default('unpaid');

            /*
             * Provider/admin information.
             */
            $table->text('provider_notes')
                ->nullable();

            $table->text('cancellation_reason')
                ->nullable();

            /*
             * Lifecycle timestamps.
             */
            $table->timestamp('accepted_at')
                ->nullable();

            $table->timestamp('confirmed_at')
                ->nullable();

            $table->timestamp('completed_at')
                ->nullable();

            $table->timestamp('cancelled_at')
                ->nullable();

            $table->timestamps();

            /*
             * Helpful indexes for marketplace queries.
             */
            $table->index([
                'customer_id',
                'booking_status',
            ]);

            $table->index([
                'service_provider_id',
                'booking_status',
            ]);

            $table->index([
                'service_id',
                'event_date',
            ]);

            $table->index([
                'event_date',
                'booking_status',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};