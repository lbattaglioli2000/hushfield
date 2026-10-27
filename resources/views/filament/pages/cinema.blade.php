@php
    /** @var \App\Models\Screening|null $showing */
    /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\Screening> $queued */
    /** @var bool $open */
    /** @var \Illuminate\Support\Carbon $now */
    $tz = config('hushfield.timezone');
    $cell = 'padding: .5rem .75rem; text-align: left; border-top: 1px solid rgba(127, 127, 127, .2);';
    $length = fn (int $seconds): string => $seconds >= 3600 ? gmdate('G:i:s', $seconds) : gmdate('i:s', $seconds);
    $link = fn (\App\Models\Screening $screening): string => 'https://www.youtube.com/watch?v=' . $screening->video_id;
@endphp

<x-filament-panels::page>
    <div wire:poll.10s style="display: flex; flex-direction: column; gap: 1.5rem">
        @unless ($open)
            <x-filament::section>
                <x-slot name="heading">
                    The screen is switched off
                    <x-filament::badge color="danger" style="display: inline-flex; margin-inline-start: .5rem">Off</x-filament::badge>
                </x-slot>
                <p style="font-size: .875rem; opacity: .7">
                    The VFA screen kill switch is off, so every screen is dark. The programme below keeps running on paper; turn the switch back on under Settings → Feature flags.
                </p>
            </x-filament::section>
        @endunless

        <x-filament::section :description="'Times are Hushfield time (' . $tz . '). Last updated ' . $now->timezone($tz)->format('g:i:s A') . '.'">
            <x-slot name="heading">Now showing</x-slot>

            @if ($showing)
                @php($elapsed = (int) $showing->starts_at->diffInSeconds($now))
                @php($runs = (int) $showing->starts_at->diffInSeconds($showing->ends_at))

                <x-slot name="afterHeader">
                    {{ ($this->cancelAction)(['screening' => $showing->id]) }}
                </x-slot>

                <div style="display: flex; flex-direction: column; gap: .5rem">
                    <a href="{{ $link($showing) }}" target="_blank" rel="noopener" style="font-weight: 600">{{ $showing->title }}</a>
                    <p style="font-size: .875rem; opacity: .7">
                        {{ $showing->channel ?? 'Unknown channel' }} · {{ $length($elapsed) }} of {{ $length($runs) }}
                        · ends {{ $showing->ends_at->timezone($tz)->format('g:i A') }}
                        @if ($showing->made_for_kids) · made for kids @endif
                    </p>
                    <div style="height: .375rem; border-radius: 999px; background: rgba(127, 127, 127, .2); overflow: hidden">
                        <div style="height: 100%; width: {{ $runs > 0 ? min(100, round($elapsed / $runs * 100)) : 0 }}%; background: rgb(242, 184, 75)"></div>
                    </div>
                </div>
            @else
                <p style="font-size: .875rem; opacity: .7">
                    Nothing is showing. {{ $queued->isEmpty() ? 'Queue a film, or play one now.' : 'The next film starts at ' . $queued->first()->starts_at->timezone($tz)->format('g:i A') . '.' }}
                </p>
            @endif
        </x-filament::section>

        <x-filament::section :description="config('hushfield.cinema.intermission_seconds') . ' second intermission between films.'">
            <x-slot name="heading">Up next</x-slot>

            @if ($queued->isEmpty())
                <p style="font-size: .875rem; opacity: .7">The queue is empty. When the film ends, the screen shows the VFA's title card.</p>
            @else
                <table style="width: 100%; font-size: .875rem; border-collapse: collapse">
                    <thead>
                        <tr>
                            <th style="{{ $cell }} border-top: 0">Starts</th>
                            <th style="{{ $cell }} border-top: 0">Film</th>
                            <th style="{{ $cell }} border-top: 0">Length</th>
                            <th style="{{ $cell }} border-top: 0">Queued by</th>
                            <th style="{{ $cell }} border-top: 0; text-align: right"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($queued as $screening)
                            <tr wire:key="screening-{{ $screening->id }}">
                                <td style="{{ $cell }} font-variant-numeric: tabular-nums; white-space: nowrap">{{ $screening->starts_at->timezone($tz)->format('g:i A') }}</td>
                                <td style="{{ $cell }}">
                                    <a href="{{ $link($screening) }}" target="_blank" rel="noopener">{{ $screening->title }}</a>
                                    <span style="opacity: .7">· {{ $screening->channel ?? 'Unknown channel' }}@if ($screening->made_for_kids) · made for kids @endif</span>
                                </td>
                                <td style="{{ $cell }} font-variant-numeric: tabular-nums">{{ $length($screening->duration_seconds) }}</td>
                                <td style="{{ $cell }} opacity: .7">{{ $screening->scheduler?->username ?? '—' }}</td>
                                <td style="{{ $cell }} text-align: right">{{ ($this->cancelAction)(['screening' => $screening->id]) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
