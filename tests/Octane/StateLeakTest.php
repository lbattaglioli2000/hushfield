<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Models\WorldSession;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\CurrentApplication;
use Laravel\Octane\Testing\Fakes\FakeClient;
use Laravel\Octane\Testing\Fakes\FakeWorker;

/*
 * Runs real requests through Octane's worker loop: one application is booted
 * once, then cloned per request, exactly as under FrankenPHP. The worker has
 * its own database connection, so this suite commits its data (truncating
 * before and after) instead of wrapping each test in a transaction.
 */

uses(DatabaseTruncation::class);

afterEach(function () {
    $this->truncateTablesForAllConnections();
});

/**
 * Stores a logged-in database session (JSON serialized, as config/session.php sets) for the user and returns the encrypted
 * session cookie a browser would send.
 *
 * @return array<string, string>
 */
function sessionCookieFor(User $user): array
{
    $id = Str::random(40);

    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'payload' => base64_encode(json_encode([Auth::guard('web')->getName() => $user->id], JSON_THROW_ON_ERROR)),
        'last_activity' => now()->getTimestamp(),
    ]);

    $name = (string) config('session.cookie');
    $value = encrypt(CookieValuePrefix::create($name, app('encrypter')->getKey()).$id, false);

    return [$name => $value];
}

/**
 * @param  list<Request>  $requests
 */
function runOnOneWorker(array $requests): FakeClient
{
    $factory = new class(base_path()) extends ApplicationFactory
    {
        public function bootstrap(Application $app, array $initialInstances = []): Application
        {
            $app = parent::bootstrap($app, $initialInstances);

            // The test process uses the array session driver, which cannot carry a login into another application.
            $app['config']->set('session.driver', 'database');
            $app['config']->set('hushfield.maps.path', base_path('tests/fixtures/maps'));

            return $app;
        }
    };

    $testApp = app();
    $worker = new FakeWorker($factory, $client = new FakeClient($requests));

    try {
        $worker->boot();
        $worker->run();
    } finally {
        // Booting the worker points the container, facades, and Eloquent at its own application; hand them back.
        CurrentApplication::set($testApp);
        Model::setConnectionResolver($testApp['db']);
        Model::setEventDispatcher($testApp['events']);
    }

    return $client;
}

function jsonRequest(string $method, string $uri, array $cookies = []): Request
{
    return Request::create($uri, $method, cookies: $cookies, server: ['HTTP_ACCEPT' => 'application/json']);
}

test('a second request on the same worker sees nothing of the first user', function () {
    $alice = User::factory()->create(['username' => 'Alice_Leaky', 'email' => 'alice@example.com']);
    $bob = User::factory()->create(['username' => 'Bob_Clean', 'email' => 'bob@example.com']);

    $client = runOnOneWorker([
        jsonRequest('POST', '/world/session', sessionCookieFor($alice)),
        jsonRequest('POST', '/world/session', sessionCookieFor($bob)),
        jsonRequest('POST', '/world/session'),
    ]);

    expect($client->errors)->toBe([])
        ->and($client->responses)->toHaveCount(3);

    [$first, $second, $guest] = $client->responses;
    $aliceToken = WorldSession::query()->where('user_id', $alice->id)->value('session_token');
    $bobToken = WorldSession::query()->where('user_id', $bob->id)->value('session_token');

    expect($first->getStatusCode())->toBe(201)
        ->and(json_decode($first->getContent(), true))->toMatchArray(['user_id' => $alice->id, 'session_token' => $aliceToken])
        ->and($second->getStatusCode())->toBe(201)
        ->and(json_decode($second->getContent(), true))->toMatchArray(['user_id' => $bob->id, 'session_token' => $bobToken])
        ->and($second->getContent())->not->toContain($aliceToken)
        // A request with no session must not inherit the previous user.
        ->and($guest->getStatusCode())->toBe(401)
        ->and(WorldSession::query()->count())->toBe(2);
});

test('the Play page props describe only the current user', function () {
    $alice = User::factory()->create(['username' => 'Alice_Leaky', 'email' => 'alice@example.com']);
    $bob = User::factory()->create(['username' => 'Bob_Clean', 'email' => 'bob@example.com']);
    $inertia = fn (array $cookies): Request => Request::create('/play', 'GET', cookies: $cookies, server: [
        'HTTP_X_INERTIA' => 'true',
        'HTTP_X_INERTIA_VERSION' => (string) app(HandleInertiaRequests::class)->version(request()),
        'HTTP_ACCEPT' => 'text/html, application/xhtml+xml',
    ]);

    $client = runOnOneWorker([$inertia(sessionCookieFor($alice)), $inertia(sessionCookieFor($bob))]);

    expect($client->errors)->toBe([])
        ->and(array_map(fn ($response): int => $response->getStatusCode(), $client->responses))->toBe([200, 200]);

    [$first, $second] = array_map(fn ($response): array => json_decode($response->getContent(), true), $client->responses);

    expect($first['props']['auth']['user']['username'])->toBe('Alice_Leaky')
        ->and($second['props']['auth']['user']['id'])->toBe($bob->id)
        ->and($second['props']['auth']['user']['username'])->toBe('Bob_Clean')
        ->and($client->responses[1]->getContent())->not->toContain('Alice_Leaky')
        ->and($client->responses[1]->getContent())->not->toContain('alice@example.com');
});
