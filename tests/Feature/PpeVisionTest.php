<?php

namespace Tests\Feature;

use App\Models\Detection;
use App\Models\TrainingRun;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
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

    public function test_summary_is_not_replaced_when_ollama_is_disabled(): void
    {
        config(['services.ollama.enabled' => false]);
        $run = $this->completedRun();
        $run->update([
            'ai_summary' => 'Ringkasan Ollama sebelumnya.',
            'summary_generated_by' => 'Ollama · llama3.2:3b',
        ]);

        $this->post(route('summary.generate', $run))
            ->assertRedirect()
            ->assertSessionHas('error');

        $run->refresh();
        $this->assertSame('Ringkasan Ollama sebelumnya.', $run->ai_summary);
        $this->assertSame('Ollama · llama3.2:3b', $run->summary_generated_by);
    }

    public function test_summary_reads_map50_per_class_from_latest_checkpoint(): void
    {
        config([
            'services.ollama.enabled' => true,
            'services.ollama.url' => 'http://ollama.test',
            'services.ollama.model' => 'llama3.2:3b',
        ]);
        Http::fake([
            'http://ollama.test/api/generate' => Http::response([
                'response' => json_encode([
                    'performance_summary' => 'People menjadi kelas terlemah dan perlu diprioritaskan.',
                    'operational_risk' => 'False negative people dapat membuat pekerja tidak terpantau.',
                    'recommendations' => [
                        'Tambah variasi data people.',
                        'Analisis confusion matrix.',
                        'Uji confidence threshold di lapangan.',
                    ],
                ]),
            ]),
        ]);
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

        $this->post(route('summary.generate', $run))->assertRedirect();

        $run->refresh();
        $this->assertSame('Ollama · llama3.2:3b', $run->summary_generated_by);
        $this->assertStringContainsString('### Kesimpulan AI', $run->ai_summary);
        $this->assertStringContainsString('### Metrik tervalidasi dari database', $run->ai_summary);
        $this->assertStringContainsString('### mAP50-95 per kelas', $run->ai_summary);
        $this->assertStringContainsString('Masker: 59,8%', $run->ai_summary);
        $this->assertStringContainsString('### Rekomendasi', $run->ai_summary);
        $this->assertStringContainsString('3. Uji confidence threshold di lapangan.', $run->ai_summary);
        Http::assertSent(fn ($request) => $request['stream'] === false
            && $request['format']['required'] === ['performance_summary', 'operational_risk', 'recommendations']);
    }

    public function test_invalid_ollama_response_preserves_existing_summary(): void
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
        $run->update([
            'ai_summary' => 'Ringkasan Ollama sebelumnya.',
            'summary_generated_by' => 'Ollama · model-lama',
        ]);

        $this->post(route('summary.generate', $run))
            ->assertRedirect()
            ->assertSessionHas('error');

        $run->refresh();
        $this->assertSame('Ollama · model-lama', $run->summary_generated_by);
        $this->assertSame('Ringkasan Ollama sebelumnya.', $run->ai_summary);
    }

    public function test_ollama_metric_names_are_not_mistaken_for_hallucinated_numbers(): void
    {
        config([
            'services.ollama.enabled' => true,
            'services.ollama.url' => 'http://ollama.test',
            'services.ollama.model' => 'llama3.2:3b',
        ]);
        Http::fake([
            'http://ollama.test/api/generate' => Http::response([
                'response' => json_encode([
                    'performance_summary' => 'Masker paling lemah berdasarkan mAP50_95 dan perlu diprioritaskan.',
                    'operational_risk' => 'Kelas masker berisiko karena mAP50_95 relatif rendah.',
                    'recommendations' => [
                        'Perbaiki variasi data masker untuk meningkatkan akurasi model.',
                        'Tinjau false negative pada confusion matrix.',
                        'Uji confidence threshold dalam kondisi lapangan.',
                    ],
                ]),
            ]),
        ]);
        $run = $this->completedRun();

        $this->post(route('summary.generate', $run))->assertRedirect();

        $run->refresh();
        $this->assertSame('Ollama · llama3.2:3b', $run->summary_generated_by);
        $this->assertStringContainsString('mAP50_95', $run->ai_summary);
        $this->assertStringContainsString('kinerja deteksi model', $run->ai_summary);
        $this->assertStringNotContainsString('akurasi', strtolower($run->ai_summary));
    }

    public function test_ollama_gets_one_corrective_retry_before_reporting_failure(): void
    {
        config([
            'services.ollama.enabled' => true,
            'services.ollama.url' => 'http://ollama.test',
            'services.ollama.model' => 'llama3.2:3b',
        ]);
        Http::fake([
            'http://ollama.test/api/generate' => Http::sequence()
                ->push(['response' => json_encode([
                    'operational_risk' => 'Respons pertama tidak valid.',
                    'recommendations' => ['Saran tunggal.'],
                ])])
                ->push(['response' => json_encode([
                    'performance_summary' => 'Masker merupakan kelas terlemah yang perlu diprioritaskan.',
                    'operational_risk' => 'False negative masker perlu dipantau.',
                    'recommendations' => [
                        'Tambah variasi data masker.',
                        'Analisis confusion matrix.',
                        'Uji confidence threshold di lapangan.',
                    ],
                ])]),
        ]);
        $run = $this->completedRun();

        $this->post(route('summary.generate', $run))->assertRedirect();

        $run->refresh();
        $this->assertSame('Ollama · llama3.2:3b', $run->summary_generated_by);
        Http::assertSentCount(2);
    }

    public function test_online_summary_api_requires_its_private_token(): void
    {
        config(['services.summary_sync.token' => 'private-sync-token']);
        $run = $this->completedRun();

        $this->getJson('/api/local-ai/training-runs/'.$run->id)
            ->assertUnauthorized();
    }

    public function test_online_summary_api_reads_metrics_and_updates_the_same_record(): void
    {
        config([
            'services.summary_sync.token' => 'private-sync-token',
            'services.ollama.model' => 'llama3.2:3b',
        ]);
        $run = $this->completedRun();

        $source = $this->withToken('private-sync-token')
            ->getJson('/api/local-ai/training-runs/'.$run->id)
            ->assertOk()
            ->assertJsonPath('run.model_version', $run->model_version)
            ->json();

        $this->withToken('private-sync-token')
            ->putJson('/api/local-ai/training-runs/'.$run->id.'/summary', [
                'ai_summary' => '### Kesimpulan AI'."\n".'Ringkasan dari Ollama lokal.',
                'summary_generated_by' => 'Ollama · llama3.2:3b',
                'metrics_fingerprint' => $source['metrics_fingerprint'],
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Summary Ollama tersimpan di database online.');

        $run->refresh();
        $this->assertSame('Ollama · llama3.2:3b', $run->summary_generated_by);
        $this->assertStringContainsString('Ringkasan dari Ollama lokal.', $run->ai_summary);
    }

    public function test_online_summary_api_rejects_a_summary_for_stale_metrics(): void
    {
        config([
            'services.summary_sync.token' => 'private-sync-token',
            'services.ollama.model' => 'llama3.2:3b',
        ]);
        $run = $this->completedRun();
        $oldFingerprint = $this->withToken('private-sync-token')
            ->getJson('/api/local-ai/training-runs/'.$run->id)
            ->json('metrics_fingerprint');

        $run->update(['metrics' => array_merge($run->metrics, ['precision' => 0.99])]);

        $this->withToken('private-sync-token')
            ->putJson('/api/local-ai/training-runs/'.$run->id.'/summary', [
                'ai_summary' => 'Summary dari metrics lama.',
                'summary_generated_by' => 'Ollama · llama3.2:3b',
                'metrics_fingerprint' => $oldFingerprint,
            ])
            ->assertConflict();

        $this->assertNull($run->refresh()->ai_summary);
    }

    public function test_local_summary_action_can_use_online_metrics_and_push_to_online_database(): void
    {
        config([
            'services.ollama.enabled' => true,
            'services.ollama.url' => 'http://ollama.test',
            'services.ollama.model' => 'llama3.2:3b',
            'services.summary_sync.url' => 'https://dashboard.test',
            'services.summary_sync.token' => 'private-sync-token',
        ]);
        $run = $this->completedRun();
        $remoteRun = $run->only([
            'id', 'name', 'model_version', 'status', 'dataset_name', 'epochs',
            'batch_size', 'image_size', 'optimizer', 'device', 'metrics',
            'per_class_metrics',
        ]);

        Http::fake(function ($request) use ($remoteRun) {
            if ($request->method() === 'GET') {
                return Http::response([
                    'run' => $remoteRun,
                    'metrics_fingerprint' => str_repeat('a', 64),
                    'summary_url' => 'https://dashboard.test/experiments/1/summary',
                ]);
            }

            if ($request->url() === 'http://ollama.test/api/generate') {
                return Http::response(['response' => json_encode([
                    'performance_summary' => 'Masker merupakan kelas terlemah yang perlu diprioritaskan.',
                    'operational_risk' => 'False negative masker perlu dipantau.',
                    'recommendations' => [
                        'Tambah variasi data masker.',
                        'Analisis confusion matrix.',
                        'Uji confidence threshold di lapangan.',
                    ],
                ])]);
            }

            return Http::response([
                'message' => 'Summary Ollama tersimpan di database online.',
                'summary_url' => 'https://dashboard.test/experiments/1/summary',
            ]);
        });

        $this->post(route('summary.generate', $run))
            ->assertRedirect('https://dashboard.test/experiments/1/summary');

        $run->refresh();
        $this->assertNull($run->ai_summary);
        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && $request['summary_generated_by'] === 'Ollama · llama3.2:3b'
            && $request['metrics_fingerprint'] === str_repeat('a', 64));
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

    public function test_production_detection_failure_does_not_expose_internal_error(): void
    {
        Storage::fake('public');
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->app->detectEnvironment(fn () => 'production');
        config(['services.yolo.url' => 'http://yolo.test']);
        Http::fake([
            'http://yolo.test/health' => Http::response(['status' => 'ready']),
            'http://yolo.test/predict' => Http::response([
                'message' => 'Internal path C:\\private\\model.pt is unavailable.',
            ], 500),
        ]);

        $this->post(route('detection.store'), [
            'file' => UploadedFile::fake()->createWithContent(
                'worker.png',
                base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')
            ),
            'confidence' => .35,
        ])->assertRedirect()
            ->assertSessionHas('error', 'Deteksi gagal diproses. Silakan coba kembali.');

        $detection = Detection::firstOrFail();
        $this->assertSame('failed', $detection->status);
        $this->assertSame('Deteksi gagal diproses. Silakan coba kembali.', $detection->error_message);
        $this->assertStringNotContainsString('private', $detection->error_message);
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
