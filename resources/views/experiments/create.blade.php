@extends('layouts.app')
@section('title', 'Eksperimen Baru')
@section('content')
<div class="page-head"><div><p class="eyebrow">Training setup</p><h1>Eksperimen baru</h1><p>Catat konfigurasi Colab agar hasil training dapat direproduksi.</p></div></div>
<form method="post" action="{{ route('experiments.store') }}" class="card">@csrf<div class="grid form-grid">
<div class="field"><label for="name">Nama eksperimen</label><input id="name" name="name" value="{{ old('name') }}" placeholder="PPE YOLO v1" required>@error('name')<span class="error-text">{{ $message }}</span>@enderror</div>
<div class="field"><label for="model_version">Versi model</label><select id="model_version" name="model_version"><option>YOLO26s</option><option>YOLO11s</option><option>YOLO11n</option><option>YOLOv8s</option><option>YOLOv8n</option></select></div>
<div class="field"><label for="dataset_name">Nama dataset</label><input id="dataset_name" name="dataset_name" value="{{ old('dataset_name') }}" placeholder="PPE Dataset v1"></div>
<div class="field"><label for="epochs">Epoch</label><input type="number" id="epochs" name="epochs" value="{{ old('epochs',100) }}" min="1" required></div>
<div class="field"><label for="batch_size">Batch size</label><input type="number" id="batch_size" name="batch_size" value="{{ old('batch_size',16) }}" min="1" required></div>
<div class="field"><label for="image_size">Image size</label><select id="image_size" name="image_size"><option value="416">416 px</option><option value="640" selected>640 px</option><option value="1280">1280 px</option></select></div>
<div class="field"><label for="optimizer">Optimizer</label><select id="optimizer" name="optimizer"><option>auto</option><option>AdamW</option><option>SGD</option></select></div>
<div class="field"><label for="device">Device</label><input id="device" name="device" value="{{ old('device','Google Colab T4') }}"></div>
<div class="field full"><label for="notes">Catatan</label><textarea id="notes" name="notes" placeholder="Augmentasi, pembagian dataset, dan catatan lainnya...">{{ old('notes') }}</textarea></div>
</div><div style="display:flex;justify-content:flex-end;gap:10px;margin-top:22px"><a href="{{ route('experiments.index') }}" class="btn btn-secondary">Batal</a><button class="btn btn-primary">Simpan eksperimen</button></div></form>
@endsection
