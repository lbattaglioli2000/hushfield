<?php

namespace App\Filament\Pages;

use App\Features\ChatEnabled;
use App\Features\JoinsEnabled;
use App\Features\WorldFlags;
use App\Filament\Support\Staff;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * The world kill switches. Every change is written to the 'flags' activity log.
 */
class FeatureFlags extends Page
{
    protected string $view = 'filament.pages.feature-flags';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPower;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Feature flags';

    protected static ?string $title = 'Feature flags';

    /** @var array<class-string, array{label: string, description: string}> */
    private const array DESCRIPTIONS = [
        ChatEnabled::class => ['label' => 'Chat', 'description' => 'Kill switch for chat in every room. When off, nobody can send messages.'],
        JoinsEnabled::class => ['label' => 'Room joins', 'description' => 'Kill switch for joining rooms. When off, players already in a room stay but nobody new can join.'],
    ];

    public static function canAccess(): bool
    {
        return Gate::allows('viewAny', WorldFlags::class);
    }

    /**
     * @return array{flags: list<array{class: class-string, label: string, description: string, active: bool}>}
     */
    protected function getViewData(): array
    {
        return [
            'flags' => array_map(fn (string $flag): array => [
                'class' => $flag,
                ...self::describe($flag),
                'active' => WorldFlags::active($flag),
            ], WorldFlags::FLAGS),
        ];
    }

    public function toggleAction(): Action
    {
        return Action::make('toggle')
            ->label(fn (array $arguments): string => WorldFlags::active($this->flag($arguments)) ? 'Turn off' : 'Turn on')
            ->color(fn (array $arguments): string => WorldFlags::active($this->flag($arguments)) ? 'danger' : 'success')
            ->icon(Heroicon::OutlinedPower)
            ->authorize(fn (): bool => Gate::allows('update', WorldFlags::class))
            ->modalHeading(fn (array $arguments): string => (WorldFlags::active($this->flag($arguments)) ? 'Turn off ' : 'Turn on ').self::describe($this->flag($arguments))['label'])
            ->modalDescription('This takes effect for every player immediately.')
            ->schema([
                Textarea::make('reason')
                    ->label('Reason')
                    ->helperText('Recorded in the audit log.')
                    ->required()
                    ->maxLength(255)
                    ->rows(3),
            ])
            ->action(function (array $arguments, array $data, Action $action): void {
                $flag = $this->flag($arguments);
                $active = ! WorldFlags::active($flag);

                WorldFlags::set($flag, $active);

                activity('flags')
                    ->causedBy(Staff::current())
                    ->withProperties(['flag' => $flag, 'active' => $active, 'reason' => (string) $data['reason']])
                    ->event($active ? 'flag.enabled' : 'flag.disabled')
                    ->log((self::describe($flag)['label']).($active ? ' turned on' : ' turned off'));

                $action->successNotificationTitle(self::describe($flag)['label'].($active ? ' is on' : ' is off'));
                $action->success();
            });
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return class-string
     */
    private function flag(array $arguments): string
    {
        $flag = $arguments['flag'] ?? null;

        abort_unless(is_string($flag) && in_array($flag, WorldFlags::FLAGS, true), 404);

        return $flag;
    }

    /**
     * @param  class-string  $flag
     * @return array{label: string, description: string}
     */
    private static function describe(string $flag): array
    {
        return self::DESCRIPTIONS[$flag] ?? ['label' => class_basename($flag), 'description' => ''];
    }
}
