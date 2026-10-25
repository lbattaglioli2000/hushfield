<?php

namespace App\Actions\Chat;

use App\Domain\Chat\ChatFilter;
use App\Domain\Chat\ChatSanitizer;
use App\Domain\Chat\TextNormalizer;
use App\Domain\Presence\InstanceMembers;
use App\Events\Chat\ChatMessageSent;
use App\Exceptions\WorldActionRefused;
use App\Features\ChatEnabled;
use App\Features\WorldFlags;
use App\Models\ChatMessage;
use App\Models\RoomInstance;
use App\Models\User;
use Illuminate\Redis\RedisManager;

/**
 * Filters, stores, and broadcasts one chat line.
 *
 * Returns null when the line is silently dropped (an identical repeat).
 * A muted player's line comes back unsaved and unbroadcast, so only their own client shows it.
 */
final readonly class SendChatMessage
{
    public function __construct(
        private InstanceMembers $members,
        private ChatSanitizer $sanitizer,
        private ChatFilter $filter,
        private TextNormalizer $normalizer,
        private RedisManager $redis,
    ) {}

    public function __invoke(User $user, RoomInstance $instance, string $body): ?ChatMessage
    {
        if (! WorldFlags::active(ChatEnabled::class)) {
            throw WorldActionRefused::chatPaused();
        }

        if (! $this->members->contains($instance->id, $user->id)) {
            throw WorldActionRefused::notInRoom();
        }

        $body = mb_substr($this->sanitizer->sanitize($body), 0, (int) config('hushfield.chat.max_length'));

        if ($body === '') {
            throw WorldActionRefused::chatEmpty();
        }

        if ($this->isRepeat($user, $body)) {
            return null;
        }

        $result = $this->filter->filter($body, $user->chat_filter_level);

        $message = new ChatMessage([
            'room_instance_id' => $instance->id,
            'user_id' => $user->id,
            'body' => $body,
            'filtered_body' => $result->body,
            'was_filtered' => $result->wasFiltered,
        ]);

        if ($user->isMuted()) {
            return $message;
        }

        $message->save();

        broadcast(new ChatMessageSent($message))->toOthers();

        return $message;
    }

    private function isRepeat(User $user, string $body): bool
    {
        $fingerprint = hash('xxh3', $this->normalizer->compact($body));
        $stored = $this->redis->connection('world')->set(
            "chat:last:{$user->id}:{$fingerprint}",
            1,
            'EX',
            (int) config('hushfield.chat.repeat_window_seconds'),
            'NX',
        );

        return ! $stored;
    }
}
