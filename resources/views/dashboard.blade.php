@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')
<div class="page-head"><div><p class="eyebrow">Overview</p><h1>Model performance</h1><p>Pantau eksperimen PPE detection dan kesiapan model dalam satu tempat.</p></div><a class="btn btn-primary" href="{{ route('experiments.create') }}">+ Eksperimen baru</a></div>
@if($latestRun)
@php($m = $latestRun->metrics ?? [])
<div class="grid stats">
@foreach([['mAP50-95', $m['map50_95'] ?? 0], ['Precision', $m['precision'] ?? 0], ['Recall', $m['recall'] ?? 0], ['mAP50', $m['map50'] ?? 0]] as [$label,$value])
<div class="stat"><div class="stat-top"><span>{{ $label }}</span><span class="delta">●</span></div><div class="stat-value">{{ number_format($value * 100, 1) }}%</div><div class="stat-note">{{ $latestRun->name }}</div></div>
@endforeach
</div>
<div class="grid dashboard-grid">
<section class="card"><div class="card-title"><div><h2>Training curve</h2><p>Perkembangan metric selama epoch</p></div><span class="pill pill-success">{{ $latestRun->status }}</span></div>
@php($history = $latestRun->training_history ?? [])
@if(count($history))
<svg class="chart" viewBox="0 0 700 220" preserveAspectRatio="none">
@for($i=0;$i<5;$i++)<line class="chart-grid" x1="35" y1="{{ 15+$i*42 }}" x2="690" y2="{{ 15+$i*42 }}"/>@endfor
@foreach(['map50_95'=>'#1f6b4f','precision'=>'#e9a23b','recall'=>'#7294a6'] as $key=>$color)
@php($points = collect($history)->values()->map(fn($row,$i) => (35 + ($i * (655 / max(count($history)-1,1)))).','. (190 - (($row[$key] ?? 0) * 170)))->implode(' '))
<polyline points="{{ $points }}" fill="none" stroke="{{ $color }}" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
@endforeach
<text class="chart-label" x="35" y="212">Epoch awal</text><text class="chart-label" x="630" y="212">Epoch {{ $latestRun->epochs }}</text></svg>
<div class="legend"><span><i style="background:#1f6b4f"></i>mAP50-95</span><span><i style="background:#e9a23b"></i>Precision</span><span><i style="background:#7294a6"></i>Recall</span></div>
@else<div class="empty">Training history belum diimpor.</div>@endif</section>
<section class="card"><div class="card-title"><div><h2>Performa per kelas</h2><p>mAP50-95 untuk tiap kategori</p></div></div><div class="classes">
@forelse($latestRun->per_class_metrics ?? [] as $class=>$metric)<div class="class-row"><strong>{{ $class }}</strong><div class="bar"><span style="width:{{ ($metric['map50_95'] ?? 0)*100 }}%"></span></div><span>{{ isset($metric['map50_95']) ? number_format($metric['map50_95']*100,1).'%' : '—' }}</span></div>@empty<div class="empty">Metrics per kelas belum ada.</div>@endforelse
</div><a href="{{ route('summary.show', $latestRun) }}" class="btn btn-secondary" style="width:100%;margin-top:25px">Lihat analisis AI</a></section></div>
@else<section class="card empty"><h2>Belum ada eksperimen</h2><p>Tambahkan konfigurasi training pertama untuk memulai.</p><a class="btn btn-primary" href="{{ route('experiments.create') }}">Buat eksperimen</a></section>@endif
<section class="card" style="margin-top:18px"><div class="card-title"><div><h2>Eksperimen terbaru</h2><p>Riwayat konfigurasi dan hasil training</p></div><a href="{{ route('experiments.index') }}" class="btn btn-secondary btn-sm">Lihat semua</a></div><div class="table-wrap"><table><thead><tr><th>Nama</th><th>Model</th><th>Epoch</th><th>Batch</th><th>mAP50-95</th><th>Status</th></tr></thead><tbody>
@forelse($runs as $run)<tr><td><a href="{{ route('experiments.show',$run) }}"><strong>{{ $run->name }}</strong></a></td><td>{{ $run->model_version }}</td><td>{{ $run->epochs }}</td><td>{{ $run->batch_size }}</td><td>{{ isset($run->metrics['map50_95']) ? number_format($run->metrics['map50_95']*100,1).'%' : '—' }}</td><td><span class="pill {{ $run->status==='completed'?'pill-success':'pill-draft' }}">{{ $run->status }}</span></td></tr>@empty<tr><td colspan="6" class="empty">Belum ada data.</td></tr>@endforelse
</tbody></table></div></section>
@endsection
