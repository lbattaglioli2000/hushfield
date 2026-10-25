<?php

namespace App\Filament\Resources\Users\Pages;

use App\Actions\Moderation\IssueSanction;
use App\Domain\Moderation\SanctionType;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\ModerationForm;
use App\Filament\Support\Staff;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->sanctionAction(SanctionType::Warning, 'Warn', Heroicon::OutlinedExclamationTriangle, 'gray'),
            $this->sanctionAction(SanctionType::Mute, 'Mute', Heroicon::OutlinedSpeakerXMark, 'warning'),
            $this->sanctionAction(SanctionType::Ban, 'Ban', Heroicon::OutlinedNoSymbol, 'danger'),
        ];
    }

    private function sanctionAction(SanctionType $type, string $label, Heroicon $icon, string $color): Action
    {
        return Action::make($type->value)
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->authorize('sanction')
            ->modalHeading(fn (User $record): string => "{$label} {$record->username}")
            ->schema(ModerationForm::fields($type))
            ->action(function (User $record, array $data, IssueSanction $issueSanction) use ($type): void {
                $issueSanction($record, $type, ModerationForm::reason($data), ModerationForm::endsAt($data), Staff::current());
            })
            ->successNotificationTitle("{$label} issued")
            ->after(fn () => $this->dispatch('refresh-sanctions'));
    }
}
