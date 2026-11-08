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
 * Staff put an MP3 on WHSH's air, now: whatever was on goes off, and every
 * set in town plays this from its start. The recording is checked to be an
 * MP3 of a sensible length and stored on the public disk under its content
 * hash, like uploaded art. Audited.
 */
final readonly class StartBroadcast
{
    public function __construct(private Airwaves $airwaves) {}

    /**
     * @param  string  $file  A readable local path to the uploaded recording.
     *
     * @throws BroadcastRefused when it is too big, not an MP3, or too long.
     */
    public function __invoke(string $title, string $file, User $staff): Broadcast
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

        $path = Storage::disk((string) config('hushfield.assets.disk'))->putFileAs('broadcasts', new File($file), substr((string) hash_file('sha256', $file), 0, 16).'.mp3', [
            'CacheControl' => 'public, max-age=31536000, immutable',
            'ContentType' => 'audio/mpeg',
        ]);

        if ($path === false) {
            throw new RuntimeException('Could not store the recording on the public disk.');
        }

        $broadcast = $this->airwaves->change(function (CarbonInterface $now) use ($title, $path, $bytes, $seconds, $staff): Broadcast {
            Broadcast::query()->onAir($now)->update(['ends_at' => $now]);

            return Broadcast::query()->create([
                'title' => $title,
                'path' => $path,
                'bytes' => $bytes,
                'duration_seconds' => $seconds,
                'starts_at' => $now,
                'ends_at' => $now->addSeconds($seconds),
                'scheduled_by' => $staff->id,
            ]);
        });

        activity(AuditLog::Radio)
            ->performedOn($broadcast)
            ->causedBy($staff)
            ->withProperties(['title' => $title, 'path' => $path, 'duration_seconds' => $seconds])
            ->event('broadcast.started')
            ->log("On the air: {$title}");

        $this->airwaves->announce();

        return $broadcast;
    }
}
