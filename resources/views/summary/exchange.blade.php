@extends('layouts.app')
@section('title', 'Sinkronisasi Ollama Lokal')
@section('content')
<div class="page-head"><div><p class="eyebrow">Browser-assisted sync</p><h1>Sinkronisasi Ollama lokal</h1><p>{{ $run->name }} · Pindahkan metrics dan summary dengan file JSON tervalidasi.</p></div><a class="btn btn-secondary" href="{{ route('summary.show', $run) }}">Kembali ke summary</a></div>

@if($errors->any())<div class="alert alert-error"><strong>Data belum dapat diproses.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

<div class="grid dashboard-grid">
    <section class="card">
        <div class="card-title"><div><p class="eyebrow">Langkah 1</p><h2>Unduh metrics dari MySQL online</h2><p>Browser mengambil snapshot metrics yang ditandatangani server.</p></div></div>
        <form method="post" action="{{ route('summary.exchange.download', $run) }}" class="grid form-grid">
            @csrf
            <div class="field full"><label for="download-token">Token sinkronisasi</label><input id="download-token" type="password" name="sync_token" required autocomplete="off"><span class="hint">Nilainya sama dengan SUMMARY_SYNC_TOKEN pada .env lokal dan server.</span></div>
            <div class="field full"><button class="btn btn-primary" type="submit">Unduh metrics JSON</button></div>
        </form>
    </section>

    <aside class="card">
        <div class="card-title"><div><p class="eyebrow">Langkah 2</p><h2>Proses dengan Ollama lokal</h2><p>Jalankan di terminal project setelah file selesai diunduh.</p></div></div>
        <pre style="white-space:pre-wrap;overflow-wrap:anywhere;padding:14px;border-radius:10px;background:#f1f8f4;color:#183e31">php artisan summary:prepare-browser "C:\Users\Silvia Dels\Downloads\ppe-metrics-run-{{ $run->id }}.json"</pre>
        <p class="hint">Perintah menghasilkan <strong>storage/app/private/summary-sync-run-{{ $run->id }}.json</strong>. Metrics tidak dikirim ke layanan AI eksternal.</p>
    </aside>
</div>

<section class="card" style="margin-top:18px">
    <div class="card-title"><div><p class="eyebrow">Langkah 3</p><h2>Unggah summary ke MySQL online</h2><p>Server memeriksa token, model Ollama, ID eksperimen, dan fingerprint metrics sebelum menyimpan.</p></div></div>
    <form method="post" enctype="multipart/form-data" action="{{ route('summary.exchange.upload', $run) }}" class="grid form-grid">
        @csrf
        <div class="field"><label for="upload-token">Token sinkronisasi</label><input id="upload-token" type="password" name="sync_token" required autocomplete="off"></div>
        <div class="field"><label for="summary-file">File summary JSON</label><input id="summary-file" type="file" name="summary_file" accept="application/json,.json" required></div>
        <div class="field full"><button class="btn btn-primary" type="submit">Validasi dan simpan summary</button></div>
    </form>
</section>
@endsection
