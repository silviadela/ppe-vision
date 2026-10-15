<?php

namespace Tests\Feature;

use App\Models\Detection;
use App\Models\TrainingRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PpeVisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_displays_latest_training_metrics(): void
    {
        $run = $this->completedRun();

        $this->get('/')
            ->assertOk()
            ->assertSee($run->name)
            ->assertSee('68.4%')
            ->assertSee('59.8%');
    }

    public function test_final_dashboard_metrics_match_model_metadata(): void
    {
        $this->seed();
        $run = TrainingRun::where('name', 'PPE YOLO26s Dataset 4 Final')->firstOrFail();
        $modelInfo = json_decode(
            file_get_contents(base_path('inference_service/models/model-info.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        foreach (['precision', 'recall', 'f1', 'map50', 'map50_95'] as $metric) {
            $this->assertEqualsWithDelta(
                $modelInfo['metrics'][$metric],
                $run->metrics[$metric],
                0.000000001,
                "Nilai {$metric} di database berbeda dari metadata model.",
            );
        }

        foreach (['Helmet' => 'helm', 'Mask' => 'masker', 'Person' => 'people'] as $source => $application) {
            $this->assertEqualsWithDelta(
                $modelInfo['metrics']['per_class_test'][$source]['map50_95'],
                $run->per_class_metrics[$application]['map50_95'],
                0.000000001,
                "mAP50-95 kelas {$application} berbeda dari metadata model.",
            );
        }

        $this->get('/')
            ->assertOk()
            ->assertSee('mAP50-95 pada test independen')
            ->assertSee('69.3%')
            ->assertSee('95.5%')
            ->assertSee('90.7%')
            ->assertSee('93.0%')
            ->assertSee('95.7%')
            ->assertSee('69.1%')
            ->assertSee('63.4%')
            ->assertSee('75.4%');
    }

    public function test_experiment_creation_is_not_exposed(): void
    {
        $this->completedRun();

        $this->get('/')->assertOk()->assertDontSee('Eksperimen baru');
        $this->get('/experiments')->assertOk()->assertDontSee('Eksperimen baru');
        $this->get('/experiments/create')->assertNotFound();
        $this->post('/experiments', [
            'name' => 'Colab Run 02',
            'model_version' => 'YOLO11n',
            'dataset_name' => 'PPE v2',
            'epochs' => 120,
            'batch_size' => 16,
            'image_size' => 640,
            'optimizer' => 'AdamW',
            'device' => 'T4',
        ])->assertMethodNotAllowed();

        $this->assertDatabaseMissing('training_runs', ['name' => 'Colab Run 02']);
    }

    public function test_summary_uses_local_analysis_when_ollama_is_disabled(): void
    {
        config(['services.ollama.enabled' => false]);
        $run = $this->completedRun();

        $this->post(route('summary.generate', $run))->assertRedirect();

        $run->refresh();
        $this->assertNotNull($run->ai_summary);
        $this->assertSame('Analisis lokal berbasis aturan (bukan LLM)', $run->summary_generated_by);
        $this->assertStringContainsString('Masker', $run->ai_summary);
    }

    public function test_summary_reads_map50_per_class_from_latest_checkpoint(): void
    {
        config(['services.ollama.enabled' => false]);
        $run = $this->completedRun();
        $run->update([
            'per_class_metrics' => [
                'helm' => ['map50' => .975],
                'masker' => ['map50' => .922],
                'people' => ['map50' => .913],
            ],
        ]);

        $this->post(route('summary.generate', $run))->assertRedirect();

        $run->refresh();
        $this->assertStringContainsString('People', $run->ai_summary);
        $this->assertStringContainsString('mAP50 91,3%', $run->ai_summary);
    }

    public function test_ollama_summary_is_saved_as_structured_markdown(): void
    {
        config([
            'services.ollama.enabled' => true,
            'services.ollama.url' => 'http://ollama.test',
            'services.ollama.model' => 'llama3.2:3b',
        ]);
        Http::fake([
            'http://ollama.test/api/generate' => Http::response([
                'response' => json_encode([
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

        $this->post(route('summary.generate', $run))->assertRedirect();

        $run->refresh();
        $this->assertSame('Ollama · llama3.2:3b', $run->summary_generated_by);
        $this->assertStringContainsString('### Kesimpulan', $run->ai_summary);
        $this->assertStringContainsString('### mAP50-95 per kelas', $run->ai_summary);
        $this->assertStringContainsString('Masker: 59,8%', $run->ai_summary);
        $this->assertStringContainsString('### Rekomendasi', $run->ai_summary);
        $this->assertStringContainsString('3. Uji confidence threshold di lapangan.', $run->ai_summary);
        Http::assertSent(fn ($request) => $request['stream'] === false
            && $request['format']['required'] === ['operational_risk', 'recommendations']);
    }

    public function test_invalid_ollama_response_is_labelled_as_rule_based_fallback(): void
    {
        config([
            'services.ollama.enabled' => true,
            'services.ollama.url' => 'http://ollama.test',
            'services.ollama.model' => 'llama3.2:3b',
        ]);
        Http::fake([
            'http://ollama.test/api/generate' => Http::response([
                'response' => json_encode([
                    'operational_risk' => 'Jawaban tidak membahas kelas yang lemah.',
                    'recommendations' => ['Saran yang tidak sesuai.'],
                ]),
            ]),
        ]);
        $run = $this->completedRun();

        $this->post(route('summary.generate', $run))->assertRedirect();

        $run->refresh();
        $this->assertSame('Analisis lokal berbasis aturan (bukan LLM)', $run->summary_generated_by);
        $this->assertStringContainsString('### Risiko operasional', $run->ai_summary);
    }

    public function test_training_metrics_can_be_imported_manually(): void
    {
        $run = TrainingRun::create([
            'name' => 'Draft run', 'model_version' => 'YOLO11n', 'status' => 'draft',
            'epochs' => 50, 'batch_size' => 8, 'image_size' => 640,
        ]);

        $this->post(route('experiments.metrics', $run), [
            'precision' => .81, 'recall' => .78, 'map50' => .89, 'map50_95' => .64,
            'helm_map' => .70, 'masker_map' => .55, 'people_map' => .67,
        ])->assertRedirect();

        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame(.55, $run->per_class_metrics['masker']['map50_95']);
    }

    public function test_experiment_detail_displays_real_evaluation_artifacts(): void
    {
        $run = $this->completedRun();
        $run->update([
            'metrics' => [...$run->metrics, 'best_epoch' => 50],
            'results_plot_path' => 'training/run/results.png',
            'confusion_matrix_path' => 'training/run/confusion_matrix.png',
        ]);

        $this->get(route('experiments.show', $run))
            ->assertOk()
            ->assertSee('Artefak evaluasi')
            ->assertSee('Epoch terbaik 50');
    }

    public function test_detection_page_explains_when_api_is_not_configured(): void
    {
        config([
            'services.yolo.url' => null,
            'services.streamlit.url' => null,
        ]);

        $this->get(route('detection.index'))
            ->assertOk()
            ->assertSee('Demo deteksi belum tersedia');
    }

    public function test_online_detection_demo_is_embedded_in_the_dashboard(): void
    {
        config([
            'services.yolo.url' => null,
            'services.streamlit.url' => 'https://ppe-vision-demo.streamlit.app',
        ]);

        $this->get(route('detection.index'))
            ->assertOk()
            ->assertSee('Uji PPE detection')
            ->assertSee('<iframe', false)
            ->assertSee('https://ppe-vision-demo.streamlit.app?embed=true', false)
            ->assertDontSee('Laravel tetap menjadi aplikasi utama')
            ->assertDontSee('Buka demo Streamlit');
    }

    public function test_detection_result_from_yolo_api_is_persisted(): void
    {
        Storage::fake('public');
        config(['services.yolo.url' => 'http://yolo.test']);
        Http::fake([
            'http://yolo.test/health' => Http::response(['status' => 'ready']),
            'http://yolo.test/predict' => Http::response([
                'detections' => [[
                    'class' => 'helm',
                    'source_class' => 'hardhat',
                    'confidence' => .91,
                    'bbox' => [10, 20, 100, 150],
                ]],
                'inference_ms' => 193.38,
                'annotated_image' => base64_encode('annotated-image'),
            ]),
        ]);

        $this->post(route('detection.store'), [
            'file' => UploadedFile::fake()->createWithContent(
                'worker.png',
                base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')
            ),
            'confidence' => .35,
        ])->assertRedirect()->assertSessionHas('success');

        $detection = Detection::firstOrFail();
        $this->assertSame('completed', $detection->status);
        $this->assertSame('helm', $detection->detections[0]['class']);
        Storage::disk('public')->assertExists($detection->output_path);
    }

    public function test_web_installer_is_hidden_without_a_token(): void
    {
        config(['deployment.infinityfree_install_token' => null]);

        $this->get('/install')->assertNotFound();

        config(['deployment.infinityfree_install_token' => 'GANTI_DENGAN_TOKEN_ACAK_MINIMAL_32_KARAKTER']);

        $this->get('/install')->assertNotFound();
    }

    public function test_production_mode_hides_admin_writes(): void
    {
        config(['deployment.allow_admin_writes' => false]);
        $run = $this->completedRun();
        $run->update([
            'results_plot_path' => 'training/run/results.png',
            'confusion_matrix_path' => 'training/run/confusion_matrix.png',
        ]);

        $this->get('/experiments/create')->assertNotFound();
        $this->post(route('experiments.metrics', $run), [])->assertNotFound();
        $this->post(route('summary.generate', $run))->assertNotFound();
        $this->get('/')->assertOk()->assertDontSee('+ Eksperimen baru');
        $this->get(route('experiments.show', $run))
            ->assertOk()
            ->assertSee('Artefak evaluasi')
            ->assertDontSee('Impor hasil dari Colab');
    }

    public function test_web_installer_runs_migrations_and_seeder_with_the_correct_token(): void
    {
        $token = 'test-install-token-random-32-characters';
        config(['deployment.infinityfree_install_token' => $token]);

        $this->get('/install')->assertOk()->assertSee('Instalasi database');
        $this->post('/install', ['install_token' => 'wrong-token'])
            ->assertSessionHasErrors('install_token');

        $this->post('/install', ['install_token' => $token])
            ->assertOk()
            ->assertSee('Instalasi berhasil');

        $this->assertDatabaseHas('training_runs', [
            'name' => 'PPE YOLO26s Dataset 4 Final',
        ]);
    }

    private function completedRun(): TrainingRun
    {
        return TrainingRun::create([
            'name' => 'PPE Baseline',
            'model_version' => 'YOLO11n',
            'status' => 'completed',
            'epochs' => 100,
            'batch_size' => 16,
            'image_size' => 640,
            'metrics' => ['precision' => .873, 'recall' => .821, 'map50' => .912, 'map50_95' => .684],
            'per_class_metrics' => [
                'helm' => ['map50_95' => .731],
                'masker' => ['map50_95' => .598],
                'people' => ['map50_95' => .722],
            ],
        ]);
    }
}
