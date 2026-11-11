<?php

namespace App\Filament\Resources\Items\RelationManagers;

use App\Actions\Items\CopyItemAnimation;
use App\Actions\Items\PublishItemArt;
use App\Actions\Items\UnpublishItemArt;
use App\Actions\Items\UploadItemArt;
use App\Domain\Assets\InvalidAssetException;
use App\Domain\Assets\ItemArt;
use App\Domain\Items\ItemSlot;
use App\Exceptions\ModerationActionRefused;
use App\Filament\Support\Staff;
use App\Models\Item;
use App\Models\ItemArtUpload;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * A furniture item's uploaded art (docs/assets-plan.md §8, and the artist's
 * guide in docs/art/furniture.md): upload a facing, then the art publisher
 * publishes it. Every upload is kept, so publishing an older one rolls back.
 */
class ArtRelationManager extends RelationManager
{
    protected static string $relationship = 'artUploads';

    protected static ?string $title = 'Art';

    protected static bool $isLazy = false;

    protected static ?string $modelLabel = 'upload';

    /** What each facing faces, as resources/maps/README.md, "Furniture", has it. */
    public const array FACINGS = [
        0 => '0 · faces lower left',
        1 => '1 · faces upper left',
        2 => '2 · faces upper right',
        3 => '3 · faces lower right',
    ];

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Item && $ownerRecord->slot === ItemSlot::Furniture
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
        $across = ($item->wide + $item->deep) * 16;

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['uploader', 'publisher']))
            ->defaultSort('id', 'desc')
            ->description("Each facing is a PNG {$across} art pixels wide for this {$item->wide} × {$item->deep} piece, exported at 100% on a transparent background, standing on its footprint diamond (docs/art/furniture.md). The game draws a facing's published upload in place of any art for it in the repo. Changing the piece's size or shape marks its uploads stale.")
            ->columns([
                ImageColumn::make('preview')
                    ->state(fn (ItemArtUpload $record): string => app(ItemArt::class)->url($record->path))
                    ->imageHeight(64)
                    // Asked for with CORS, as the game asks: an answer without CORS headers would be cached for the game too.
                    ->extraImgAttributes(['crossorigin' => 'anonymous', 'style' => 'image-rendering: pixelated; background: repeating-conic-gradient(#d4d4d8 0 25%, #fafafa 0 50%) 0 0 / 16px 16px;']),
                TextColumn::make('facing')
                    ->formatStateUsing(fn (int $state): string => self::FACINGS[$state] ?? (string) $state),
                TextColumn::make('status')
                    ->badge()
                    ->state(fn (ItemArtUpload $record): string => match (true) {
                        $record->stale_at !== null => 'Stale',
                        $record->published_at !== null => 'Published',
                        default => 'Not published',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Published' => 'success',
                        'Stale' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('animation')
                    ->label('Switched on')
                    ->state(fn (ItemArtUpload $record): ?string => $record->animation === null ? null : $record->animation['frames'].' frames, '.array_sum($record->animation['frame_ms']).' ms')
                    ->placeholder('—'),
                TextColumn::make('size')
                    ->label('Art pixels')
                    ->state(fn (ItemArtUpload $record): string => intdiv($record->width, 2).' × '.intdiv($record->height, 2)),
                TextColumn::make('created_at')
                    ->label('Uploaded')
                    ->since()
                    ->dateTimeTooltip()
                    ->description(fn (ItemArtUpload $record): string => 'by '.($record->uploader->username ?? 'someone since removed'))
                    ->sortable(),
                TextColumn::make('publisher.username')
                    ->label('Published by')
                    ->placeholder('—'),
            ])
            ->headerActions([
                Action::make('upload')
                    ->label('Upload art')
                    ->icon(Heroicon::OutlinedArrowUpTray)
                    ->modalHeading("Upload art for {$item->name}")
                    ->schema([
                        Select::make('facing')
                            ->options(self::FACINGS)
                            ->default(0)
                            ->required(),
                        FileUpload::make('file')
                            ->label('Picture')
                            ->helperText("A PNG {$across} art pixels wide, up to ".config('hushfield.assets.upload_max_height').' tall and '.intdiv((int) config('hushfield.assets.upload_max_bytes'), 1024).' KB.')
                            ->acceptedFileTypes(['image/png'])
                            ->maxSize(intdiv((int) config('hushfield.assets.upload_max_bytes'), 1024))
                            ->storeFiles(false)
                            ->required(),
                        FileUpload::make('strip')
                            ->label('Switched on (optional)')
                            ->helperText('What plays over the picture while a placed piece is on, such as clothes tumbling: two or more frames, each the size of the picture with only what moves drawn, side by side (docs/art/furniture.md).')
                            ->acceptedFileTypes(['image/png'])
                            ->maxSize(intdiv((int) config('hushfield.assets.upload_max_bytes'), 1024))
                            ->storeFiles(false)
                            ->live()
                            // Drawn just over the piece, an animation on a rug would cover anyone standing on it.
                            ->visible(! $item->floor),
                        TextInput::make('frame_ms')
                            ->label('Frame times (ms)')
                            ->helperText('One time for every frame, such as 140, or one for each, such as 600, 80, 80, 80. At least 60 each.')
                            ->regex('/^\s*\d+(\s*,\s*\d+)*\s*$/')
                            ->requiredWith('strip')
                            ->visible(fn (callable $get): bool => ! $item->floor && filled($get('strip'))),
                        Toggle::make('publish')
                            ->label('Publish it now')
                            ->helperText('Players see it on their next visit to a room it stands in.')
                            ->default(true)
                            ->visible(fn (): bool => Gate::allows('publishArt', $item)),
                    ])
                    ->action(function (array $data, Action $action, UploadItemArt $upload, PublishItemArt $publish) use ($item): void {
                        $file = $data['file'];

                        if (! $file instanceof TemporaryUploadedFile) {
                            return;
                        }

                        $this->runOrNotify($action, 'Not uploaded', function () use ($item, $data, $file, $upload, $publish): void {
                            $strip = $data['strip'] ?? null;
                            $art = $upload(
                                $item, (int) $data['facing'], (string) $file->get(), Staff::current(),
                                $strip instanceof TemporaryUploadedFile ? (string) $strip->get() : null,
                                array_map(intval(...), explode(',', (string) ($data['frame_ms'] ?? ''))),
                            );

                            if (($data['publish'] ?? false) === true) {
                                $publish($art, Staff::current());
                            }
                        });
                    })
                    ->successNotificationTitle('Art uploaded'),
            ])
            ->recordActions([
                // Both work on the animation, in one menu so a row's actions fit the table.
                ActionGroup::make([
                    Action::make('animate')
                        ->label(fn (ItemArtUpload $record): string => $record->animation === null ? 'Add animation…' : 'Change animation…')
                        ->icon(Heroicon::OutlinedPlay)
                        ->modalHeading(fn (ItemArtUpload $record): string => "Switched on, facing {$record->facing}")
                        ->modalDescription('Makes a new upload with this picture and the strip, so you needn\'t upload the picture again. It publishes and rolls back like any upload.')
                        // Drawn just over the piece, an animation on a rug would cover anyone standing on it.
                        ->visible(fn (ItemArtUpload $record): bool => ! $item->floor && $record->stale_at === null)
                        ->schema([
                            FileUpload::make('strip')
                                ->label('Switched on')
                                ->helperText(fn (ItemArtUpload $record): string => 'Two or more frames side by side, each '.intdiv($record->width, 2).' × '.intdiv($record->height, 2).' like this picture, with only what moves drawn (docs/art/furniture.md).')
                                ->acceptedFileTypes(['image/png'])
                                ->maxSize(intdiv((int) config('hushfield.assets.upload_max_bytes'), 1024))
                                ->storeFiles(false)
                                ->required(),
                            TextInput::make('frame_ms')
                                ->label('Frame times (ms)')
                                ->helperText('One time for every frame, such as 140, or one for each, such as 600, 80, 80, 80. At least 60 each.')
                                ->regex('/^\s*\d+(\s*,\s*\d+)*\s*$/')
                                ->default(fn (ItemArtUpload $record): ?string => $record->animation === null ? null : implode(', ', array_unique($record->animation['frame_ms'])))
                                ->required(),
                            Toggle::make('publish')
                                ->label('Publish it now')
                                ->default(true)
                                ->visible(fn (): bool => Gate::allows('publishArt', $item)),
                        ])
                        ->action(function (ItemArtUpload $record, array $data, Action $action, UploadItemArt $upload, PublishItemArt $publish): void {
                            $strip = $data['strip'];

                            if (! $strip instanceof TemporaryUploadedFile) {
                                return;
                            }

                            $this->runOrNotify($action, 'Not animated', function () use ($record, $data, $strip, $upload, $publish): void {
                                $art = $upload->animate($record, (string) $strip->get(), Staff::current(), array_map(intval(...), explode(',', (string) $data['frame_ms'])));

                                if (($data['publish'] ?? false) === true) {
                                    $publish($art, Staff::current());
                                }
                            });
                        })
                        ->successNotificationTitle('Animation added'),
                    Action::make('copyAnimation')
                        ->label('Copy animation to colours…')
                        ->icon(Heroicon::OutlinedDocumentDuplicate)
                        ->modalHeading('Copy this animation to other colours')
                        ->modalDescription(fn (ItemArtUpload $record): string => "Each colour you pick gets a new upload for facing {$record->facing}: its own published picture, with this upload's animation and frame times.")
                        ->visible(fn (ItemArtUpload $record): bool => $record->animation !== null && CopyItemAnimation::colours($item)->isNotEmpty())
                        ->schema([
                            CheckboxList::make('colours')
                                ->options(fn (): array => CopyItemAnimation::colours($item)->mapWithKeys(fn (Item $colour): array => [$colour->id => $colour->label()])->all())
                                ->default(fn (): array => CopyItemAnimation::colours($item)->pluck('id')->all())
                                ->required(),
                            Toggle::make('publish')
                                ->label('Publish them now')
                                ->default(true)
                                ->visible(fn (): bool => Gate::allows('publishArt', $item)),
                        ])
                        ->action(function (ItemArtUpload $record, array $data, CopyItemAnimation $copy): void {
                            $result = $copy($record, array_values(array_map(intval(...), $data['colours'])), Staff::current(), ($data['publish'] ?? false) === true);
                            $skipped = implode(' ', array_map(fn (string $colour, string $why): string => "{$colour}: skipped, {$why}.", array_keys($result['skipped']), $result['skipped']));

                            $notification = Notification::make()
                                ->title($result['copied'] === [] ? 'Nothing copied' : 'Animation copied to '.implode(', ', $result['copied']))
                                ->body($skipped === '' ? null : $skipped);
                            $notification = $result['copied'] === [] ? $notification->warning() : $notification->success();
                            // What was skipped stays up to be read.
                            ($skipped === '' ? $notification : $notification->persistent())->send();
                        }),
                ])
                    ->label('Animation')
                    ->icon(Heroicon::OutlinedPlay)
                    ->dropdownWidth(Width::ExtraSmall)
                    ->link(),
                Action::make('publish')
                    ->label('Publish')
                    ->icon(Heroicon::OutlinedEye)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Players see it in place of what this facing shows now, on their next visit to a room it stands in.')
                    ->visible(fn (ItemArtUpload $record): bool => $record->published_at === null && $record->stale_at === null && Gate::allows('publishArt', $item))
                    ->action(fn (ItemArtUpload $record, Action $action, PublishItemArt $publish) => $this->runOrNotify($action, 'Not published', fn () => $publish($record, Staff::current())))
                    ->successNotificationTitle('Art published'),
                Action::make('unpublish')
                    ->label('Unpublish')
                    ->icon(Heroicon::OutlinedEyeSlash)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('The facing goes back to its art in the repo, if it has any, or to the placeholder.')
                    ->visible(fn (ItemArtUpload $record): bool => $record->published_at !== null && Gate::allows('publishArt', $item))
                    ->action(fn (ItemArtUpload $record, Action $action, UnpublishItemArt $unpublish) => $this->runOrNotify($action, 'Not unpublished', fn () => $unpublish($record, Staff::current())))
                    ->successNotificationTitle('Art unpublished'),
            ]);
    }

    /**
     * Shows a refused upload or publish as a notification, keeping the modal
     * open so another file can be tried.
     *
     * @param  Closure(): mixed  $run
     */
    private function runOrNotify(Action $action, string $failed, Closure $run): void
    {
        try {
            $run();
        } catch (InvalidAssetException|ModerationActionRefused $refused) {
            Notification::make()->danger()->title($failed)->body($refused->getMessage())->persistent()->send();

            $action->halt();
        }
    }
}
