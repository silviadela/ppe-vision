<?php

namespace Tests\Feature;

use App\Models\TrainingRun;
use App\Services\SummaryExchangeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BrowserSummarySyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.summary_sync.token' => 'private-sync-token-at-least-32-characters',
            'services.ollama.enabled' => true,
            'services.ollama.url' => 'http://ollama.test',
            'services.ollama.model' => 'llama3.2:3b',
        ]);
    }

    public function test_browser_exchange_page_is_available_without_enabling_admin_writes(): void
    {
        config(['deployment.allow_admin_writes' => false]);
        $run = $this->completedRun();

        $this->get(route('summary.exchange', $run))
            ->assertOk()
            ->assertSee('Sinkronisasi Ollama lokal')
            ->assertSee('summary:prepare-browser');
    }

    public function test_online_summary_page_links_to_the_browser_exchange(): void
    {
        config(['deployment.allow_admin_writes' => false]);
        $run = $this->completedRun();
        $run->update([
            'ai_summary' => '### Kesimpulan AI'."\nRingkasan tersimpan.",
            'summary_generated_by' => 'Ollama · llama3.2:3b',
        ]);

        $this->get(route('summary.show', $run))
            ->assertOk()
            ->assertSee('Perbarui analisis AI')
            ->assertSee(route('summary.exchange', $run), false);
    }

    public function test_metrics_download_requires_the_private_token(): void
    {
        $run = $this->completedRun();

        $this->post(route('summary.exchange.download', $run), [
            'sync_token' => 'wrong-token',
        ])->assertSessionHasErrors('sync_token');
    }

    public function test_browser_download_returns_a_signed_metrics_package(): void
    {
        $run = $this->completedRun();

        $response = $this->post(route('summary.exchange.download', $run), [
            'sync_token' => 'private-sync-token-at-least-32-characters',
        ])->assertOk()->assertDownload('ppe-metrics-run-'.$run->id.'.json');

        $payload = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(SummaryExchangeService::METRICS_SCHEMA, $payload['schema']);
        $this->assertSame($run->id, $payload['run']['id']);
        $this->assertSame(64, strlen($payload['metrics_fingerprint']));
        $this->assertSame(64, strlen($payload['source_signature']));
    }

    public function test_metrics_fingerprint_is_stable_across_php_float_precision_settings(): void
    {
        $run = $this->completedRun();
        $exchange = app(SummaryExchangeService::class);
        $previousPrecision = ini_get('serialize_precision');

        try {
            ini_set('serialize_precision', '53');
            $downloadedJson = json_encode(
                $exchange->metricsPackage($run),
                JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
            );

            ini_set('serialize_precision', '-1');
            $validated = $exchange->validateMetricsPackage(json_decode(
                $downloadedJson,
                true,
                flags: JSON_THROW_ON_ERROR,
            ));
        } finally {
            ini_set('serialize_precision', (string) $previousPrecision);
        }

        $this->assertSame($run->id, $validated['run']['id']);
    }

    public function test_local_command_creates_a_valid_ollama_result_file_without_a_database_write(): void
    {
        Storage::fake('local');
        Http::fake([
            'http://ollama.test/api/generate' => Http::response([
                'response' => json_encode([
                    'performance_summary' => 'Masker merupakan kelas terlemah yang perlu diprioritaskan.',
                    'operational_risk' => 'False negative masker perlu dipantau.',
                    'recommendations' => [
                        'Tambah variasi data masker.',
                        'Analisis confusion matrix.',
                        'Uji confidence threshold di lapangan.',
                    ],
                ]),
            ]),
        ]);

        $run = $this->completedRun();
        $source = app(SummaryExchangeService::class)->metricsPackage($run);
        Storage::disk('local')->put('ppe-metrics-run-'.$run->id.'.json', json_encode($source));
        $outputPath = Storage::disk('local')->path('summary-sync-run-'.$run->id.'.json');

        $this->artisan('summary:prepare-browser', [
            'metrics_file' => Storage::disk('local')->path('ppe-metrics-run-'.$run->id.'.json'),
            '--output' => $outputPath,
        ])->assertSuccessful();

        Storage::disk('local')->assertExists('summary-sync-run-'.$run->id.'.json');
        $result = json_decode(Storage::disk('local')->get('summary-sync-run-'.$run->id.'.json'), true);
        $this->assertSame(SummaryExchangeService::RESULT_SCHEMA, $result['schema']);
        $this->assertSame('Ollama · llama3.2:3b', $result['summary_generated_by']);
        $this->assertStringContainsString('### Metrik tervalidasi dari database', $result['ai_summary']);
    }

    public function test_browser_upload_saves_a_valid_ollama_result_to_the_same_record(): void
    {
        $run = $this->completedRun();
        $exchange = app(SummaryExchangeService::class);
        $source = $exchange->metricsPackage($run);
        $result = $exchange->resultPackage($source, $this->validSummary());

        $this->post(route('summary.exchange.upload', $run), [
            'sync_token' => 'private-sync-token-at-least-32-characters',
            'summary_file' => UploadedFile::fake()->createWithContent(
                'summary-sync-run-'.$run->id.'.json',
                json_encode($result, JSON_UNESCAPED_UNICODE),
            ),
        ])->assertRedirect(route('summary.show', $run))
            ->assertSessionHas('success');

        $run->refresh();
        $this->assertSame('Ollama · llama3.2:3b', $run->summary_generated_by);
        $this->assertStringContainsString('Ringkasan dari Ollama lokal.', $run->ai_summary);
    }

    public function test_browser_upload_rejects_a_result_when_online_metrics_changed(): void
    {
        $run = $this->completedRun();
        $exchange = app(SummaryExchangeService::class);
        $result = $exchange->resultPackage($exchange->metricsPackage($run), $this->validSummary());
        $run->update(['metrics' => [...$run->metrics, 'precision' => 0.99]]);

        $this->post(route('summary.exchange.upload', $run), [
            'sync_token' => 'private-sync-token-at-least-32-characters',
            'summary_file' => UploadedFile::fake()->createWithContent('stale.json', json_encode($result)),
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertNull($run->refresh()->ai_summary);
    }

    private function validSummary(): array
    {
        return [
            'text' => "### Kesimpulan AI\nRingkasan dari Ollama lokal."
                ."\n\n### Metrik tervalidasi dari database\nMetrik pasti dari Laravel."
                ."\n\n### Rekomendasi\n1. Evaluasi data.",
            'engine' => 'Ollama · llama3.2:3b',
        ];
    }

    private function completedRun(): TrainingRun
    {
        return TrainingRun::create([
            'name' => 'PPE Browser Sync Test',
            'model_version' => 'YOLO26s',
            'status' => 'completed',
            'epochs' => 60,
            'batch_size' => 16,
            'image_size' => 640,
            'metrics' => [
                'precision' => .9548,
                'recall' => .9070,
                'f1' => .9303,
                'map50' => .9570,
                'map50_95' => .6930,
            ],
            'per_class_metrics' => [
                'helm' => ['map50_95' => .6905],
                'masker' => ['map50_95' => .6340],
                'people' => ['map50_95' => .7545],
            ],
        ]);
    }
}
