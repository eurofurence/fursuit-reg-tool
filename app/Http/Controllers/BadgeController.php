<?php

namespace App\Http\Controllers;

use App\Models\Badge\Badge;
use App\Models\Species;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BadgeController extends Controller
{
    public function index()
    {

    }

    public function create()
    {
        return Inertia::render('Badges/BadgesCreate', [
            'species' => Species::has('fursuits',count: 5)->orWhere('checked',true)->get('name'),
        ]);
    }

    public function store(Request $request)
    {
    }

    public function show(Badge $badge)
    {
    }

    public function edit(Badge $badge)
    {
    }

    public function update(Request $request, Badge $badge)
    {
    }

    public function destroy(Badge $badge)
    {
    }
}
