<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Admin\BadgePdfController;
use App\Http\Controllers\Controller;
use App\Models\Badge\Badge;
use App\Support\Manage\Action;
use App\Support\Manage\Status;
use App\Support\Manage\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Response;

/**
 * Badge Preview, the successor to App\the old panel\Pages\BadgePreview.
 *
 * A read-only tool page: look a badge up by custom id, read back the six details the
 * blade showed, then open or download its PDF. No table, no filters, no writes.
 *
 * The Livewire page kept the loaded badge in component state; here the state is the URL.
 * The lookup POSTs, flashes its toast and redirects to `?custom_id=…`, so the details
 * panel is a plain GET that survives a reload, and the two PDF buttons are ordinary
 * links a browser can genuinely open in a new tab. `target="_blank"` on a Livewire
 * `redirect()` never opened one.
 *
 * Two more things the same change 34 settles: the default badge class is `EF30_Badge`,
 * which is what BadgePdfController actually renders, rather than the blade's
 * `EF28_Badge`, so the screen can no longer label a badge one class and hand you
 * another; and every detail is read null-safely, because the blade walked
 * `->species->name`, `->user->name` and `->event->name` unguarded through relations that
 * soft-delete.
 *
 * Not event-scoped. Plan 2.9 lists the scoped surfaces and this is not one of them: a
 * custom id is looked up wherever it lives, as today.
 *
 * No extra gate either. `can:access-manage` on the group is the whole guard, so
 * reviewers keep the page they have today; the PDF routes are what gains a guard, in
 * routes/web.php.
 */
class BadgePreviewController extends Controller
{
    /**
     * What BadgePdfController falls back to for an event with no `badge_class`, and now
     * what the details panel reports.
     */
    public const DEFAULT_BADGE_CLASS = 'EF30_Badge';

    /**
     * The lookup form's own limit, mirrored from the TextInput's maxLength(255).
     */
    private const MAX_CUSTOM_ID = 255;

    /**
     * The page, with the details panel filled in when the query names a badge.
     *
     * `actions` is a top-level prop rather than something the client assembles, so the
     * two buttons are declared once, server-side, the way every other action in the
     * panel is.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('manage-admin');

        $customId = $this->requestedCustomId($request);
        $badge = $customId === null ? null : $this->find($customId);

        return inertia('Manage/Tools/BadgePreview', [
            'customId' => $customId,
            'badge' => $badge === null ? null : $this->details($badge),
            'actions' => $badge === null ? [] : $this->pdfActions($badge->custom_id),
        ]);
    }

    /**
     * `loadBadge()`. Same two notifications, verbatim, and the typed id rides back in the
     * query so a failed lookup still shows the operator what they asked for.
     */
    public function lookup(Request $request): RedirectResponse
    {
        Gate::authorize('manage-admin');

        $validated = $request->validate([
            'custom_id' => ['required', 'string', 'max:'.self::MAX_CUSTOM_ID],
        ]);

        $customId = $validated['custom_id'];
        $badge = $this->find($customId);

        if ($badge === null) {
            Toast::flashDanger('Badge not found', 'No badge found with custom ID: '.$customId);

            return $this->backToPage($customId);
        }

        /*
         * The fursuit name, straight through. `mb_convert_encoding($name, 'UTF-8',
         * 'UTF-8')` was a no-op, not sanitization, so dropping it
         * changes no byte of this body. A badge whose fursuit is soft-deleted has no
         * name to report and says so with an empty one rather than throwing.
         */
        Toast::flashSuccess('Badge loaded', 'Badge found for: '.($badge->fursuit?->name ?? ''));

        return $this->backToPage($customId);
    }

    /**
     * `viewPdf()`, inline. Rendering is delegated rather than copied: one implementation
     * of "which badge class renders this" is the whole point of change 34.
     */
    public function viewPdf(string $customId, BadgePdfController $pdf): HttpResponse|RedirectResponse
    {
        Gate::authorize('manage-admin');

        if (! $this->exists($customId)) {
            return $this->noBadgeLoaded($customId);
        }

        return $pdf->view($customId);
    }

