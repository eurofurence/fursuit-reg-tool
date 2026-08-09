{{--
    The status band: the first thing an attendee reads, and the reason these mails stopped being a
    wall of paragraphs. `tone` is one of ok / warn / stop / info and only sets colour; the label
    carries the meaning, so a client that strips colour still reads correctly.

    Colours are inline because mail clients drop <style> blocks, and they are the same values the
    panel uses in the admin: EF navy #1f3c86 with green / amber / red for the three outcomes.
--}}
@props(['tone' => 'info'])

@php
    $palette = [
        'ok' => ['bg' => '#e3f4ee', 'fg' => '#0f6f52', 'line' => '#bfe4d7'],
        'warn' => ['bg' => '#fdf1dc', 'fg' => '#8a5a00', 'line' => '#f0dcb4'],
        'stop' => ['bg' => '#fbe6e3', 'fg' => '#a32a1f', 'line' => '#f0c9c3'],
        'info' => ['bg' => '#eaeffb', 'fg' => '#1f3c86', 'line' => '#ccd8f2'],
    ];

    $colors = $palette[$tone] ?? $palette['info'];
@endphp

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin-bottom: 18px;">
<tr>
<td style="background-color: {{ $colors['bg'] }}; border: 1px solid {{ $colors['line'] }}; border-radius: 3px; padding: 11px 16px; color: {{ $colors['fg'] }}; font-size: 13px; font-weight: bold; letter-spacing: 0.03em; text-transform: uppercase; font-family: Arial, Helvetica, sans-serif;">
{{ $slot }}
</td>
</tr>
</table>
