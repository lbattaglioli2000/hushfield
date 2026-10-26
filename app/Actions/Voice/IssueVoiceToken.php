<?php

namespace App\Actions\Voice;

use App\Domain\Presence\InstanceMembers;
use App\Domain\Voice\LiveKit;
use App\Exceptions\WorldActionRefused;
use App\Features\WorldFlag;
use App\Models\RoomInstance;
use App\Models\User;

/**
 * Lets an adult resident into their room instance's voice room. Minors never
 * get a token, so they can neither speak nor hear. A muted resident may listen
 * but not speak.
 */
final readonly class IssueVoiceToken
{
    public function __construct(
        private InstanceMembers $members,
        private LiveKit $liveKit,
    ) {}

    /**
     * @return array{url: string, token: string, can_speak: bool}
     */
    public function __invoke(User $user, RoomInstance $instance): array
    {
        if ($user->is_minor) {
            throw WorldActionRefused::voiceAdultsOnly();
        }

        if (! $this->liveKit->configured()) {
            throw WorldActionRefused::voicePaused();
        }

        WorldFlag::Voice->ensureActive();

        $this->members->ensureContains($instance->id, $user->id);

        $canSpeak = ! $user->isMuted();

        return [
            'url' => $this->liveKit->url(),
            'token' => $this->liveKit->joinToken($user, $instance->id, $canSpeak),
            'can_speak' => $canSpeak,
        ];
    }
}
