<?php

namespace App\Http\Controllers;

use App\Models\Detection;
use App\Models\TrainingRun;

class DashboardController extends Controller
{
    public function index()
    {
        $latestRun = TrainingRun::latest('finished_at')->first();

        return view('dashboard', [
            'latestRun' => $latestRun,
            'runs' => TrainingRun::latest()->take(5)->get(),
            'experimentCount' => TrainingRun::count(),
            'detectionCount' => Detection::where('status', 'completed')->count(),
        ]);
    }
}
