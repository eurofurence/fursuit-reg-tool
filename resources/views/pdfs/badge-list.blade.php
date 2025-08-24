<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Badge List - {{ $event->name }}</title>
    <style>
        @page {
            margin: 20mm;
            size: A4;
        }
        
        body {
            font-family: Arial, sans-serif;
            font-size: 10px;
            line-height: 1.2;
            margin: 0;
            padding: 0;
        }
        
        .page-header {
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #000;
            padding-bottom: 10px;
        }
        
        .page-header h1 {
            font-size: 18px;
            margin: 0 0 5px 0;
            font-weight: bold;
        }
        
        .page-header h2 {
            font-size: 14px;
            margin: 0 0 5px 0;
            color: #666;
        }
        
        .range-section {
            margin-bottom: 30px;
            page-break-inside: avoid;
        }
        
        .range-header {
            background-color: #f0f0f0;
            padding: 8px;
            font-weight: bold;
            font-size: 12px;
            border: 1px solid #ccc;
            margin-bottom: 10px;
        }
        
        .badges-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9px;
        }
        
        .badges-table th {
            background-color: #e0e0e0;
            padding: 5px;
            text-align: left;
            border: 1px solid #ccc;
            font-weight: bold;
        }
        
        .badges-table td {
            padding: 4px 5px;
            border: 1px solid #ccc;
            vertical-align: top;
        }
        
        .badges-table tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        
        .attendee-id {
            font-weight: bold;
            width: 80px;
        }
        
        .attendee-name {
            width: 120px;
        }
        
        .badge-numbers {
            font-family: monospace;
            font-size: 8px;
        }
        
        .badge-count {
            color: #666;
            font-size: 8px;
        }
        
        .page-break {
            page-break-before: always;
        }
        
        .no-data {
            text-align: center;
            color: #666;
            font-style: italic;
            padding: 20px;
        }
    </style>
</head>
<body>
    <div class="page-header">
        <h1>Free Badge List</h1>
        <h2>{{ $event->name }}</h2>
        <p>Generated: {{ now()->format('Y-m-d H:i:s') }}</p>
    </div>

    @if(empty($groupedBadges))
        <div class="no-data">
            No free badges found for this event.
        </div>
    @else
        @foreach($groupedBadges as $range => $attendees)
            @if(!$loop->first)
                <div class="page-break"></div>
            @endif
            
            <div class="range-section">
                <div class="range-header">
                    Badge Range: {{ $range }}
                    <span style="float: right;">{{ count($attendees) }} attendee(s)</span>
                </div>
                
                @if(empty($attendees))
                    <div class="no-data">No badges in this range.</div>
                @else
                    <table class="badges-table">
                        <thead>
                            <tr>
                                <th class="attendee-id">Attendee ID</th>
                                <th class="attendee-name">Name</th>
                                <th class="badge-numbers">Badge Numbers</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($attendees as $attendeeData)
                                <tr>
                                    <td class="attendee-id">{{ $attendeeData['attendee_id'] }}</td>
                                    <td class="attendee-name">{{ $attendeeData['attendee_name'] }}</td>
                                    <td class="badge-numbers">
                                        {{ implode(', ', $attendeeData['badges']) }}
                                        <div class="badge-count">({{ count($attendeeData['badges']) }} badges)</div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        @endforeach
        
        <div style="margin-top: 40px; text-align: center; color: #666; font-size: 8px;">
            Total Ranges: {{ count($groupedBadges) }} | 
            Total Attendees: {{ collect($groupedBadges)->flatten(1)->count() }} |
            Total Badges: {{ collect($groupedBadges)->flatten(1)->sum(fn($a) => count($a['badges'])) }}
        </div>
    @endif
</body>
</html>