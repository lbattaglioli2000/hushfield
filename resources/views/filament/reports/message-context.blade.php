@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\ChatMessage> $messages */
    $messages = $getState() ?? collect();
    $reportedId = $getRecord()?->chat_message_id;
@endphp

<x-dynamic-component :component="$getEntryWrapperView()" :entry="$entry">
    @if ($messages->isEmpty())
        <p style="opacity: .7">The reported message is no longer available.</p>
    @else
        <div style="display: flex; flex-direction: column; gap: 2px; font-size: .875rem">
            @foreach ($messages as $message)
                @php($isReported = $message->id === $reportedId)
                <div
                    @class(['hf-report-context-row', 'hf-reported' => $isReported])
                    style="display: grid; grid-template-columns: 5.5rem 9rem 1fr; gap: .75rem; padding: .375rem .625rem; border-radius: .375rem; {{ $isReported ? 'background: rgba(239, 68, 68, .15); outline: 2px solid rgb(239, 68, 68); font-weight: 600;' : '' }}"
                >
                    <span style="opacity: .6; font-variant-numeric: tabular-nums">{{ $message->created_at->format('H:i:s') }}</span>
                    <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap">{{ $message->user->username }}</span>
                    <span>
                        {{ $message->body }}
                        @if ($message->was_filtered)
                            <span style="opacity: .6; font-weight: 400"> (shown as “{{ $message->filtered_body }}”)</span>
                        @endif
                        @if ($isReported)
                            <span style="color: rgb(239, 68, 68); font-weight: 600"> ← reported</span>
                        @endif
                    </span>
                </div>
            @endforeach
        </div>
    @endif
</x-dynamic-component>
