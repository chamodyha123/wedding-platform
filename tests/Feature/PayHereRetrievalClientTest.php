<?php

namespace Tests\Feature;

use App\Services\Payments\PayHereRetrievalClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class PayHereRetrievalClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['services.payhere.mode' => 'sandbox', 'services.payhere.app_id' => 'test-app-id', 'services.payhere.app_secret' => 'test-app-secret']);
    }

    public function test_sandbox_oauth_search_and_normalization_are_server_side(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://sandbox.payhere.lk/merchant/v1/oauth/token' => Http::response(['access_token' => 'test-token', 'expires_in' => 599]),
            'https://sandbox.payhere.lk/merchant/v1/payment/search*' => Http::response(['status' => 1, 'data' => [[
                'order_id' => 'PAY-1', 'payment_id' => 123, 'status' => 'RECEIVED', 'amount' => 100,
                'currency' => 'LKR', 'customer' => ['email' => 'private@example.test'], 'payment_method' => ['card_no' => '****'],
            ]]]),
        ]);

        $records = app(PayHereRetrievalClient::class)->search('PAY-1');
        $this->assertSame([['order_id' => 'PAY-1', 'payment_id' => '123', 'status' => 'RECEIVED', 'amount' => '100', 'currency' => 'LKR']], $records);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://sandbox.payhere.lk/merchant/v1/oauth/token'
            && $request->method() === 'POST' && $request['grant_type'] === 'client_credentials' && $request->hasHeader('Authorization'));
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://sandbox.payhere.lk/merchant/v1/payment/search')
            && $request->method() === 'GET' && $request['order_id'] === 'PAY-1' && $request->hasHeader('Authorization'));
    }

    public function test_cached_token_avoids_a_second_oauth_request_and_live_urls_are_selected(): void
    {
        config(['services.payhere.mode' => 'live']);
        Http::preventStrayRequests();
        Http::fake([
            'https://www.payhere.lk/merchant/v1/oauth/token' => Http::response(['access_token' => 'test-token', 'expires_in' => 599]),
            'https://www.payhere.lk/merchant/v1/payment/search*' => Http::response(['status' => 1, 'data' => []]),
        ]);
        $client = app(PayHereRetrievalClient::class);
        $client->search('PAY-1');
        $client->search('PAY-2');
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://www.payhere.lk/merchant/v1/oauth/token');
    }

    public function test_missing_credentials_and_invalid_gateway_responses_fail_without_search(): void
    {
        config(['services.payhere.app_id' => null]);
        Http::preventStrayRequests();
        try {
            app(PayHereRetrievalClient::class)->search('PAY-1');
            $this->fail('Expected configuration failure.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
        Http::assertNothingSent();
    }

    public function test_oauth_failures_fail_closed(): void
    {
        foreach ([401, 403, 500] as $status) {
            Cache::flush();
            Http::preventStrayRequests();
            Http::fake(['*oauth/token' => Http::response([], $status)]);
            $this->assertSearchFails();
        }
    }

    public function test_malformed_or_incomplete_oauth_responses_fail_closed(): void
    {
        foreach ([
            ['expires_in' => 599],
            ['access_token' => 'test-token'],
            'not-json',
        ] as $response) {
            Cache::flush();
            Http::preventStrayRequests();
            Http::fake(['*oauth/token' => Http::response($response)]);
            $this->assertSearchFails();
        }
    }

    public function test_search_failures_fail_closed(): void
    {
        foreach ([401, 429, 500] as $status) {
            Cache::flush();
            Http::preventStrayRequests();
            Http::fake([
                '*oauth/token' => Http::response(['access_token' => 'test-token', 'expires_in' => 599]),
                '*payment/search*' => Http::response([], $status),
            ]);
            $this->assertSearchFails();
        }
    }

    public function test_malformed_search_response_and_connection_failures_fail_closed(): void
    {
        Cache::flush();
        Http::preventStrayRequests();
        Http::fake([
            '*oauth/token' => Http::response(['access_token' => 'test-token', 'expires_in' => 599]),
            '*payment/search*' => Http::response(['status' => 1]),
        ]);
        $this->assertSearchFails();

        Cache::flush();
        Http::preventStrayRequests();
        Http::fake(['*oauth/token' => Http::failedConnection()]);
        $this->assertSearchFails();

        Cache::flush();
        Http::preventStrayRequests();
        Http::fake([
            '*oauth/token' => Http::response(['access_token' => 'test-token', 'expires_in' => 599]),
            '*payment/search*' => Http::failedConnection(),
        ]);
        $this->assertSearchFails();
    }

    private function assertSearchFails(): void
    {
        try {
            app(PayHereRetrievalClient::class)->search('PAY-1');
            $this->fail('Expected PayHere retrieval failure.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
    }
}
