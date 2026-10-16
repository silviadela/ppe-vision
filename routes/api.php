<?php

use App\Http\Controllers\Api\LocalAiSummaryController;
use Illuminate\Support\Facades\Route;

Route::middleware(['summary.sync', 'throttle:10,1'])
    ->prefix('local-ai/training-runs/{trainingRun}')
    ->group(function () {
        Route::get('/', [LocalAiSummaryController::class, 'show'])
            ->name('api.local-ai.show');
        Route::put('/summary', [LocalAiSummaryController::class, 'update'])
            ->name('api.local-ai.update');
    });
