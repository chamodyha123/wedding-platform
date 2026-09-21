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

class ReviewManagementTest extends TestCase
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

    public function test_customer_lists_only_own_reviews(): void
    {
        [$customer, $review] = $this->createReviewFixture('list-own');
        [, $otherReview] = $this->createReviewFixture('list-other');
        $token = $customer->createToken('customer-token')->plainTextToken;

        $reviews = $this->withToken($token)
            ->getJson('/api/customer/reviews')
            ->assertOk()
            ->json('reviews');

        $this->assertCount(1, $reviews);
        $this->assertSame($review->id, $reviews[0]['id']);
        $this->assertNotSame($otherReview->id, $reviews[0]['id']);
    }

    public function test_customer_can_show_own_review(): void
    {
        [$customer, $review] = $this->createReviewFixture('show-own');
        $token = $customer->createToken('customer-token')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/customer/reviews/'.$review->id)
            ->assertOk()
            ->assertJsonPath('review.id', $review->id)
            ->assertJsonPath('review.rating', 4);
    }

    public function test_customer_can_update_rating(): void
    {
        [$customer, $review] = $this->createReviewFixture('update-rating');
        $token = $customer->createToken('customer-token')->plainTextToken;

        $this->withToken($token)
            ->putJson('/api/customer/reviews/'.$review->id, [
                'rating' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('review.rating', 2);

        $this->assertDatabaseHas('reviews', [
            'id' => $review->id,
            'rating' => 2,
            'comment' => 'Original review.',
        ]);
    }

    public function test_customer_can_update_comment(): void
    {
        [$customer, $review] = $this->createReviewFixture('update-comment');
        $token = $customer->createToken('customer-token')->plainTextToken;

        $this->withToken($token)
            ->putJson('/api/customer/reviews/'.$review->id, [
                'comment' => 'Updated comment.',
            ])
            ->assertOk()
            ->assertJsonPath('review.comment', 'Updated comment.');

        $this->assertDatabaseHas('reviews', [
            'id' => $review->id,
            'rating' => 4,
            'comment' => 'Updated comment.',
        ]);
    }

    public function test_empty_review_update_is_rejected(): void
    {
        [$customer, $review] = $this->createReviewFixture('update-empty');
        $token = $customer->createToken('customer-token')->plainTextToken;

        $this->withToken($token)
            ->putJson('/api/customer/reviews/'.$review->id, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['review']);
    }

    public function test_customer_can_delete_own_review(): void
    {
        [$customer, $review] = $this->createReviewFixture('delete-own');
        $token = $customer->createToken('customer-token')->plainTextToken;

        $this->withToken($token)
            ->deleteJson('/api/customer/reviews/'.$review->id)
            ->assertOk()
            ->assertJsonPath('message', 'Review deleted successfully.');

        $this->assertDatabaseMissing('reviews', [
            'id' => $review->id,
        ]);
    }

    /**
     * @return array{0: User, 1: Review}
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
            'verification_status' => 'verified',
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
        $booking = Booking::create([
            'booking_reference' => 'BK-'.$suffix,
            'customer_id' => $customer->id,
            'service_provider_id' => $provider->id,
            'service_id' => $service->id,
            'event_date' => now()->subDay()->toDateString(),
            'start_time' => '10:00',
            'end_time' => '11:00',
            'total_amount' => 100,
            'booking_status' => 'completed',
            'payment_status' => 'unpaid',
        ]);
        $review = Review::create([
            'booking_id' => $booking->id,
            'customer_id' => $customer->id,
            'service_provider_id' => $provider->id,
            'service_id' => $service->id,
            'rating' => 4,
            'comment' => 'Original review.',
        ]);

        return [$customer, $review];
    }
}
