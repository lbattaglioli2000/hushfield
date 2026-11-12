<?php

namespace App\Filament\Resources\Books\Pages;

use App\Actions\Books\SaveBookDraft;
use App\Exceptions\ModerationActionRefused;
use App\Filament\Resources\Books\BookResource;
use App\Filament\Support\Staff;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

/** A new book starts as a draft, through SaveBookDraft. */
class CreateBook extends CreateRecord
{
    protected static string $resource = BookResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            /** @var array{title: string, body: string, cover: string, author_id: int|null, library_shelf_id: int} $data */
            return app(SaveBookDraft::class)(null, $data, Staff::current());
        } catch (ModerationActionRefused $refused) {
            Notification::make()->danger()->title('Not saved')->body($refused->getMessage())->persistent()->send();

            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
