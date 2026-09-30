<?php

namespace App\Services\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PayHereRetrievalClient
{
    /**
     * @return list<array{order_id: string, payment_id: string, status: string, amount: string, currency: string}>
     */
    public function search(string $orderId): array
    {
        try {
            $response = Http::withToken($this->accessToken())
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(10)
                ->get($this->settings()['search_url'], [
                    'order_id' => $orderId,
                ]);
        } catch (ConnectionException) {
            throw new RuntimeException('PayHere retrieval is unavailable.');
        }

        if (! $response->successful()) {
            throw new RuntimeException('PayHere retrieval is unavailable.');
        }

        $payload = $response->json();

        if (! is_array($payload) || ! array_key_exists('data', $payload) || ! is_array($payload['data'])) {
            throw new RuntimeException('PayHere retrieval response is invalid.');
        }

        return collect($payload['data'])
            ->filter(fn (mixed $record): bool => is_array($record)
                && isset($record['order_id'], $record['payment_id'], $record['status'], $record['amount'], $record['currency']))
            ->map(fn (array $record): array => [
                'order_id' => (string) $record['order_id'],
                'payment_id' => (string) $record['payment_id'],
                'status' => (string) $record['status'],
                'amount' => (string) $record['amount'],
                'currency' => (string) $record['currency'],
            ])
            ->values()
            ->all();
    }

    private function accessToken(): string
    {
        $settings = $this->settings();
        $cacheKey = 'payhere.retrieval.access_token.'.$settings['mode'];

        $cachedToken = Cache::get($cacheKey);

        if (is_string($cachedToken) && $cachedToken !== '') {
            return $cachedToken;
        }

        try {
            $response = Http::asForm()
                ->withBasicAuth($settings['app_id'], $settings['app_secret'])
                ->connectTimeout(5)
                ->timeout(10)
                ->post($settings['token_url'], [
                    'grant_type' => 'client_credentials',
                ]);
        } catch (ConnectionException) {
            throw new RuntimeException('PayHere retrieval is unavailable.');
        }

        $payload = $response->json();

        if (! $response->successful() || ! is_array($payload)
            || ! isset($payload['access_token'], $payload['expires_in'])
            || ! is_string($payload['access_token']) || ! is_numeric($payload['expires_in'])) {
            throw new RuntimeException('PayHere retrieval is unavailable.');
        }

        $expiresIn = max((int) $payload['expires_in'] - 30, 60);
        Cache::put($cacheKey, $payload['access_token'], now()->addSeconds($expiresIn));

        return $payload['access_token'];
    }

    /** @return array{mode: string, app_id: string, app_secret: string, token_url: string, search_url: string} */
    private function settings(): array
    {
        $mode = config('services.payhere.mode');
        $appId = config('services.payhere.app_id');
        $appSecret = config('services.payhere.app_secret');
        $urls = config('services.payhere.retrieval.'.$mode);

        if (! in_array($mode, ['sandbox', 'live'], true) || ! is_string($appId) || $appId === ''
            || ! is_string($appSecret) || $appSecret === '' || ! is_array($urls)
            || ! isset($urls['token_url'], $urls['search_url'])) {
            throw new RuntimeException('PayHere retrieval is not configured.');
        }

        return ['mode' => $mode, 'app_id' => $appId, 'app_secret' => $appSecret, 'token_url' => $urls['token_url'], 'search_url' => $urls['search_url']];
    }
}
