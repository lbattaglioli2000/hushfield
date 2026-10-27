<?php

namespace App\Actions\Cinema;

use App\Domain\Audit\AuditLog;
use App\Domain\Cinema\HouseLights;
use App\Domain\Cinema\Programme;
use App\Models\Theater;
use App\Models\User;

/**
 * Sets a theater's house lights. Everyone in it sees them change at once.
 */
final readonly class SetHouseLights
{
    public function __construct(private Programme $programme) {}

    /**
     * @return bool Whether they changed.
     */
    public function __invoke(string $theater, HouseLights $lights, User $staff): bool
    {
        $row = Theater::query()->firstOrNew(['slug' => $theater]);

        if ($row->exists && $row->house_lights === $lights) {
            return false;
        }

        $row->house_lights = $lights;
        $row->save();

        activity(AuditLog::Cinema)
            ->causedBy($staff)
            ->withProperties(['theater' => $theater, 'house_lights' => $lights->value])
            ->event('lights.set')
            ->log(($this->programme->theaters()[$theater] ?? $theater).' house lights: '.$lights->getLabel());

        $this->programme->announce($theater);

        return true;
    }
}
