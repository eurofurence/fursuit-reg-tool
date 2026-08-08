<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\PdfGeneratorRequest;
use App\Models\Badge\Badge;
use App\Models\Badge\State_Payment\Paid;
use App\Models\Badge\State_Payment\Unpaid;
use App\Support\Manage\EventScope;
use App\Support\Manage\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Response;
use Mpdf\HTMLParserMode;
use Mpdf\Mpdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * PDF Generator, the successor to App\Filament\Pages\PdfGenerator (audit 5.1).
 *
 * Two PDFs over the same form: a badge list, grouped by range and one range per page,
 * and a box label. Both are reads. Nothing on this controller writes a row, queues a job
 * or touches a file; the only output is a stream.
 *
 * Both downloads are GET routes rather than posted actions. A download is a read, and a
 * plain GET is the one shape a browser can answer by saving a file while leaving the page
 * it was opened from alone - which is also what makes the failure path work: every one of
 * the five parity notifications redirects back to the page with a flashed toast, so a
 * refused generation lands the operator back where they were rather than on a blank tab.
 *
 * Four things the plan fixes rather than ports:
 *
 *  - the event comes from App\Support\Manage\EventScope, the one event filter. The
 *    Filament page read `session('filament.admin.selected_event_id')`, a key nothing ever
 *    writes - FilamentEventSelector writes `filament_selected_event_id` - so the header
 *    selection has never reached this page and it has always silently used the newest
 *    event (plan 2.9, audit 63). The `No event selected in the header.` notification was
 *    dead code for the same reason and is now reachable, meaning what it says;
 *  - the badge-list filename is slugged. `$selectedEvent->name` went straight into
 *    `Content-Disposition` (PdfGenerator.php:308) and an event name is free-text admin
 *    input, so a quote, a slash or a newline broke or injected into the header. The
 *    box-label filename already used `Str::slug()`; now both do (plan 2.10 #31, audit 15);
 *  - badges numbered outside every configured range are reported instead of dropped. The
 *    default ranges stop at 4999 and everything above it silently vanished from the PDF
 *    (plan 2.10 #33, audit 47). See `groupBadges()`;
 *  - a range holding more badges than one page fits is paged instead of truncated. The
 *    view kept the first `rows_per_column * columns` numbers and threw the rest away under
 *    a header that had counted them all (plan 2.10 #74). See `paginateSections()`.
 *
 * `pdfs/badge-list-range.blade.php` is the one blade this module changes, in the two
 * places that lost or refused data: the truncating slice above, and a `str_repeat()` with
 * a negative count that killed the whole document on any attendee id longer than four
 * characters (plan 2.10 #74).
 */
class PdfGeneratorController extends Controller
{
    /** `mount()`'s default range list, verbatim. */
    public const DEFAULT_RANGES = '0-999,1000-1999,2000-2999,3000-3999,4000-4999';

    public const DEFAULT_ROWS_PER_COLUMN = 50;

    public const DEFAULT_COLUMNS = 12;

    public const DEFAULT_FONT_SIZE = 6;

    /**
     * The heading the leftover badges are listed under, rendered by the same
     * `pdfs.badge-list-range` view as every other section, so the count reads back as
     * `Outside configured ranges (n attendees)` (plan 2.10 #33).
     */
    public const OUT_OF_RANGE_LABEL = 'Outside configured ranges';

    /**
     * The same count on the response, so a caller that never opens the PDF - a monitor, a
     * test, an operator reading the network tab - can still see that the range list did
     * not cover every badge.
     */
    public const OUT_OF_RANGE_HEADER = 'X-Badges-Out-Of-Range';

    /**
     * What a range's second and later pages are headed with, so a page carries the range it
     * belongs to and still counts only the badges printed on it (plan 2.10 #74).
     */
    public const CONTINUED_SUFFIX = '(continued)';

    /** A4 portrait for the badge list, `[210, 94]` for a box label. */
    private const BADGE_LIST_FORMAT = 'A4';

    private const BOX_LABEL_FORMAT = [210, 94];

    /** One margin on all four sides, for both documents. */
    private const MARGIN = 5;

    /**
     * The form. Options and defaults are server-declared, the way every other choice in
     * the panel is, so the parity suite can assert on the copy.
     */
    public function index(EventScope $scope): Response
    {
        return inertia('Manage/Tools/PdfGenerator', [
            'event' => $scope->event()?->only(['id', 'name']),
            'pdfTypes' => [
                ['value' => 'badge_list', 'label' => 'Badge List (Badges by Range)'],
                /*
                 * Filament said `Box Labels (3 per A4 page)`. The code renders exactly one
                 * label on a 210x94mm page and always has (plan 2.10 #32, audit 45).
                 */
                ['value' => 'box_labels', 'label' => 'Box Labels (1 per page)'],
            ],
            'paymentStatuses' => [
                ['value' => 'all', 'label' => 'All Badges'],
                ['value' => 'paid', 'label' => 'Paid Badges Only'],
                ['value' => 'unpaid', 'label' => 'Unpaid Badges Only'],
            ],
            'defaults' => [
                'pdf_type' => 'badge_list',
                'payment_status' => 'all',
                'badge_ranges' => self::DEFAULT_RANGES,
                'title' => '',
                'subtitle' => '',
                'rows_per_column' => self::DEFAULT_ROWS_PER_COLUMN,
                'columns' => self::DEFAULT_COLUMNS,
                'font_size' => self::DEFAULT_FONT_SIZE,
            ],
        ]);
    }

    /**
     * `generateBadgeListPdf()`, with its four notifications verbatim and in source order.
     */
    public function badgeList(PdfGeneratorRequest $request, EventScope $scope): StreamedResponse|RedirectResponse
    {
        $event = $scope->event();

        if ($event === null) {
            Toast::flashDanger('Error', 'No event selected in the header.');

            return $this->backToPage();
        }

        $paymentStatus = $request->validated('payment_status') ?? 'all';
        $badges = $this->badges($scope, $paymentStatus);

        if ($badges->isEmpty()) {
            $filterText = match ($paymentStatus) {
                'paid' => 'paid badges',
                'unpaid' => 'unpaid badges',
                default => 'badges'
            };

            Toast::flashWarning('No Data', "No {$filterText} found for the current event.");

            return $this->backToPage();
        }

        /*
         * An empty range list is not a parse failure: Filament only parsed when the field
         * had a value and otherwise fell back to computed 1000-wide buckets, which is the
         * one path where no badge can be out of range. That fallback is kept as it was.
         *
         * Absent and empty are the same thing here, because ConvertEmptyStringsToNull
         * turns `?badge_ranges=` into null before the request is validated. The field is
         * `->required()` and prefilled with DEFAULT_RANGES on the form, so either shape
         * means a hand-built URL rather than a submission.
         */
        $customRanges = [];
        $rangesInput = trim((string) ($request->validated('badge_ranges') ?? ''));

        if ($rangesInput !== '') {
            $customRanges = $this->parseRanges($rangesInput);

            if (empty($customRanges)) {
                Toast::flashDanger('Invalid Range Format', 'Please enter valid badge ranges in the format: 1-1699,1700-2400');

                return $this->backToPage();
            }
        }

        ['grouped' => $grouped, 'outOfRange' => $outOfRange] = $this->groupBadges($badges, $customRanges);

        if (empty($grouped)) {
            Toast::flashWarning('No Badges in Ranges', 'No badges found within the specified ranges. Please check your range settings.');

            return $this->backToPage();
        }

        $mpdf = $this->mpdf(self::BADGE_LIST_FORMAT);

        $mpdf->WriteHTML($this->utf8(view('pdfs.badge-list-css')->render()), HTMLParserMode::HEADER_CSS);
        $mpdf->WriteHTML($this->utf8(view('pdfs.badge-list-header', ['event' => $event])->render()), HTMLParserMode::HTML_BODY);

        $sections = $this->orderSections($grouped, $customRanges);

        /*
         * The leftovers go last, as their own page. Filament dropped them on the floor;
         * this is the whole of plan 2.10 #33: the operator gets the same PDF plus one
         * page saying which badges the range list did not cover.
         */
        if ($outOfRange !== []) {
            $sections[] = ['range' => self::OUT_OF_RANGE_LABEL, 'attendees' => $outOfRange];
        }

        $rowsPerColumn = (int) ($request->validated('rows_per_column') ?? self::DEFAULT_ROWS_PER_COLUMN);
        $columns = (int) ($request->validated('columns') ?? self::DEFAULT_COLUMNS);
        $fontSize = (int) ($request->validated('font_size') ?? self::DEFAULT_FONT_SIZE);

        $sections = self::paginateSections($sections, $rowsPerColumn, $columns);

        $isFirst = true;

        foreach ($sections as $section) {
            if (! $isFirst) {
                $mpdf->AddPage();
            }

            $isFirst = false;

            $mpdf->WriteHTML($this->utf8(view('pdfs.badge-list-range', [
                'range' => $section['range'],
                'attendees' => $section['attendees'],
                'rowsPerColumn' => $rowsPerColumn,
                'columns' => $columns,
                'fontSize' => $fontSize,
            ])->render()), HTMLParserMode::HTML_BODY);
        }

        $suffix = match ($paymentStatus) {
            'paid' => '-paid',
            'unpaid' => '-unpaid',
            default => ''
        };

        return $this->stream(
            $mpdf,
            'badge-list-'.$this->slug($event->name).$suffix.'-'.now()->format('Y-m-d').'.pdf',
            [self::OUT_OF_RANGE_HEADER => (string) count($outOfRange)],
        );
    }

    /**
     * `generateBoxLabelsPdf()`. One label, one page, one configurable title and subtitle.
     */
    public function boxLabels(PdfGeneratorRequest $request): StreamedResponse|RedirectResponse
    {
        $title = (string) ($request->validated('title') ?? '');
        $subtitle = (string) ($request->validated('subtitle') ?? '');

        if ($title === '') {
            Toast::flashDanger('Error', 'Title is required for box labels.');

            return $this->backToPage();
        }

        /*
         * `mb_convert_encoding($v, 'UTF-8', 'UTF-8')` on the title and subtitle was a
         * no-op, not sanitization, and the blade escapes both anyway. The document-level
         * check below is the one that did something and it stays.
         */
        $html = view('pdfs.box-labels', ['title' => $title, 'subtitle' => $subtitle])->render();

        if (! mb_check_encoding($html, 'UTF-8')) {
            $html = mb_convert_encoding($html, 'UTF-8', 'auto');
        }

        $mpdf = $this->mpdf(self::BOX_LABEL_FORMAT);
        $mpdf->WriteHTML($this->withBoxLabelGeometry($html));

        return $this->stream($mpdf, 'box-label-'.$this->slug($title).'-'.now()->format('Y-m-d').'.pdf');
    }

    /**
     * Sections to pages. One page holds `rows_per_column * columns` badge numbers, and a
     * range holding more than that has to become more than one page.
     *
     * Without this the view took the overflow: it chunked the list into columns of
     * `rows_per_column` and then sliced the chunks down to `columns`, so with the shipped
     * defaults a 1000-wide range printed its first 600 numbers and dropped the rest, under
     * a header that had already counted all of them. Nothing reported the loss - the
     * badges were inside a declared range, so `OUT_OF_RANGE_HEADER` counted zero (plan
     * 2.10 #74).
     *
     * Later pages of the same range keep the range in their heading and carry
     * `CONTINUED_SUFFIX`, so the count each page prints is the count of what is on it.
     *
     * @param  array<int, array{range: string, attendees: array<int, array{attendee_id: string, sort_key: array<int, int>}>}>  $sections
     * @return array<int, array{range: string, attendees: array<int, array{attendee_id: string, sort_key: array<int, int>}>}>
     */
    public static function paginateSections(array $sections, int $rowsPerColumn, int $columns): array
    {
        $capacity = max(1, $rowsPerColumn * $columns);
        $pages = [];

        foreach ($sections as $section) {
            $chunks = array_chunk($section['attendees'], $capacity);

            // A section with no attendees still gets its page and its empty-state line.
            if ($chunks === []) {
                $pages[] = $section;

                continue;
            }

            foreach ($chunks as $index => $chunk) {
                $pages[] = [
                    'range' => $index === 0 ? $section['range'] : $section['range'].' '.self::CONTINUED_SUFFIX,
                    'attendees' => $chunk,
                ];
            }
        }

        return $pages;
    }

    /**
     * The printable area of a box label in millimetres, derived from the page format and
     * the margins rather than restated (plan 2.10 #32).
     *
     * @return array{float, float} width, height
     */
    public static function boxLabelContentSize(): array
    {
        [$width, $height] = self::BOX_LABEL_FORMAT;

        return [(float) ($width - 2 * self::MARGIN), (float) ($height - 2 * self::MARGIN)];
    }

    /**
     * The badges of the selected event, in badge-number order.
     *
     * The scope does the `whereHas('fursuit', event_id)` the page did by hand, so this
     * page and every list in the panel ask the same question of the same selection.
     *
     * Filament also eager-loaded `fursuit.user.eventUsers`; nothing downstream reads a
     * user, an event user or even a fursuit - the PDF is built from `custom_id` alone -
     * so the load is gone. No rendered byte changes.
     *
     * @return Collection<int, Badge>
     */
    private function badges(EventScope $scope, string $paymentStatus): Collection
    {
        $query = $scope->apply(Badge::query(), 'fursuit');

        if ($paymentStatus === 'paid') {
            $query->whereState('status_payment', Paid::class);
        } elseif ($paymentStatus === 'unpaid') {
            $query->whereState('status_payment', Unpaid::class);
        }
        // 'all' - no additional filter needed

        return $query->get()
            ->sortBy(function (Badge $badge) {
                // Badges without a custom_id sort to the end; they are dropped further down.
                if (empty($badge->custom_id)) {
                    return [999999, 999999];
                }

                return $this->parseCustomId($badge->custom_id);
            })
            ->values();
    }

    /**
     * `"104-1"` to `[104, 1]`, padded to two parts so two ids always compare.
     *
     * @return array<int, int>
     */
    private function parseCustomId(?string $customId): array
    {
        if (empty($customId)) {
            return [0, 0];
        }

        $result = array_map(fn (string $part) => (int) $part, explode('-', $customId));

        while (count($result) < 2) {
            $result[] = 0;
        }

        return $result;
    }

    /**
     * `1-1699,1700-2400` to a sorted list of ranges. A part that is not exactly
     * `start-end`, or whose start is past its end, is skipped; a list where every part is
     * skipped is the `Invalid Range Format` notification.
     *
     * @return array<int, array{start: int, end: int, key: string}>
     */
    private function parseRanges(string $rangesString): array
    {
        $ranges = [];

        foreach (explode(',', $rangesString) as $range) {
            $range = trim($range);

            if ($range === '') {
                continue;
            }

            $parts = explode('-', $range);

            if (count($parts) !== 2) {
                continue;
            }

            $start = (int) trim($parts[0]);
            $end = (int) trim($parts[1]);

            if ($start <= $end) {
                $ranges[] = ['start' => $start, 'end' => $end, 'key' => "{$start}-{$end}"];
            }
        }

        usort($ranges, fn (array $a, array $b) => $a['start'] <=> $b['start']);

        return $ranges;
    }

    /**
     * Badges to `[range key => attendees]`, plus the ones no range claimed.
     *
     * A badge with an empty `custom_id` is dropped, as before: it has no number to file
     * under and the PDF is a list of numbers.
     *
     * Everything else is filed. With custom ranges a badge outside all of them used to
     * fall out of the loop and out of the document, so a default range list ending at
     * 4999 quietly omitted badge 5000 and up, and the only warning fired when *every*
     * range was empty (plan 2.10 #33, audit 47). Those badges come back as their own
     * bucket. Without custom ranges the computed 1000-wide buckets cover every number, so
     * the bucket is always empty there.
     *
     * @param  Collection<int, Badge>  $badges
     * @param  array<int, array{start: int, end: int, key: string}>  $customRanges
     * @return array{grouped: array<string, array<int, array{attendee_id: string, sort_key: array<int, int>}>>, outOfRange: array<int, array{attendee_id: string, sort_key: array<int, int>}>}
     */
    private function groupBadges(Collection $badges, array $customRanges = []): array
    {
        $grouped = [];
        $outOfRange = [];
        $useCustomRanges = ! empty($customRanges);

        foreach ($badges as $badge) {
            if (empty($badge->custom_id)) {
                continue;
            }

            $parsedId = $this->parseCustomId($badge->custom_id);
            $mainBadgeNumber = $parsedId[0];

            $entry = ['attendee_id' => $badge->custom_id, 'sort_key' => $parsedId];

            if (! $useCustomRanges) {
                $rangeStart = intval($mainBadgeNumber / 1000) * 1000;
                $grouped[$rangeStart.'-'.($rangeStart + 999)][] = $entry;

                continue;
            }

            $rangeKey = null;

            foreach ($customRanges as $range) {
                if ($mainBadgeNumber >= $range['start'] && $mainBadgeNumber <= $range['end']) {
                    $rangeKey = $range['key'];
                    break;
                }
            }

            if ($rangeKey === null) {
                $outOfRange[] = $entry;

                continue;
            }

            $grouped[$rangeKey][] = $entry;
        }

        foreach ($grouped as &$rangeData) {
            usort($rangeData, fn (array $a, array $b) => $a['sort_key'] <=> $b['sort_key']);
        }

        unset($rangeData);

        usort($outOfRange, fn (array $a, array $b) => $a['sort_key'] <=> $b['sort_key']);

        return ['grouped' => $grouped, 'outOfRange' => $outOfRange];
    }

    /**
     * Custom ranges keep the order they were entered in (already sorted by start);
     * computed ranges are sorted by their numeric start. A range no badge landed in is
     * not given a page, as before.
     *
     * @param  array<string, array<int, array{attendee_id: string, sort_key: array<int, int>}>>  $grouped
     * @param  array<int, array{start: int, end: int, key: string}>  $customRanges
     * @return array<int, array{range: string, attendees: array<int, array{attendee_id: string, sort_key: array<int, int>}>}>
     */
    private function orderSections(array $grouped, array $customRanges): array
    {
        if (! empty($customRanges)) {
            $sections = [];

            foreach ($customRanges as $range) {
                if (isset($grouped[$range['key']])) {
                    $sections[] = ['range' => $range['key'], 'attendees' => $grouped[$range['key']]];
                }
            }

            return $sections;
        }

        $sections = [];

        foreach ($grouped as $range => $attendees) {
            $sections[(int) explode('-', $range)[0]] = ['range' => $range, 'attendees' => $attendees];
        }

        ksort($sections);

        return array_values($sections);
    }

    /**
     * @param  string|array{int, int}  $format
     */
    private function mpdf(string|array $format): Mpdf
    {
        return new Mpdf([
            'format' => $format,
            'orientation' => 'P',
            'margin_left' => self::MARGIN,
            'margin_right' => self::MARGIN,
            'margin_top' => self::MARGIN,
            'margin_bottom' => self::MARGIN,
            'mode' => 'utf-8',
            'default_font' => 'helvetica',
        ]);
    }

    /**
     * Pins the label body to the page's own printable area.
     *
     * `pdfs/box-labels.blade.php` restates the size as a hardcoded `200mm x 84mm`, which
     * is today's format and margins worked out by hand and left to drift the next time
     * either changes (audit 45). The rule appended here is the same two numbers derived
     * from `BOX_LABEL_FORMAT` and `MARGIN`, and it is last in the document, so the format
     * is the single source of truth and the blade's copy can no longer disagree with it.
     */
    private function withBoxLabelGeometry(string $html): string
    {
        [$width, $height] = self::boxLabelContentSize();

        $style = sprintf('<style>body, .border { width: %smm; height: %smm; }</style>', $width, $height);

        if (str_contains($html, '</head>')) {
            return Str::replaceFirst('</head>', $style.'</head>', $html);
        }

        return $style.$html;
    }

    /**
     * mPDF renders into memory and the stream hands it over; nothing is written to disk.
     *
     * @param  array<string, string>  $headers
     */
    private function stream(Mpdf $mpdf, string $filename, array $headers = []): StreamedResponse
    {
        return response()->streamDownload(function () use ($mpdf) {
            echo $mpdf->Output('', 'S');
        }, $filename, $headers);
    }

    /**
     * The filename half of plan 2.10 #31. A free-text event name or box-label title
     * carries quotes, slashes and newlines straight into `Content-Disposition` otherwise.
     */
    private function slug(?string $value): string
    {
        return Str::slug((string) $value);
    }

    private function backToPage(): RedirectResponse
    {
        return redirect()->route('manage.tools.pdf');
    }

    /**
     * Every chunk mPDF is handed goes through this, exactly as before: a badge name or an
     * event name that arrived as anything but UTF-8 breaks the parser rather than one
     * glyph.
     */
    private function utf8(string $html): string
    {
        return mb_convert_encoding($html, 'UTF-8', 'auto');
    }
}
