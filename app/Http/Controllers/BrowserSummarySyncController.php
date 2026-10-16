<?php

namespace App\Http\Controllers;

use App\Models\TrainingRun;
use App\Services\SummaryExchangeService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use JsonException;
use RuntimeException;
use Throwable;

class BrowserSummarySyncController extends Controller
{
    public function show(TrainingRun $trainingRun)
    {
        abort_if(empty($trainingRun->metrics), 404);

        return view('summary.exchange', ['run' => $trainingRun]);
    }

    public function download(
        Request $request,
        TrainingRun $trainingRun,
        SummaryExchangeService $exchange,
    ) {
        $this->validateToken($request, $exchange);
        abort_if(empty($trainingRun->metrics), 422, 'Metrics belum tersedia.');

        $payload = $exchange->metricsPackage($trainingRun);
        $filename = 'ppe-metrics-run-'.$trainingRun->id.'.json';

        return response()->json(
            $payload,
            200,
            [
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                'Cache-Control' => 'no-store, private',
                'X-Content-Type-Options' => 'nosniff',
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    public function upload(
        Request $request,
        TrainingRun $trainingRun,
        SummaryExchangeService $exchange,
    ) {
        $this->validateToken($request, $exchange);
        $validated = $request->validate([
            'summary_file' => ['required', 'file', 'max:64'],
        ]);

        try {
            $contents = $validated['summary_file']->get();
            $payload = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($payload)) {
                throw new RuntimeException('File summary harus berisi objek JSON.');
            }

            $updates = $exchange->validateResultPackage($trainingRun, $payload);
            $trainingRun->update($updates);
        } catch (JsonException) {
            return back()->with('error', 'File summary bukan JSON yang valid.');
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('summary.show', $trainingRun)
            ->with('success', 'Summary Ollama lokal berhasil disimpan ke dashboard online.');
    }

    private function validateToken(Request $request, SummaryExchangeService $exchange): void
    {
        $request->validate([
            'sync_token' => ['required', 'string', 'max:255'],
        ]);

        if (! $exchange->tokenIsValid($request->string('sync_token')->toString())) {
            throw ValidationException::withMessages([
                'sync_token' => 'Token sinkronisasi tidak cocok.',
            ]);
        }
    }
}
