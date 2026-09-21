<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Review;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProviderReviewVisibilityTest extends TestCase
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

    public function test_provider_can_list_only_reviews_for_owned_provider(): void
    {
        [$providerUser, , , , $ownedReview] = $this->createReviewFixture('owned');
        [, , , , $foreignReview] = $this->createReviewFixture('foreign');
        $token = $providerUser->createToken('provider-token')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/provider/reviews')
            ->assertOk()
            ->assertJsonStructure([
                'reviews' => [
                    'current_page',
                    'per_page',
                    'data' => [
                        '*' => [
                            'id',
                            'booking_id',
                            'customer_id',
                            'service_id',
                            'rating',
                            'comment',
                            'created_at',
                            'updated_at',
                            'customer' => ['id', 'name'],
                            'service' => ['id', 'name', 'slug'],
                        ],
                    ],
                ],
            ]);

        $reviews = $response->json('reviews.data');

        $this->assertCount(1, $reviews);
        $this->assertSame($ownedReview->id, $reviews[0]['id']);
        $this->assertNotSame($foreignReview->id, $reviews[0]['id']);
        $this->assertSame(15, $response->json('reviews.per_page'));
    }

    public function test_provider_can_view_owned_review(): void
    {
        [$providerUser, , , , $review] = $this->createReviewFixture('details');
        $token = $providerUser->createToken('provider-token')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/provider/reviews/'.$review->id)
            ->assertOk()
            ->assertJsonPath('review.id', $review->id)
            ->assertJsonPath('review.rating', 4);
    }

    public function test_provider_cannot_view_another_providers_review(): void
    {
        [$providerUser] = $this->createReviewFixture('first');
        [, , , , $foreignReview] = $this->createReviewFixture('second');
        $token = $providerUser->createToken('provider-token')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/provider/reviews/'.$foreignReview->id)
            ->assertNotFound()
            ->assertJsonPath('message', 'Review not found.');
    }

    public function test_nonexistent_review_returns_not_found(): void
    {
        [$providerUser] = $this->createReviewFixture('missing');
        $token = $providerUser->createToken('provider-token')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/provider/reviews/999999')
            ->assertNotFound()
            ->assertJsonPath('message', 'Review not found.');
    }

    public function test_customer_cannot_access_provider_review_routes(): void
    {
        [$providerUser, , , , $review] = $this->createReviewFixture('customer');
        $customer = User::factory()->create();
        $customer->assignRole('customer');
        $token = $customer->createToken('customer-token')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/provider/reviews')
            ->assertForbidden();

        $this->withToken($token)
            ->getJson('/api/provider/reviews/'.$review->id)
            ->assertForbidden();

        $this->assertNotNull($providerUser);
    }

    public function test_unauthenticated_requests_cannot_access_provider_review_routes(): void
    {
        $this->getJson('/api/provider/reviews')->assertUnauthorized();
        $this->getJson('/api/provider/reviews/1')->assertUnauthorized();
    }

    public function test_rating_filter_returns_only_matching_reviews(): void
    {
        [$providerUser, $provider, $customer, $service, $review] = $this->createReviewFixture('rating');
        $otherCustomer = User::factory()->create([
            'email' => 'rating-other-customer@example.test',
        ]);
        $otherCustomer->assignRole('customer');
        $matchingReview = $this->createReviewForService(
            $provider,
            $otherCustomer,
            $service,
            'rating-matching',
            3
        );
        $token = $providerUser->createToken('provider-token')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/provider/reviews?rating=3')
            ->assertOk();

        $reviews = $response->json('reviews.data');

        $this->assertCount(1, $reviews);
        $this->assertSame($matchingReview->id, $reviews[0]['id']);
        $this->assertNotSame($review->id, $reviews[0]['id']);
    }

    public function test_service_filter_remains_scoped_to_provider(): void
    {
        [$providerUser, , , $ownedService, $ownedReview] = $this->createReviewFixture('service-owned');
        [, , , $foreignService, $foreignReview] = $this->createReviewFixture('service-foreign');
        $token = $providerUser->createToken('provider-token')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/provider/reviews?service_id='.$ownedService->id)
            ->assertOk()
            ->assertJsonPath('reviews.data.0.id', $ownedReview->id);

        $response = $this->withToken($token)
            ->getJson('/api/provider/reviews?service_id='.$foreignService->id)
            ->assertOk();

        $this->assertCount(0, $response->json('reviews.data'));
        $this->assertNotSame($ownedReview->id, $foreignReview->id);
    }

    public function test_invalid_rating_returns_unprocessable_entity(): void
    {
        [$providerUser] = $this->createReviewFixture('invalid-rating');
        $token = $providerUser->createToken('provider-token')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/provider/reviews?rating=6')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rating']);
    }

    public function test_invalid_per_page_returns_unprocessable_entity(): void
    {
        [$providerUser] = $this->createReviewFixture('invalid-per-page');
        $token = $providerUser->createToken('provider-token')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/provider/reviews?per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);
    }

    public function test_provider_review_response_excludes_sensitive_fields(): void
    {
        [$providerUser, , $customer, , $review] = $this->createReviewFixture('safe-response');
        $token = $providerUser->createToken('provider-token')->plainTextToken;

        $reviewData = $this->withToken($token)
            ->getJson('/api/provider/reviews/'.$review->id)
            ->assertOk()
            ->json('review');

        $this->assertSame([
            'id',
            'booking_id',
            'customer_id',
            'service_id',
            'rating',
            'comment',
            'created_at',
            'updated_at',
            'customer',
            'service',
        ], array_keys($reviewData));
        $this->assertSame(['id', 'name'], array_keys($reviewData['customer']));
        $this->assertSame(['id', 'name', 'slug'], array_keys($reviewData['service']));
        $this->assertSame($customer->id, $reviewData['customer']['id']);
        $this->assertArrayNotHasKey('email', $reviewData['customer']);
        $this->assertArrayNotHasKey('phone', $reviewData['customer']);
        $this->assertArrayNotHasKey('payment', $reviewData);
        $this->assertArrayNotHasKey('metadata', $reviewData);
        $this->assertArrayNotHasKey('provider', $reviewData);
    }

    public function test_provider_without_business_profile_cannot_access_reviews(): void
    {
        $providerUser = User::factory()->create([
            'email' => 'no-profile-provider@example.test',
        ]);
        $providerUser->assignRole('service_provider');
        $token = $providerUser->createToken('provider-token')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/provider/reviews')
            ->assertNotFound()
            ->assertJsonPath('message', 'Business profile not found.');
    }

    /**
     * @return array{0: User, 1: ServiceProvider, 2: User, 3: Service, 4: Review}
     */
    private function createReviewFixture(string $suffix): array
    {
        $providerUser = User::factory()->create([
            'email' => $suffix.'-provider@example.test',
        ]);
        $providerUser->assignRole('service_provider');

        $provider = ServiceProvider::create([
            'user_id' => $providerUser->id,
            'business_name' => 'Provider '.$suffix,
            'business_slug' => 'provider-'.$suffix,
            'verification_status' => 'pending',
            'is_active' => true,
        ]);

        $customer = User::factory()->create([
            'email' => $suffix.'-customer@example.test',
        ]);
        $customer->assignRole('customer');

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

        return [
            $providerUser,
            $provider,
            $customer,
            $service,
            $this->createReviewForService(
                $provider,
                $customer,
                $service,
                $suffix,
                4
            ),
        ];
    }

    private function createReviewForService(
        ServiceProvider $provider,
        User $customer,
        Service $service,
        string $suffix,
        int $rating
    ): Review {
        $booking = Booking::create([
            'booking_reference' => 'BK-'.$suffix,
            'customer_id' => $customer->id,
            'service_provider_id' => $provider->id,
            'service_id' => $service->id,
            'event_date' => now()->addDays(3)->toDateString(),
            'start_time' => '10:00',
            'end_time' => '11:00',
            'total_amount' => 100,
            'booking_status' => 'completed',
            'payment_status' => 'unpaid',
        ]);

        return Review::create([
            'booking_id' => $booking->id,
            'customer_id' => $customer->id,
            'service_provider_id' => $provider->id,
            'service_id' => $service->id,
            'rating' => $rating,
            'comment' => 'A provider-visible review.',
        ]);
    }
}
