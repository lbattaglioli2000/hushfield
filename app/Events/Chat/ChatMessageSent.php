<?php

namespace App\Events\Chat;

use App\Events\RoomEvent;
use App\Models\ChatMessage;

final class ChatMessageSent extends RoomEvent
{
    public function __construct(public readonly ChatMessage $message)
    {
        parent::__construct($message->room_instance_id);
    }

    protected function payload(): array
    {
        return $this->message->toPayload();
    }
}
