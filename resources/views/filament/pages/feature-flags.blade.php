@php
    /** @var list<array{class: string, label: string, description: string, active: bool}> $flags */
@endphp

<x-filament-panels::page>
    <div style="display: flex; flex-direction: column; gap: 1.5rem">
        @foreach ($flags as $flag)
            <x-filament::section :description="$flag['description']" wire:key="flag-{{ $flag['class'] }}">
                <x-slot name="heading">
                    {{ $flag['label'] }}
                    <x-filament::badge :color="$flag['active'] ? 'success' : 'danger'" style="display: inline-flex; margin-inline-start: .5rem">
                        {{ $flag['active'] ? 'On' : 'Off' }}
                    </x-filament::badge>
                </x-slot>

                <x-slot name="afterHeader">
                    {{ ($this->toggleAction)(['flag' => $flag['class']]) }}
                </x-slot>
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
