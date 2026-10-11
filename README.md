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

Metric checkpoint terbaik (epoch 60): precision 0.93930, recall 0.90805, mAP50 0.95095, dan mAP50-95 0.67852. Grafik asli tersedia di halaman detail eksperimen.

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

### 1. Laravel di alwaysdata

Laravel adalah aplikasi utama dan menggunakan alamat `https://NAMA_AKUN.alwaysdata.net`. Deployment alwaysdata memakai PHP 8.3 dan MariaDB persisten; Docker dan kartu kredit tidak diperlukan.

Repository menyediakan:

- `.env.alwaysdata.example` untuk konfigurasi production;
- `deploy/alwaysdata/deploy.sh` untuk instalasi dan pembaruan;
- [`ALWAYSDATA_DEPLOY.md`](ALWAYSDATA_DEPLOY.md) untuk panduan lengkap.

Root situs alwaysdata wajib diarahkan ke `/home/NAMA_AKUN/www/ppe-vision/public`. Database dibuat melalui menu **Databases > MySQL** dan kredensialnya hanya disimpan pada `.env` di server.

### 2. Demo di Streamlit Community Cloud

1. Buka Streamlit Community Cloud dan pilih repository yang sama.
2. Gunakan entrypoint `streamlit_app/app.py`.
3. Checkpoint `inference_service/models/best.pt` berukuran sekitar 19 MB dan disiapkan agar dapat ikut repository. Gunakan repository private jika checkpoint tidak boleh dibagikan. Sebagai alternatif, hapus pengecualian model dari `.gitignore`, host model secara privat, lalu isi `YOLO_MODEL_URL` dan `YOLO_MODEL_SHA256` pada Streamlit Secrets.

Setelah layanan inferensi aktif, simpan URL `https://...streamlit.app` pada `STREAMLIT_DEMO_URL` di server alwaysdata. Dashboard otomatis menambahkan mode embed dan menampilkannya di menu **Demo Deteksi**. Link yang dibagikan kepada penguji cukup URL Laravel `https://NAMA_AKUN.alwaysdata.net`.

## Ringkasan dengan Ollama (opsional)

Secara default aplikasi memakai analisis lokal berbasis aturan. Untuk memakai Ollama:

```env
OLLAMA_ENABLED=true
OLLAMA_URL=http://127.0.0.1:11434
OLLAMA_MODEL=llama3.2:3b
```

Setelah mengubah `.env`, jalankan `php artisan config:clear`.

> Metrics pada seeder saat ini berasal dari checkpoint Google Colab yang telah diintegrasikan. Ganti kembali setelah model hasil retraining tersedia.
