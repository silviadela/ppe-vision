@extends('layouts.app')
@section('title', 'AI Summary')
@section('content')
<div class="page-head"><div><p class="eyebrow">AI insight</p><h1>Ringkasan hasil model</h1><p>{{ $run->name }} · Analisis metrics dalam bahasa yang mudah dipahami.</p></div>
<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
@if(config('deployment.allow_admin_writes') && config('services.ollama.enabled'))<form method="post" action="{{ route('summary.generate',$run) }}">@csrf<button class="btn btn-primary">{{ $run->ai_summary ? 'Buat ulang dengan Ollama' : 'Buat ringkasan dengan Ollama' }}</button></form>@elseif($run->ai_summary)<span class="pill pill-success">AI summary tersimpan</span>@endif
@if(config('services.summary_sync.token'))<a class="btn btn-secondary" href="{{ route('summary.exchange', $run) }}" title="Unduh metrics dan unggah hasil Ollama lokal"><svg aria-hidden="true" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="width:17px;height:17px"><path stroke-width="2" d="M20 12a8 8 0 0 1-14.9 4M4 12A8 8 0 0 1 18.9 8M5 20v-4h4M19 4v4h-4"/></svg>Perbarui analisis AI</a>@endif
</div>
</div>
<div class="grid dashboard-grid"><section class="card"><div class="card-title"><div><h2>Executive summary</h2><p>Evaluasi otomatis berdasarkan hasil {{ ($run->metrics['evaluation_split'] ?? null) === 'test' ? 'test independen' : 'validasi' }}</p></div></div>
@if($run->ai_summary)<div class="summary">{!! \Illuminate\Support\Str::markdown($run->ai_summary, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}</div><div class="summary-meta">Angka divalidasi dari database · Analisis oleh {{ $run->summary_generated_by }} · {{ $run->updated_at->diffForHumans() }}</div>@else<div class="empty"><h3>Ringkasan belum dibuat</h3><p>Aktifkan Ollama lokal, lalu klik tombol di atas. Aplikasi tidak membuat summary pengganti berbasis aturan.</p></div>@endif
</section><aside class="card"><div class="card-title"><div><h2>Snapshot metrics</h2><p>Dasar analisis</p></div></div>
@foreach(['mAP50-95'=>'map50_95','Precision'=>'precision','Recall'=>'recall','F1'=>'f1','mAP50'=>'map50'] as $label=>$key)<div class="setting"><span>{{ $label }}</span><strong>{{ number_format(($run->metrics[$key]??0)*100,1) }}%</strong></div>@endforeach
<div class="card-title" style="margin-top:22px"><div><h2>mAP50-95 per kelas</h2><p>Dari evaluasi test independen</p></div></div>
@foreach($run->per_class_metrics ?? [] as $class => $metric)<div class="setting"><span style="text-transform:capitalize">{{ $class }}</span><strong>{{ isset($metric['map50_95']) ? number_format($metric['map50_95']*100,1).'%' : '—' }}</strong></div>@endforeach
</aside></div>
@endsection
