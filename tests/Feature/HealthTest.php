<?php

use App\Models\User;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Redis;
use Inertia\Testing\AssertableInertia;

test('the home page renders for visitors, with its lines from the lang file', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('welcome')
            ->where('copy.landing_intro', __('hushfield.landing_intro'))
            ->missing('copy.on_air'));
});

test('the home page sends signed-in residents to the town', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertRedirect(route('play'));
});

test('GET /up is healthy when the database, Redis, and the queue answer', function () {
    $this->getJson('/up')->assertOk()->assertExactJson(['status' => 'up']);
});

test('GET /up fails when world Redis is unreachable', function () {
    config(['app.debug' => false]);

    $config = config('database.redis');
    $config['world'] = [...$config['world'], 'port' => 1, 'max_retries' => 0];
    $this->app->instance('redis', new RedisManager($this->app, $config['client'], $config));
    Redis::clearResolvedInstance('redis');

    $this->getJson('/up')->assertServerError()->assertExactJson(['status' => 'down']);
});
