<?php

namespace App\Filament\Resources\BlockedTerms\Schemas;

use App\Domain\Chat\BlockedTermMatchType;
use App\Domain\Chat\BlockedTermSeverity;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class BlockedTermForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('term')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule->where('match_type', self::matchType($get)?->value))
                    ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                        if (self::matchType($get) === BlockedTermMatchType::Regex && @preg_match('~'.str_replace('~', '\~', (string) $value).'~iu', '') === false) {
                            $fail('This is not a valid regular expression.');
                        }
                    })
                    ->helperText('For regex, write the pattern without delimiters. It is matched against each normalized word.'),
                Select::make('match_type')
                    ->options(BlockedTermMatchType::class)
                    ->default(BlockedTermMatchType::Exact)
                    ->required()
                    ->live(),
                Select::make('severity')
                    ->options(BlockedTermSeverity::class)
                    ->default(BlockedTermSeverity::Medium)
                    ->required()
                    ->helperText('Low terms are only blocked by the strict filter. Medium and high are blocked by every filter.'),
            ]);
    }

    private static function matchType(Get $get): ?BlockedTermMatchType
    {
        $state = $get('match_type');

        return $state instanceof BlockedTermMatchType ? $state : BlockedTermMatchType::tryFrom(is_string($state) ? $state : '');
    }
}
