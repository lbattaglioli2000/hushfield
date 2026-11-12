<?php

namespace App\Filament\Resources\Books;

use App\Actions\Books\PublishBook;
use App\Actions\Books\WithdrawBook;
use App\Domain\Books\BookState;
use App\Domain\Books\BookText;
use App\Exceptions\ModerationActionRefused;
use App\Filament\Support\ModerationForm;
use App\Filament\Support\Staff;
use App\Models\Book;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

/**
 * Publish, Withdraw and Restore, on a book's view and edit pages.
 */
final class BookActions
{
    /**
     * @return list<Action>
     */
    public static function all(): array
    {
        return [self::publish(), self::withdraw(), self::restore()];
    }

    /**
     * Saves the form first on the edit page, so what is published is what staff see.
     * A blocked term stops it; links, emails and phone numbers ask staff to confirm.
     */
    public static function publish(): Action
    {
        return Action::make('publish')
            ->label('Publish')
            ->icon(Heroicon::OutlinedBookOpen)
            ->color('success')
            ->visible(fn (Book $record): bool => $record->state() === BookState::Draft)
            ->authorize('publish')
            ->modalHeading(fn (Book $record): string => "Publish {$record->title}")
            ->modalDescription(function (Book $record): string {
                ['blocked' => $blocked, 'warnings' => $warnings] = app(BookText::class)->check($record->title, $record->body);

                return match (true) {
                    $blocked !== [] => ModerationActionRefused::bookBlocked($blocked)->getMessage(),
                    $warnings !== [] => 'It looks like it has '.ModerationActionRefused::contactKinds($warnings).'. Players aged 13 to 17 read books too.',
                    default => "It goes on the {$record->libraryShelf->name} shelf of the library, where every resident can read it and check out a copy.",
                };
            })
            ->schema(fn (Book $record): array => app(BookText::class)->check($record->title, $record->body)['warnings'] === [] ? [] : [
                Checkbox::make('confirmed')->label('I checked it. Publish it anyway.')->accepted(),
            ])
            ->action(function (Book $record, array $data, Action $action, PublishBook $publish, mixed $livewire): void {
                if ($livewire instanceof EditRecord) {
                    $livewire->save(shouldRedirect: false, shouldSendSavedNotification: false);
                    $record->refresh();
                }

                ModerationForm::runOrNotify($action, fn () => $publish($record, Staff::current(), (bool) ($data['confirmed'] ?? false)));
            })
            ->successNotificationTitle('Published. It is on the library shelf now.');
    }

    public static function withdraw(): Action
    {
        return Action::make('withdraw')
            ->label('Withdraw')
            ->icon(Heroicon::OutlinedEyeSlash)
            ->color('danger')
            ->visible(fn (Book $record): bool => $record->state() === BookState::Published)
            ->authorize('withdraw')
            ->modalHeading(fn (Book $record): string => "Withdraw {$record->title}")
            ->modalDescription('It leaves the library, and every copy residents hold turns into a plain spine with no title, author or text. Restoring brings it all back.')
            ->schema([Textarea::make('reason')->required()->maxLength(500)])
            ->action(function (Book $record, array $data, Action $action, WithdrawBook $withdraw): void {
                ModerationForm::runOrNotify($action, fn () => $withdraw($record, Staff::current(), (string) $data['reason']));
            })
            ->successNotificationTitle('Withdrawn.');
    }

    public static function restore(): Action
    {
        return Action::make('restore')
            ->label('Restore')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->visible(fn (Book $record): bool => $record->state() === BookState::Withdrawn)
            ->authorize('withdraw')
            ->modalHeading(fn (Book $record): string => "Restore {$record->title}")
            ->modalDescription('It goes back on its library shelf, and every copy can be read again.')
            ->schema([Textarea::make('reason')->required()->maxLength(500)])
            ->action(function (Book $record, array $data, Action $action, WithdrawBook $withdraw): void {
                ModerationForm::runOrNotify($action, fn () => $withdraw->restore($record, Staff::current(), (string) $data['reason']));
            })
            ->successNotificationTitle('Restored.');
    }
}
