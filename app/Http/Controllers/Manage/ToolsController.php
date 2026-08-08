<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Support\Manage\Navigation;
use Illuminate\Support\Facades\Gate;
use Inertia\Response;

/**
 * The Tools index: one card per tool, and the successor to the Tools and Maintenance rail
 * groups.
 *
 * Both groups are gone from the rail. Between them they held three pages - PDF Generator,
 * Badge Preview and DB Service - and a rail row carries a name and nothing else, which is
 * the one thing those names do not explain. The cards say what each page is for, the same
 * way the Settings submenu does for its panes, and Tools is one rail entry instead of two
 * headings over three rows.
 *
 * The list comes from Navigation::tools() rather than from here, so the rail entry and the
 * cards cannot drift and the filtering is the panel's usual pair: Route::has, so a page a
 * phase has not registered is absent rather than a dead card, plus the gate, so DB Service
 * is absent for a reviewer rather than a card that answers 403.
 *
 * Reads only. This page renders a menu; every write still lives on the tool it belongs to,
 * behind that tool's own guard.
 */
class ToolsController extends Controller
{
    public function index(Navigation $navigation): Response
    {
        Gate::authorize('manage-admin');

        return inertia('Manage/Tools/Index', [
            'tools' => $navigation->tools(),
        ]);
    }
}
