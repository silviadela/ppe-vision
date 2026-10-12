# PPE Vision

Dashboard Laravel untuk mencatat konfigurasi training YOLO, menampilkan precision/recall/mAP, membuat ringkasan metrics, dan mendemokan deteksi kelas **Helm, Masker, People** melalui gambar, video, serta foto kamera.

## Arsitektur aplikasi

Pengguna cukup membuka satu URL dashboard. Halaman **Demo Deteksi** menampilkan panel inferensi secara langsung di dalam layout aplikasi, sehingga tidak perlu berpindah tab atau membuka link kedua. Panel menyediakan tiga mode: upload gambar, upload video, dan ambil foto dari kamera.

```text
Pengguna
   |
   v
Dashboard web
   |- Dashboard
   |- Konfigurasi eksperimen
   |- Metrics dan artefak training
   |- AI summary
   |- Riwayat deteksi lokal
   |
   `- Demo Deteksi ---> gambar / video / kamera ---> best.pt
```

Untuk pengembangan lokal, form cadangan dapat mengirim gambar ke FastAPI. Pada hosting gratis, proses Python/PyTorch berjalan pada layanan inferensi yang ditampilkan sebagai panel tertanam. Pengguna tetap melihat satu dashboard dan cukup menerima satu URL.

## Menjalankan Laravel

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
php artisan migrate --seed
npm install
npm run build
php artisan serve
```

Buka `http://127.0.0.1:8000`. Database lokal memakai SQLite.

## Model Colab yang terintegrasi

Project menggunakan checkpoint **PPE YOLO11s New Dataset v1** dengan kelas sumber `Helmet`, `Mask`, dan `Person`. API menormalkannya menjadi `helm`, `masker`, dan `people`. Konfigurasi dan checksum model tercatat di `inference_service/models/model-info.json`.

Metric checkpoint terbaik (epoch 60): precision 0.93318, recall 0.89616, mAP50 0.93655, dan mAP50-95 0.66467. mAP50 per kelas adalah Helmet 0.975, Mask 0.922, dan Person 0.913. Grafik asli tersedia di halaman detail eksperimen.

File model berada di `inference_service/models/best.pt` dan sudah diizinkan oleh `.gitignore` untuk ikut repository. Jika project dipindahkan tanpa file model, lakukan langkah berikut.

## Menyambungkan model Colab

1. Unduh `best.pt` dari Google Colab.
2. Simpan sebagai `inference_service/models/best.pt`.
3. Aktifkan environment Python dan jalankan API:

```powershell
.\.venv\Scripts\Activate.ps1
uvicorn inference_service.main:app --host 127.0.0.1 --port 8001
```

4. Isi `.env` Laravel dengan `YOLO_API_URL=http://127.0.0.1:8001`, lalu jalankan `php artisan config:clear`.

API dapat diperiksa di `http://127.0.0.1:8001/health`.

## Menjalankan demo Streamlit lokal

```powershell
.\.venv\Scripts\python.exe -m pip install -r streamlit_app\requirements.txt
.\.venv\Scripts\python.exe -m streamlit run streamlit_app\app.py
```

Streamlit akan tersedia pada `http://127.0.0.1:8501`. Tambahkan URL tersebut ke `.env` Laravel:

```env
STREAMLIT_DEMO_URL=http://127.0.0.1:8501
```

Kemudian jalankan `php artisan config:clear`. Buka menu **Demo Deteksi**; panel upload dan hasil inferensi akan langsung tampil di halaman tersebut.

Mode video memerlukan `ffmpeg` agar hasil dapat diputar sebagai MP4 di browser. Streamlit Community Cloud memasang dependensi sistem dari `packages.txt`. Mode kamera menggunakan pengambilan satu foto sehingga tidak memerlukan STUN/TURN; pengguna cukup memberi izin kamera lalu menekan **Take Photo**.

## Deployment gratis

### 1. Laravel di InfinityFree

Laravel adalah aplikasi utama dan menggunakan subdomain gratis InfinityFree. Deployment memakai PHP dan MySQL persisten tanpa kartu kredit. Karena paket gratis tidak menyediakan SSH, Composer, atau NPM, semua dependency dibangun lebih dahulu di komputer.

Repository menyediakan:

- `.env.infinityfree.example` untuk konfigurasi production;
- `deploy/infinityfree/build-package.ps1` untuk membuat paket siap-upload;
- halaman instalasi database satu kali di `/install`;
- [`INFINITYFREE_DEPLOY.md`](INFINITYFREE_DEPLOY.md) untuk panduan lengkap.

Seluruh isi paket diunggah ke `htdocs`. `.htaccess` root mengarahkan request ke folder Laravel `public`, sedangkan kredensial MySQL hanya disimpan dalam `.env` server.

### 2. Demo di Streamlit Community Cloud

1. Buka Streamlit Community Cloud dan pilih repository yang sama.
2. Gunakan entrypoint `streamlit_app/app.py`.
3. Checkpoint `inference_service/models/best.pt` berukuran sekitar 19 MB dan disiapkan agar dapat ikut repository. Gunakan repository private jika checkpoint tidak boleh dibagikan. Sebagai alternatif, hapus pengecualian model dari `.gitignore`, host model secara privat, lalu isi `YOLO_MODEL_URL` dan `YOLO_MODEL_SHA256` pada Streamlit Secrets.
4. Pada **App settings > Sharing**, pilih **This app is public and searchable**. Aplikasi private tidak dapat ditampilkan melalui iframe Laravel kepada penguji.

Setelah layanan inferensi aktif, simpan URL `https://...streamlit.app` pada `STREAMLIT_DEMO_URL` di `.env` InfinityFree. Dashboard otomatis menambahkan mode embed dan menampilkannya di menu **Demo Deteksi**. Link yang dibagikan kepada penguji cukup URL Laravel dari InfinityFree.

## Ringkasan dengan Ollama (opsional)

Secara default aplikasi memakai analisis lokal berbasis aturan. Untuk memakai Ollama:

```env
OLLAMA_ENABLED=true
OLLAMA_URL=http://127.0.0.1:11434
OLLAMA_MODEL=llama3.2:3b
```

Setelah mengubah `.env`, jalankan `php artisan config:clear`.

> Metrics pada seeder saat ini berasal dari checkpoint Google Colab yang telah diintegrasikan. Ganti kembali setelah model hasil retraining tersedia.
