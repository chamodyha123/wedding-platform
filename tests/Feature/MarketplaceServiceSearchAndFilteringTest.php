<?php

namespace Tests\Feature;

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

    /**
     * @param  array<int, array<string, mixed>>  $services
     * @return array<int, int>
     */
    private function serviceIds(array $services): array
    {
        return array_map(fn (array $service): int => $service['id'], $services);
    }
}
