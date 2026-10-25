<?php

namespace App\Providers;

use App\Actions\Identity\ApplyAccountPolicy;
use App\Domain\Chat\BlockedTermsFilter;
use App\Domain\Chat\BlockedTermsUsernameScreen;
use App\Domain\Chat\ChatFilter;
use App\Domain\Identity\UsernameScreen;
use App\Domain\World\WalkabilityGridRepository;
use App\Exceptions\WorldActionRefused;
use App\Features\WorldFlags;
use App\Http\Responses\WorldResponse;
use App\Models\User;
use App\Policies\ActivityPolicy;
use App\Policies\WorldFlagsPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Spatie\Activitylog\Models\Activity;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ChatFilter::class, BlockedTermsFilter::class);
        $this->app->bind(UsernameScreen::class, BlockedTermsUsernameScreen::class);

        // Safe as a singleton under Octane: it only caches immutable grids keyed by map version.
        $this->app->singleton(WalkabilityGridRepository::class);
    }

    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();
        $this->configureHealthCheck();

        // Neither lives in App\Models, so policy discovery would not find them.
        Gate::policy(Activity::class, ActivityPolicy::class);
        Gate::policy(WorldFlags::class, WorldFlagsPolicy::class);

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
            ->response(fn (): JsonResponse => WorldResponse::json(['dropped' => true], 202)));

        RateLimiter::for('chat', fn (Request $request): Limit => Limit::perSecond(3, 5)
            ->by('chat:'.$request->user()?->id)
            ->response(fn (): JsonResponse => WorldActionRefused::chatTooFast()->render()));

        RateLimiter::for('report', fn (Request $request): Limit => Limit::perHour(5)
            ->by('report:'.$request->user()?->id)
            ->response(fn (): JsonResponse => WorldActionRefused::reportTooMany()->render()));

        RateLimiter::for('economy', fn (Request $request): Limit => Limit::perMinute(20)
            ->by('economy:'.$request->user()?->id)
            ->response(fn (): JsonResponse => WorldActionRefused::shopTooFast()->render()));

        RateLimiter::for('home', fn (Request $request): Limit => Limit::perMinute(60)
            ->by('home:'.$request->user()?->id)
            ->response(fn (): JsonResponse => WorldActionRefused::homeTooFast()->render()));

        RateLimiter::for('friends', fn (Request $request): Limit => Limit::perMinute(30)
            ->by('friends:'.$request->user()?->id)
            ->response(fn (): JsonResponse => WorldActionRefused::friendsTooFast()->render()));

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
