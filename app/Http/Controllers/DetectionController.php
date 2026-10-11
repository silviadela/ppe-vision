<?php

namespace App\Http\Controllers;

use App\Models\Detection;
use App\Models\TrainingRun;
use App\Services\YoloInferenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class DetectionController extends Controller
{
    public function index(YoloInferenceService $service)
    {
        $streamlitUrl = filter_var(config('services.streamlit.url'), FILTER_VALIDATE_URL) ?: null;
        $streamlitEmbedUrl = $streamlitUrl
            ? $streamlitUrl.(str_contains($streamlitUrl, '?') ? '&' : '?').'embed=true'
            : null;

        return view('detection.index', [
            'runs' => TrainingRun::where('status', 'completed')->latest()->get(),
            'detections' => Detection::latest()->take(8)->get(),
            'apiReady' => $service->isReady(),
            'streamlitUrl' => $streamlitUrl,
            'streamlitEmbedUrl' => $streamlitEmbedUrl,
        ]);
    }

    public function store(Request $request, YoloInferenceService $service)
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'training_run_id' => ['nullable', 'exists:training_runs,id'],
            'confidence' => ['required', 'numeric', 'min:0.05', 'max:0.95'],
        ]);

        $file = $data['file'];
        $path = $file->store('detections/input', 'public');
        $detection = Detection::create([
            'training_run_id' => $data['training_run_id'] ?? null,
            'source_name' => $file->getClientOriginalName(),
            'source_path' => $path,
            'status' => 'processing',
        ]);

        try {
            $result = $service->detect($file, (float) $data['confidence']);
            $outputPath = null;
            if (! empty($result['annotated_image'])) {
                $image = base64_decode($result['annotated_image'], true);
                if ($image !== false) {
                    $outputPath = 'detections/output/'.Str::uuid().'.jpg';
                    Storage::disk('public')->put($outputPath, $image);
                }
            }
            $detection->update([
                'status' => 'completed',
                'detections' => $result['detections'] ?? [],
                'inference_ms' => $result['inference_ms'] ?? null,
                'output_path' => $outputPath,
            ]);

            return back()->with('success', 'Deteksi berhasil diproses.');
        } catch (Throwable $exception) {
            $detection->update(['status' => 'failed', 'error_message' => $exception->getMessage()]);

            return back()->with('error', $exception->getMessage());
        }
    }
}
