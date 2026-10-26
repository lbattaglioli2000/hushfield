<?php

namespace App\Filament\Resources\Items\Schemas;

use App\Domain\Items\ItemRarity;
use App\Domain\Items\ItemSlot;
use App\Domain\World\Footprint;
use App\Filament\Forms\Components\ShapePicker;
use App\Models\Item;
use Closure;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

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
                            ->disabled(self::isVariant(...))
                            ->required(),
                        Select::make('rarity')
                            ->options(ItemRarity::class)
                            ->default(ItemRarity::Common)
                            ->required(),
                    ]),
                Section::make('Colour')
                    ->description(fn (?Item $record): string => $record?->parent !== null
                        ? "A colour of {$record->parent->name}. It stands like it, so its size and shape are set there."
                        : 'Shown as a swatch in the shop when this piece comes in more than one colour (see Variants below).')
                    ->columns(2)
                    ->visible(self::isFurniture(...))
                    ->schema([
                        TextInput::make('variant_label')
                            ->label('Colour name')
                            ->placeholder('Rose')
                            ->maxLength(30)
                            ->required(self::isVariant(...)),
                        ColorPicker::make('swatch')
                            ->regex('/^#[0-9a-fA-F]{6}$/')
                            ->required(self::isVariant(...)),
                    ]),
                Section::make('Furniture')
                    ->description('How the piece stands in a home. Turning it a quarter turn swaps wide and deep, and turns its shape. Resizing a placed piece does not move anything already standing near it. Its colours follow it.')
                    ->columns(4)
                    ->visible(self::isFurniture(...))
                    // A colour stands like the item it is a colour of: see Item::INHERITED.
                    ->disabled(self::isVariant(...))
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
                        ShapePicker::make('shape')
                            ->label('Shape')
                            ->helperText('Click a tile to add or remove it. This is the floor the piece stands on, seen as in the game before any turn: it faces the front edge. Empty tiles stay open floor, so players walk there and other pieces fit there. The shape turns with the piece.')
                            ->size(fn (Get $get): int => self::size($get('wide')), fn (Get $get): int => self::size($get('deep')))
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

    private static function isFurniture(Get $get): bool
    {
        return $get('slot') === ItemSlot::Furniture || $get('slot') === ItemSlot::Furniture->value;
    }

    private static function isVariant(?Item $record): bool
    {
        return $record?->variant_of !== null;
    }

    /**
     * The shape's tiles, keyed "x,y", row by row.
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
