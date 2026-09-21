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

class ReviewRatingSummaryTest extends TestCase
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

    public function test_public_service_list_exposes_rating_summary(): void
    {
        [, , $service] = $this->createProviderFixture('service-list');
        $this->createReview($service, 'service-list-review', 5);

        $serviceData = $this->findServiceInResponse(
            $this->getJson('/api/marketplace/services?per_page=100')
                ->assertOk()
                ->json('data'),
            $service->id
        );

        $this->assertSame(1, $serviceData['reviews_count']);
        $this->assertSame(5, $serviceData['average_rating']);
    }

    public function test_public_single_service_exposes_rating_summary(): void
    {
        [, , $service] = $this->createProviderFixture('service-show');
        $this->createReview($service, 'service-show-review', 4);

        $this->getJson('/api/marketplace/services/'.$service->slug)
            ->assertOk()
            ->assertJsonPath('service.reviews_count', 1)
            ->assertJsonPath('service.average_rating', 4);
    }

    public function test_service_with_no_reviews_returns_null_average(): void
    {
        [, , $service] = $this->createProviderFixture('service-empty');

        $this->getJson('/api/marketplace/services/'.$service->slug)
            ->assertOk()
            ->assertJsonPath('service.reviews_count', 0)
            ->assertJsonPath('service.average_rating', null);
    }

    public function test_multiple_service_reviews_are_averaged_and_rounded(): void
    {
        [, , $service] = $this->createProviderFixture('service-average');
        $this->createReview($service, 'service-average-one', 5);
        $this->createReview($service, 'service-average-two', 4);
        $this->createReview($service, 'service-average-three', 4);

        $this->getJson('/api/marketplace/services/'.$service->slug)
            ->assertOk()
            ->assertJsonPath('service.reviews_count', 3)
            ->assertJsonPath('service.average_rating', 4.33);
    }

    public function test_public_provider_list_exposes_rating_summary(): void
    {
        [$provider, , $service] = $this->createProviderFixture('provider-list');
        $this->createReview($service, 'provider-list-review', 5);

        $providerData = $this->findProviderInResponse(
            $this->getJson('/api/marketplace/providers?per_page=100')
                ->assertOk()
                ->json('data'),
            $provider->id
        );

        $this->assertSame(1, $providerData['reviews_count']);
        $this->assertSame(5, $providerData['average_rating']);
    }

    public function test_public_single_provider_exposes_rating_summary(): void
    {
        [$provider, , $service] = $this->createProviderFixture('provider-show');
        $this->createReview($service, 'provider-show-review', 4);

        $this->getJson('/api/marketplace/providers/'.$provider->business_slug)
            ->assertOk()
            ->assertJsonPath('provider.reviews_count', 1)
            ->assertJsonPath('provider.average_rating', 4);
    }

    public function test_provider_with_no_reviews_returns_null_average(): void
    {
        [$provider] = $this->createProviderFixture('provider-empty');

        $this->getJson('/api/marketplace/providers/'.$provider->business_slug)
            ->assertOk()
            ->assertJsonPath('provider.reviews_count', 0)
            ->assertJsonPath('provider.average_rating', null);
    }

    public function test_provider_summary_includes_reviews_from_multiple_services(): void
    {
        [$provider, , $firstService] = $this->createProviderFixture('provider-multiple');
        $secondService = $this->createService($provider, 'provider-multiple-second');
        $this->createReview($firstService, 'provider-multiple-first-review', 5);
        $this->createReview($secondService, 'provider-multiple-second-review', 3);

        $this->getJson('/api/marketplace/providers/'.$provider->business_slug)
            ->assertOk()
            ->assertJsonPath('provider.reviews_count', 2)
            ->assertJsonPath('provider.average_rating', 4);
    }

    public function test_provider_dashboard_uses_actual_review_summary(): void
    {
        [$provider, $providerUser, $service] = $this->createProviderFixture('dashboard');
        $this->createReview($service, 'dashboard-review-one', 5);
        $this->createReview($service, 'dashboard-review-two', 4);
        $token = $providerUser->createToken('provider-token')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/provider/dashboard')
            ->assertOk()
            ->assertJsonPath('dashboard.statistics.reviews_count', 2)
            ->assertJsonPath('dashboard.statistics.average_rating', 4.5);

        $this->assertNotNull($provider);
    }

    public function test_provider_dashboard_summary_is_scoped_to_authenticated_provider(): void
    {
        [$providerUser, $providerUserAccount, $service] = $this->createProviderFixture('dashboard-owned');
        [, , $foreignService] = $this->createProviderFixture('dashboard-foreign');
        $this->createReview($service, 'dashboard-owned-review', 5);
        $this->createReview($foreignService, 'dashboard-foreign-review', 1);
        $token = $providerUserAccount->createToken('provider-token')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/provider/dashboard')
            ->assertOk()
            ->assertJsonPath('dashboard.provider.id', $providerUser->id)
            ->assertJsonPath('dashboard.statistics.reviews_count', 1)
            ->assertJsonPath('dashboard.statistics.average_rating', 5);
    }

    public function test_public_service_reviews_summary_ignores_rating_filter_and_pagination(): void
    {
        [, , $service] = $this->createProviderFixture('reviews-summary');
        $this->createReview($service, 'reviews-summary-one', 5);
        $this->createReview($service, 'reviews-summary-two', 4);
        $this->createReview($service, 'reviews-summary-three', 4);

        $response = $this->getJson(
            '/api/marketplace/services/'.$service->slug.'/reviews?rating=5&per_page=1'
        )->assertOk();

        $this->assertSame(1, count($response->json('reviews.data')));
        $this->assertSame(3, $response->json('rating_summary.reviews_count'));
        $this->assertSame(4.33, $response->json('rating_summary.average_rating'));
        $this->assertSame(1, $response->json('reviews.per_page'));
    }

    /**
     * @return array{0: ServiceProvider, 1: User, 2: Service}
     */
    private function createProviderFixture(string $suffix): array
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

        return [
            $provider,
            $providerUser,
            $this->createService($provider, $suffix),
        ];
    }

    private function createService(
        ServiceProvider $provider,
        string $suffix
    ): Service {
        $category = ServiceCategory::create([
            'name' => 'Category '.$suffix,
            'slug' => 'category-'.$suffix,
            'is_active' => true,
        ]);

        return Service::create([
            'service_provider_id' => $provider->id,
            'service_category_id' => $category->id,
            'name' => 'Service '.$suffix,
            'slug' => 'service-'.$suffix,
            'status' => 'published',
        ]);
    }

    private function createReview(
        Service $service,
        string $suffix,
        int $rating
    ): Review {
        $customer = User::factory()->create([
            'email' => $suffix.'-customer@example.test',
        ]);
        $customer->assignRole('customer');

        $booking = Booking::create([
            'booking_reference' => 'BK-'.$suffix,
            'customer_id' => $customer->id,
            'service_provider_id' => $service->service_provider_id,
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
            'service_provider_id' => $service->service_provider_id,
            'service_id' => $service->id,
            'rating' => $rating,
            'comment' => 'A rating summary review.',
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $services
     * @return array<string, mixed>
     */
    private function findServiceInResponse(array $services, int $serviceId): array
    {
        foreach ($services as $service) {
            if ($service['id'] === $serviceId) {
                return $service;
            }
        }

        self::fail('Expected service was not found in the public response.');
    }

    /**
     * @param array<int, array<string, mixed>> $providers
     * @return array<string, mixed>
     */
    private function findProviderInResponse(array $providers, int $providerId): array
    {
        foreach ($providers as $provider) {
            if ($provider['id'] === $providerId) {
                return $provider;
            }
        }

        self::fail('Expected provider was not found in the public response.');
    }
}
