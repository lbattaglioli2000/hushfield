@php
    /** @var list<\App\Features\WorldFlag> $flags */
@endphp

<x-filament-panels::page>
    <div style="display: flex; flex-direction: column; gap: 1.5rem">
        @foreach ($flags as $flag)
            @php($active = $flag->isActive())

            <x-filament::section :description="$flag->description()" wire:key="flag-{{ $flag->name }}">
                <x-slot name="heading">
                    {{ $flag->getLabel() }}
                    <x-filament::badge :color="$active ? 'success' : 'danger'" style="display: inline-flex; margin-inline-start: .5rem">
                        {{ $active ? 'On' : 'Off' }}
                    </x-filament::badge>
                </x-slot>

                <x-slot name="afterHeader">
                    {{ ($this->toggleAction)(['flag' => $flag->value, 'active' => ! $active]) }}
                </x-slot>
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
