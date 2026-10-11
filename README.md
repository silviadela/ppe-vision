# PPE Vision

Dashboard Laravel untuk mencatat konfigurasi training YOLO, menampilkan precision/recall/mAP, membuat ringkasan metrics, dan mendemokan deteksi kelas **Helm, Masker, People**.

## Arsitektur aplikasi

Pengguna cukup membuka satu URL dashboard. Halaman **Demo Deteksi** menampilkan panel inferensi secara langsung di dalam layout aplikasi, sehingga tidak perlu berpindah tab atau membuka link kedua.

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
   `- Demo Deteksi ---> panel inferensi tertanam ---> best.pt
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

Project lokal telah menggunakan checkpoint **PPE YOLO11s Best Config** dengan kelas sumber `Hardhat`, `Mask`, dan `Person`. API menormalkannya menjadi `helm`, `masker`, dan `people`. Konfigurasi dan checksum model tercatat di `inference_service/models/model-info.json`.

Metric checkpoint terbaik (epoch 50): precision 0.72450, recall 0.87209, mAP50 0.77007, dan mAP50-95 0.54627. Grafik asli tersedia di halaman detail eksperimen.

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

## Deployment gratis

### 1. Laravel di Render

Project sudah menyediakan `Dockerfile`, `render.yaml`, konfigurasi Apache, migrasi, dan seeder otomatis.

1. Simpan project pada repository GitHub.
2. Di Render pilih **New > Blueprint** lalu hubungkan repository.
3. Render membaca `render.yaml` dan membuat service `ppe-vision-dashboard`.
4. Isi environment variable `STREAMLIT_DEMO_URL` setelah Streamlit memperoleh URL publik.

Render Free menggunakan filesystem sementara. SQLite, riwayat upload, dan file hasil deteksi dapat kembali ke data awal setelah service tidur atau restart. Konfigurasi ini sesuai untuk assessment/demo, bukan penyimpanan production.

### 2. Demo di Streamlit Community Cloud

1. Buka Streamlit Community Cloud dan pilih repository yang sama.
2. Gunakan entrypoint `streamlit_app/app.py`.
3. Checkpoint `inference_service/models/best.pt` berukuran sekitar 19 MB dan disiapkan agar dapat ikut repository. Gunakan repository private jika checkpoint tidak boleh dibagikan. Sebagai alternatif, hapus pengecualian model dari `.gitignore`, host model secara privat, lalu isi `YOLO_MODEL_URL` dan `YOLO_MODEL_SHA256` pada Streamlit Secrets.

Setelah layanan inferensi aktif, salin URL `https://...streamlit.app` ke variable `STREAMLIT_DEMO_URL` di Render. Dashboard otomatis menambahkan mode embed dan menampilkannya di menu **Demo Deteksi**. Link yang dibagikan kepada penguji cukup URL Render, misalnya `https://ppe-vision-dashboard.onrender.com`.

## Ringkasan dengan Ollama (opsional)

Secara default aplikasi memakai analisis lokal berbasis aturan. Untuk memakai Ollama:

```env
OLLAMA_ENABLED=true
OLLAMA_URL=http://127.0.0.1:11434
OLLAMA_MODEL=llama3.2:3b
```

Setelah mengubah `.env`, jalankan `php artisan config:clear`.

> Metrics pada seeder saat ini berasal dari checkpoint Google Colab yang telah diintegrasikan. Ganti kembali setelah model hasil retraining tersedia.
