<?php

/*
 * PDF Generator (parity checklist 21, audit 5.1).
 *
 * The biggest custom page in the Filament panel, 483 lines, ported as two GET downloads
 * over one form. What is asserted below is the parity contract - the five notifications
 * verbatim, the option labels, the defaults - plus the three things the plan fixes rather
 * than ports:
 *
 *  - the event comes from the one event scope. The page read
 *    `session('filament.admin.selected_event_id')`, which nothing writes, so it silently
 *    used the newest event and its "no event selected" branch was unreachable (plan 2.9,
 *    audit 63);
 *  - the badge-list filename is slugged, so a free-text event name cannot break or inject
 *    into `Content-Disposition` (plan 2.10 #31, audit 15);
 *  - badges numbered outside every declared range are reported instead of dropped (plan
 *    2.10 #33, audit 47).
 *
 * And the property that holds the whole page together: generating a PDF is a read, so a
 * generation writes nothing.
 */

use App\Http\Controllers\Manage\PdfGeneratorController;
use App\Models\Badge\Badge;
use App\Models\Badge\State_Payment\Paid;
use App\Models\Badge\State_Payment\Unpaid;
use App\Models\Event;
use App\Models\Fursuit\Fursuit;
use App\Models\User;
use App\Support\Manage\EventScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\travelTo;

/** Where App\Support\Manage\Toast writes: Inertia's own flash bag. */
const MANAGE_PDF_TOAST = 'inertia.flash_data.toast';

/**
 * How many pages a rendered document has. mPDF emits one `/Type /Page` object per page
 * plus one `/Type /Pages` tree, and the tree matches the same prefix, so it is subtracted
 * back out. Enough to tell "one section" from "one section and the leftovers".
 */
function pdfPages(string $pdf): int
{
    return substr_count($pdf, '/Type /Page') - substr_count($pdf, '/Type /Pages');
}

beforeEach(function () {
    Storage::fake('s3');

    // A fixed clock, because both filenames end in the render date.
    travelTo(now()->setDate(2026, 3, 4)->setTime(9, 0));

    $this->admin = User::factory()->create(['is_admin' => true, 'is_reviewer' => false]);
    $this->reviewer = User::factory()->create(['is_admin' => false, 'is_reviewer' => true]);
    $this->nobody = User::factory()->create(['is_admin' => false, 'is_reviewer' => false]);

    // The selected event is deliberately the older one: the page it replaces always used
    // the newest, whatever the header said.
    $this->event = Event::factory()->create(['name' => 'Eurofurence 29', 'starts_at' => now()->subYear()]);
    $this->newerEvent = Event::factory()->create(['name' => 'Eurofurence 30', 'starts_at' => now()->addMonth()]);

    $this->badge = function (string $customId, ?Event $event = null, ?string $payment = null) {
        $fursuit = Fursuit::factory()->create([
            'user_id' => User::factory()->create()->id,
            'event_id' => ($event ?? $this->event)->id,
        ]);

        return Badge::factory()->create([
            'fursuit_id' => $fursuit->id,
            'custom_id' => $customId,
            'status_payment' => $payment ?? Paid::$name,
        ]);
    };

    /** Every read below states which event scope it is asking for. */
    $this->scoped = fn (?int $eventId) => actingAs($this->admin)->withSession([
        EventScope::SESSION_ID => $eventId,
        EventScope::SESSION_CHOSEN => true,
    ]);
});

test('a guest is redirected to login', function () {
    get(route('admin.tools.pdf'))->assertRedirect();
});

test('an attendee cannot reach the tool at all', function () {
    actingAs($this->nobody)->get(route('admin.tools.pdf'))->assertForbidden();
    actingAs($this->nobody)->get(route('admin.tools.pdf.badge-list'))->assertForbidden();
    actingAs($this->nobody)->get(route('admin.tools.pdf.box-labels', ['title' => 'x']))->assertForbidden();
});

