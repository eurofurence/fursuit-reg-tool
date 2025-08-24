<div class="range-section">
    <div class="range-header">
        {{ $range }} ({{ count($attendees) }} attendees)
    </div>

    @if(empty($attendees))
        <div class="no-data">No attendees in this range.</div>
    @else
        @php
            $attendeeList = collect($attendees)->pluck('attendee_id')->sort()->values()->toArray();
            $rowsPerColumn = $rowsPerColumn ?? 50;
            $fontSize = $fontSize ?? 6;
            $columns = array_chunk($attendeeList, $rowsPerColumn);
            $numColumns = count($columns);
            $maxRows = max(array_map('count', $columns));
        @endphp

        <table class="attendee-table" style="font-size: {{ $fontSize }}px;">
            @for($row = 0; $row < $maxRows; $row++)
                <tr>
                    @for($col = 0; $col < $numColumns; $col++)
                        <td class="attendee-cell">
                            @if(isset($columns[$col][$row]))
                                {{ $columns[$col][$row] }}
                            @endif
                        </td>
                    @endfor
                </tr>
            @endfor
        </table>
    @endif
</div>
