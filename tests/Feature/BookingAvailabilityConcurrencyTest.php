<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Service;
use App\Models\ServiceAvailability;
use App\Models\ServiceCategory;
use App\Models\ServicePackage;
use App\Models\ServiceProvider;
use App\Models\User;
use App\Notifications\BookingCompletedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BookingAvailabilityConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped(
                'The configured SQLite test driver is not installed.'
            );
        }

        parent::setUp();

        foreach (['customer', 'service_provider', 'admin'] as $role) {
            Role::create([
                'name' => $role,
                'guard_name' => 'web',
            ]);
        }
    }

    public function test_verified_provider_can_delete_own_availability(): void
    {
        $fixture = $this->createMarketplaceFixture('delete-own');
        $availability = $this->createAvailability($fixture['service']);

        $this->asProvider($fixture['provider_user'])
            ->deleteJson($this->availabilityUrl($fixture['service'], $availability))
            ->assertOk()
            ->assertJsonPath('message', 'Service availability deleted successfully.');

        $this->assertDatabaseMissing('service_availabilities', [
            'id' => $availability->id,
        ]);
    }

    public function test_provider_cannot_delete_another_providers_availability(): void
    {
        $ownerFixture = $this->createMarketplaceFixture('delete-owner');
        $otherFixture = $this->createMarketplaceFixture('delete-other');
        $availability = $this->createAvailability($ownerFixture['service']);

        $this->asProvider($otherFixture['provider_user'])
            ->deleteJson($this->availabilityUrl($ownerFixture['service'], $availability))
            ->assertNotFound()
            ->assertJsonPath('message', 'Service not found.');

        $this->assertDatabaseHas('service_availabilities', [
            'id' => $availability->id,
        ]);
    }

    public function test_deleting_a_missing_or_already_deleted_availability_returns_not_found(): void
    {
        $fixture = $this->createMarketplaceFixture('delete-missing');
        $availability = $this->createAvailability($fixture['service']);
        $url = $this->availabilityUrl($fixture['service'], $availability);

        $this->asProvider($fixture['provider_user'])
            ->deleteJson($url)
            ->assertOk();

        $this->asProvider($fixture['provider_user'])
            ->deleteJson($url)
            ->assertNotFound()
            ->assertJsonPath('message', 'Service availability record not found.');
    }

    public function test_unverified_provider_cannot_delete_availability(): void
    {
        $fixture = $this->createMarketplaceFixture('delete-unverified');
        $fixture['provider']->update(['verification_status' => 'pending']);
        $availability = $this->createAvailability($fixture['service']);

        $this->asProvider($fixture['provider_user'])
            ->deleteJson($this->availabilityUrl($fixture['service'], $availability))
            ->assertForbidden()
            ->assertJsonPath(
                'message',
                'Your business must be verified before you can delete service availability.'
            );

        $this->assertDatabaseHas('service_availabilities', [
            'id' => $availability->id,
        ]);
    }

    public function test_provider_can_delete_availability_for_a_service_with_an_existing_booking(): void
    {
        $fixture = $this->createMarketplaceFixture('delete-booked');
        $availability = $this->createAvailability($fixture['service']);
        $this->createBooking($fixture, 'confirmed', 'paid');

        $this->asProvider($fixture['provider_user'])
            ->deleteJson($this->availabilityUrl($fixture['service'], $availability))
            ->assertOk();

        $this->assertDatabaseMissing('service_availabilities', [
            'id' => $availability->id,
        ]);
    }

    public function test_provider_can_complete_a_confirmed_booking_once_and_notifies_once(): void
    {
        Notification::fake();

        $fixture = $this->createMarketplaceFixture('complete-once');
        $booking = $this->createBooking($fixture, 'confirmed', 'paid', now()->subDay()->toDateString());
        $url = '/api/provider/bookings/'.$booking->id.'/complete';

        $this->asProvider($fixture['provider_user'])
            ->postJson($url)
            ->assertOk();

        $this->asProvider($fixture['provider_user'])
            ->postJson($url)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Only confirmed bookings can be completed.');

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'booking_status' => 'completed',
            'payment_status' => 'paid',
        ]);

        Notification::assertSentToTimes(
            $fixture['customer'],
            BookingCompletedNotification::class,
            1
        );
    }

    public function test_completion_rechecks_provider_ownership_under_the_booking_lock(): void
    {
        $ownerFixture = $this->createMarketplaceFixture('complete-owner');
        $otherFixture = $this->createMarketplaceFixture('complete-other');
        $booking = $this->createBooking($ownerFixture, 'confirmed', 'paid', now()->subDay()->toDateString());

        $this->asProvider($otherFixture['provider_user'])
            ->postJson('/api/provider/bookings/'.$booking->id.'/complete')
            ->assertNotFound()
            ->assertJsonPath('message', 'Booking not found.');

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'booking_status' => 'confirmed',
        ]);
    }

    public function test_failed_completion_leaves_the_booking_unchanged(): void
    {
        $fixture = $this->createMarketplaceFixture('complete-pending');
        $booking = $this->createBooking($fixture, 'pending', 'unpaid', now()->subDay()->toDateString());

        $this->asProvider($fixture['provider_user'])
            ->postJson('/api/provider/bookings/'.$booking->id.'/complete')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Only confirmed bookings can be completed.');

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'booking_status' => 'pending',
            'payment_status' => 'unpaid',
            'completed_at' => null,
        ]);
    }

    public function test_notification_failure_rolls_back_booking_completion(): void
    {
        $fixture = $this->createMarketplaceFixture('complete-rollback');
        $booking = $this->createBooking($fixture, 'confirmed', 'paid', now()->subDay()->toDateString());

        DB::unprepared(
            "CREATE TRIGGER fail_booking_completion_notification
            BEFORE INSERT ON notifications
            BEGIN
                SELECT RAISE(ABORT, 'notification write failed');
            END"
        );

        try {
            $this->asProvider($fixture['provider_user'])
                ->postJson('/api/provider/bookings/'.$booking->id.'/complete')
                ->assertStatus(500);
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS fail_booking_completion_notification');
        }

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'booking_status' => 'confirmed',
            'payment_status' => 'paid',
            'completed_at' => null,
        ]);
    }

    /**
     * @return array{customer: User, provider_user: User, provider: ServiceProvider, service: Service, package: ServicePackage}
     */
    private function createMarketplaceFixture(string $suffix): array
    {
        $customer = User::factory()->create([
            'email' => $suffix.'-customer@example.test',
        ]);
        $customer->assignRole('customer');

        $providerUser = User::factory()->create([
            'email' => $suffix.'-provider@example.test',
        ]);
        $providerUser->assignRole('service_provider');

        $provider = ServiceProvider::create([
            'user_id' => $providerUser->id,
            'business_name' => 'Provider '.$suffix,
            'business_slug' => 'provider-'.$suffix,
            'verification_status' => 'verified',
            'is_active' => true,
        ]);

        $category = ServiceCategory::create([
            'name' => 'Category '.$suffix,
            'slug' => 'category-'.$suffix,
        ]);

        $service = Service::create([
            'service_provider_id' => $provider->id,
            'service_category_id' => $category->id,
            'name' => 'Service '.$suffix,
            'slug' => 'service-'.$suffix,
            'status' => 'published',
        ]);

        $package = ServicePackage::create([
            'service_id' => $service->id,
            'name' => 'Package '.$suffix,
            'slug' => 'package-'.$suffix,
            'price' => 100,
            'status' => 'published',
        ]);

        return compact('customer', 'providerUser', 'provider', 'service', 'package') + [
            'provider_user' => $providerUser,
        ];
    }

    private function createAvailability(Service $service): ServiceAvailability
    {
        return ServiceAvailability::create([
            'service_id' => $service->id,
            'date' => now()->addDays(5)->toDateString(),
            'start_time' => '09:00',
            'end_time' => '17:00',
            'status' => 'available',
        ]);
    }

    /**
     * @param  array{customer: User, provider_user: User, provider: ServiceProvider, service: Service, package: ServicePackage}  $fixture
     */
    private function createBooking(
        array $fixture,
        string $bookingStatus,
        string $paymentStatus,
        ?string $eventDate = null
    ): Booking {
        return Booking::create([
            'booking_reference' => 'BK-'.str_replace('-', '', uniqid('', true)),
            'customer_id' => $fixture['customer']->id,
            'service_provider_id' => $fixture['provider']->id,
            'service_id' => $fixture['service']->id,
            'service_package_id' => $fixture['package']->id,
            'event_date' => $eventDate ?? now()->addDays(5)->toDateString(),
            'start_time' => '10:00',
            'end_time' => '11:00',
            'event_location' => 'Concurrency Test Venue',
            'total_amount' => 100,
            'booking_status' => $bookingStatus,
            'payment_status' => $paymentStatus,
        ]);
    }

    private function asProvider(User $providerUser): static
    {
        return $this->withToken(
            $providerUser->createToken('provider-token')->plainTextToken
        );
    }

    private function availabilityUrl(Service $service, ServiceAvailability $availability): string
    {
        return '/api/provider/services/'.$service->id.'/availability/'.$availability->id;
    }
}
