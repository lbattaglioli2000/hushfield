<?php

namespace App\Actions\Radio;

use App\Domain\Audit\AuditLog;
use App\Domain\Radio\Airwaves;
use App\Domain\Radio\BroadcastRefused;
use App\Models\Broadcast;
use App\Models\User;
use Carbon\CarbonInterface;
use getID3;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Staff put an MP3 on WHSH's lineup: on the air now, or scheduled for a
 * time of their choosing that never moves. Every set in town plays it from
 * its start, and it cuts short whatever is on then. Scheduled broadcasts may
 * not overlap one another. The recording is checked to be an MP3 of a
 * sensible length and stored on the public disk under its content hash, like
 * uploaded art. Audited.
 */
final readonly class ScheduleBroadcast
{
    public function __construct(private Airwaves $airwaves) {}

    /**
     * @param  string  $file  A readable local path to the uploaded recording.
     * @param  CarbonInterface|null  $startsAt  When it goes on the air; null for now.
     *
     * @throws BroadcastRefused when it is too big, not an MP3, too long, or its time is past or taken.
     */
    public function __invoke(string $title, string $file, User $staff, ?CarbonInterface $startsAt = null): Broadcast
    {
        $bytes = (int) filesize($file);
        $maxBytes = (int) config('hushfield.radio.upload_max_bytes');
        $maxMinutes = (int) config('hushfield.radio.max_minutes');

        if ($bytes > $maxBytes) {
            throw new BroadcastRefused(sprintf('That recording is %d MB; a broadcast may be up to %d MB.', (int) ceil($bytes / 1048576), intdiv($maxBytes, 1048576)));
        }

        $info = (new getID3)->analyze($file);
        $seconds = (int) ceil((float) ($info['playtime_seconds'] ?? 0));

        $format = $info['audio']['dataformat'] ?? $info['fileformat'] ?? null;

        if ($format !== 'mp3' || $seconds < 1) {
            throw new BroadcastRefused('That file is not an MP3 the set can play'.(is_string($format) && $format !== 'mp3' ? ' (it looks like '.strtoupper($format).')' : '').'.');
        }

        if ($seconds > $maxMinutes * 60) {
            throw new BroadcastRefused(sprintf('That recording runs %d minutes; a broadcast may be up to %d.', (int) ceil($seconds / 60), $maxMinutes));
        }

        // Checked again under the lock; this first check keeps a refused file out of the bucket.
        if ($startsAt !== null) {
            self::ensureFree($startsAt, $seconds);
        }

        $path = Storage::disk((string) config('hushfield.assets.disk'))->putFileAs('broadcasts', new File($file), substr((string) hash_file('sha256', $file), 0, 16).'.mp3', [
            'CacheControl' => 'public, max-age=31536000, immutable',
            'ContentType' => 'audio/mpeg',
        ]);

        if ($path === false) {
            throw new RuntimeException('Could not store the recording on the public disk.');
        }

        // Whatever is on when it starts gives way to it there; see Airwaves::lineup().
        $broadcast = $this->airwaves->change(function (CarbonInterface $now) use ($title, $path, $bytes, $seconds, $staff, $startsAt): Broadcast {
            if ($startsAt !== null) {
                self::ensureFree($startsAt, $seconds);
            } elseif (($on = $this->airwaves->onAir($now)) !== null) {
                // Ended outright, so it gives way even to a broadcast started in the same second.
                Broadcast::query()->whereKey($on->id)->update(['ends_at' => $now]);
            }

            $startsAt ??= $now;

            return Broadcast::query()->create([
                'title' => $title,
                'path' => $path,
                'bytes' => $bytes,
                'duration_seconds' => $seconds,
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->addSeconds($seconds),
                'scheduled_by' => $staff->id,
            ]);
        });

        activity(AuditLog::Radio)
            ->performedOn($broadcast)
            ->causedBy($staff)
            ->withProperties(['title' => $title, 'path' => $path, 'duration_seconds' => $seconds, 'starts_at' => $broadcast->starts_at->toIso8601String()])
            ->event($startsAt === null ? 'broadcast.started' : 'broadcast.scheduled')
            ->log(($startsAt === null ? 'On the air: ' : 'Scheduled: ').$title);

        $this->airwaves->announce();

        return $broadcast;
    }

    /**
     * A scheduled broadcast needs its time to itself: in the future, and
     * clear of every other one still to come.
     *
     * @throws BroadcastRefused
     */
    private static function ensureFree(CarbonInterface $at, int $seconds): void
    {
        $now = now();

        if ($at->lessThanOrEqualTo($now)) {
            throw new BroadcastRefused('A scheduled broadcast has to start in the future. To start it now, use Go on air.');
        }

        $clash = Broadcast::query()->where('starts_at', '>', $now)
            ->where('starts_at', '<', $at->addSeconds($seconds))
            ->where('ends_at', '>', $at)
            ->orderBy('starts_at')
            ->first();

        if ($clash !== null) {
            throw new BroadcastRefused("It would overlap \"{$clash->title}\", scheduled for {$clash->starts_at->timezone((string) config('hushfield.timezone'))->format('D j M, g:i A')}.");
        }
    }
}
