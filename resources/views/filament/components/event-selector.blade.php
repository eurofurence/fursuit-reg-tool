<div class="flex items-center gap-2 px-2">
    <label for="event-selector" class="text-sm font-medium text-gray-950 dark:text-white">
        Event:
    </label>
    <select 
        id="event-selector"
        class="fi-input block w-auto rounded-lg border-gray-300 shadow-sm transition duration-75 focus:border-primary-500 focus:ring-1 focus:ring-inset focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white sm:text-sm"
        onchange="window.location.href = updateQueryStringParameter(window.location.href, 'selected_event_id', this.value)"
    >
        @foreach($events as $event)
            <option value="{{ $event->id }}" @if($event->id == $selectedEventId) selected @endif>
                {{ $event->name }} ({{ $event->starts_at->format('Y') }})
                @if($event->id == $selectedEventId)
                    @if($event->allowsOrders())
                        ✓ Orders Open
                    @else
                        ✗ Orders Closed
                    @endif
                @endif
            </option>
        @endforeach
    </select>
</div>

<script>
function updateQueryStringParameter(uri, key, value) {
    var re = new RegExp("([?&])" + key + "=.*?(&|$)", "i");
    var separator = uri.indexOf('?') !== -1 ? "&" : "?";
    if (uri.match(re)) {
        return uri.replace(re, '$1' + key + "=" + value + '$2');
    } else {
        return uri + separator + key + "=" + value;
    }
}
</script>