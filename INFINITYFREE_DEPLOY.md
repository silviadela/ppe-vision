# Deployment Laravel ke InfinityFree

Laravel tetap menjadi website yang dibagikan kepada penguji. Streamlit hanya menjalankan model YOLO dan ditampilkan di menu **Demo Deteksi**.

```text
Penguji -> URL Laravel InfinityFree -> Demo Deteksi -> Streamlit -> best.pt
```

InfinityFree menyediakan PHP, MySQL, SSL, dan subdomain gratis tanpa kartu. Paket gratis tidak menyediakan SSH, Composer, atau NPM di server. Karena itu, dependency dan asset dibangun di komputer sebelum diunggah.

Referensi resmi:

- <https://www.infinityfree.com/>
- <https://forum.infinityfree.com/t/how-to-install-a-laravel-site-on-infinityfree/118578>

## 1. Buat akun dan hosting gratis

1. Daftar di <https://dash.infinityfree.com/register>.
2. Pilih **Create Account** dan buat subdomain gratis.
3. Tunggu sampai status hosting menjadi **Active**.
4. Buka **Manage**, kemudian catat alamat website yang diberikan.

Alamatnya akan menyerupai:

```text
https://ppe-vision.great-site.net
```

Tidak perlu membeli domain premium.

## 2. Buat database MySQL

Di control panel hosting:

1. Buka **MySQL Databases**.
2. Buat database, misalnya `ppe`.
3. Catat nilai yang ditampilkan untuk:
   - MySQL Hostname, misalnya `sqlXXX.infinityfree.com`;
   - MySQL Database Name, misalnya `if0_XXXXXXXX_ppe`;
   - MySQL Username, misalnya `if0_XXXXXXXX`;
   - password hosting/database.

Gunakan nilai persis dari control panel. `DB_HOST` bukan `localhost`.

## 3. Buat paket upload di komputer

Dari PowerShell pada folder project jalankan:

```powershell
powershell -ExecutionPolicy Bypass -File .\deploy\infinityfree\build-package.ps1
```

Skrip akan:

- menyalin source Laravel yang diperlukan;
- memasang dependency production ke folder paket;
- membangun CSS dan JavaScript Vite;
- membuat `APP_KEY` unik;
- membuat storage publik tanpa symlink;
- menghasilkan folder `deploy/infinityfree/package/htdocs`;
- menghasilkan `deploy/infinityfree/ppe-vision-infinityfree.zip`.

Folder `node_modules`, kode Python, dan model `.pt` tidak dimasukkan karena inferensi sudah dijalankan oleh Streamlit.

## 4. Isi konfigurasi production

Buka file berikut dari hasil paket:

```text
deploy/infinityfree/package/htdocs/.env
```

Ganti nilai berikut:

```env
APP_URL=https://SUBDOMAIN_INFINITYFREE_ANDA

DB_HOST=HOST_DARI_CONTROL_PANEL
DB_DATABASE=NAMA_DATABASE_DARI_CONTROL_PANEL
DB_USERNAME=USERNAME_DARI_CONTROL_PANEL
DB_PASSWORD=PASSWORD_DATABASE

INFINITYFREE_INSTALL_TOKEN=TOKEN_ACAK_RAHASIA_MINIMAL_32_KARAKTER
```

Contoh token dapat berupa gabungan huruf dan angka acak. Jangan mengirimkan `.env` atau password database ke GitHub.

URL Streamlit sudah terisi:

```env
STREAMLIT_DEMO_URL=https://ppe-vision-lculpeehh7bzalqxpwtbf5.streamlit.app
```

## 5. Upload ke folder `htdocs`

Gunakan FileZilla atau File Manager dengan kredensial FTP dari InfinityFree.

1. Buka folder remote `htdocs`.
2. Hapus file bawaan `index2.html` jika tersedia.
3. Unggah **seluruh isi** `deploy/infinityfree/package/htdocs` ke folder remote `htdocs`.
4. Pastikan file `htdocs/.htaccess`, `htdocs/.env`, dan `htdocs/public/.htaccess` ikut terunggah. File bertitik mungkin tersembunyi pada FTP client.

Jangan hanya mengunggah folder `public`. Struktur Laravel lengkap dan folder `vendor` diperlukan.

## 6. Pasang database melalui browser

Setelah semua file selesai diunggah, buka:

```text
https://SUBDOMAIN_INFINITYFREE_ANDA/install
```

Masukkan nilai `INFINITYFREE_INSTALL_TOKEN`, kemudian klik **Pasang database**. Laravel akan menjalankan migration dan seeder melalui aplikasi sehingga tidak memerlukan SSH atau phpMyAdmin import.

Setelah muncul **Instalasi berhasil**:

1. Edit kembali file `htdocs/.env` melalui File Manager/FTP.
2. Ubah menjadi:

```env
INFINITYFREE_INSTALL_TOKEN=
```

3. Simpan. Halaman `/install` sekarang akan mengembalikan 404.

## 7. Verifikasi website

Buka alamat berikut:

```text
https://SUBDOMAIN_INFINITYFREE_ANDA/
https://SUBDOMAIN_INFINITYFREE_ANDA/up
https://SUBDOMAIN_INFINITYFREE_ANDA/detection
```

Yang dibagikan kepada penguji hanya URL pertama. Laptop pengembang tidak perlu menyala karena Laravel dan database berada di InfinityFree, sedangkan YOLO berada di Streamlit Community Cloud.

## Memperbarui aplikasi

1. Tarik kode GitHub terbaru di komputer.
2. Jalankan kembali `build-package.ps1`.
3. Upload ulang isi paket, tetapi **jangan menimpa `.env` remote**.
4. Jika ada migration baru, isi sementara `INFINITYFREE_INSTALL_TOKEN`, buka `/install`, lalu kosongkan kembali tokennya.

Data MySQL tidak terhapus ketika file aplikasi diperbarui.

## Pemecahan masalah

- **403/404:** pastikan `.htaccess` di root `htdocs` dan `public` ikut terunggah.
- **500:** periksa kredensial database dan pastikan folder `storage` serta `bootstrap/cache` dapat ditulis.
- **Tampilan tanpa CSS:** pastikan folder `public/build` ikut terunggah.
- **Dashboard belum memiliki data:** buka `/install` dan jalankan instalasi menggunakan token.
- **Streamlit tidur:** buka menu Demo Deteksi dan bangunkan aplikasi saat diminta; laptop Anda tetap tidak perlu menyala.
