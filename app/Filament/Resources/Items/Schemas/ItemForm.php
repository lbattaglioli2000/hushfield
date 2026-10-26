<?php

namespace App\Filament\Resources\Items\Schemas;

use App\Domain\Items\ItemRarity;
use App\Domain\Items\ItemSlot;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
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
                            ->required(),
                        Select::make('rarity')
                            ->options(ItemRarity::class)
                            ->default(ItemRarity::Common)
                            ->required(),
                    ]),
                Section::make('Furniture')
                    ->description('How the piece stands in a home. Turning it a quarter turn swaps wide and deep. Resizing a placed piece does not move anything already standing near it.')
                    ->columns(4)
                    ->visible(fn (Get $get): bool => $get('slot') === ItemSlot::Furniture || $get('slot') === ItemSlot::Furniture->value)
                    ->schema([
                        TextInput::make('wide')
                            ->label('Tiles wide')
                            ->helperText('Along x.')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(4)
                            ->default(1)
                            ->required(),
                        TextInput::make('deep')
                            ->label('Tiles deep')
                            ->helperText('Along y.')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(4)
                            ->default(1)
                            ->required(),
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
}
