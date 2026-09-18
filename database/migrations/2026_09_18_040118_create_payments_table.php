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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            /*
             * Booking this payment belongs to.
             *
             * A booking may have multiple payment attempts.
             */
            $table->foreignId('booking_id')
                ->constrained('bookings')
                ->cascadeOnDelete();

            /*
             * Customer who made the payment.
             */
            $table->foreignId('customer_id')
                ->constrained('users')
                ->cascadeOnDelete();

            /*
             * Unique internal payment reference.
             *
             * Example:
             * PAY-20260918-A1B2C3
             */
            $table->string('payment_reference')
                ->unique();

            /*
             * Amount charged for this payment.
             */
            $table->decimal(
                'amount',
                12,
                2
            );

            /*
             * Currency used for the transaction.
             */
            $table->string(
                'currency',
                3
            )->default('LKR');

            /*
             * Payment method.
             *
             * Examples later:
             * card
             * bank_transfer
             * gateway
             */
            $table->string('payment_method')
                ->nullable();

            /*
             * Current transaction state.
             *
             * pending
             * processing
             * paid
             * failed
             * cancelled
             * refunded
             */
            $table->string('status')
                ->default('pending');

            /*
             * Name of payment gateway/provider.
             *
             * Kept nullable so the payment module can
             * initially work without a real gateway.
             */
            $table->string('gateway')
                ->nullable();

            /*
             * Transaction/reference returned by the
             * external payment gateway.
             */
            $table->string('gateway_transaction_id')
                ->nullable()
                ->unique();

            /*
             * Optional gateway response or other
             * structured payment metadata.
             */
            $table->json('metadata')
                ->nullable();

            /*
             * Human-readable failure information.
             */
            $table->text('failure_reason')
                ->nullable();

            /*
             * Lifecycle timestamps.
             */
            $table->timestamp('paid_at')
                ->nullable();

            $table->timestamp('failed_at')
                ->nullable();

            $table->timestamp('cancelled_at')
                ->nullable();

            $table->timestamp('refunded_at')
                ->nullable();

            $table->timestamps();

            /*
             * Useful indexes for booking/customer
             * payment-history queries.
             */
            $table->index([
                'booking_id',
                'status',
            ]);

            $table->index([
                'customer_id',
                'status',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};