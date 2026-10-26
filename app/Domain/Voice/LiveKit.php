<?php

namespace App\Domain\Voice;

use App\Models\User;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Proximity voice runs on LiveKit: one LiveKit room per room instance, joined
 * with a short-lived token minted here. Who may join, and whether they may
 * speak, is decided by IssueVoiceToken; this class only signs and evicts.
 */
final class LiveKit
{
    public function configured(): bool
    {
        return filled(config('hushfield.voice.url'))
            && filled(config('hushfield.voice.api_key'))
            && filled(config('hushfield.voice.api_secret'));
    }

    public function url(): string
    {
        return (string) config('hushfield.voice.url');
    }

    public static function roomName(int $instanceId): string
    {
        return "instance-{$instanceId}";
    }

    /**
     * A token to join the instance's voice room: always to listen, to speak
     * only with $canSpeak, and never to publish anything but a microphone.
     */
    public function joinToken(User $user, int $instanceId, bool $canSpeak): string
    {
        return $this->sign([
            'sub' => (string) $user->id,
            'name' => $user->username,
            'video' => [
                'room' => self::roomName($instanceId),
                'roomJoin' => true,
                'canSubscribe' => true,
                'canPublish' => $canSpeak,
                'canPublishSources' => ['microphone'],
                'canPublishData' => false,
                'canUpdateOwnMetadata' => false,
            ],
        ]);
    }

    /**
     * Takes the user out of the instance's voice room, if they are in it. A token
     * is only checked on joining, so this is what ends a connection already open.
     */
    public function removeParticipant(int $instanceId, int $userId): void
    {
        $room = self::roomName($instanceId);
        $response = Http::withToken($this->sign(['video' => ['room' => $room, 'roomAdmin' => true]]))
            ->timeout(3)
            ->post($this->httpUrl().'/twirp/livekit.RoomService/RemoveParticipant', [
                'room' => $room,
                'identity' => (string) $userId,
            ]);

        // Not in the room (or the room never opened) is the usual case, not a failure.
        if ($response->failed() && $response->status() !== 404) {
            report(new RequestException($response));
        }
    }

    private function httpUrl(): string
    {
        return rtrim((string) preg_replace('#^ws#', 'http', $this->url()), '/');
    }

    /**
     * An HS256 JWT in LiveKit's access-token shape.
     *
     * @param  array<string, mixed>  $claims
     */
    private function sign(array $claims): string
    {
        $now = time();
        $encode = fn (array $part): string => rtrim(strtr(base64_encode((string) json_encode($part)), '+/', '-_'), '=');
        $payload = $encode(['alg' => 'HS256', 'typ' => 'JWT']).'.'.$encode([
            'iss' => (string) config('hushfield.voice.api_key'),
            'nbf' => $now,
            'exp' => $now + (int) config('hushfield.voice.token_ttl_seconds'),
            ...$claims,
        ]);
        $signature = hash_hmac('sha256', $payload, (string) config('hushfield.voice.api_secret'), true);

        return $payload.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }
}
