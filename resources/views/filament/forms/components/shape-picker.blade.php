@php
    $wide = max(1, min(8, $getWide()));
    $deep = max(1, min(8, $getDeep()));
    // 2:1 diamonds as in the game, covered tiles raised as blocks.
    [$half, $quarter, $lift] = [40, 20, 14];
    [$padX, $padTop, $padBottom] = [64, $lift + 34, 44];
    $width = ($wide + $deep) * $half + 2 * $padX;
    $height = ($wide + $deep) * $quarter + $padTop + $padBottom;
    $ox = $padX + $deep * $half;
    $point = fn (float $x, float $y, int $up = 0): array => [$ox + ($x - $y) * $half, $padTop + ($x + $y) * $quarter - $up];
    $poly = fn (array ...$points): string => implode(' ', array_map(fn (array $p): string => $p[0].','.$p[1], $points));
    $tiles = [];

    for ($y = 0; $y < $deep; $y++) {
        for ($x = 0; $x < $wide; $x++) {
            $tiles[] = ['key' => "{$x},{$y}", 'x' => $x, 'y' => $y];
        }
    }

    // Back to front, so nearer blocks are drawn over farther ones.
    usort($tiles, fn (array $a, array $b): int => ($a['x'] + $a['y']) <=> ($b['x'] + $b['y']));
    $mid = fn (array $a, array $b): array => [($a[0] + $b[0]) / 2, ($a[1] + $b[1]) / 2];
    $front = $mid($point(0, $deep), $point($wide, $deep));
    $alongX = $mid($point(0, 0), $point($wide, 0));
    $alongY = $mid($point(0, 0), $point(0, $deep));
    // Labels sit out from the middle of the back edges, square to them.
    [$nx, $ny] = [0.447 * 24, 0.894 * 24];
    $xLabel = [$alongX[0] + $nx, $alongX[1] - $lift - $ny];
    $yLabel = [$alongY[0] - $nx, $alongY[1] - $lift - $ny];
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        wire:key="shape-picker-{{ $wide }}x{{ $deep }}"
        x-data="{
            state: $wire.$entangle(@js($getStatePath())),
            has(key) { return Array.isArray(this.state) && this.state.includes(key) },
            toggle(key) {
                const ticked = Array.isArray(this.state) ? this.state : []
                this.state = this.has(key) ? ticked.filter((k) => k !== key) : [...ticked, key]
            },
        }"
        class="hf-shape"
        {{ $getExtraAttributeBag() }}
    >
        <style>
            .hf-shape svg { display: block; max-width: 100%; height: auto; user-select: none; }
            .hf-shape .floor { fill: var(--gray-100); stroke: var(--gray-400); stroke-dasharray: 4 3; }
            .hf-shape .top { fill: var(--primary-400); stroke: var(--primary-700); }
            .hf-shape .lit { fill: var(--primary-500); stroke: var(--primary-700); }
            .hf-shape .shade { fill: var(--primary-700); stroke: var(--primary-800); }
            .hf-shape .tile { cursor: pointer; outline: none; }
            .hf-shape .tile:hover .floor { fill: var(--primary-100); stroke: var(--primary-500); }
            .hf-shape .tile:hover .top { fill: var(--primary-300); }
            .hf-shape .tile:focus-visible polygon { stroke: var(--gray-950); stroke-width: 2; stroke-dasharray: none; }
            .hf-shape .front { stroke: var(--primary-600); stroke-width: 4; stroke-linecap: round; }
            .hf-shape text { font-size: 12px; fill: var(--gray-500); }
            .hf-shape text.strong { fill: var(--primary-700); font-weight: 600; }
            .dark .hf-shape .floor { fill: var(--gray-800); stroke: var(--gray-500); }
            .dark .hf-shape .tile:hover .floor { fill: var(--gray-700); stroke: var(--primary-400); }
            .dark .hf-shape .tile:focus-visible polygon { stroke: white; }
            .dark .hf-shape text { fill: var(--gray-400); }
            .dark .hf-shape text.strong { fill: var(--primary-400); }
            .dark .hf-shape .front { stroke: var(--primary-400); }
        </style>

        <svg
            viewBox="0 0 {{ $width }} {{ $height }}"
            width="{{ $width }}"
            role="group"
            aria-label="Tiles the piece covers"
        >
            <text
                x="{{ $xLabel[0] }}" y="{{ $xLabel[1] }}" text-anchor="middle"
                transform="rotate(26.57 {{ $xLabel[0] }} {{ $xLabel[1] }})"
            >{{ $wide }} wide (x) →</text>
            <text
                x="{{ $yLabel[0] }}" y="{{ $yLabel[1] }}" text-anchor="middle"
                transform="rotate(-26.57 {{ $yLabel[0] }} {{ $yLabel[1] }})"
            >← {{ $deep }} deep (y)</text>

            @foreach ($tiles as $tile)
                @php
                    [$x, $y] = [$tile['x'], $tile['y']];
                    [$top, $right, $bottom, $left] = [$point($x, $y), $point($x + 1, $y), $point($x + 1, $y + 1), $point($x, $y + 1)];
                    $up = fn (array $p): array => [$p[0], $p[1] - $lift];
                @endphp

                <g
                    class="tile"
                    role="checkbox"
                    tabindex="0"
                    aria-label="Tile {{ $x }}, {{ $y }}"
                    x-bind:aria-checked="has(@js($tile['key'])) ? 'true' : 'false'"
                    x-on:click="toggle(@js($tile['key']))"
                    x-on:keydown.space.prevent="toggle(@js($tile['key']))"
                    x-on:keydown.enter.prevent="toggle(@js($tile['key']))"
                >
                    <title>Tile {{ $x }}, {{ $y }}</title>
                    <polygon class="floor" points="{{ $poly($top, $right, $bottom, $left) }}" x-show="! has(@js($tile['key']))" />
                    <g x-show="has(@js($tile['key']))">
                        <polygon class="lit" points="{{ $poly($up($left), $up($bottom), $bottom, $left) }}" />
                        <polygon class="shade" points="{{ $poly($up($bottom), $up($right), $right, $bottom) }}" />
                        <polygon class="top" points="{{ $poly($up($top), $up($right), $up($bottom), $up($left)) }}" />
                    </g>
                </g>
            @endforeach

            @php
                [$fromX, $fromY] = $point(0, $deep);
                [$toX, $toY] = $point($wide, $deep);
            @endphp
            <line class="front" x1="{{ $fromX - 4 }}" y1="{{ $fromY + 6 }}" x2="{{ $toX - 4 }}" y2="{{ $toY + 6 }}" />
            <text
                class="strong" x="{{ $front[0] - 18 }}" y="{{ $front[1] + 26 }}" text-anchor="middle"
                transform="rotate(26.57 {{ $front[0] - 18 }} {{ $front[1] + 26 }})"
            >front</text>
        </svg>
    </div>
</x-dynamic-component>
