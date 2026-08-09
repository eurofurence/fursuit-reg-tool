<?php

use App\Http\Controllers\PrintAgent\AgentBatchController;
use App\Http\Controllers\PrintAgent\AgentJobController;
use App\Http\Controllers\PrintAgent\AgentSessionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Print agent API
|--------------------------------------------------------------------------
|
| Consumed by the native Windows print agent, which drives the Zebra card
| printer on the convention LAN. It authenticates with a Sanctum bearer token
| issued per machine, because it is a desktop application and cannot hold a
| browser session like the POS does.
|
| Every handler scopes its lookups to the calling machine. See AgentController.
|
*/

Route::middleware('auth:sanctum')->prefix('api/print-agent')->name('print-agent.')->group(function () {
    Route::get('/config', [AgentSessionController::class, 'config'])->name('config');
    Route::post('/printers', [AgentSessionController::class, 'registerPrinters'])->name('printers.register');
    Route::post('/printers/condition', [AgentSessionController::class, 'reportCondition'])->name('printers.condition');

    Route::get('/batches', [AgentBatchController::class, 'index'])->name('batches.index');
    Route::post('/batches/{batch}/start', [AgentBatchController::class, 'start'])->name('batches.start');
    Route::post('/batches/{batch}/pause', [AgentBatchController::class, 'pause'])->name('batches.pause');
    Route::post('/batches/{batch}/resume', [AgentBatchController::class, 'resume'])->name('batches.resume');
    Route::post('/batches/{batch}/cancel', [AgentBatchController::class, 'cancel'])->name('batches.cancel');

    Route::get('/jobs/held', [AgentJobController::class, 'held'])->name('jobs.held');
    Route::post('/jobs/claim', [AgentJobController::class, 'claim'])->name('jobs.claim');
    Route::post('/jobs/{job}/heartbeat', [AgentJobController::class, 'heartbeat'])->name('jobs.heartbeat');
    Route::post('/jobs/{job}/printing', [AgentJobController::class, 'printing'])->name('jobs.printing');
    Route::post('/jobs/{job}/printed', [AgentJobController::class, 'printed'])->name('jobs.printed');
    Route::post('/jobs/{job}/failed', [AgentJobController::class, 'failed'])->name('jobs.failed');

    Route::post('/jobs/{job}/reprint', [AgentJobController::class, 'reprint'])->name('jobs.reprint');
    Route::post('/jobs/{job}/verify', [AgentJobController::class, 'verify'])->name('jobs.verify');
});
