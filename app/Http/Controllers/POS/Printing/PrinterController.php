<?php

namespace App\Http\Controllers\POS\Printing;

use App\Domain\Printing\Models\Printer;
use App\Enum\PrintJobTypeEnum;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PrinterController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->input('printers');

        collect($data)->each(function ($printer) use ($request) {
            $request->user('machine')->printers()->firstOrCreate([
                'name' => $printer['name'],
            ],[
                'name' => $printer['name'],
                'type' => PrintJobTypeEnum::Receipt,
                'paper_sizes' => $printer['sizes'],
                'default_paper_size' => $printer['sizes'][0]['name'],
            ]);
        });
    }
}
