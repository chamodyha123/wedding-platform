<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$payload = json_decode(base64_decode($argv[1] ?? '', true), true, 512, JSON_THROW_ON_ERROR);
$barrierDirectory = $payload['barrier_directory'];
$workerName = $payload['worker_name'];

file_put_contents($barrierDirectory.'/'.$workerName.'.ready', 'ready');

$deadline = microtime(true) + 10;

while (! file_exists($barrierDirectory.'/release')) {
    if (microtime(true) >= $deadline) {
        file_put_contents($barrierDirectory.'/'.$workerName.'.result', json_encode([
            'error' => 'Timed out waiting for the concurrency barrier.',
        ], JSON_THROW_ON_ERROR));

        exit(1);
    }

    usleep(10_000);
}

try {
    $app = require __DIR__.'/../../bootstrap/app.php';

    $database = (string) config('database.connections.pgsql.database');

    if (
        env('RUN_POSTGRES_TESTS') !== '1'
        || config('database.default') !== 'pgsql'
        || ! is_string(env('DB_HOST'))
        || env('DB_HOST') === ''
        || ! is_string(env('DB_PORT'))
        || env('DB_PORT') === ''
        || $database === ''
        || $database !== env('POSTGRES_TEST_DATABASE')
        || ! str_starts_with($database, 'wedding_marketplace_test_')
        || DB::connection('pgsql')->selectOne('select current_database() as database')->database !== $database
    ) {
        throw new RuntimeException('Worker refused a non-isolated PostgreSQL connection.');
    }

    DB::statement("set application_name = 'wedding_marketplace_pgtest_{$workerName}'");

    $kernel = $app->make(Kernel::class);
    $request = Request::create(
        $payload['uri'],
        $payload['method'],
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$payload['token'],
        ],
        json_encode($payload['body'], JSON_THROW_ON_ERROR)
    );
    $response = $kernel->handle($request);

    file_put_contents($barrierDirectory.'/'.$workerName.'.result', json_encode([
        'status' => $response->getStatusCode(),
        'body' => json_decode($response->getContent(), true),
    ], JSON_THROW_ON_ERROR));

    $kernel->terminate($request, $response);
} catch (Throwable $exception) {
    file_put_contents($barrierDirectory.'/'.$workerName.'.result', json_encode([
        'error' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR));

    exit(1);
}
