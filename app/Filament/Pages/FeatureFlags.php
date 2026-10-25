<?php

namespace App\Filament\Pages;

use App\Actions\World\SetWorldFlag;
use App\Features\WorldFlag;
use App\Features\WorldFlags;
use App\Filament\Support\ModerationForm;
use App\Filament\Support\Staff;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * The world kill switches. Each button carries the state it was rendered for,
 * so a stale page can only ask for what its staff member saw, never flip blindly.
 */
class FeatureFlags extends Page
{
    protected string $view = 'filament.pages.feature-flags';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPower;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Feature flags';

    protected static ?string $title = 'Feature flags';

    public static function canAccess(): bool
    {
        return Gate::allows('viewAny', WorldFlags::class);
    }

    /**
     * @return array{flags: list<WorldFlag>}
     */
    protected function getViewData(): array
    {
        return ['flags' => WorldFlag::cases()];
    }

    public function toggleAction(): Action
    {
        return Action::make('toggle')
            ->label(fn (array $arguments): string => self::active($arguments) ? 'Turn on' : 'Turn off')
            ->color(fn (array $arguments): string => self::active($arguments) ? 'success' : 'danger')
            ->icon(Heroicon::OutlinedPower)
            ->authorize('update', WorldFlags::class)
            ->modalHeading(fn (array $arguments): string => (self::active($arguments) ? 'Turn on ' : 'Turn off ').self::flag($arguments)->getLabel())
            ->modalDescription('This takes effect for every player immediately.')
            ->schema([ModerationForm::reasonField()])
            ->action(function (array $arguments, array $data, SetWorldFlag $setWorldFlag, Action $action): void {
                $flag = self::flag($arguments);
                $active = self::active($arguments);

                $setWorldFlag($flag, $active, Staff::current(), ModerationForm::reason($data));

                $action->successNotificationTitle($flag->getLabel().($active ? ' is on' : ' is off'));
                $action->success();
            });
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private static function flag(array $arguments): WorldFlag
    {
        $flag = WorldFlag::tryFrom(is_string($arguments['flag'] ?? null) ? $arguments['flag'] : '');

        abort_if($flag === null, 404);

        return $flag;
    }

    /**
     * The state the staff member asked for.
     *
     * @param  array<string, mixed>  $arguments
     */
    private static function active(array $arguments): bool
    {
        $active = $arguments['active'] ?? null;

        abort_unless(is_bool($active), 404);

        return $active;
    }
}
