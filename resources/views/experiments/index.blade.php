@extends('layouts.app')
@section('title', 'Eksperimen')
@section('content')
<div class="page-head"><div><p class="eyebrow">Training</p><h1>Eksperimen</h1><p>Bandingkan konfigurasi dan hasil training YOLO yang telah dievaluasi.</p></div></div>
<section class="card"><div class="table-wrap"><table><thead><tr><th>Eksperimen</th><th>Dataset</th><th>Setting</th><th>mAP50-95</th><th>Status</th><th></th></tr></thead><tbody>
@forelse($runs as $run)<tr><td><strong>{{ $run->name }}</strong><div class="hint">{{ $run->model_version }}</div></td><td>{{ $run->dataset_name ?: '—' }}</td><td>{{ $run->epochs }} ep · batch {{ $run->batch_size }} · {{ $run->image_size }}px</td><td>{{ isset($run->metrics['map50_95']) ? number_format($run->metrics['map50_95']*100,1).'%' : '—' }}</td><td><span class="pill {{ $run->status==='completed'?'pill-success':'pill-draft' }}">{{ $run->status }}</span></td><td><a class="btn btn-secondary btn-sm" href="{{ route('experiments.show',$run) }}">Detail</a></td></tr>@empty<tr><td colspan="6" class="empty">Belum ada eksperimen.</td></tr>@endforelse
</tbody></table></div></section><div style="margin-top:18px">{{ $runs->links() }}</div>
@endsection