// Checklist line 83: no extra gate beyond access-manage, so reviewers keep the page.
test('a reviewer reaches the page, because access-manage is the whole guard', function () {
    actingAs($this->reviewer)
        ->get(route('admin.tools.pdf'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Manage/Tools/PdfGenerator'));
});

test('the form opens on the Filament defaults', function () {
    ($this->scoped)($this->event->id)
        ->get(route('admin.tools.pdf'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('event.name', 'Eurofurence 29')
            ->where('defaults.pdf_type', 'badge_list')
            ->where('defaults.payment_status', 'all')
            ->where('defaults.badge_ranges', '0-999,1000-1999,2000-2999,3000-3999,4000-4999')
            ->where('defaults.title', '')
            ->where('defaults.subtitle', '')
            ->where('defaults.rows_per_column', 50)
            ->where('defaults.columns', 12)
            ->where('defaults.font_size', 6)
            ->etc()
        );
});

test('the selects carry their Filament labels, with the box-label count corrected', function () {
    ($this->scoped)($this->event->id)
        ->get(route('admin.tools.pdf'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('pdfTypes.0.value', 'badge_list')
            ->where('pdfTypes.0.label', 'Badge List (Badges by Range)')
            ->where('pdfTypes.1.value', 'box_labels')
            // Plan 2.10 #32, audit 45: it has only ever rendered one label per page.
            ->where('pdfTypes.1.label', 'Box Labels (1 per page)')
            ->where('paymentStatuses.0.label', 'All Badges')
            ->where('paymentStatuses.1.label', 'Paid Badges Only')
            ->where('paymentStatuses.2.label', 'Unpaid Badges Only')
            ->etc()
        );
});

// Plan 2.9, audit 63: the branch behind this notification was unreachable, because the
// key the page read was never written and it fell back to the newest event instead.
test('all events selected reports that no event is selected, rather than guessing one', function () {
    ($this->badge)('10-1');

    ($this->scoped)(null)
        ->get(route('admin.tools.pdf.badge-list'))
        ->assertRedirect(route('admin.tools.pdf'))
        ->assertSessionHas(MANAGE_PDF_TOAST.'.tone', 'danger')
        ->assertSessionHas(MANAGE_PDF_TOAST.'.title', 'Error')
        ->assertSessionHas(MANAGE_PDF_TOAST.'.body', 'No event selected in the header.');
});

test('the badge list covers the selected event and nothing else', function () {
    // Every badge belongs to the newer event, which is what the old page would have used.
    ($this->badge)('10-1', $this->newerEvent);

    ($this->scoped)($this->event->id)
        ->get(route('admin.tools.pdf.badge-list'))
        ->assertRedirect(route('admin.tools.pdf'))
        ->assertSessionHas(MANAGE_PDF_TOAST.'.title', 'No Data')
        ->assertSessionHas(MANAGE_PDF_TOAST.'.body', 'No badges found for the current event.');

    ($this->scoped)($this->newerEvent->id)
        ->get(route('admin.tools.pdf.badge-list'))
        ->assertOk();
});

test('the No Data body names the payment filter that found nothing', function () {
    ($this->badge)('10-1', null, Paid::$name);

    ($this->scoped)($this->event->id)
        ->get(route('admin.tools.pdf.badge-list', ['payment_status' => 'unpaid']))
        ->assertSessionHas(MANAGE_PDF_TOAST.'.tone', 'warning')
        ->assertSessionHas(MANAGE_PDF_TOAST.'.title', 'No Data')
        ->assertSessionHas(MANAGE_PDF_TOAST.'.body', 'No unpaid badges found for the current event.');

    ($this->badge)('11-1', null, Unpaid::$name);

    ($this->scoped)($this->event->id)
        ->get(route('admin.tools.pdf.badge-list', ['payment_status' => 'unpaid']))
        ->assertOk();
});

test('an unparsable range list is refused with the Filament copy', function () {
    ($this->badge)('10-1');

    ($this->scoped)($this->event->id)
        ->get(route('admin.tools.pdf.badge-list', ['badge_ranges' => 'not a range']))
        ->assertRedirect(route('admin.tools.pdf'))
        ->assertSessionHas(MANAGE_PDF_TOAST.'.tone', 'danger')
        ->assertSessionHas(MANAGE_PDF_TOAST.'.title', 'Invalid Range Format')
        ->assertSessionHas(MANAGE_PDF_TOAST.'.body', 'Please enter valid badge ranges in the format: 1-1699,1700-2400');
});

test('a range list no badge falls into is refused with the Filament copy', function () {
    ($this->badge)('10-1');

    ($this->scoped)($this->event->id)
        ->get(route('admin.tools.pdf.badge-list', ['badge_ranges' => '2000-2999']))
        ->assertRedirect(route('admin.tools.pdf'))
        ->assertSessionHas(MANAGE_PDF_TOAST.'.tone', 'warning')
        ->assertSessionHas(MANAGE_PDF_TOAST.'.title', 'No Badges in Ranges')
        ->assertSessionHas(MANAGE_PDF_TOAST.'.body', 'No badges found within the specified ranges. Please check your range settings.');
});

test('the badge list streams a PDF', function () {
    ($this->badge)('10-1');

    $response = ($this->scoped)($this->event->id)->get(route('admin.tools.pdf.badge-list'));

    $response->assertOk();

    expect(substr($response->streamedContent(), 0, 4))->toBe('%PDF');
});

// Plan 2.10 #31, audit 15: PdfGenerator.php:308 interpolated the raw event name into
// Content-Disposition, and an event name is free-text admin input.
test('the badge-list filename is slugged, so an event name cannot break the header', function () {
    $this->event->update(['name' => 'Euro"fur/ence "30"']);
    ($this->badge)('10-1', null, Paid::$name);

    $disposition = ($this->scoped)($this->event->id)
        ->get(route('admin.tools.pdf.badge-list', ['payment_status' => 'paid']))
        ->assertOk()
        ->headers->get('content-disposition');

    expect($disposition)->toContain('badge-list-eurofurence-30-paid-2026-03-04.pdf')
        ->and($disposition)->not->toContain('/')
        ->and($disposition)->not->toContain('Euro"fur');
});

test('the payment filter names itself in the filename, or does not', function () {
    ($this->badge)('10-1', null, Unpaid::$name);

    $unpaid = ($this->scoped)($this->event->id)
        ->get(route('admin.tools.pdf.badge-list', ['payment_status' => 'unpaid']))
        ->headers->get('content-disposition');

    $all = ($this->scoped)($this->event->id)
        ->get(route('admin.tools.pdf.badge-list', ['payment_status' => 'all']))
        ->headers->get('content-disposition');

    expect($unpaid)->toContain('badge-list-eurofurence-29-unpaid-2026-03-04.pdf')
        ->and($all)->toContain('badge-list-eurofurence-29-2026-03-04.pdf');
});

// Plan 2.10 #33, audit 47: the default ranges stop at 4999 and everything above it was
// dropped from the document without a word.
test('badges outside every declared range are reported, not dropped', function () {
    ($this->badge)('10-1');
    ($this->badge)('5000-1');
    ($this->badge)('6000-1');

    // The form's own default, which is exactly the range list that loses badge 5000 and up.
    $response = ($this->scoped)($this->event->id)
        ->get(route('admin.tools.pdf.badge-list', ['badge_ranges' => PdfGeneratorController::DEFAULT_RANGES]))
        ->assertOk()
        ->assertHeader(PdfGeneratorController::OUT_OF_RANGE_HEADER, '2');

    // Two pages, not one: the range the ranges cover, then the leftovers, listed under
    // OUT_OF_RANGE_LABEL by the same view every other section uses.
    expect(pdfPages($response->streamedContent()))->toBe(2);
});

test('a range list that covers every badge reports nothing out of range', function () {
    ($this->badge)('10-1');
    ($this->badge)('5000-1');

    $response = ($this->scoped)($this->event->id)
        ->get(route('admin.tools.pdf.badge-list', ['badge_ranges' => '0-9999']))
        ->assertHeader(PdfGeneratorController::OUT_OF_RANGE_HEADER, '0');

    // One range, one page, and no leftovers page behind it.
    expect(pdfPages($response->streamedContent()))->toBe(1);

    // No range list at all keeps Filament's fallback to computed 1000-wide buckets, which
    // cover every number there is, so nothing can fall outside them.
    ($this->scoped)($this->event->id)
        ->get(route('admin.tools.pdf.badge-list'))
        ->assertHeader(PdfGeneratorController::OUT_OF_RANGE_HEADER, '0');
});

// -------------------------------------------------------------------------------------
// Plan 2.10 #74. Two defects in `pdfs.badge-list-range`, both of which the out-of-range
// bucket above made reachable: it is the first thing that ever routed a five-digit id into
// the view, and it is one more section that can overflow a page.
// -------------------------------------------------------------------------------------

test('an attendee id longer than four characters renders instead of killing the document', function () {
    ($this->badge)('10-1');
    // attendee_id comes from the registration service and goes into custom_id verbatim, so
    // any event past 9,999 registrations produces one of these.
    ($this->badge)('12345-1');

    $response = ($this->scoped)($this->event->id)
        ->get(route('admin.tools.pdf.badge-list', ['badge_ranges' => PdfGeneratorController::DEFAULT_RANGES]))
        ->assertOk()
        ->assertHeader(PdfGeneratorController::OUT_OF_RANGE_HEADER, '1');

    // Before the clamp this was a ValueError out of str_repeat() with a negative count, so
    // the request 500'd and the operator got no PDF at all rather than one missing a row.
    expect(substr($response->streamedContent(), 0, 4))->toBe('%PDF');
});

test('a range holding more badges than one page fits is paged, not truncated', function () {
    foreach (['1-1', '2-1', '3-1', '4-1', '5-1'] as $customId) {
        ($this->badge)($customId);
    }

    $response = ($this->scoped)($this->event->id)
        ->get(route('admin.tools.pdf.badge-list', [
            'badge_ranges' => '0-999',
            // Four numbers to a page, five badges to print.
            'rows_per_column' => 2,
            'columns' => 2,
        ]))
        ->assertOk()
        // Every one of them is inside a declared range, so the out-of-range bucket is not
        // what catches this and nothing else on the response reported the loss either.
        ->assertHeader(PdfGeneratorController::OUT_OF_RANGE_HEADER, '0');

    expect(pdfPages($response->streamedContent()))->toBe(2);
});

test('paging a section keeps every badge and names the range on each page', function () {
    $attendees = collect(range(1, 5))
        ->map(fn (int $n) => ['attendee_id' => $n.'-1', 'sort_key' => [$n, 1]])
        ->all();

    $pages = PdfGeneratorController::paginateSections([['range' => '0-999', 'attendees' => $attendees]], 2, 2);

    expect($pages)->toHaveCount(2)
        ->and($pages[0]['range'])->toBe('0-999')
        ->and($pages[1]['range'])->toBe('0-999 (continued)')
        ->and(array_merge($pages[0]['attendees'], $pages[1]['attendees']))->toBe($attendees);
});

test('the range view drops nothing when the list overflows its columns', function () {
    $attendees = collect(range(1, 700))->map(fn (int $n) => ['attendee_id' => $n.'-1'])->all();

    $html = view('pdfs.badge-list-range', [
        'range' => '0-999',
        'attendees' => $attendees,
        'rowsPerColumn' => 50,
        'columns' => 12,
    ])->render();

    // The header counted 700, so the table below it has to hold 700. It used to hold the
    // first 600 and say 700.
    expect($html)->toContain('0-999 (700 attendees)')
        ->and($html)->toContain('601-1')
        ->and($html)->toContain('700-1');
});

test('an attendee id is escaped into the range view rather than rendered as markup', function () {
    $html = view('pdfs.badge-list-range', [
        'range' => '0-999',
        'attendees' => [['attendee_id' => '<b>1</b>-1']],
        'rowsPerColumn' => 50,
        'columns' => 12,
    ])->render();

    // Only the alignment padding is markup. The id is registration-service input.
    expect($html)->not->toContain('<b>1</b>')
        ->and($html)->toContain('&lt;b&gt;');
});

test('the layout numbers are integers with a ceiling', function () {
    ($this->badge)('10-1');

    ($this->scoped)($this->event->id)
        ->get(route('admin.tools.pdf.badge-list', ['columns' => 100000]))
        ->assertSessionHasErrors('columns');

    ($this->scoped)($this->event->id)
        ->get(route('admin.tools.pdf.badge-list', ['rows_per_column' => 'lots']))
        ->assertSessionHasErrors('rows_per_column');

    ($this->scoped)($this->event->id)
        ->get(route('admin.tools.pdf.badge-list', ['payment_status' => 'refunded']))
        ->assertSessionHasErrors('payment_status');
});

test('box labels refuse an empty title with the Filament copy', function () {
    ($this->scoped)($this->event->id)
        ->get(route('admin.tools.pdf.box-labels'))
        ->assertRedirect(route('admin.tools.pdf'))
        ->assertSessionHas(MANAGE_PDF_TOAST.'.tone', 'danger')
        ->assertSessionHas(MANAGE_PDF_TOAST.'.title', 'Error')
        ->assertSessionHas(MANAGE_PDF_TOAST.'.body', 'Title is required for box labels.');
});

test('box labels stream a PDF under a slugged filename', function () {
    $response = ($this->scoped)($this->event->id)
        ->get(route('admin.tools.pdf.box-labels', ['title' => 'Badge Range 1-999', 'subtitle' => 'Free Badges']));

    $response->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('box-label-badge-range-1-999-2026-03-04.pdf')
        ->and(substr($response->streamedContent(), 0, 4))->toBe('%PDF');
});

// Plan 2.10 #32, audit 45: the blade restates the printable area as a hardcoded
// 200mm x 84mm. It is the page format minus the margins, and now derived from them.
test('the box-label content size is derived from the page format and the margins', function () {
    expect(PdfGeneratorController::boxLabelContentSize())->toBe([200.0, 84.0]);
});

// The property the whole page rests on. A download is a read.
test('generating either PDF writes nothing', function () {
    ($this->badge)('10-1');
    ($this->badge)('5000-1');

    $writes = [];

    DB::listen(function ($query) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete|replace|truncate|alter|drop)\b/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });

    $before = Badge::query()->get()->toArray();

    ($this->scoped)($this->event->id)->get(route('admin.tools.pdf.badge-list'))->assertOk();
    ($this->scoped)($this->event->id)->get(route('admin.tools.pdf.box-labels', ['title' => 'Box']))->assertOk();

    expect($writes)->toBe([])
        ->and(Badge::query()->get()->toArray())->toBe($before);
});

// The rail carries one "Tools" entry now, not a row per tool, so the card on the Tools
// index is what makes this page reachable.
test('the tool is reachable from the Tools index', function () {
    $tools = actingAs($this->admin)
        ->get(route('admin.tools.index'))
        ->viewData('page')['props']['tools'];

    expect(collect($tools)->pluck('label'))->toContain('PDF Generator')
        ->and(collect($tools)->firstWhere('label', 'PDF Generator')['url'])
        ->toBe(route('admin.tools.pdf'));
});
