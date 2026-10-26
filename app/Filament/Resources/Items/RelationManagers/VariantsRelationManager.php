<?php

namespace App\Filament\Resources\Items\RelationManagers;

use App\Actions\Items\AddItemVariant;
use App\Domain\Assets\ArtStatus;
use App\Domain\Assets\ItemArt;
use App\Domain\Items\ItemSlot;
use App\Exceptions\ModerationActionRefused;
use App\Filament\Resources\Items\ItemResource;
use App\Models\Item;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * A furniture item's other colours. Each is an item of its own, on the same
 * tiles as this one, with its own price, art and place in the shop; the shop
 * shows the family as one entry with a swatch per colour.
 */
class VariantsRelationManager extends RelationManager
{
    protected static string $relationship = 'variants';

    protected static ?string $title = 'Variants';

    protected static bool $isLazy = false;

    protected static ?string $modelLabel = 'colour';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Item && $ownerRecord->slot === ItemSlot::Furniture && $ownerRecord->variant_of === null
            && (auth()->user()?->can('update', $ownerRecord) ?? false);
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        /** @var Item $item */
        $item = $this->getOwnerRecord();

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('publishedArt'))
            ->defaultSort('id')
            ->description('Other colours of this piece. Each stands on the same tiles as this one and follows it when it is resized, but has its own price, art and place in the shop, which shows them as swatches on one entry. This item is a colour too: give it a colour name and swatch above. To draw a colour, recolour the finished piece (docs/art/furniture.md).')
            ->columns([
                ColorColumn::make('swatch'),
                TextColumn::make('variant_label')
                    ->label('Colour')
                    ->description(fn (Item $record): string => $record->slug),
                TextColumn::make('price')
                    ->numeric()
                    ->suffix(' coins'),
                TextColumn::make('art')
                    ->badge()
                    ->state(fn (Item $record): ArtStatus => app(ItemArt::class)->status($record)),
                IconColumn::make('in_shop')
                    ->label('In shop')
                    ->boolean(),
            ])
            ->headerActions([
                Action::make('addVariant')
                    ->label('Add a colour')
                    ->icon(Heroicon::OutlinedSwatch)
                    ->modalHeading("Add a colour of {$item->name}")
                    ->modalDescription('It starts off the shelves. Upload its art, then put it in the shop.')
                    ->schema([
                        TextInput::make('variant_label')
                            ->label('Colour name')
                            ->placeholder('Moss')
                            ->required()
                            ->maxLength(30),
                        ColorPicker::make('swatch')
                            ->required()
                            ->regex('/^#[0-9a-fA-F]{6}$/'),
                        TextInput::make('price')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->maxValue(1_000_000)
                            ->suffix('coins')
                            ->default($item->price)
                            ->required(),
                    ])
                    ->action(function (array $data, Action $action, AddItemVariant $addVariant) use ($item): void {
                        try {
                            $variant = $addVariant($item, (string) $data['variant_label'], (string) $data['swatch'], (int) $data['price']);
                            // Straight to the new colour's page, where its art is uploaded.
                            $action->successRedirectUrl(ItemResource::getUrl('edit', ['record' => $variant]));
                        } catch (ModerationActionRefused $refused) {
                            Notification::make()->danger()->title('Not added')->body($refused->getMessage())->persistent()->send();

                            $action->halt();
                        }
                    })
                    ->successNotificationTitle('Colour added. Upload its art below.'),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label('Edit and art')
                    ->icon(Heroicon::OutlinedPhoto)
                    ->url(fn (Item $record): string => ItemResource::getUrl('edit', ['record' => $record])),
            ]);
    }
}
