<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServicePackage;
use App\Models\ServiceProvider;
use App\Models\User;
use App\Notifications\BookingAcceptedNotification;
use App\Notifications\BookingCancelledNotification;
use App\Notifications\BookingCompletedNotification;
use App\Notifications\BookingCreatedNotification;
use App\Notifications\BookingRejectedNotification;
use App\Notifications\PaymentConfirmedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NotificationTest extends TestCase
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

    public function test_booking_created_notifies_provider_user(): void
    {
        Notification::fake();

        $fixture = $this->createMarketplaceFixture('booking-created');

        $customer = $fixture['customer'];
        $providerUser = $fixture['provider_user'];
        $service = $fixture['service'];
        $package = $fixture['package'];
        $eventDate = $fixture['event_date'];

        $token = $customer
            ->createToken('customer-token')
            ->plainTextToken;

        $response = $this->withToken($token)
            ->postJson('/api/customer/bookings', [
                'service_id' => $service->id,
                'service_package_id' => $package->id,
                'event_date' => $eventDate,
                'start_time' => '10:00',
                'end_time' => '11:00',
                'event_location' => 'Notification Test Venue',
                'customer_notes' => 'Notification test booking.',
            ])
            ->assertCreated();

        $bookingId = $response->json('booking.id');

        $this->assertDatabaseHas('bookings', [
            'id' => $bookingId,
            'customer_id' => $customer->id,
            'service_provider_id' => $fixture['provider']->id,
            'booking_status' => 'pending',
        ]);

        Notification::assertSentTo(
            $providerUser,
            BookingCreatedNotification::class
        );

        Notification::assertNotSentTo(
            $customer,
            BookingCreatedNotification::class
        );
    }

    public function test_booking_accepted_notifies_customer_once(): void
    {
        Notification::fake();

        $fixture = $this->createBookingFixture(
            'booking-accepted',
            'pending'
        );

        $providerUser = $fixture['provider_user'];
        $customer = $fixture['customer'];
        $booking = $fixture['booking'];

        $token = $providerUser
            ->createToken('provider-token')
            ->plainTextToken;

        $url = '/api/provider/bookings/'.$booking->id.'/accept';

        $this->withToken($token)
            ->postJson($url, [
                'provider_notes' => 'Accepted for the requested date.',
            ])
            ->assertOk();

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'booking_status' => 'accepted',
        ]);

        Notification::assertSentTo(
            $customer,
            BookingAcceptedNotification::class
        );

        $this->withToken($token)
            ->postJson($url)
            ->assertStatus(422);

        Notification::assertSentToTimes(
            $customer,
            BookingAcceptedNotification::class,
            1
        );
    }

    public function test_booking_rejected_notifies_customer_once(): void
    {
        Notification::fake();

        $fixture = $this->createBookingFixture(
            'booking-rejected',
            'pending'
        );

        $providerUser = $fixture['provider_user'];
        $customer = $fixture['customer'];
        $booking = $fixture['booking'];

        $token = $providerUser
            ->createToken('provider-token')
            ->plainTextToken;

        $url = '/api/provider/bookings/'.$booking->id.'/reject';

        $this->withToken($token)
            ->postJson($url, [
                'provider_notes' => 'Unavailable for this booking.',
            ])
            ->assertOk();

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'booking_status' => 'rejected',
        ]);

        Notification::assertSentTo(
            $customer,
            BookingRejectedNotification::class
        );

        $this->withToken($token)
            ->postJson($url)
            ->assertStatus(422);

        Notification::assertSentToTimes(
            $customer,
            BookingRejectedNotification::class,
            1
        );
    }

    public function test_booking_cancelled_notifies_provider_user(): void
    {
        Notification::fake();

        $fixture = $this->createBookingFixture(
            'booking-cancelled',
            'accepted'
        );

        $customer = $fixture['customer'];
        $providerUser = $fixture['provider_user'];
        $booking = $fixture['booking'];

        $token = $customer
            ->createToken('customer-token')
            ->plainTextToken;

        $this->withToken($token)
            ->postJson(
                '/api/customer/bookings/'.$booking->id.'/cancel',
                [
                    'cancellation_reason' => 'Plans changed.',
                ]
            )
            ->assertOk();

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'booking_status' => 'cancelled',
            'payment_status' => 'unpaid',
            'cancellation_reason' => 'Plans changed.',
        ]);

        Notification::assertSentTo(
            $providerUser,
            BookingCancelledNotification::class
        );
    }

    public function test_booking_completed_notifies_customer_once(): void
    {
        Notification::fake();

        $fixture = $this->createBookingFixture(
            'booking-completed',
            'confirmed',
            'paid',
            now()->subDay()->toDateString()
        );

        $providerUser = $fixture['provider_user'];
        $customer = $fixture['customer'];
        $booking = $fixture['booking'];

        $token = $providerUser
            ->createToken('provider-token')
            ->plainTextToken;

        $url = '/api/provider/bookings/'.$booking->id.'/complete';

        $this->withToken($token)
            ->postJson($url)
            ->assertOk();

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'booking_status' => 'completed',
            'payment_status' => 'paid',
        ]);

        $this->assertNotNull(
            $booking->fresh()->completed_at
        );

        Notification::assertSentTo(
            $customer,
            BookingCompletedNotification::class
        );

        $this->withToken($token)
            ->postJson($url)
            ->assertStatus(422);

        Notification::assertSentToTimes(
            $customer,
            BookingCompletedNotification::class,
            1
        );
    }

    public function test_payment_confirmed_notifies_customer_once(): void
    {
        Notification::fake();

        $fixture = $this->createBookingFixture(
            'payment-confirmed',
            'accepted',
            'pending'
        );

        $customer = $fixture['customer'];
        $booking = $fixture['booking'];

        $payment = Payment::create([
            'booking_id' => $booking->id,
            'customer_id' => $customer->id,
            'payment_reference' => 'PAY-payment-confirmed',
            'amount' => $booking->total_amount,
            'currency' => 'LKR',
            'payment_method' => 'card',
            'status' => 'pending',
        ]);

        $token = $customer
            ->createToken('customer-token')
            ->plainTextToken;

        $url =
            '/api/customer/bookings/'.
            $booking->id.
            '/payments/'.
            $payment->id.
            '/success';

        $this->withToken($token)
            ->postJson($url)
            ->assertOk();

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'paid',
        ]);

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'booking_status' => 'confirmed',
            'payment_status' => 'paid',
        ]);

        Notification::assertSentTo(
            $customer,
            PaymentConfirmedNotification::class
        );

        $this->withToken($token)
            ->postJson($url)
            ->assertStatus(409);

        Notification::assertSentToTimes(
            $customer,
            PaymentConfirmedNotification::class,
            1
        );
    }

    public function test_authenticated_user_lists_only_own_notifications_newest_first(): void
    {
        $firstUser = $this->createCustomer(
            'notifications-owner'
        );

        $secondUser = $this->createCustomer(
            'notifications-other'
        );

        $older = $this->storeBookingAcceptedNotification(
            $firstUser,
            'owner-older'
        );

        $older->forceFill([
            'created_at' => now()->subMinutes(2),
        ])->save();

        $newer = $this->storeBookingAcceptedNotification(
            $firstUser,
            'owner-newer'
        );

        $other = $this->storeBookingAcceptedNotification(
            $secondUser,
            'other-user'
        );

        $token = $firstUser
            ->createToken('customer-token')
            ->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/notifications?per_page=100')
            ->assertOk()
            ->assertJsonPath(
                'pagination.total',
                2
            )
            ->assertJsonCount(
                2,
                'notifications'
            );

        $this->assertSame(
            $newer->id,
            $response->json('notifications.0.id')
        );

        $this->assertSame(
            $older->id,
            $response->json('notifications.1.id')
        );

        $ids = collect(
            $response->json('notifications')
        )
            ->pluck('id')
            ->all();

        $this->assertNotContains(
            $other->id,
            $ids
        );
    }

    public function test_authenticated_user_lists_only_unread_notifications(): void
    {
        $user = $this->createCustomer(
            'unread-owner'
        );

        $read = $this->storeBookingAcceptedNotification(
            $user,
            'already-read'
        );

        $read->markAsRead();

        $unread = $this->storeBookingAcceptedNotification(
            $user,
            'still-unread'
        );

        $token = $user
            ->createToken('customer-token')
            ->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/notifications/unread?per_page=100')
            ->assertOk()
            ->assertJsonPath(
                'pagination.total',
                1
            )
            ->assertJsonCount(
                1,
                'notifications'
            );

        $this->assertSame(
            $unread->id,
            $response->json('notifications.0.id')
        );

        $this->assertNull(
            $unread->fresh()->read_at
        );

        $this->assertNotNull(
            $read->fresh()->read_at
        );
    }

    public function test_user_can_mark_own_notification_as_read(): void
    {
        $user = $this->createCustomer(
            'mark-own'
        );

        $notification =
            $this->storeBookingAcceptedNotification(
                $user,
                'mark-own'
            );

        $token = $user
            ->createToken('customer-token')
            ->plainTextToken;

        $this->withToken($token)
            ->postJson(
                '/api/notifications/'.
                $notification->id.
                '/read'
            )
            ->assertOk()
            ->assertJsonPath(
                'notification.id',
                $notification->id
            );

        $this->assertNotNull(
            $notification->fresh()->read_at
        );
    }

    public function test_user_cannot_mark_another_users_notification_as_read(): void
    {
        $owner = $this->createCustomer(
            'foreign-owner'
        );

        $attacker = $this->createCustomer(
            'foreign-attacker'
        );

        $notification =
            $this->storeBookingAcceptedNotification(
                $owner,
                'foreign-notification'
            );

        $token = $attacker
            ->createToken('customer-token')
            ->plainTextToken;

        $this->withToken($token)
            ->postJson(
                '/api/notifications/'.
                $notification->id.
                '/read'
            )
            ->assertNotFound();

        $this->assertNull(
            $notification->fresh()->read_at
        );
    }

    public function test_read_all_only_marks_current_users_notifications(): void
    {
        $firstUser = $this->createCustomer(
            'read-all-owner'
        );

        $secondUser = $this->createCustomer(
            'read-all-other'
        );

        $this->storeBookingAcceptedNotification(
            $firstUser,
            'read-all-one'
        );

        $this->storeBookingAcceptedNotification(
            $firstUser,
            'read-all-two'
        );

        $otherNotification =
            $this->storeBookingAcceptedNotification(
                $secondUser,
                'read-all-other'
            );

        $token = $firstUser
            ->createToken('customer-token')
            ->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/notifications/read-all')
            ->assertOk()
            ->assertJsonPath(
                'updated_count',
                2
            );

        $this->assertSame(
            0,
            $firstUser
                ->unreadNotifications()
                ->count()
        );

        $this->assertSame(
            1,
            $secondUser
                ->unreadNotifications()
                ->count()
        );

        $this->assertNull(
            $otherNotification->fresh()->read_at
        );
    }

    public function test_notification_routes_require_authentication(): void
    {
        $this->getJson('/api/notifications')
            ->assertUnauthorized();

        $this->getJson('/api/notifications/unread')
            ->assertUnauthorized();

        $this->postJson('/api/notifications/read-all')
            ->assertUnauthorized();

        $this->postJson(
            '/api/notifications/nonexistent-id/read'
        )
            ->assertUnauthorized();
    }

    public function test_payment_notification_payload_excludes_sensitive_gateway_data(): void
    {
        $user = $this->createCustomer(
            'safe-payment-data'
        );

        $user->notify(
            new PaymentConfirmedNotification(
                paymentId: 99,
                paymentReference: 'PAY-SAFE-99',
                bookingId: 77,
                bookingReference: 'BK-SAFE-77',
                amount: '100.00',
                currency: 'LKR',
            )
        );

        $notification = $user
            ->notifications()
            ->firstOrFail();

        $data = $notification->data;

        $this->assertSame(
            'payment_confirmed',
            $data['type']
        );

        $this->assertSame(
            99,
            $data['payment_id']
        );

        $this->assertSame(
            'PAY-SAFE-99',
            $data['payment_reference']
        );

        $this->assertSame(
            77,
            $data['booking_id']
        );

        $this->assertSame(
            'BK-SAFE-77',
            $data['booking_reference']
        );

        $this->assertSame(
            '100.00',
            $data['amount']
        );

        $this->assertSame(
            'LKR',
            $data['currency']
        );

        foreach ([
            'gateway',
            'gateway_transaction_id',
            'metadata',
            'md5sig',
            'merchant_secret',
            'api_key',
            'access_token',
            'card_number',
            'cvv',
            'password',
            'signature',
        ] as $sensitiveKey) {
            $this->assertArrayNotHasKey(
                $sensitiveKey,
                $data
            );
        }
    }

    /**
     * @return array{
     *     customer: User,
     *     provider_user: User,
     *     provider: ServiceProvider,
     *     service: Service,
     *     package: ServicePackage,
     *     event_date: string
     * }
     */
    private function createMarketplaceFixture(
        string $suffix
    ): array {
        $customer = $this->createCustomer(
            $suffix.'-customer'
        );

        $providerUser = User::factory()->create([
            'email' =>
                $suffix.
                '-provider@example.test',
        ]);

        $providerUser->assignRole(
            'service_provider'
        );

        $provider = ServiceProvider::create([
            'user_id' => $providerUser->id,
            'business_name' =>
                'Provider '.$suffix,
            'business_slug' =>
                'provider-'.$suffix,
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

        $eventDate = now()
            ->addDays(5)
            ->toDateString();

        $service->availabilities()->create([
            'date' => $eventDate,
            'start_time' => '09:00',
            'end_time' => '17:00',
            'status' => 'available',
        ]);

        return [
            'customer' => $customer,
            'provider_user' => $providerUser,
            'provider' => $provider,
            'service' => $service,
            'package' => $package,
            'event_date' => $eventDate,
        ];
    }

    /**
     * @return array{
     *     customer: User,
     *     provider_user: User,
     *     provider: ServiceProvider,
     *     service: Service,
     *     package: ServicePackage,
     *     booking: Booking
     * }
     */
    private function createBookingFixture(
        string $suffix,
        string $bookingStatus = 'pending',
        string $paymentStatus = 'unpaid',
        ?string $eventDate = null
    ): array {
        $fixture =
            $this->createMarketplaceFixture(
                $suffix
            );

        $booking = Booking::create([
            'booking_reference' =>
                'BK-'.$suffix,

            'customer_id' =>
                $fixture['customer']->id,

            'service_provider_id' =>
                $fixture['provider']->id,

            'service_id' =>
                $fixture['service']->id,

            'service_package_id' =>
                $fixture['package']->id,

            'event_date' =>
                $eventDate ??
                $fixture['event_date'],

            'start_time' => '10:00',

            'end_time' => '11:00',

            'event_location' =>
                'Notification Test Venue',

            'total_amount' => 100,

            'booking_status' =>
                $bookingStatus,

            'payment_status' =>
                $paymentStatus,
        ]);

        return [
            ...$fixture,
            'booking' => $booking,
        ];
    }

    private function createCustomer(
        string $suffix
    ): User {
        $customer = User::factory()->create([
            'email' =>
                $suffix.'@example.test',
        ]);

        $customer->assignRole('customer');

        return $customer;
    }

    private function storeBookingAcceptedNotification(
        User $user,
        string $suffix
    ): DatabaseNotification {
        $bookingReference = 'BK-'.$suffix;

        $user->notify(
            new BookingAcceptedNotification(
                bookingId: 1,
                bookingReference: $bookingReference,
                serviceName:
                    'Service '.$suffix,
                eventDate: now()
                    ->addDays(5)
                    ->toDateString(),
            )
        );

        $notification = $user
            ->notifications()
            ->get()
            ->first(
                function (
                    DatabaseNotification $notification
                ) use ($bookingReference): bool {
                    return (
                        $notification->data['booking_reference']
                            ?? null
                    ) === $bookingReference;
                }
            );

        $this->assertNotNull(
            $notification,
            'Expected database notification was not created.'
        );

        return $notification;
    }
}