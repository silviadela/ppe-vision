<?php

namespace App\Http\Controllers;

use App\Models\TrainingRun;
use Illuminate\Http\Request;

class TrainingRunController extends Controller
{
    public function index()
    {
        return view('experiments.index', ['runs' => TrainingRun::latest()->paginate(10)]);
    }

    public function create()
    {
        $this->ensureAdminWritesEnabled();

        return view('experiments.create');
    }

    public function store(Request $request)
    {
        $this->ensureAdminWritesEnabled();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'model_version' => ['required', 'string', 'max:50'],
            'dataset_name' => ['nullable', 'string', 'max:100'],
            'epochs' => ['required', 'integer', 'min:1', 'max:10000'],
            'batch_size' => ['required', 'integer', 'min:1', 'max:1024'],
            'image_size' => ['required', 'integer', 'min:32', 'max:4096'],
            'optimizer' => ['nullable', 'string', 'max:50'],
            'device' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:3000'],
        ]);

        $data['status'] = 'draft';
        $run = TrainingRun::create($data);

        return redirect()->route('experiments.show', $run)->with('success', 'Eksperimen berhasil dibuat.');
    }

    public function show(TrainingRun $trainingRun)
    {
        return view('experiments.show', ['run' => $trainingRun]);
    }

    public function importMetrics(Request $request, TrainingRun $trainingRun)
    {
        $this->ensureAdminWritesEnabled();

        $data = $request->validate([
            'results_csv' => ['nullable', 'file', 'mimes:csv,txt', 'max:5120'],
            'precision' => ['required', 'numeric', 'between:0,1'],
            'recall' => ['required', 'numeric', 'between:0,1'],
            'map50' => ['required', 'numeric', 'between:0,1'],
            'map50_95' => ['required', 'numeric', 'between:0,1'],
            'helm_map' => ['nullable', 'numeric', 'between:0,1'],
            'masker_map' => ['nullable', 'numeric', 'between:0,1'],
            'people_map' => ['nullable', 'numeric', 'between:0,1'],
        ]);

        $history = null;
        if ($request->hasFile('results_csv')) {
            $history = $this->readUltralyticsCsv($request->file('results_csv')->getRealPath());
        }

        $trainingRun->update([
            'status' => 'completed',
            'finished_at' => now(),
            'metrics' => collect($data)->only(['precision', 'recall', 'map50', 'map50_95'])->map(fn ($value) => (float) $value)->all(),
            'per_class_metrics' => [
                'helm' => ['map50_95' => (float) ($data['helm_map'] ?? $data['map50_95'])],
                'masker' => ['map50_95' => (float) ($data['masker_map'] ?? $data['map50_95'])],
                'people' => ['map50_95' => (float) ($data['people_map'] ?? $data['map50_95'])],
            ],
            'training_history' => $history ?: $trainingRun->training_history,
        ]);

        return back()->with('success', 'Metrics training berhasil diimpor.');
    }

    private function readUltralyticsCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return [];
        }

        $headers = array_map(fn ($value) => trim((string) $value, " \t\n\r\0\x0B\xEF\xBB\xBF"), fgetcsv($handle) ?: []);
        $rows = [];
        while (($values = fgetcsv($handle)) !== false) {
            if (count($values) !== count($headers)) {
                continue;
            }
            $row = array_combine($headers, $values);
            $rows[] = [
                'epoch' => (int) ($row['epoch'] ?? count($rows) + 1),
                'precision' => (float) ($row['metrics/precision(B)'] ?? 0),
                'recall' => (float) ($row['metrics/recall(B)'] ?? 0),
                'map50_95' => (float) ($row['metrics/mAP50-95(B)'] ?? 0),
            ];
        }
        fclose($handle);

        return $rows;
    }

    private function ensureAdminWritesEnabled(): void
    {
        abort_unless(config('deployment.allow_admin_writes'), 404);
    }
}
