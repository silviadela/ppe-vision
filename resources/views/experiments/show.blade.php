@extends('layouts.app')
@section('title', $run->name)
@section('content')
<div class="page-head"><div><p class="eyebrow">Experiment detail</p><h1>{{ $run->name }}</h1><p>{{ $run->dataset_name }} · {{ $run->model_version }}</p></div>@if($run->metrics)<a class="btn btn-primary" href="{{ route('summary.show',$run) }}">Analisis AI</a>@endif</div>
<div class="grid metric-grid">
@foreach([['mAP50-95',$run->metrics['map50_95']??null],['mAP50',$run->metrics['map50']??null],['Precision',$run->metrics['precision']??null],['Recall',$run->metrics['recall']??null]] as [$label,$value])
<div class="stat"><div class="stat-top">{{ $label }}</div><div class="stat-value">{{ $value===null?'—':number_format($value*100,1).'%' }}</div></div>@endforeach
</div>
<div class="grid dashboard-grid" style="margin-top:18px">
<section class="card"><div class="card-title"><div><h2>Konfigurasi training</h2><p>Parameter yang digunakan di Google Colab</p></div></div><div class="grid settings-grid">
@foreach(['Model'=>$run->model_version,'Epoch'=>$run->epochs,'Batch size'=>$run->batch_size,'Image size'=>$run->image_size.' px','Optimizer'=>$run->optimizer,'Device'=>$run->device] as $label=>$value)<div class="setting"><span>{{ $label }}</span><strong>{{ $value ?: '—' }}</strong></div>@endforeach
</div>@if($run->notes)<p style="margin-top:18px;color:var(--muted)">{{ $run->notes }}</p>@endif</section>
<section class="card"><div class="card-title"><div><h2>Kelas objek</h2><p>Pemetaan kelas model ke istilah assessment</p></div></div><div class="classes">
@forelse($run->per_class_metrics??[] as $class=>$metric)
<div class="setting"><strong style="text-transform:capitalize">{{ $class }}</strong><span>Kelas model: {{ $metric['source_class'] ?? $class }} @if(isset($metric['map50'])) · mAP50 {{ number_format($metric['map50']*100,1) }}% @elseif(isset($metric['map50_95'])) · mAP50-95 {{ number_format($metric['map50_95']*100,1) }}% @else · metric per kelas tidak tersedia @endif</span></div>
@empty
<div class="empty">Hasil belum diimpor.</div>
@endforelse
</div></section></div>
@if($run->results_plot_path || $run->confusion_matrix_path)
<section class="card" style="margin-top:18px">
<div class="card-title"><div><h2>Artefak evaluasi</h2><p>Grafik dan confusion matrix asli dari Google Colab.</p></div><span class="pill pill-success">Epoch terbaik {{ $run->metrics['best_epoch'] ?? '—' }}</span></div>
<div class="artifact-grid">
@foreach([
    'Training Results' => $run->results_plot_path,
    'Confusion Matrix' => $run->confusion_matrix_path,
    'Normalized Confusion Matrix' => $run->confusion_matrix_normalized_path,
    'Precision–Recall Curve' => $run->pr_curve_path,
    'F1–Confidence Curve' => $run->metrics['artifacts']['f1_curve'] ?? null,
    'Precision–Confidence Curve' => $run->metrics['artifacts']['precision_curve'] ?? null,
    'Recall–Confidence Curve' => $run->metrics['artifacts']['recall_curve'] ?? null,
    'Distribusi Label Dataset' => $run->metrics['artifacts']['label_distribution'] ?? null,
    'Contoh Prediksi Validasi' => $run->metrics['artifacts']['validation_prediction'] ?? null,
] as $label => $path)
@if($path)<figure class="artifact"><a href="{{ asset($path) }}" target="_blank" rel="noopener"><img src="{{ asset($path) }}" alt="{{ $label }}"></a><figcaption>{{ $label }}</figcaption></figure>@endif
@endforeach
</div>
</section>
@endif
<section class="card" style="margin-top:18px">
<div class="card-title"><div><h2>Impor hasil dari Colab</h2><p>Gunakan nilai akhir dari validasi; unggah results.csv untuk menggambar training curve.</p></div><span class="pill pill-draft">0–1</span></div>
<form method="post" action="{{ route('experiments.metrics',$run) }}" enctype="multipart/form-data">@csrf
<div class="grid form-grid">
<div class="field full"><label>Ultralytics results.csv (opsional)</label><input type="file" name="results_csv" accept=".csv,text/csv"><span class="hint">Biasanya berada di runs/detect/train/results.csv</span></div>
@foreach(['precision'=>'Precision','recall'=>'Recall','map50'=>'mAP50','map50_95'=>'mAP50-95'] as $name=>$label)<div class="field"><label>{{ $label }}</label><input type="number" name="{{ $name }}" min="0" max="1" step="0.0001" value="{{ old($name,$run->metrics[$name]??'') }}" required>@error($name)<span class="error-text">{{ $message }}</span>@enderror</div>@endforeach
@foreach(['helm_map'=>'Helm mAP50-95','masker_map'=>'Masker mAP50-95','people_map'=>'People mAP50-95'] as $name=>$label)<div class="field"><label>{{ $label }}</label><input type="number" name="{{ $name }}" min="0" max="1" step="0.0001" value="{{ old($name) }}" placeholder="Opsional"></div>@endforeach
</div><div style="display:flex;justify-content:flex-end;margin-top:18px"><button class="btn btn-primary">Simpan hasil training</button></div></form>
</section>
@endsection
