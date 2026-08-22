{{--
    One template for every badge mail an attendee receives.

    The shape was chosen in review with the badge team (see the layout matrix artifact): a coloured
    status band saying what happened, a headline in plain words, then the questions an attendee
    actually asks, each answered in one line. Before this, all seven mails were an undifferentiated
    stack of paragraphs in which the verdict, the reason and the "do not reply" boilerplate carried
    equal weight.

    Rules baked in here rather than left to each notification:

      - The fursuit name lives in the subject only, and quoted. An attendee's name can be full of
        punctuation, which derails a sentence but survives quotes fine.
      - One action button, and only when there is something to do.
      - No amounts. Prices go stale in an inbox; the mail says "bring a card" and the badge page
        carries the number.
      - Nothing claims a badge is on a printer or that another mail follows: late orders are printed
        on demand at the desk, so neither is reliably true.

    @param string      $greeting  "Hi {name}!"
    @param string|null $band      Short status, e.g. "Approved"; null prints no band at all
    @param string      $tone      ok | warn | stop | info
    @param string      $headline  One sentence, plain words
    @param array       $answers   [['q' => ..., 'a' => ...], ...]; an entry may also carry 'hours',
                                  which prints the desk's opening days as a list under its answer
    @param string|null $finding   The reviewer's own sentence, quoted apart from the prose
    @param array|null  $action    ['label' => ..., 'url' => ...]
    @param string|null $note      Optional closing line above the footer
    @param string      $pickupUrl Public pickup page
    @param string|null $eventName For the footer's "why am I getting this"
--}}
<x-mail::message>
@if ($band)
<x-mail::band :tone="$tone">{{ $band }}</x-mail::band>
@endif

{{ $greeting }}

## {{ $headline }}

@foreach ($answers as $answer)
**{{ $answer['q'] }}**
@if (!empty($answer['a']))
{{ $answer['a'] }}
@endif
@if (!empty($answer['hours']))

@foreach ($answer['hours'] as $row)
- {{ \Carbon\CarbonImmutable::parse($row['date'])->format('D, j M') }}@if (!empty($row['today'])) (today)@endif: {{ $row['opens'] }} &ndash; {{ $row['closes'] }}@if ($row['note']) &mdash; {{ $row['note'] }}@endif

@endforeach

@endif

@endforeach
@if ($finding)
<x-mail::panel>
**What we found**
{{ $finding }}
</x-mail::panel>
@endif
@isset($action)
<x-mail::button :url="$action['url']">{{ $action['label'] }}</x-mail::button>
@endisset
@if ($note)
{{ $note }}
@endif
<x-mail::subcopy>
Pickup times: [{{ $pickupUrl }}]({{ $pickupUrl }})
@if ($eventName)

You are receiving this because you ordered a fursuit badge for {{ $eventName }}.
@endif

Questions? fursuit-team@eurofurence.org - replies to this address are not read.
</x-mail::subcopy>
</x-mail::message>
