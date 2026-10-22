<?php

namespace Tests\Postgres;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\ServiceAvailability;
use App\Notifications\BookingCompletedNotification;
use App\Notifications\PaymentConfirmedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class BookingConcurrencyTest extends PostgresIntegrationTestCase
{
    public function test_complete_migrations_preserve_postgres_booking_payment_constraints(): void
    {
        $fixture = $this->marketplaceFixture('migration');
        $booking = $this->booking($fixture, 'accepted', 'pending');

        Payment::create(['booking_id' => $booking->id, 'customer_id' => $fixture['customer']->id, 'payment_reference' => 'PAY-migration-one', 'amount' => 100, 'currency' => 'LKR', 'status' => 'pending']);
        Payment::create(['booking_id' => $booking->id, 'customer_id' => $fixture['customer']->id, 'payment_reference' => 'PAY-migration-two', 'amount' => 100, 'currency' => 'LKR', 'status' => 'pending']);
        ServiceAvailability::create(['service_id' => $fixture['service']->id, 'date' => now()->addDays(3)->toDateString(), 'start_time' => '09:00', 'end_time' => '17:00', 'status' => 'available']);

        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseHas('service_availabilities', ['service_id' => $fixture['service']->id]);
    }

    public function test_concurrent_overlapping_booking_requests_allow_only_one_booking(): void
    {
        $fixture = $this->marketplaceFixture('booking-race');
        ServiceAvailability::create(['service_id' => $fixture['service']->id, 'date' => now()->addDays(3)->toDateString(), 'start_time' => '09:00', 'end_time' => '17:00', 'status' => 'available']);
        $body = ['service_id' => $fixture['service']->id, 'service_package_id' => $fixture['package']->id, 'event_date' => now()->addDays(3)->toDateString(), 'start_time' => '10:00', 'end_time' => '11:00'];
        $secondCustomer = $this->marketplaceFixture('booking-race-second')['customer'];

        $results = $this->runWorkers([
            $this->request('booking-one', '/api/customer/bookings', $fixture['customer']->createToken('booking-one')->plainTextToken, $body),
            $this->request('booking-two', '/api/customer/bookings', $secondCustomer->createToken('booking-two')->plainTextToken, $body),
        ], providerId: $fixture['provider']->id);

        $this->assertSame([201, 409], $this->statuses($results));
        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_booking_and_availability_deletion_resolve_to_an_allowed_final_state(): void
    {
        $fixture = $this->marketplaceFixture('availability-race');
        $availability = ServiceAvailability::create(['service_id' => $fixture['service']->id, 'date' => now()->addDays(3)->toDateString(), 'start_time' => '09:00', 'end_time' => '17:00', 'status' => 'available']);
        $body = ['service_id' => $fixture['service']->id, 'service_package_id' => $fixture['package']->id, 'event_date' => now()->addDays(3)->toDateString(), 'start_time' => '10:00', 'end_time' => '11:00'];

        $results = $this->runWorkers([
            $this->request('create', '/api/customer/bookings', $fixture['customer']->createToken('create')->plainTextToken, $body),
            $this->request('delete', '/api/provider/services/'.$fixture['service']->id.'/availability/'.$availability->id, $fixture['provider_user']->createToken('delete')->plainTextToken, [], 'DELETE'),
        ], providerId: $fixture['provider']->id);

        $this->assertSame(200, $results['delete']['status']);
        $this->assertContains($results['create']['status'], [201, 422]);
        $this->assertDatabaseMissing('service_availabilities', ['id' => $availability->id]);
        $this->assertDatabaseCount('bookings', $results['create']['status'] === 201 ? 1 : 0);
    }

    public function test_concurrent_completion_creates_one_transition_and_notification(): void
    {
        $fixture = $this->marketplaceFixture('complete-race');
        $booking = $this->booking($fixture, 'confirmed', 'paid', now()->subDay()->toDateString());
        $token = $fixture['provider_user']->createToken('complete')->plainTextToken;
        $results = $this->runWorkers([$this->request('one', '/api/provider/bookings/'.$booking->id.'/complete', $token, []), $this->request('two', '/api/provider/bookings/'.$booking->id.'/complete', $token, [])], bookingId: $booking->id);

        $this->assertSame([200, 422], $this->statuses($results));
        $this->assertSame('completed', $booking->fresh()->booking_status);
        $this->assertSame(1, $fixture['customer']->notifications()->where('type', BookingCompletedNotification::class)->count());
    }

    public function test_concurrent_payhere_callback_replay_is_idempotent(): void
    {
        $fixture = $this->marketplaceFixture('callback-race');
        $booking = $this->booking($fixture, 'accepted', 'pending');
        $payment = Payment::create(['booking_id' => $booking->id, 'customer_id' => $fixture['customer']->id, 'payment_reference' => 'PAY-callback-race', 'amount' => 100, 'currency' => 'LKR', 'status' => 'pending', 'gateway' => 'payhere']);
        $body = $this->buildPayHereCallbackPayload($payment->payment_reference, 'PAYHERE-callback-race');
        $results = $this->runWorkers([$this->request('one', '/api/payments/payhere/notify', '', $body), $this->request('two', '/api/payments/payhere/notify', '', $body)], bookingId: $booking->id);

        $this->assertSame([200, 200], $this->statuses($results));
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('confirmed', $booking->fresh()->booking_status);
        $this->assertSame(1, $fixture['customer']->notifications()->where('type', PaymentConfirmedNotification::class)->count());
    }

    public function test_payment_callback_and_cancellation_do_not_regress_state_or_deadlock(): void
    {
        $fixture = $this->marketplaceFixture('cancel-race');
        $booking = $this->booking($fixture, 'accepted', 'pending');
        $payment = Payment::create(['booking_id' => $booking->id, 'customer_id' => $fixture['customer']->id, 'payment_reference' => 'PAY-cancel-race', 'amount' => 100, 'currency' => 'LKR', 'status' => 'pending', 'gateway' => 'payhere']);
        $results = $this->runWorkers([
            $this->request('callback', '/api/payments/payhere/notify', '', $this->buildPayHereCallbackPayload($payment->payment_reference, 'PAYHERE-cancel-race')),
            $this->request('cancel', '/api/customer/bookings/'.$booking->id.'/cancel', $fixture['customer']->createToken('cancel')->plainTextToken, ['cancellation_reason' => 'Changed plans.']),
        ], bookingId: $booking->id);

        $this->assertSame(200, $results['callback']['status']);
        $this->assertContains($results['cancel']['status'], [200, 422]);
        $this->assertContains([$booking->fresh()->booking_status, $booking->fresh()->payment_status, $payment->fresh()->status], [['cancelled', 'unpaid', 'cancelled'], ['confirmed', 'paid', 'paid']]);
    }

    private function booking(array $fixture, string $status, string $paymentStatus, ?string $date = null): Booking
    {
        return Booking::create(['booking_reference' => 'BK-'.Str::uuid(), 'customer_id' => $fixture['customer']->id, 'service_provider_id' => $fixture['provider']->id, 'service_id' => $fixture['service']->id, 'service_package_id' => $fixture['package']->id, 'event_date' => $date ?? now()->addDays(3)->toDateString(), 'start_time' => '10:00', 'end_time' => '11:00', 'total_amount' => 100, 'booking_status' => $status, 'payment_status' => $paymentStatus]);
    }

    private function buildPayHereCallbackPayload(string $orderId, string $paymentId): array
    {
        $amount = '100.00';
        $signature = strtoupper(md5('postgres-test-merchant'.$orderId.$amount.'LKR2'.strtoupper(md5('postgres-test-secret'))));

        return ['merchant_id' => 'postgres-test-merchant', 'order_id' => $orderId, 'payment_id' => $paymentId, 'payhere_amount' => $amount, 'payhere_currency' => 'LKR', 'status_code' => '2', 'md5sig' => $signature];
    }

    private function request(string $name, string $uri, string $token, array $body, string $method = 'POST'): array
    {
        return compact('name', 'uri', 'token', 'body', 'method');
    }

    /** @param list<array{name: string, uri: string, token: string, body: array<string, mixed>, method: string}> $requests */
    private function runWorkers(array $requests, ?int $providerId = null, ?int $bookingId = null): array
    {
        $directory = storage_path('framework/testing/pgsql-'.Str::uuid());
        File::ensureDirectoryExists($directory);
        $processes = [];

        try {
            $connection = DB::connection('pgsql');
            $connection->beginTransaction();

            if ($providerId !== null) {
                $connection->table('service_providers')->where('id', $providerId)->lockForUpdate()->first();
            }

            if ($bookingId !== null) {
                $connection->table('bookings')->where('id', $bookingId)->lockForUpdate()->first();
            }

            foreach ($requests as $request) {
                $payload = [...$request, 'worker_name' => $request['name'], 'barrier_directory' => $directory];
                $process = new Process([PHP_BINARY, base_path('tests/Support/PostgresHttpWorker.php'), base64_encode(json_encode($payload, JSON_THROW_ON_ERROR))]);
                $process->setTimeout(15);
                $process->start();
                $processes[$request['name']] = $process;
            }

            $deadline = microtime(true) + 5;
            while (count(glob($directory.'/*.ready') ?: []) !== count($requests)) {
                $this->assertWorkersAreRunning($processes, $directory);

                if (microtime(true) >= $deadline) {
                    $this->fail('PostgreSQL workers did not reach the controlled barrier.');
                }

                usleep(10_000);
            }

            file_put_contents($directory.'/release', 'release');
            $this->waitForWorkerLockContention(count($requests), $processes, $directory);
            $connection->commit();
            $results = [];

            foreach ($processes as $name => $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $results[$name] = json_decode(file_get_contents($directory.'/'.$name.'.result'), true, 512, JSON_THROW_ON_ERROR);
                $this->assertArrayNotHasKey('error', $results[$name]);
            }

            return $results;
        } finally {
            if (isset($connection) && $connection->transactionLevel() > 0) {
                $connection->rollBack();
            }

            foreach ($processes as $process) {
                $process->stop();
            }
            File::deleteDirectory($directory);
        }
    }

    /** @param array<string, Process> $processes */
    private function waitForWorkerLockContention(int $expectedWorkers, array $processes, string $directory): void
    {
        $deadline = microtime(true) + 5;

        while (microtime(true) < $deadline) {
            $this->assertWorkersAreRunning($processes, $directory);

            $waitingWorkers = DB::connection('pgsql')->table('pg_stat_activity')
                ->where('datname', DB::raw('current_database()'))
                ->where('application_name', 'like', 'wedding_marketplace_pgtest_%')
                ->where('wait_event_type', 'Lock')
                ->count();

            if ($waitingWorkers === $expectedWorkers) {
                return;
            }

            usleep(10_000);
        }

        $this->fail('PostgreSQL workers did not contend on the expected row lock.');
    }

    /** @param array<string, Process> $processes */
    private function assertWorkersAreRunning(array $processes, string $directory): void
    {
        foreach ($processes as $name => $process) {
            if ($process->isRunning()) {
                continue;
            }

            $resultPath = $directory.'/'.$name.'.result';
            $result = is_file($resultPath)
                ? json_decode(file_get_contents($resultPath), true, 512, JSON_THROW_ON_ERROR)
                : [];
            $error = $result['error'] ?? $process->getErrorOutput();

            $this->fail("PostgreSQL worker {$name} exited before lock contention: {$error}");
        }
    }

    private function statuses(array $results): array
    {
        $statuses = array_column($results, 'status');
        sort($statuses);

        return $statuses;
    }
}
