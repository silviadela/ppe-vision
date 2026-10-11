@extends('layouts.app')
@section('title', 'AI Summary')
@section('content')
<div class="page-head"><div><p class="eyebrow">AI insight</p><h1>Ringkasan hasil model</h1><p>{{ $run->name }} · Analisis metrics dalam bahasa yang mudah dipahami.</p></div><form method="post" action="{{ route('summary.generate',$run) }}">@csrf<button class="btn btn-primary">{{ $run->ai_summary ? 'Buat ulang ringkasan' : 'Buat ringkasan AI' }}</button></form></div>
<div class="grid dashboard-grid"><section class="card"><div class="card-title"><div><h2>Executive summary</h2><p>Evaluasi otomatis berdasarkan hasil validasi</p></div></div>
@if($run->ai_summary)<div class="summary">{{ $run->ai_summary }}</div><div class="summary-meta">Dibuat oleh {{ $run->summary_generated_by }} · {{ $run->updated_at->diffForHumans() }}</div>@else<div class="empty"><h3>Ringkasan belum dibuat</h3><p>Klik tombol di atas. Jika Ollama belum aktif, aplikasi memakai analisis lokal berbasis aturan.</p></div>@endif
</section><aside class="card"><div class="card-title"><div><h2>Snapshot metrics</h2><p>Dasar analisis</p></div></div>
@foreach(['mAP50-95'=>'map50_95','Precision'=>'precision','Recall'=>'recall','mAP50'=>'map50'] as $label=>$key)<div class="setting"><span>{{ $label }}</span><strong>{{ number_format(($run->metrics[$key]??0)*100,1) }}%</strong></div>@endforeach
</aside></div>
@endsection
