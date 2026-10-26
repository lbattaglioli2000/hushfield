<?php

namespace App\Filament\Resources\Items\Schemas;

use App\Domain\Items\ItemRarity;
use App\Domain\Items\ItemSlot;
use App\Domain\World\Footprint;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\GridDirection;

class ItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Item')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(60),
                        TextInput::make('slug')
                            ->required()
                            ->maxLength(60)
                            ->alphaDash()
                            ->unique(ignoreRecord: true)
                            ->disabledOn('edit')
                            ->helperText('Stable identifier used by the client and in asset names. Cannot change once created.'),
                        Textarea::make('description')
                            ->maxLength(255)
                            ->rows(2)
                            ->columnSpanFull(),
                        Select::make('slot')
                            ->options(ItemSlot::class)
                            ->live()
                            ->required(),
                        Select::make('rarity')
                            ->options(ItemRarity::class)
                            ->default(ItemRarity::Common)
                            ->required(),
                    ]),
                Section::make('Furniture')
                    ->description('How the piece stands in a home. Turning it a quarter turn swaps wide and deep, and turns its shape. Resizing a placed piece does not move anything already standing near it.')
                    ->columns(4)
                    ->visible(fn (Get $get): bool => $get('slot') === ItemSlot::Furniture || $get('slot') === ItemSlot::Furniture->value)
                    ->schema([
                        TextInput::make('wide')
                            ->label('Tiles wide')
                            ->helperText('Along x.')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(8)
                            ->default(1)
                            ->required()
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set) => $set('shape', array_keys(self::tiles($get)))),
                        TextInput::make('deep')
                            ->label('Tiles deep')
                            ->helperText('Along y.')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(8)
                            ->default(1)
                            ->required()
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set) => $set('shape', array_keys(self::tiles($get)))),
                        TextInput::make('height')
                            ->label('Height')
                            ->helperText('Of the placeholder block, until it has art.')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(256)
                            ->suffix('px')
                            ->default(18)
                            ->required(),
                        Toggle::make('floor')
                            ->label('Lies on the floor')
                            ->helperText('Like a rug: walked over, and one other piece may stand on it.')
                            ->default(false),
                        CheckboxList::make('shape')
                            ->label('Shape')
                            ->helperText('The tiles it covers before any turn: the back row first, x across. Unticked tiles stay open floor, so players walk there and other pieces fit there. It turns with the piece.')
                            ->options(fn (Get $get): array => self::tiles($get))
                            ->columns(fn (Get $get): int => self::size($get('wide')))
                            ->gridDirection(GridDirection::Row)
                            // A plain block (no shape, which reaches here as []) ticks every tile.
                            ->formatStateUsing(fn (?array $state, Get $get): array => array_keys(array_filter(
                                self::tiles($get),
                                function (string $tile) use ($state): bool {
                                    [$x, $y] = array_map(intval(...), explode(',', $tile));

                                    return blank($state) || ($state[$y][$x] ?? null) === Footprint::FILLED;
                                },
                                ARRAY_FILTER_USE_KEY,
                            )))
                            ->dehydrateStateUsing(function (?array $state, Get $get): ?array {
                                $mask = self::mask($state ?? [], $get);

                                return str_contains(implode('', $mask), Footprint::EMPTY) ? $mask : null;
                            })
                            ->required()
                            ->rules([fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                $problem = Footprint::maskProblem(self::mask(is_array($value) ? $value : [], $get), self::size($get('wide')), self::size($get('deep')));

                                if ($problem !== null) {
                                    $fail($problem);
                                }
                            }])
                            ->columnSpanFull(),
                    ]),
                Section::make('Shop')
                    ->columns(3)
                    ->schema([
                        TextInput::make('price')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->maxValue(1_000_000)
                            ->suffix('coins')
                            ->required(),
                        Toggle::make('in_shop')
                            ->label('In the shop')
                            ->helperText('Off: hidden from the shop. Residents keep it, and staff can still grant it.')
                            ->default(false),
                        Toggle::make('members_only')
                            ->label('Members only')
                            ->helperText('Shown in the shop but locked. Membership does not exist yet.'),
                    ]),
            ]);
    }

    /**
     * The shape grid's boxes, keyed "x,y", row by row.
     *
     * @return array<string, string>
     */
    private static function tiles(Get $get): array
    {
        $tiles = [];

        for ($y = 0; $y < self::size($get('deep')); $y++) {
            for ($x = 0; $x < self::size($get('wide')); $x++) {
                $tiles["{$x},{$y}"] = "{$x}, {$y}";
            }
        }

        return $tiles;
    }

    /**
     * The ticked boxes as a Footprint mask.
     *
     * @param  array<mixed>  $ticked
     * @return list<string>
     */
    private static function mask(array $ticked, Get $get): array
    {
        $mask = [];

        for ($y = 0; $y < self::size($get('deep')); $y++) {
            $row = '';

            for ($x = 0; $x < self::size($get('wide')); $x++) {
                $row .= in_array("{$x},{$y}", $ticked, true) ? Footprint::FILLED : Footprint::EMPTY;
            }

            $mask[] = $row;
        }

        return $mask;
    }

    /** A size field's value as a whole number of tiles, 1–8 whatever was typed. */
    private static function size(mixed $value): int
    {
        return max(1, min(8, is_numeric($value) ? (int) $value : 1));
    }
}
