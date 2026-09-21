<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Review;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceProvider;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReviewLifecycleSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('The configured SQLite test driver is not installed.');
        }

        parent::setUp();

        foreach (['customer', 'service_provider', 'admin'] as $role) {
            Role::create([
                'name' => $role,
                'guard_name' => 'web',
            ]);
        }
    }

    public function test_unauthenticated_customer_review_creation_returns_401(): void
    {
        [, $booking] = $this->createBookingFixture('unauthenticated');

        $this->postJson('/api/customer/bookings/'.$booking->id.'/reviews', [
            'rating' => 5,
        ])->assertUnauthorized();
    }

    public function test_provider_cannot_create_customer_review(): void
    {
        [, $booking, $provider] = $this->createBookingFixture('provider-create');
        $providerUser = User::findOrFail($provider->user_id);
        $token = $providerUser->createToken('provider-token')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/customer/bookings/'.$booking->id.'/reviews', [
                'rating' => 5,
            ])
            ->assertForbidden();
    }

    public function test_customer_cannot_review_another_customers_booking(): void
    {
        [, $booking] = $this->createBookingFixture('foreign-booking');
        $attacker = User::factory()->create([
            'email' => 'foreign-attacker@example.test',
        ]);
        $attacker->assignRole('customer');
        $token = $attacker->createToken('customer-token')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/customer/bookings/'.$booking->id.'/reviews', [
                'rating' => 5,
            ])
            ->assertNotFound()
            ->assertJsonPath('message', 'Booking not found.');
    }

    public function test_only_completed_bookings_can_be_reviewed(): void
    {
        foreach (['pending', 'accepted', 'confirmed'] as $status) {
            [$customer, $booking] = $this->createBookingFixture(
                'status-'.$status,
                $status
            );

            $response = $this->actingAs($customer, 'sanctum')
                ->postJson('/api/customer/bookings/'.$booking->id.'/reviews', [
                    'rating' => 5,
                ]);

            $this->assertSame(
                422,
                $response->status(),
                $status.' response: '.$response->getContent()
            );
            $response->assertJsonValidationErrors(['booking']);
        }
    }

    public function test_completed_booking_can_be_reviewed_with_server_derived_identity(): void
    {
        [$customer, $booking, $provider, $service] = $this->createBookingFixture(
            'completed-create'
        );
        [, $otherBooking, $otherProvider, $otherService] = $this->createBookingFixture(
            'override-values'
        );
        $token = $customer->createToken('customer-token')->plainTextToken;

        $response = $this->withToken($token)
            ->postJson('/api/customer/bookings/'.$booking->id.'/reviews', [
                'rating' => 5,
                'comment' => 'Completed booking review.',
                'booking_id' => $otherBooking->id,
                'customer_id' => $otherBooking->customer_id,
                'service_provider_id' => $otherProvider->id,
                'service_id' => $otherService->id,
            ])
            ->assertCreated();

        $reviewId = $response->json('review.id');

        $this->assertDatabaseHas('reviews', [
            'id' => $reviewId,
            'booking_id' => $booking->id,
            'customer_id' => $customer->id,
            'service_provider_id' => $provider->id,
            'service_id' => $service->id,
            'rating' => 5,
        ]);
    }

    public function test_rating_below_one_and_above_five_return_422(): void
    {
        foreach ([0, 6] as $rating) {
            [$customer, $booking] = $this->createBookingFixture(
                'invalid-rating-'.$rating
            );
            $token = $customer->createToken('customer-token')->plainTextToken;

            $this->withToken($token)
                ->postJson('/api/customer/bookings/'.$booking->id.'/reviews', [
                    'rating' => $rating,
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['rating']);
        }
    }

    public function test_comment_over_2000_characters_returns_422(): void
    {
        [$customer, $booking] = $this->createBookingFixture('long-comment');
        $token = $customer->createToken('customer-token')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/customer/bookings/'.$booking->id.'/reviews', [
                'rating' => 5,
                'comment' => str_repeat('x', 2001),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['comment']);
    }

    public function test_duplicate_review_returns_409_and_keeps_one_review(): void
    {
        [$customer, $booking] = $this->createBookingFixture('duplicate-review');
        $token = $customer->createToken('customer-token')->plainTextToken;
        $payload = [
            'rating' => 5,
            'comment' => 'First review.',
        ];

        $this->withToken($token)
            ->postJson('/api/customer/bookings/'.$booking->id.'/reviews', $payload)
            ->assertCreated();

        $this->withToken($token)
            ->postJson('/api/customer/bookings/'.$booking->id.'/reviews', $payload)
            ->assertConflict()
            ->assertJsonPath('message', 'This booking has already been reviewed.');

        $this->assertSame(1, Review::where('booking_id', $booking->id)->count());
    }

    public function test_database_unique_constraint_rejects_duplicate_booking_review(): void
    {
        [$customer, $booking, $provider, $service] = $this->createBookingFixture(
            'database-duplicate'
        );
        $review = Review::create([
            'booking_id' => $booking->id,
            'customer_id' => $customer->id,
            'service_provider_id' => $provider->id,
            'service_id' => $service->id,
            'rating' => 5,
        ]);

        $this->expectException(QueryException::class);

        Review::create([
            'booking_id' => $booking->id,
            'customer_id' => $customer->id,
            'service_provider_id' => $provider->id,
            'service_id' => $service->id,
            'rating' => 4,
        ]);

        $this->assertNotNull($review);
    }

    public function test_customer_cannot_access_another_customers_review(): void
    {
        [$owner, $review] = $this->createReviewFixture('foreign-review');
        $attacker = User::factory()->create([
            'email' => 'review-attacker@example.test',
        ]);
        $attacker->assignRole('customer');
        $token = $attacker->createToken('customer-token')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/customer/reviews/'.$review->id)
            ->assertNotFound();

        $this->withToken($token)
            ->putJson('/api/customer/reviews/'.$review->id, [
                'rating' => 1,
            ])
            ->assertNotFound();

        $this->withToken($token)
            ->deleteJson('/api/customer/reviews/'.$review->id)
            ->assertNotFound();

        $this->assertNotNull($owner);
    }

    public function test_customer_update_cannot_change_review_ownership_ids(): void
    {
        [$customer, $review] = $this->createReviewFixture('update-ownership');
        [, $otherBooking, $otherProvider, $otherService] = $this->createBookingFixture(
            'update-override'
        );
        $token = $customer->createToken('customer-token')->plainTextToken;

        $this->withToken($token)
            ->putJson('/api/customer/reviews/'.$review->id, [
                'rating' => 2,
                'comment' => 'Updated safely.',
                'booking_id' => $otherBooking->id,
                'customer_id' => $otherBooking->customer_id,
                'service_provider_id' => $otherProvider->id,
                'service_id' => $otherService->id,
            ])
            ->assertOk();

        $this->assertDatabaseHas('reviews', [
            'id' => $review->id,
            'booking_id' => $review->booking_id,
            'customer_id' => $customer->id,
            'service_provider_id' => $review->service_provider_id,
            'service_id' => $review->service_id,
            'rating' => 2,
            'comment' => 'Updated safely.',
        ]);
    }

    public function test_provider_review_routes_are_read_only(): void
    {
        [$providerUser, , , , , $review] = $this->createProviderReviewFixture(
            'provider-read-only'
        );
        $token = $providerUser->createToken('provider-token')->plainTextToken;

        $this->withToken($token)
            ->putJson('/api/provider/reviews/'.$review->id, [
                'rating' => 1,
            ])
            ->assertMethodNotAllowed();

        $this->withToken($token)
            ->deleteJson('/api/provider/reviews/'.$review->id)
            ->assertMethodNotAllowed();
    }

    public function test_public_reviews_require_all_visibility_conditions(): void
    {
        $cases = [
            'unpublished' => function (Service $service, ServiceProvider $provider, ServiceCategory $category): void {
                $service->update(['status' => 'draft']);
            },
            'unverified' => function (Service $service, ServiceProvider $provider, ServiceCategory $category): void {
                $provider->update(['verification_status' => 'pending']);
            },
            'inactive-provider' => function (Service $service, ServiceProvider $provider, ServiceCategory $category): void {
                $provider->update(['is_active' => false]);
            },
            'inactive-category' => function (Service $service, ServiceProvider $provider, ServiceCategory $category): void {
                $category->update(['is_active' => false]);
            },
        ];

        foreach ($cases as $suffix => $change) {
            [, , $service, $provider, $category] = $this->createProviderReviewFixture(
                'public-'.$suffix
            );
            $change($service, $provider, $category);

            $this->getJson('/api/marketplace/services/'.$service->slug.'/reviews')
                ->assertNotFound()
                ->assertJsonPath('message', 'Service not found.');
        }
    }

    public function test_public_review_payload_excludes_private_booking_and_customer_fields(): void
    {
        [, , $service, , , $review] = $this->createProviderReviewFixture(
            'public-privacy'
        );

        $reviewData = $this->getJson(
            '/api/marketplace/services/'.$service->slug.'/reviews'
        )
            ->assertOk()
            ->json('reviews.data.0');

        $this->assertSame([
            'id',
            'rating',
            'comment',
            'created_at',
            'updated_at',
            'customer',
        ], array_keys($reviewData));
        $this->assertSame(['id', 'name'], array_keys($reviewData['customer']));
        $this->assertArrayNotHasKey('email', $reviewData['customer']);
        $this->assertArrayNotHasKey('phone', $reviewData['customer']);
        $this->assertArrayNotHasKey('booking_id', $reviewData);
        $this->assertSame($review->id, $reviewData['id']);
    }

    /**
     * @return array{0: User, 1: Booking, 2: ServiceProvider, 3: Service}
     */
    private function createBookingFixture(
        string $suffix,
        string $status = 'completed'
    ): array {
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
            'is_active' => true,
        ]);
        $service = Service::create([
            'service_provider_id' => $provider->id,
            'service_category_id' => $category->id,
            'name' => 'Service '.$suffix,
            'slug' => 'service-'.$suffix,
            'status' => 'published',
        ]);
        $customer = User::factory()->create([
            'email' => $suffix.'-customer@example.test',
        ]);
        $customer->assignRole('customer');
        $booking = Booking::create([
            'booking_reference' => 'BK-'.$suffix,
            'customer_id' => $customer->id,
            'service_provider_id' => $provider->id,
            'service_id' => $service->id,
            'event_date' => now()->addDays(3)->toDateString(),
            'start_time' => '10:00',
            'end_time' => '11:00',
            'total_amount' => 100,
            'booking_status' => $status,
            'payment_status' => 'unpaid',
        ]);

        return [$customer, $booking, $provider, $service];
    }

    /**
     * @return array{0: User, 1: Review}
     */
    private function createReviewFixture(string $suffix): array
    {
        [$customer, $booking, $provider, $service] = $this->createBookingFixture($suffix);

        return [
            $customer,
            Review::create([
                'booking_id' => $booking->id,
                'customer_id' => $customer->id,
                'service_provider_id' => $provider->id,
                'service_id' => $service->id,
                'rating' => 4,
                'comment' => 'Existing review.',
            ]),
        ];
    }

    /**
     * @return array{0: User, 1: ServiceProvider, 2: Service, 3: ServiceProvider, 4: ServiceCategory, 5: Review}
     */
    private function createProviderReviewFixture(string $suffix): array
    {
        [$customer, $booking, $provider, $service] = $this->createBookingFixture($suffix);
        $review = Review::create([
            'booking_id' => $booking->id,
            'customer_id' => $customer->id,
            'service_provider_id' => $provider->id,
            'service_id' => $service->id,
            'rating' => 4,
            'comment' => 'Public review.',
        ]);

        return [
            User::whereKey($provider->user_id)->firstOrFail(),
            $provider,
            $service,
            $provider,
            ServiceCategory::whereKey($service->service_category_id)->firstOrFail(),
            $review,
        ];
    }
}