    /**
     * `downloadPdf()`, as an attachment. Kept a separate route from the view: they are
     * two distinct actions and collapsing them loses the download.
     */
    public function downloadPdf(string $customId, BadgePdfController $pdf): HttpResponse|RedirectResponse
    {
        Gate::authorize('manage-admin');

        if (! $this->exists($customId)) {
            return $this->noBadgeLoaded($customId);
        }

        return $pdf->download($customId);
    }

    /**
     * The Livewire `if (! $this->badge)` branch, reached here by a stale link or a
     * hand-typed id rather than by an empty component property.
     */
    private function noBadgeLoaded(string $customId): RedirectResponse
    {
        Toast::flashWarning('No badge loaded', 'Please load a badge first');

        return $this->backToPage($customId);
    }

    private function backToPage(string $customId): RedirectResponse
    {
        return redirect()->route('admin.tools.badge-preview', ['custom_id' => $customId]);
    }

    /**
     * The id the query asks for, or null.
     *
     * Read rather than validated: a ValidationException on a GET redirects back to the
     * URL that carried the value, which is the same URL, and the page would bounce. The
     * form's own limit is applied here too, so an overlong id cannot enter through the
     * query string either; it simply names no badge.
     */
    private function requestedCustomId(Request $request): ?string
    {
        $customId = $request->query('custom_id');

        if (! is_string($customId) || $customId === '' || mb_strlen($customId) > self::MAX_CUSTOM_ID) {
            return null;
        }

        return $customId;
    }

    /**
     * `Badge::with([...])->where('custom_id', …)->first()`, unchanged.
     *
     * `custom_id` lost its global unique index in
     * 2024_12_24_000001_fix_badge_custom_id_uniqueness_constraint, so two events can hold
     * the same id and this returns whichever the driver hands back first, exactly as the
     * the old panel page did. Parity, not an endorsement.
     */
    private function find(string $customId): ?Badge
    {
        return Badge::with(['fursuit.user', 'fursuit.species', 'fursuit.event'])
            ->where('custom_id', $customId)
            ->first();
    }

    private function exists(string $customId): bool
    {
        return Badge::where('custom_id', $customId)->exists();
    }

    /**
     * The blade's six rows, in its order. Every relation is optional: `Fursuit` and
     * `Badge` both soft-delete, and a deleted fursuit is exactly the row that took the
     * old page down.
     *
     * @return array<string, string|null>
     */
    private function details(Badge $badge): array
    {
        $fursuit = $badge->fursuit;

        return [
            'custom_id' => $badge->custom_id,
            'fursuit_name' => $fursuit?->name,
            'species' => $fursuit?->species?->name,
            'owner' => $fursuit?->user?->name,
            'event' => $fursuit?->event?->name,
            'badge_class' => $fursuit?->event?->badge_class ?? self::DEFAULT_BADGE_CLASS,
        ];
    }

    /**
     * The blade's two buttons, with their labels, colours and icons.
     *
     * `newTab()` on the view button only, matching the blade, which put `target="_blank"`
     * on that one and nothing on the download. Plan 2.10 change 34 is about making that
     * one target actually work; the download is served `Content-Disposition: attachment`
     * and needs no tab of its own.
     *
     * @return array<int, array<string, mixed>>
     */
    private function pdfActions(?string $customId): array
    {
        if ($customId === null) {
            return [];
        }

        return [
            Action::link(
                'view-pdf',
                'View PDF in Browser',
                route('admin.tools.badge-preview.pdf.view', ['customId' => $customId])
            )->icon('eye')->newTab()->toArray(),

            // the old panel's `color('success')`, which is Status::OK here.
            Action::link(
                'download-pdf',
                'Download PDF',
                route('admin.tools.badge-preview.pdf.download', ['customId' => $customId])
            )->icon('download')->tone(Status::OK)->toArray(),
        ];
    }
}
