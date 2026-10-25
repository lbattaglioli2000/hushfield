<?php

namespace App\Filament\Resources\Items\Schemas;

use App\Domain\Items\ItemRarity;
use App\Domain\Items\ItemSlot;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
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
                            ->required(),
                        Select::make('rarity')
                            ->options(ItemRarity::class)
                            ->default(ItemRarity::Common)
                            ->required(),
                        TagsInput::make('sprite_layers')
                            ->label('Sprite layers')
                            ->required()
                            ->helperText('Asset keys drawn for this item, bottom layer first.')
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
}
