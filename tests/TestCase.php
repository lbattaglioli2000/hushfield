<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Redis;
use Laravel\Fortify\Features;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /** The only Redis databases tests may write to. Dev data lives in 0–2. */
    private const array TEST_REDIS_DATABASES = [13, 14, 15];

    protected function setUp(): void
    {
        parent::setUp();

        config(['hushfield.maps.path' => base_path('tests/fixtures/maps')]);

        // Built assets go to a scratch folder, built on first use, so tests never touch a dev build.
        $assets = sys_get_temp_dir().'/hushfield-test-assets-'.getmypid();
        config(['hushfield.assets.output' => "{$assets}/built", 'hushfield.assets.index' => "{$assets}/assets.json"]);

        $this->withoutVite();
        $this->flushWorldRedis();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }

    private function flushWorldRedis(): void
    {
        foreach (['default', 'cache', 'world'] as $connection) {
            $database = (int) config("database.redis.{$connection}.database");

            if (! in_array($database, self::TEST_REDIS_DATABASES, true)) {
                throw new RuntimeException("Refusing to run tests against Redis database {$database} for the [{$connection}] connection. Check phpunit.xml.");
            }
        }

        Redis::connection('world')->flushdb();
    }
}
