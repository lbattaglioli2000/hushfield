<?php

namespace App\Actions\Chat;

use App\Domain\Chat\ChatFilter;
use App\Domain\Chat\ChatSanitizer;
use App\Domain\Chat\TextNormalizer;
use App\Domain\Presence\InstanceMembers;
use App\Events\Chat\ChatMessageSent;
use App\Events\WorldBroadcast;
use App\Exceptions\WorldActionRefused;
use App\Features\ChatEnabled;
use App\Features\WorldFlags;
use App\Models\ChatMessage;
use App\Models\RoomInstance;
use App\Models\User;
use Illuminate\Redis\RedisManager;

/**
 * Sanitizes, filters, stores, and broadcasts one chat line. This is the only
 * place chat input is cleaned and measured, so every refusal has the same shape.
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

        $this->members->ensureContains($instance->id, $user->id);

        $body = $this->sanitizer->sanitize($body);

        if ($body === '') {
            throw WorldActionRefused::chatEmpty();
        }

        if (mb_strlen($body) > (int) config('hushfield.chat.max_length')) {
            throw WorldActionRefused::chatTooLong();
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

        WorldBroadcast::toOthers(new ChatMessageSent($message));

        return $message;
    }

    /**
     * Claims the line's fingerprint for the repeat window; false when it was already claimed.
     */
    private function isRepeat(User $user, string $body): bool
    {
        $fingerprint = hash('xxh3', $this->normalizer->compact($body));
        $ttl = (int) config('hushfield.chat.repeat_window_seconds');

        // SET key 1 NX EX ttl, in phpredis's option-array form.
        $stored = $this->redis->connection('world')->command('set', [
            "chat:last:{$user->id}:{$fingerprint}",
            1,
            ['nx', 'ex' => $ttl],
        ]);

        return ! $stored;
    }
}
