<?php

namespace App\Filament\Resources\Activities;

use App\Domain\Audit\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;

/**
 * How audit entries read in the table and the detail view.
 */
final class ActivityFormat
{
    public static function logName(?string $logName): ?string
    {
        return $logName === null ? null : (AuditLog::tryFrom($logName)?->getLabel() ?? $logName);
    }

    public static function causer(Activity $activity): ?string
    {
        return self::describe($activity->causer, $activity->causer_type, $activity->causer_id);
    }

    public static function subject(Activity $activity): ?string
    {
        return self::describe($activity->subject, $activity->subject_type, $activity->subject_id);
    }

    /**
     * @return array<string, string>
     */
    public static function properties(Activity $activity): array
    {
        return collect($activity->properties?->all() ?? [])
            ->mapWithKeys(fn (mixed $value, int|string $key): array => [(string) $key => match (true) {
                is_string($value) => $value,
                $value === null => 'null',
                default => (string) json_encode($value),
            }])
            ->all();
    }

    private static function describe(?Model $model, ?string $type, ?int $id): ?string
    {
        if ($type === null) {
            return null;
        }

        $name = class_basename($type);

        return match (true) {
            $model instanceof User => "{$model->username} ({$name} #{$id})",
            default => "{$name} #{$id}",
        };
    }
}
