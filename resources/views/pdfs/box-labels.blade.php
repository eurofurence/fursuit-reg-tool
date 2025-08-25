<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Box Label - {{ $title }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            height: 84mm;
            width: 200mm;
        }

        .border {
            height: 84mm;
            width: 200mm;
            box-sizing: border-box;
            display: table;
        }

        .label-content {
            display: table-cell;
            vertical-align: middle;
            text-align: center;
            padding: 8mm 15mm 10mm 15mm; /* top right bottom left - reduced top padding by 3mm */
        }

        .label-title {
            font-size: 72pt;
            font-weight: bold;
            margin: 0 auto 8mm auto;
            color: #000;
            line-height: 1.2;
            text-align: center;
            display: block;
        }

        .label-subtitle {
            font-size: 40pt;
            color: #666;
            margin: 0 auto;
            line-height: 1.2;
            text-align: center;
            display: block;
        }
    </style>
</head>
<body>
    <div class="border">
        <div class="label-content">
            <div class="label-title">{{ $title }}</div>
            @if(!empty($subtitle))
                <div class="label-subtitle">{{ $subtitle }}</div>
            @endif
        </div>
    </div>
</body>
</html>
