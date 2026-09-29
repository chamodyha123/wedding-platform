<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Review;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketplaceServiceSearchAndFilteringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('The configured SQLite test driver is not installed.');
        }

        parent::setUp();
    }

    public function test_public_services_can_be_found_by_name_and_description_case_insensitively(): void
    {
        $provider = $this->createProvider('search');
        $category = $this->createCategory('search');
        $nameMatch = $this->createService($provider, $category, 'name-match', 'Golden Photography', 'Wedding memories');
        $descriptionMatch = $this->createService($provider, $category, 'description-match', 'Videography', 'Cinematic wedding photography');
        $nonMatch = $this->createService($provider, $category, 'non-match', 'Catering', 'Reception food');

        $response = $this->getJson('/api/marketplace/services?search=%20PHOTOGRAPHY%20&per_page=100')->assertOk();

        $this->assertSame([$descriptionMatch->id, $nameMatch->id], $this->serviceIds($response->json('data')));
        $this->assertNotContains($nonMatch->id, $this->serviceIds($response->json('data')));
    }

    public function test_empty_or_whitespace_search_preserves_the_unfiltered_public_listing(): void
    {
        $provider = $this->createProvider('empty-search');
        $category = $this->createCategory('empty-search');
        $firstService = $this->createService($provider, $category, 'first', 'Flowers', 'Seasonal arrangements');
        $secondService = $this->createService($provider, $category, 'second', 'Music', 'Live band');

        $response = $this->getJson('/api/marketplace/services?search=%20%20%20&per_page=1&page=2')->assertOk()->assertJsonPath('total', 2)->assertJsonPath('per_page', 1)->assertJsonPath('current_page', 2);
        $service = $response->json('data.0');

        $this->assertContains($service['id'], [$firstService->id, $secondService->id]);
        $this->assertSame(0, $service['reviews_count']);
        $this->assertNull($service['average_rating']);
    }

    public function test_search_validation_rejects_arrays_and_overlong_values(): void
    {
        $this->getJson('/api/marketplace/services?search[]=photography')->assertUnprocessable()->assertJsonValidationErrors(['search']);
        $this->getJson('/api/marketplace/services?search='.str_repeat('a', 256))->assertUnprocessable()->assertJsonValidationErrors(['search']);
    }

    public function test_search_never_exposes_non_public_services_or_providers(): void
    {
        $category = $this->createCategory('hidden-search');
        $visible = $this->createService($this->createProvider('visible'), $category, 'visible', 'Hidden Keyword', 'Visible service');
        $draft = $this->createService($this->createProvider('draft'), $category, 'draft', 'Hidden Keyword', 'Draft service', 'draft');
        $unverified = $this->createService($this->createProvider('unverified', 'pending'), $category, 'unverified', 'Hidden Keyword', 'Unverified service');
        $inactive = $this->createService($this->createProvider('inactive', 'verified', false), $category, 'inactive', 'Hidden Keyword', 'Inactive service');

        $response = $this->getJson('/api/marketplace/services?search=hidden%20keyword&per_page=100')->assertOk()->assertJsonPath('total', 1);

        $this->assertSame([$visible->id], $this->serviceIds($response->json('data')));
        $this->assertNotContains($draft->id, $this->serviceIds($response->json('data')));
        $this->assertNotContains($unverified->id, $this->serviceIds($response->json('data')));
        $this->assertNotContains($inactive->id, $this->serviceIds($response->json('data')));
    }

    public function test_category_and_provider_filters_compose_with_search(): void
    {
        $firstProvider = $this->createProvider('first-provider');
        $secondProvider = $this->createProvider('second-provider');
        $weddingCategory = $this->createCategory('wedding');
        $otherCategory = $this->createCategory('other');
        $match = $this->createService($firstProvider, $weddingCategory, 'match', 'Wedding Photography', 'Full-day coverage');
        $wrongProvider = $this->createService($secondProvider, $weddingCategory, 'wrong-provider', 'Wedding Photography', 'Full-day coverage');
        $wrongCategory = $this->createService($firstProvider, $otherCategory, 'wrong-category', 'Wedding Photography', 'Full-day coverage');

        $response = $this->getJson('/api/marketplace/services?search=wedding&category='.$weddingCategory->slug.'&provider='.$firstProvider->business_slug.'&per_page=100')->assertOk()->assertJsonPath('total', 1);

        $this->assertSame([$match->id], $this->serviceIds($response->json('data')));
        $this->assertNotContains($wrongProvider->id, $this->serviceIds($response->json('data')));
        $this->assertNotContains($wrongCategory->id, $this->serviceIds($response->json('data')));
    }

    public function test_filters_do_not_expose_hidden_services_and_validate_scalar_values(): void
    {
        $category = $this->createCategory('filter-hidden');
        $visibleProvider = $this->createProvider('filter-visible');
        $hiddenProvider = $this->createProvider('filter-hidden', 'pending');
        $visible = $this->createService($visibleProvider, $category, 'visible', 'Visible service', 'Visible');
        $hidden = $this->createService($hiddenProvider, $category, 'hidden', 'Hidden service', 'Hidden');

        $this->getJson('/api/marketplace/services?category='.$category->slug.'&provider='.$hiddenProvider->business_slug)->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/marketplace/services?category='.$category->slug.'&provider='.$visibleProvider->business_slug)->assertOk()->assertJsonPath('data.0.id', $visible->id);
        $this->getJson('/api/marketplace/services?category[]=bad')->assertUnprocessable()->assertJsonValidationErrors(['category']);
        $this->getJson('/api/marketplace/services?provider[]=bad')->assertUnprocessable()->assertJsonValidationErrors(['provider']);

        $this->assertNotSame($visible->id, $hidden->id);
    }

    public function test_service_sorting_is_deterministic_and_preserves_the_default_order(): void
    {
        $provider = $this->createProvider('sorting');
        $category = $this->createCategory('sorting');
        $alpha = $this->createService($provider, $category, 'alpha', 'Alpha', 'Wedding coverage');
        $beta = $this->createService($provider, $category, 'beta', 'Beta', 'Wedding coverage');
        $gamma = $this->createService($provider, $category, 'gamma', 'Gamma', 'Wedding coverage');
        $unrated = $this->createService($provider, $category, 'unrated', 'Unrated', 'Wedding coverage');
        $alpha->forceFill(['created_at' => now()->subDays(3)])->save();
        $beta->forceFill(['created_at' => now()->subDays(2)])->save();
        $gamma->forceFill(['created_at' => now()->subDay()])->save();
        $this->createReview($alpha, 'alpha-rating', 4);
        $this->createReview($beta, 'beta-rating', 4);
        $this->createReview($gamma, 'gamma-rating', 2);

        $this->assertSame([$unrated->id, $gamma->id, $beta->id, $alpha->id], $this->serviceIds($this->getJson('/api/marketplace/services?per_page=100')->assertOk()->json('data')));
        $this->assertSame([$unrated->id, $gamma->id, $beta->id, $alpha->id], $this->serviceIds($this->getJson('/api/marketplace/services?sort=newest&per_page=100')->assertOk()->json('data')));
        $this->assertSame([$alpha->id, $beta->id, $gamma->id, $unrated->id], $this->serviceIds($this->getJson('/api/marketplace/services?sort=oldest&per_page=100')->assertOk()->json('data')));
        $this->assertSame([$alpha->id, $beta->id, $gamma->id, $unrated->id], $this->serviceIds($this->getJson('/api/marketplace/services?sort=name_asc&per_page=100')->assertOk()->json('data')));
        $this->assertSame([$unrated->id, $gamma->id, $beta->id, $alpha->id], $this->serviceIds($this->getJson('/api/marketplace/services?sort=name_desc&per_page=100')->assertOk()->json('data')));
        $this->assertSame([$beta->id, $alpha->id, $gamma->id, $unrated->id], $this->serviceIds($this->getJson('/api/marketplace/services?sort=rating_high&per_page=100')->assertOk()->json('data')));
        $this->assertSame([$gamma->id, $alpha->id, $beta->id, $unrated->id], $this->serviceIds($this->getJson('/api/marketplace/services?sort=rating_low&per_page=100')->assertOk()->json('data')));
    }

    public function test_service_sorting_validates_and_composes_with_public_filters(): void
    {
        $provider = $this->createProvider('sort-provider');
        $otherProvider = $this->createProvider('sort-other');
        $category = $this->createCategory('sort-category');
        $otherCategory = $this->createCategory('sort-other');
        $match = $this->createService($provider, $category, 'sort-match', 'Wedding Alpha', 'Wedding');
        $this->createService($otherProvider, $category, 'sort-provider-miss', 'Wedding Beta', 'Wedding');
        $this->createService($provider, $otherCategory, 'sort-category-miss', 'Wedding Gamma', 'Wedding');
        $hidden = $this->createService($this->createProvider('sort-hidden', 'pending'), $category, 'sort-hidden', 'Wedding Hidden', 'Wedding');

        $this->getJson('/api/marketplace/services?sort=password')->assertUnprocessable()->assertJsonValidationErrors(['sort']);
        $this->getJson('/api/marketplace/services?sort=DROP%20TABLE')->assertUnprocessable()->assertJsonValidationErrors(['sort']);
        $response = $this->getJson('/api/marketplace/services?search=wedding&category='.$category->slug.'&provider='.$provider->business_slug.'&sort=name_asc&per_page=1&page=1')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('per_page', 1);

        $this->assertSame([$match->id], $this->serviceIds($response->json('data')));
        $this->assertNotContains($hidden->id, $this->serviceIds($response->json('data')));
    }

    public function test_provider_search_finds_only_public_data_and_preserves_aggregates(): void
    {
        $category = $this->createCategory('provider-search');
        $publicProvider = $this->createProvider('golden-lens');
        $publicProvider->update(['business_name' => 'Golden Lens Studio', 'description' => 'Artful wedding stories']);
        $publicProvider->categories()->attach($category->id);
        $publicService = $this->createService($publicProvider, $category, 'provider-search', 'Photography', 'Public service');
        $this->createReview($publicService, 'provider-search-rating', 5);
        $descriptionProvider = $this->createProvider('description-match');
        $descriptionProvider->update(['description' => 'Cinematic celebrations']);
        $descriptionProvider->categories()->attach($category->id);
        $unverified = $this->createProvider('hidden-provider', 'pending');
        $unverified->update(['business_name' => 'Golden Hidden']);
        $inactive = $this->createProvider('inactive-provider', 'verified', false);
        $inactive->update(['description' => 'Artful hidden stories']);
        $inactiveCategory = ServiceCategory::create(['name' => 'Hidden Category', 'slug' => 'hidden-category', 'is_active' => false]);
        $unverified->categories()->attach($inactiveCategory->id);

        $nameResponse = $this->getJson('/api/marketplace/providers?search=%20GOLDEN%20&per_page=100')->assertOk()->assertJsonPath('total', 1);
        $this->assertSame([$publicProvider->id], $this->providerIds($nameResponse->json('data')));
        $this->assertSame(1, $nameResponse->json('data.0.reviews_count'));
        $this->assertSame(5, $nameResponse->json('data.0.average_rating'));
        $this->assertSame([$descriptionProvider->id], $this->providerIds($this->getJson('/api/marketplace/providers?search=CINEMATIC&per_page=100')->assertOk()->json('data')));
        $this->assertSame([$publicProvider->id, $descriptionProvider->id], $this->providerIds($this->getJson('/api/marketplace/providers?search=category%20provider-search&per_page=100')->assertOk()->assertJsonPath('total', 2)->json('data')));
        $this->getJson('/api/marketplace/providers?search[]=golden')->assertUnprocessable()->assertJsonValidationErrors(['search']);
        $this->getJson('/api/marketplace/providers?search='.str_repeat('a', 256))->assertUnprocessable()->assertJsonValidationErrors(['search']);
        $this->assertNotContains($unverified->id, $this->providerIds($this->getJson('/api/marketplace/providers?search=hidden&per_page=100')->assertOk()->json('data')));
        $this->assertNotContains($inactive->id, $this->providerIds($this->getJson('/api/marketplace/providers?search=artful&per_page=100')->assertOk()->json('data')));
    }

    public function test_whitespace_only_provider_search_preserves_public_listing(): void
    {
        $first = $this->createProvider('provider-whitespace-first');
        $second = $this->createProvider('provider-whitespace-second');

        $response = $this->getJson('/api/marketplace/providers?search=%20%20%20&per_page=100')->assertOk()->assertJsonPath('total', 2);

        $this->assertSame([$first->id, $second->id], $this->providerIds($response->json('data')));
    }

    private function createProvider(string $suffix, string $verificationStatus = 'verified', bool $isActive = true): ServiceProvider
    {
        $user = User::factory()->create(['email' => $suffix.'@example.test']);

        return ServiceProvider::create(['user_id' => $user->id, 'business_name' => 'Provider '.$suffix, 'business_slug' => 'provider-'.$suffix, 'verification_status' => $verificationStatus, 'is_active' => $isActive]);
    }

    private function createCategory(string $suffix): ServiceCategory
    {
        return ServiceCategory::create(['name' => 'Category '.$suffix, 'slug' => 'category-'.$suffix, 'is_active' => true]);
    }

    private function createService(ServiceProvider $provider, ServiceCategory $category, string $suffix, string $name, string $description, string $status = 'published'): Service
    {
        return Service::create(['service_provider_id' => $provider->id, 'service_category_id' => $category->id, 'name' => $name, 'slug' => 'service-'.$suffix, 'description' => $description, 'status' => $status]);
    }

    private function createReview(Service $service, string $suffix, int $rating): Review
    {
        $customer = User::factory()->create(['email' => $suffix.'@example.test']);
        $booking = Booking::create(['booking_reference' => 'BK-'.$suffix, 'customer_id' => $customer->id, 'service_provider_id' => $service->service_provider_id, 'service_id' => $service->id, 'event_date' => now()->addDay()->toDateString(), 'start_time' => '10:00', 'end_time' => '11:00', 'total_amount' => 100, 'booking_status' => 'completed', 'payment_status' => 'unpaid']);

        return Review::create(['booking_id' => $booking->id, 'customer_id' => $customer->id, 'service_provider_id' => $service->service_provider_id, 'service_id' => $service->id, 'rating' => $rating]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $services
     * @return array<int, int>
     */
    private function serviceIds(array $services): array
    {
        return array_map(fn (array $service): int => $service['id'], $services);
    }

    /**
     * @param  array<int, array<string, mixed>>  $providers
     * @return array<int, int>
     */
    private function providerIds(array $providers): array
    {
        return array_map(fn (array $provider): int => $provider['id'], $providers);
    }
}
