<div class="range-section">
    <div class="range-header">
        {{ $range }} ({{ count($attendees) }} attendees)
    </div>

    @if(empty($attendees))
        <div class="no-data">No attendees in this range.</div>
    @else
        @php
            // Sort custom_ids numerically by parsing them
            $attendeeList = collect($attendees)->pluck('attendee_id')->sort(function ($a, $b) {
                // Parse custom_ids like "10-1", "104-1" for proper numeric sorting
                $partsA = explode('-', (string)$a);
                $partsB = explode('-', (string)$b);
                
                // Compare first part numerically
                $firstA = isset($partsA[0]) ? (int)$partsA[0] : 0;
                $firstB = isset($partsB[0]) ? (int)$partsB[0] : 0;
                
                if ($firstA !== $firstB) {
                    return $firstA - $firstB;
                }
                
                // If first parts are equal, compare second part
                $secondA = isset($partsA[1]) ? (int)$partsA[1] : 0;
                $secondB = isset($partsB[1]) ? (int)$partsB[1] : 0;
                
                return $secondA - $secondB;
            })->values()->toArray();
            $rowsPerColumn = $rowsPerColumn ?? 50;
            $numColumns = $columns ?? 12;
            $fontSize = $fontSize ?? 6;
            $columnData = array_chunk($attendeeList, $rowsPerColumn);
            // Never fewer columns than the list needs. This used to array_slice() the chunks
            // down to the requested column count, which dropped every badge past
            // rowsPerColumn x columns while the header above went on printing the full count.
            // The controller pages a range before it gets here, so this only has to hold when
            // the view is rendered directly.
            $numColumns = max($numColumns, count($columnData));
            // Ensure we have exactly the number of columns requested (some may be empty)
            while (count($columnData) < $numColumns) {
                $columnData[] = [];
            }
            $maxRows = max(array_map('count', $columnData));
        @endphp

        <table class="attendee-table" style="font-size: {{ $fontSize }}px;">
            @for($row = 0; $row < $maxRows; $row++)
                <tr>
                    @for($col = 0; $col < $numColumns; $col++)
                        <td class="attendee-cell" style="width: {{ 100 / $numColumns }}%;">
                            @if(isset($columnData[$col][$row]))
                                @php
                                    $badgeId = $columnData[$col][$row];
                                    // Split the badge ID to get the number before the dash
                                    $parts = explode('-', $badgeId);
                                    $firstPart = $parts[0] ?? '';
                                    $secondPart = isset($parts[1]) ? '-' . $parts[1] : '';
                                    
                                    // Calculate how many spaces needed (4 digit alignment).
                                    // Clamped: an attendee id longer than four characters made
                                    // this negative and str_repeat() throws on PHP 8, taking the
                                    // whole document with it.
                                    $spacesNeeded = max(0, 4 - strlen($firstPart));
                                    $padding = str_repeat('&nbsp;', $spacesNeeded);

                                    // Create the padded badge ID with non-breaking spaces. Only
                                    // the padding is markup; the id itself comes from the
                                    // registration service, so it is escaped.
                                    $paddedBadgeId = $padding . e($firstPart . $secondPart);
                                @endphp
                                {!! $paddedBadgeId !!}
                            @endif
                        </td>
                    @endfor
                </tr>
            @endfor
        </table>
    @endif
</div>
