@php
    /** @var \App\Models\Broadcast|null $onAir */
    /** @var \Illuminate\Support\Carbon $now */
    $tz = config('hushfield.timezone');
    $length = fn (int $seconds): string => $seconds >= 3600 ? gmdate('G:i:s', $seconds) : gmdate('i:s', $seconds);
@endphp

<x-filament-panels::page>
    <div wire:poll.10s>
        <x-filament::section :description="'Times are Hushfield time (' . $tz . '). Last updated ' . $now->timezone($tz)->format('g:i:s A') . '.'">
            <x-slot name="heading">
                WHSH 106.1
                @if ($onAir)
                    <x-filament::badge color="danger" style="display: inline-flex; margin-inline-start: .5rem">On air</x-filament::badge>
                @else
                    <x-filament::badge color="gray" style="display: inline-flex; margin-inline-start: .5rem">Off air</x-filament::badge>
                @endif
            </x-slot>

            @if ($onAir)
                @php($elapsed = (int) $onAir->starts_at->diffInSeconds($now))
                @php($runs = (int) $onAir->starts_at->diffInSeconds($onAir->ends_at))

                <x-slot name="afterHeader">
                    {{ ($this->stopAction)(['broadcast' => $onAir->id]) }}
                </x-slot>

                <div style="display: flex; flex-direction: column; gap: .5rem">
                    <p style="font-weight: 600">{{ $onAir->title }}</p>
                    <p style="font-size: .875rem; opacity: .7">
                        {{ $length($elapsed) }} of {{ $length($runs) }}
                        · ends {{ $onAir->ends_at->timezone($tz)->format('g:i A') }}
                        · put on by {{ $onAir->scheduler?->username ?? '—' }}
                    </p>
                    <div style="height: .375rem; border-radius: 999px; background: rgba(127, 127, 127, .2); overflow: hidden">
                        <div style="height: 100%; width: {{ $runs > 0 ? min(100, round($elapsed / $runs * 100)) : 0 }}%; background: rgb(242, 184, 75)"></div>
                    </div>
                    {{-- To check what is airing; it plays from the start, not where the town is. --}}
                    <audio wire:key="broadcast-{{ $onAir->id }}" wire:ignore controls preload="none" src="{{ $onAir->url() }}" style="width: 100%; margin-top: .5rem"></audio>
                </div>
            @else
                <p style="font-size: .875rem; opacity: .7">
                    WHSH is off the air, and Augustin Moss has the ticker. Go on air to play an MP3 on every set in town.
                </p>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
