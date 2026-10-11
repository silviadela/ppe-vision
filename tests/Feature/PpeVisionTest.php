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
            ->assertSee('68.4%');
    }

    public function test_training_configuration_can_be_saved(): void
    {
        $response = $this->post(route('experiments.store'), [
            'name' => 'Colab Run 02',
            'model_version' => 'YOLO11n',
            'dataset_name' => 'PPE v2',
            'epochs' => 120,
            'batch_size' => 16,
            'image_size' => 640,
            'optimizer' => 'AdamW',
            'device' => 'T4',
        ]);

        $run = TrainingRun::where('name', 'Colab Run 02')->firstOrFail();
        $response->assertRedirect(route('experiments.show', $run));
        $this->assertSame('draft', $run->status);
    }

    public function test_summary_uses_local_analysis_when_ollama_is_disabled(): void
    {
        config(['services.ollama.enabled' => false]);
        $run = $this->completedRun();

        $this->post(route('summary.generate', $run))->assertRedirect();

        $run->refresh();
        $this->assertNotNull($run->ai_summary);
        $this->assertSame('Analisis lokal berbasis aturan', $run->summary_generated_by);
        $this->assertStringContainsString('masker', $run->ai_summary);
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
