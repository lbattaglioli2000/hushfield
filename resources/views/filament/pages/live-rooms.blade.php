@php
    /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\Room> $rooms */
    /** @var array<int, int> $counts */
    $cell = 'padding: .5rem .75rem; text-align: left; border-top: 1px solid rgba(127, 127, 127, .2);';
@endphp

<x-filament-panels::page>
    <div wire:poll.5s style="display: flex; flex-direction: column; gap: 1.5rem">
        <p style="font-size: .875rem; opacity: .7">
            Occupancy is read from Redis and refreshes every 5 seconds. Last updated {{ now()->format('H:i:s') }}.
        </p>

        @forelse ($rooms as $room)
            @php($total = $room->instances->sum(fn ($instance) => $counts[$instance->id] ?? 0))

            <x-filament::section :description="$room->slug . ' · ' . $room->capacity_per_instance . ' per instance' . ($room->is_active ? '' : ' · inactive')">
                <x-slot name="heading">
                    {{ $room->name }}
                    <x-filament::badge :color="$total > 0 ? 'success' : 'gray'" style="display: inline-flex; margin-inline-start: .5rem">
                        {{ $total }} {{ str('player')->plural($total) }}
                    </x-filament::badge>
                </x-slot>

                <x-slot name="afterHeader">
                    {{ ($this->gatherAction)(['room' => $room->id]) }}
                </x-slot>

                @if ($room->instances->isEmpty())
                    <p style="font-size: .875rem; opacity: .7">No instances yet. One opens when the first player joins.</p>
                @else
                    <table style="width: 100%; font-size: .875rem; border-collapse: collapse">
                        <thead>
                            <tr>
                                <th style="{{ $cell }} border-top: 0">Instance</th>
                                <th style="{{ $cell }} border-top: 0">Status</th>
                                <th style="{{ $cell }} border-top: 0">Occupancy</th>
                                <th style="{{ $cell }} border-top: 0">Empty since</th>
                                <th style="{{ $cell }} border-top: 0; text-align: right"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($room->instances as $instance)
                                @php($count = $counts[$instance->id] ?? 0)
                                <tr wire:key="instance-{{ $instance->id }}">
                                    <td style="{{ $cell }}">#{{ $instance->number }}</td>
                                    <td style="{{ $cell }}">
                                        <x-filament::badge :color="$instance->is_open ? 'success' : 'gray'" style="display: inline-flex">
                                            {{ $instance->is_open ? 'Open' : 'Closed' }}
                                        </x-filament::badge>
                                    </td>
                                    <td style="{{ $cell }} font-variant-numeric: tabular-nums">
                                        {{ $count }} / {{ $room->capacity_per_instance }}
                                    </td>
                                    <td style="{{ $cell }} opacity: .7">{{ $instance->empty_since?->diffForHumans() ?? '—' }}</td>
                                    <td style="{{ $cell }} text-align: right">
                                        @if ($instance->is_open)
                                            {{ ($this->closeInstanceAction)(['instance' => $instance->id]) }}
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </x-filament::section>
        @empty
            <x-filament::section>
                <p style="font-size: .875rem; opacity: .7">No rooms have been imported yet.</p>
            </x-filament::section>
        @endforelse
    </div>
</x-filament-panels::page>
