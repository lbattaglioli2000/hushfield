<?php

namespace App\Events\Chat;

use App\Events\WorldEvent;
use App\Models\ChatMessage;
use Illuminate\Broadcasting\PresenceChannel;

final class ChatMessageSent extends WorldEvent
{
    public function __construct(public readonly ChatMessage $message)
    {
        parent::__construct();
    }

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel("room.{$this->message->room_instance_id}");
    }

    public function broadcastAs(): string
    {
        return 'ChatMessageSent';
    }

    protected function payload(): array
    {
        return [
            'id' => $this->message->id,
            'user_id' => $this->message->user_id,
            'body' => $this->message->filtered_body,
        ];
    }
}
