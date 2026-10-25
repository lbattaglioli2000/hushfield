<?php

use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Redis;

test('the home page renders', function () {
    $this->get(route('home'))->assertOk();
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
