<?php

namespace App\Filament\Resources\BlockedTerms\Actions;

use App\Domain\Chat\BlockedTermsFilter;
use App\Domain\Chat\FilterLevel;
use App\Domain\Chat\FilterResult;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/**
 * Runs sample text through the live chat filter so admins can check the list.
 */
final class TestFilterAction
{
    public static function make(): Action
    {
        return Action::make('testFilter')
            ->label('Test the filter')
            ->icon(Heroicon::OutlinedBeaker)
            ->color('gray')
            ->authorize('viewAny')
            ->modalHeading('Test the chat filter')
            ->modalDescription('Type a sample message and pick a filter level to see what players would see.')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->schema([
                Textarea::make('sample')
                    ->label('Sample text')
                    ->rows(3)
                    ->maxLength(255)
                    ->live(debounce: 400),
                Select::make('level')
                    ->label('Filter level')
                    ->options(FilterLevel::class)
                    ->default(FilterLevel::Strict)
                    ->selectablePlaceholder(false)
                    ->live(),
                Section::make('Result')
                    ->compact()
                    ->schema([
                        Text::make(fn (Get $get): string => self::run($get)->body ?? 'Type some text to test it.')
                            ->weight('bold'),
                        Text::make(fn (Get $get): string => match (true) {
                            ($result = self::run($get)) === null => '',
                            $result->matches === [] => 'No matches. The message would be shown unchanged.',
                            default => 'Matched: '.implode(', ', $result->matches),
                        })
                            ->color(fn (Get $get): string => (self::run($get)->wasFiltered ?? false) ? 'danger' : 'success'),
                    ]),
            ]);
    }

    private static function run(Get $get): ?FilterResult
    {
        $sample = $get('sample');

        if (! is_string($sample) || trim($sample) === '') {
            return null;
        }

        $level = $get('level');
        $level = $level instanceof FilterLevel ? $level : (FilterLevel::tryFrom(is_string($level) ? $level : '') ?? FilterLevel::Strict);

        return app(BlockedTermsFilter::class)->filter($sample, $level);
    }
}
