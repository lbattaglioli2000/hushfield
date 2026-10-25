<?php

namespace App\Providers;

use App\Actions\Identity\ApplyAccountPolicy;
use App\Domain\Chat\BlockedTermsFilter;
use App\Domain\Chat\ChatFilter;
use App\Domain\World\WalkabilityGridRepository;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ChatFilter::class, BlockedTermsFilter::class);

        // Safe as a singleton under Octane: it only caches immutable grids keyed by map version.
        $this->app->singleton(WalkabilityGridRepository::class);
    }

    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();
        $this->configureHealthCheck();

        Event::listen(Login::class, function (Login $event): void {
            if ($event->user instanceof User) {
                app(ApplyAccountPolicy::class)($event->user);
            }
        });
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    protected function configureRateLimiting(): void
    {
        // Excess moves are dropped quietly; the client simply keeps its last confirmed path.
        RateLimiter::for('move', fn (Request $request): Limit => Limit::perSecond(5)
            ->by('move:'.$request->user()?->id)
            ->response(fn (): JsonResponse => response()->json(['dropped' => true], 202)));

        RateLimiter::for('chat', fn (Request $request): Limit => Limit::perSecond(3, 5)
            ->by('chat:'.$request->user()?->id)
            ->response(fn (): JsonResponse => response()->json(['reason' => 'chat_too_fast', 'message' => __('hushfield.chat_too_fast')], 429)));

        RateLimiter::for('report', fn (Request $request): Limit => Limit::perHour(5)
            ->by('report:'.$request->user()?->id)
            ->response(fn (): JsonResponse => response()->json(['reason' => 'report_too_many', 'message' => __('hushfield.report_too_many')], 429)));

        RateLimiter::for('join-room', fn (Request $request): Limit => Limit::perMinute(10)->by('join:'.$request->user()?->id));

        RateLimiter::for('registration', fn (Request $request): Limit => Limit::perHour(5)->by('register:'.$request->ip()));
    }

    /**
     * GET /up fails unless the database, Redis, and the queue all answer.
     */
    protected function configureHealthCheck(): void
    {
        Event::listen(DiagnosingHealth::class, function (): void {
            DB::select('select 1');
            Redis::connection('world')->ping();
            Queue::size();
        });
    }
}
