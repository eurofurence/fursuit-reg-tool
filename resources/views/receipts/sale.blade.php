<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt</title>
    <style>
        body {
            font-size: 9pt;
        }

        .receipt-container {
            margin: 0 auto;
            padding-left: 4mm;
            padding-right: 4mm;
        }

        h1 {
            text-align: center;
        }

        .details, .footer {
            margin-top: 20px;
        }

        .items {
            margin-top: 20px;
            border-top: 1px solid #000;
            border-bottom: 1px solid #000;
        }

        .items th, .items td {
            padding: 8px;
            text-align: left;
        }

        .items th {
            background-color: #f0f0f0;
        }

        .footer {
            border-top: 1px solid #000;
            padding-top: 20px;
        }

        table td {
            white-space: normal !important;
            word-break: break-word !important;
            word-wrap: break-word !important;
        }

    </style>
</head>
<body>
<div class="receipt-container">
    <div style="text-align:center;padding-bottom:3mm;">
        <h1>Eurofurence</h1>
        <div>
            <div>Eurofurence e.V.</div>
            <div>Am Kielshof 21a</div>
            <div>51105 Köln, Deutschland</div>
            <div>USt-IdNr-ID: DE219481694</div>
        </div>
        <div>Rechnung FSB-{{$checkout->created_at->year}}-{{ $checkout->id }}</div>
        <div>{{$checkout->created_at->format('d.m.Y')}}</div>
        <div>Bedient von: {{ $checkout->cashier->name }}</div>
        <div>Belegdatum: {{$checkout->created_at->format('d.m.Y')}}</div>
    </div>

    <div style="text-align:center">
        <div>============ POSITIONEN ============</div>
    </div>

    <!-- Itemized Positions -->
    <table width="100%">
        <thead>
        <tbody>
        @foreach($checkout->items as $item)
            <tr>
                <td>1x</td>
                <td>{{ $item->name }}</td>
                <td style="text-align:right;">{{ number_format($item->total / 100, 2, ',', '.') }} €</td>
            </tr>
            @foreach($item->description as $description)
                <tr>
                    <td></td>
                    <td>{{ $description }}</td>
                    <td style="text-align:right;"></td>
                </tr>
            @endforeach
        @endforeach
        <tr>
            <td colspan="3">-----------------------------------------</td>
        </tr>
        <!-- Gesamt -->
        <tr>
            <td colspan="2">Gesamt</td>
            <td style="text-align:right;">{{ number_format($checkout->total / 100, 2, ',', '.') }} €</td>
        </tr>
        <!-- MwSt -->
        <tr>
            <td colspan="2">davon 19% USt</td>
            <td style="text-align:right;">{{ number_format($checkout->tax / 100, 2, ',', '.') }} €</td>
        </tr>
        </tbody>
    </table>

    <div style="text-align:center; padding-top:5mm;">
        <div>========== ZAHLUNGSARTEN ==========</div>
    </div>
    <!-- Table for Payment Types -->
    <table width="100%">
        <tr>
            <td>{{ ($checkout->payment_method === 'cash') ? 'Barzahlung' : 'Kartenzahlung'  }}</td>
            <td style="text-align:right;">{{ number_format($checkout->total / 100, 2, ',', '.') }} €</td>
        </tr>
    </table>
    <div style="text-align:center; padding-top:5mm;">
        <div>=============== TSE ===============</div>
    </div>
    <!-- Center Image QR Code-->
    <div style="text-align:center; padding-top:5mm;">
        <img src="{{ $qr }}" alt="QR Code" style="width: 70%;">
    </div>

</div>
</body>
</html>
