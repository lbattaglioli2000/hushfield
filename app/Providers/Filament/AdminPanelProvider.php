<?php

namespace App\Providers\Filament;

use App\Features\WorldFlags;
use App\Filament\Support\UsernameFilamentManager;
use App\Policies\ActivityPolicy;
use App\Policies\WorldFlagsPolicy;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Spatie\Activitylog\Models\Activity;

class AdminPanelProvider extends PanelProvider
{
    public function register(): void
    {
        $this->app->scoped('filament', fn (): UsernameFilamentManager => new UsernameFilamentManager);
        Facade::clearResolvedInstance('filament');

        parent::register();
    }

    public function boot(): void
    {
        // Neither lives in App\Models, so policy discovery would not find them.
        Gate::policy(Activity::class, ActivityPolicy::class);
        Gate::policy(WorldFlags::class, WorldFlagsPolicy::class);
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('Hushfield admin')
            ->colors([
                'primary' => Color::Amber,
            ])
            ->navigationGroups(['Moderation', 'World', 'Settings'])
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
