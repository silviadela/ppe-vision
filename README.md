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

Project menggunakan checkpoint **PPE YOLO26s Dataset 4 Final** dengan kelas sumber `Helmet`, `Mask`, dan `Person`. API menormalkannya menjadi `helm`, `masker`, dan `people`. Konfigurasi, checksum, hasil random search, dan rincian evaluasi model tercatat di `inference_service/models/model-info.json` serta `database/data`.

Setelah lima trial random search, konfigurasi trial 4 dilatih ulang selama 60 epoch. Evaluasi pada **212 gambar test independen** menghasilkan precision 0.95482, recall 0.90702, F1 0.93031, mAP50 0.95702, dan mAP50-95 0.69299. mAP50-95 per kelas adalah Helmet 0.69054, Mask 0.63396, dan Person 0.75447. Grafik training dan evaluasi test tersedia di halaman detail eksperimen.

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
- halaman instalasi database satu kali di `/install`.

Seluruh isi paket diunggah ke `htdocs`. `.htaccess` root mengarahkan request ke folder Laravel `public`, sedangkan kredensial MySQL hanya disimpan dalam `.env` server.

### 2. Demo di Streamlit Community Cloud

1. Buka Streamlit Community Cloud dan pilih repository yang sama.
2. Gunakan entrypoint `streamlit_app/app.py`.
3. Checkpoint `inference_service/models/best.pt` berukuran sekitar 19 MB dan disiapkan agar dapat ikut repository. Gunakan repository private jika checkpoint tidak boleh dibagikan. Sebagai alternatif, hapus pengecualian model dari `.gitignore`, host model secara privat, lalu isi `YOLO_MODEL_URL` dan `YOLO_MODEL_SHA256` pada Streamlit Secrets.
4. Pada **App settings > Sharing**, pilih **This app is public and searchable**. Aplikasi private tidak dapat ditampilkan melalui iframe Laravel kepada penguji.

Setelah layanan inferensi aktif, simpan URL `https://...streamlit.app` pada `STREAMLIT_DEMO_URL` di `.env` InfinityFree. Dashboard otomatis menambahkan mode embed dan menampilkannya di menu **Demo Deteksi**. Link yang dibagikan kepada penguji cukup URL Laravel dari InfinityFree.

## Ringkasan lokal dengan Ollama

Implementasi final menggunakan Ollama lokal untuk menyusun kesimpulan kualitatif, risiko operasional, dan tiga rekomendasi dari metrik model. Laravel mengirim metrik yang benar kepada Ollama serta tetap menampilkan angka dari database agar LLM tidak menjadi sumber nilai evaluasi. Jika Ollama tidak aktif, tidak dapat dihubungi, atau responsnya tidak lolos validasi setelah percobaan koreksi, summary lama dipertahankan dan aplikasi menampilkan error. Tidak ada summary baru berbasis aturan.

Konfigurasi lokal:

```env
OLLAMA_ENABLED=true
OLLAMA_URL=http://127.0.0.1:11434
OLLAMA_MODEL=llama3.2:3b
```

Setelah mengubah `.env`, jalankan `php artisan config:clear`.

Karena InfinityFree tidak dapat mengakses Ollama pada komputer pengguna, proses AI dijalankan oleh Laravel lokal. Hasil yang lolos validasi dipindahkan melalui halaman browser bertoken ke MySQL InfinityFree dan kemudian ditampilkan secara read-only pada dashboard online.

### Sinkronisasi Ollama lokal ke database online

Mode sinkronisasi memakai MySQL InfinityFree sebagai satu-satunya sumber data operasional. SQLite hanya dipakai untuk development dan test otomatis; Streamlit, YOLO, dan Ollama tidak memiliki database tambahan.

Tambahkan token acak yang sama pada `.env` InfinityFree dan `.env` lokal:

```dotenv
SUMMARY_SYNC_TOKEN=TOKEN_ACAK_YANG_SAMA
```

Pada `.env` InfinityFree:

```dotenv
SUMMARY_SYNC_TOKEN=TOKEN_ACAK_YANG_SAMA
```

#### Sinkronisasi melalui browser

InfinityFree gratis menampilkan browser security challenge pada request programatik. Karena itu, perintah API otomatis tidak dapat menerima JSON dari domain tersebut. Gunakan alur browser-assisted berikut:

1. Buka `https://DOMAIN-ANDA/local-ai-sync/1` di browser.
2. Pada langkah 1, masukkan token dan unduh `ppe-metrics-run-1.json`.
3. Pastikan Ollama aktif, lalu di terminal project jalankan:

```powershell
php artisan summary:prepare-browser "C:\Users\NAMA\Downloads\ppe-metrics-run-1.json"
```

4. Kembali ke halaman sinkronisasi. Pada langkah 3, masukkan token dan unggah `storage/app/private/summary-sync-run-1.json`.
5. Laravel online memvalidasi token, tanda tangan sumber, ID eksperimen, fingerprint metrics, label model Ollama, dan struktur summary sebelum menyimpan ke MySQL.

File JSON hanya menjadi media transfer sementara dan bukan database. Metrics tetap berasal dari MySQL online, sedangkan teks analisis hanya dibuat oleh Ollama lokal.

Alur browser menolak token yang salah, label mesin selain model Ollama yang dikonfigurasi, file yang diubah, dan summary yang dibuat dari metrics lama. Token tidak boleh dimasukkan ke repository.

> Metrics pada seeder berasal dari model final YOLO26s yang aktif dan evaluasi test independen di dalam paket Dataset 4.
