<?php

namespace App\Domain\Cinema;

use DateInterval;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Looks a film up with the YouTube Data API v3 (videos.list, one quota unit)
 * before it goes on the programme, so the screen never meets a video it can't
 * play. YouTube's policies also require knowing whether each embedded video
 * is made for kids; that is stored with the screening.
 */
final class YouTube
{
    private const string VIDEOS_URL = 'https://www.googleapis.com/youtube/v3/videos';

    /**
     * The video id in a YouTube link (watch, youtu.be, embed, shorts, live) or
     * a bare id; null when there is none.
     */
    public static function videoId(string $input): ?string
    {
        $input = trim($input);

        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $input) === 1) {
            return $input;
        }

        $parts = parse_url(str_contains($input, '://') ? $input : "https://{$input}");
        $host = strtolower(preg_replace('/^(www\.|m\.|music\.)/', '', $parts['host'] ?? '') ?? '');
        $path = $parts['path'] ?? '';

        $id = match (true) {
            $host === 'youtu.be' => explode('/', ltrim($path, '/'))[0],
            in_array($host, ['youtube.com', 'youtube-nocookie.com'], true) && $path === '/watch' => self::query($parts['query'] ?? '', 'v'),
            in_array($host, ['youtube.com', 'youtube-nocookie.com'], true) => preg_match('#^/(?:embed|shorts|live|v)/([^/?]+)#', $path, $match) === 1 ? $match[1] : null,
            default => null,
        };

        return is_string($id) && preg_match('/^[A-Za-z0-9_-]{11}$/', $id) === 1 ? $id : null;
    }

    /**
     * @throws FilmRefused saying why, in words for staff.
     */
    public function lookup(string $input): Film
    {
        $id = self::videoId($input) ?? throw new FilmRefused('That is not a YouTube link or video id.');
        $key = config('hushfield.cinema.youtube_key');

        if (blank($key)) {
            throw new FilmRefused('YOUTUBE_DATA_API_KEY is not set, so films cannot be looked up.');
        }

        try {
            $response = Http::timeout(5)->get(self::VIDEOS_URL, [
                'part' => 'snippet,contentDetails,status',
                'id' => $id,
                'key' => $key,
            ]);
        } catch (ConnectionException) {
            throw new FilmRefused('YouTube could not be reached. Please try again.');
        }

        if ($response->failed()) {
            report($response->toException());

            throw new FilmRefused("YouTube refused the lookup ({$response->status()}). Check the API key.");
        }

        /** @var array<string, mixed>|null $video */
        $video = $response->json('items.0');

        if ($video === null) {
            throw new FilmRefused('YouTube has no public video with that id. It may be private or deleted.');
        }

        $status = (array) ($video['status'] ?? []);
        $details = (array) ($video['contentDetails'] ?? []);
        $snippet = (array) ($video['snippet'] ?? []);

        if (! in_array($status['privacyStatus'] ?? null, ['public', 'unlisted'], true) || ($status['uploadStatus'] ?? null) !== 'processed') {
            throw new FilmRefused('That video is not available to watch yet.');
        }

        if (($status['embeddable'] ?? false) !== true) {
            throw new FilmRefused('Its owner does not allow it to be shown outside YouTube.');
        }

        if (($snippet['liveBroadcastContent'] ?? 'none') !== 'none') {
            throw new FilmRefused('Live streams cannot go on the programme; it needs a film with an end.');
        }

        if (data_get($details, 'contentRating.ytRating') === 'ytAgeRestricted') {
            throw new FilmRefused('That video is age-restricted, and Hushfield is open to residents from 13.');
        }

        $seconds = self::seconds((string) ($details['duration'] ?? ''));
        $max = (int) config('hushfield.cinema.max_minutes');

        if ($seconds < 1) {
            throw new FilmRefused('YouTube did not say how long that video is.');
        }

        if ($seconds > $max * 60) {
            throw new FilmRefused("That film runs longer than the VFA's limit of {$max} minutes.");
        }

        return new Film(
            videoId: $id,
            title: (string) ($snippet['title'] ?? $id),
            channel: isset($snippet['channelTitle']) ? (string) $snippet['channelTitle'] : null,
            durationSeconds: $seconds,
            madeForKids: ($status['madeForKids'] ?? false) === true,
        );
    }

    /** An ISO 8601 duration, such as PT1H2M3S, in seconds; 0 when unreadable. */
    private static function seconds(string $duration): int
    {
        try {
            $interval = new DateInterval($duration);
        } catch (Throwable) {
            return 0;
        }

        return $interval->d * 86400 + $interval->h * 3600 + $interval->i * 60 + $interval->s;
    }

    private static function query(string $query, string $key): ?string
    {
        parse_str($query, $values);

        return is_string($values[$key] ?? null) ? $values[$key] : null;
    }
}
