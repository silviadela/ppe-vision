<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DetectionController;
use App\Http\Controllers\InstallationController;
use App\Http\Controllers\SummaryController;
use App\Http\Controllers\TrainingRunController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

Route::get('/install', [InstallationController::class, 'show'])
    ->middleware('throttle:10,1')
    ->name('installation.show');
Route::post('/install', [InstallationController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('installation.store');

Route::resource('experiments', TrainingRunController::class)
    ->parameters(['experiments' => 'trainingRun'])
    ->only(['index', 'create', 'store', 'show']);
Route::post('/experiments/{trainingRun}/metrics', [TrainingRunController::class, 'importMetrics'])->name('experiments.metrics');

Route::get('/experiments/{trainingRun}/summary', [SummaryController::class, 'show'])->name('summary.show');
Route::post('/experiments/{trainingRun}/summary', [SummaryController::class, 'generate'])->name('summary.generate');

Route::get('/detection', [DetectionController::class, 'index'])->name('detection.index');
Route::post('/detection', [DetectionController::class, 'store'])->name('detection.store');
