<?php

namespace App\Events;

/**
 * Sends world broadcasts after the durable state is already written. A websocket
 * outage is reported but never undoes or interrupts the action that caused it.
 *
 * broadcast() dispatches when its PendingBroadcast is destroyed, so each call
 * below discards it inside the rescued closure, where a failure can be caught.
 */
final class WorldBroadcast
{
    public static function send(WorldEvent $event): void
    {
        rescue(function () use ($event): void {
            broadcast($event);
        });
    }

    /**
     * Skips the socket that made the current request (its client already applied the change).
     */
    public static function toOthers(WorldEvent $event): void
    {
        rescue(function () use ($event): void {
            broadcast($event)->toOthers();
        });
    }
}
