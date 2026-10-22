<?php

namespace Tests\Postgres;

use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServicePackage;
use App\Models\ServiceProvider;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

abstract class PostgresIntegrationTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertIsolatedPostgresDatabase();

        $migrationExitCode = Artisan::call('migrate:fresh', [
            '--database' => 'pgsql',
            '--force' => true,
        ]);

        if ($migrationExitCode !== 0) {
            throw new RuntimeException('PostgreSQL migrations failed.');
        }

        foreach (['customer', 'service_provider', 'admin'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }

        config(['services.payhere' => $this->payHereSettings()]);
    }

    /** @return array{mode: string, merchant_id: string, merchant_secret: string, return_url: string, cancel_url: string, notify_url: string, currency: string} */
    protected function payHereSettings(): array
    {
        return [
            'mode' => 'sandbox',
            'merchant_id' => 'postgres-test-merchant',
            'merchant_secret' => 'postgres-test-secret',
            'return_url' => 'https://example.test/return',
            'cancel_url' => 'https://example.test/cancel',
            'notify_url' => 'https://example.test/notify',
            'currency' => 'LKR',
        ];
    }

    protected function assertIsolatedPostgresDatabase(): void
    {
        if (env('RUN_POSTGRES_TESTS') !== '1') {
            throw new RuntimeException('Set RUN_POSTGRES_TESTS=1 to run PostgreSQL integration tests.');
        }

        if (config('database.default') !== 'pgsql') {
            throw new RuntimeException('PostgreSQL integration tests require DB_CONNECTION=pgsql.');
        }

        if (! is_string(env('DB_HOST')) || env('DB_HOST') === '' || ! is_string(env('DB_PORT')) || env('DB_PORT') === '') {
            throw new RuntimeException('PostgreSQL integration tests require explicit DB_HOST and DB_PORT values.');
        }

        $database = (string) config('database.connections.pgsql.database');

        if ($database === '' || $database !== env('POSTGRES_TEST_DATABASE') || ! Str::startsWith($database, 'wedding_marketplace_test_')) {
            throw new RuntimeException('POSTGRES_TEST_DATABASE must exactly name an isolated wedding_marketplace_test_* database.');
        }

        $connectedDatabase = DB::connection('pgsql')->selectOne('select current_database() as database')->database;

        if ($connectedDatabase !== $database) {
            throw new RuntimeException('The configured PostgreSQL connection is not connected to POSTGRES_TEST_DATABASE.');
        }
    }

    /** @return array{customer: User, provider_user: User, provider: ServiceProvider, service: Service, package: ServicePackage} */
    protected function marketplaceFixture(string $suffix): array
    {
        $customer = User::factory()->create(['email' => $suffix.'-customer@example.test']);
        $customer->assignRole('customer');
        $providerUser = User::factory()->create(['email' => $suffix.'-provider@example.test']);
        $providerUser->assignRole('service_provider');
        $provider = ServiceProvider::create(['user_id' => $providerUser->id, 'business_name' => 'Provider '.$suffix, 'business_slug' => 'provider-'.$suffix, 'verification_status' => 'verified', 'is_active' => true]);
        $category = ServiceCategory::create(['name' => 'Category '.$suffix, 'slug' => 'category-'.$suffix]);
        $service = Service::create(['service_provider_id' => $provider->id, 'service_category_id' => $category->id, 'name' => 'Service '.$suffix, 'slug' => 'service-'.$suffix, 'status' => 'published']);
        $package = ServicePackage::create(['service_id' => $service->id, 'name' => 'Package '.$suffix, 'slug' => 'package-'.$suffix, 'price' => 100, 'status' => 'published']);

        return compact('customer', 'providerUser', 'provider', 'service', 'package') + ['provider_user' => $providerUser];
    }
}
