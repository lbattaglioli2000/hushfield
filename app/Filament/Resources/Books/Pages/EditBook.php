<?php

namespace App\Filament\Resources\Books\Pages;

use App\Actions\Books\SaveBookDraft;
use App\Exceptions\ModerationActionRefused;
use App\Filament\Resources\Books\BookActions;
use App\Filament\Resources\Books\BookResource;
use App\Filament\Support\Staff;
use App\Models\Book;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

/**
 * Saves through SaveBookDraft, which checks a published book's changes as
 * publishing does. Publish, Withdraw and Restore sit in the header.
 */
class EditBook extends EditRecord
{
    protected static string $resource = BookResource::class;

    protected function getHeaderActions(): array
    {
        return [ViewAction::make(), ...BookActions::all()];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Book $record */
        try {
            /** @var array{title: string, body: string, cover: string, author_id: int|null, library_shelf_id: int, confirmed?: bool} $data */
            return app(SaveBookDraft::class)($record, $data, Staff::current(), (bool) ($data['confirmed'] ?? false));
        } catch (ModerationActionRefused $refused) {
            Notification::make()->danger()->title('Not saved')->body($refused->getMessage())->persistent()->send();

            throw new Halt;
        }
    }
}
