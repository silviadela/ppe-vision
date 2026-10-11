@extends('layouts.app')
@section('title', 'Demo Deteksi')
@section('content')
<div class="page-head">
    <div>
        <p class="eyebrow">Inference demo</p>
        <h1>Uji PPE detection</h1>
        <p>Deteksi Helm, Masker, dan People melalui gambar, video, atau kamera langsung.</p>
    </div>
</div>

<div class="class-strip">
    <span>Kelas model</span>
    <strong>Helm</strong>
    <strong>Masker</strong>
    <strong>People</strong>
</div>

@if($streamlitEmbedUrl)
    <section class="streamlit-panel" aria-label="Demo deteksi PPE">
        <iframe
            class="streamlit-frame"
            src="{{ $streamlitEmbedUrl }}"
            title="Demo deteksi PPE"
            loading="eager"
            allow="camera; autoplay; fullscreen"
        ></iframe>
        <noscript>
            <p>JavaScript perlu diaktifkan untuk menjalankan demo deteksi.</p>
            <a class="btn btn-primary" href="{{ $streamlitUrl }}">Buka demo deteksi</a>
        </noscript>
    </section>
@elseif($apiReady)
    <div class="grid dashboard-grid">
        <form method="post" action="{{ route('detection.store') }}" enctype="multipart/form-data" class="card">
            @csrf
            <div class="card-title">
                <div><h2>Pengujian gambar</h2><p>Pilih gambar PPE yang ingin diperiksa</p></div>
                <span class="pill pill-success">Siap</span>
            </div>
            <div class="upload">
                <div class="upload-icon">+</div>
                <h3>Pilih gambar pengujian</h3>
                <p class="hint">JPG, PNG, atau WEBP · maksimum 10 MB</p>
                <input type="file" name="file" accept="image/jpeg,image/png,image/webp" required>
            </div>
            <div class="grid form-grid" style="margin-top:18px">
                <div class="field">
                    <label>Model/eksperimen</label>
                    <select name="training_run_id">
                        <option value="">Model aktif</option>
                        @foreach($runs as $run)<option value="{{ $run->id }}">{{ $run->name }}</option>@endforeach
                    </select>
                </div>
                <div class="field">
                    <label>Confidence threshold</label>
                    <input type="number" name="confidence" value="0.20" min="0.05" max="0.95" step="0.05">
                    <span class="hint">Gunakan 0.20 untuk checkpoint saat ini.</span>
                </div>
            </div>
            <button class="btn btn-primary" style="width:100%;margin-top:18px">Jalankan deteksi</button>
        </form>

        <section class="card">
            <div class="card-title"><div><h2>Riwayat deteksi</h2><p>Pengujian terbaru</p></div></div>
            @forelse($detections as $item)
                @php($detectedItems = collect($item->detections ?? []))
                <div class="setting detection-history-item">
                    @if($item->output_path)
                        <img src="{{ asset('storage/'.$item->output_path) }}" alt="Hasil {{ $item->source_name }}">
                    @endif
                    <span>{{ $item->created_at->format('d M Y · H:i') }}</span>
                    <strong>{{ $item->source_name }}</strong>
                    <span>{{ $detectedItems->count() }} objek · {{ $item->inference_ms ? $item->inference_ms.' ms' : '—' }}</span>
                    @if($detectedItems->isNotEmpty())
                        <div class="detection-tags">
                            @foreach($detectedItems->take(8) as $detected)
                                <span>{{ ucfirst($detected['class'] ?? 'objek') }} {{ isset($detected['confidence']) ? number_format($detected['confidence'] * 100, 0).'%' : '' }}</span>
                            @endforeach
                        </div>
                    @else
                        <div class="no-detection">Belum ada kelas yang melewati confidence threshold.</div>
                    @endif
                    <span class="pill {{ $item->status==='completed'?'pill-success':($item->status==='failed'?'pill-failed':'pill-draft') }}">{{ $item->status }}</span>
                    @if($item->error_message)<div class="error-text" style="margin-top:5px">{{ $item->error_message }}</div>@endif
                </div>
            @empty
                <div class="empty">Belum ada pengujian.</div>
            @endforelse
        </section>
    </div>
@else
    <section class="card demo-unavailable">
        <div class="upload-icon">!</div>
        <h2>Demo deteksi belum tersedia</h2>
        <p>Alamat demo online belum dikonfigurasi. Isi <code>STREAMLIT_DEMO_URL</code>, lalu muat ulang halaman ini.</p>
    </section>
@endif
@endsection
