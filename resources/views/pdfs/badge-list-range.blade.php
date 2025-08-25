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
            // Ensure we have exactly the number of columns requested (some may be empty)
            while (count($columnData) < $numColumns) {
                $columnData[] = [];
            }
            // Limit to the requested number of columns
            $columnData = array_slice($columnData, 0, $numColumns);
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
                                    
                                    // Calculate how many spaces needed (4 digit alignment)
                                    $spacesNeeded = 4 - strlen($firstPart);
                                    $padding = str_repeat('&nbsp;', $spacesNeeded);
                                    
                                    // Create the padded badge ID with non-breaking spaces
                                    $paddedBadgeId = $padding . $firstPart . $secondPart;
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
