# Deployment Laravel ke alwaysdata Free

Laravel menjadi aplikasi utama. Streamlit hanya ditampilkan sebagai panel deteksi pada menu **Demo Deteksi**.

## 1. Buat akun hosting gratis

Daftar di <https://www.alwaysdata.com/en/register/> dan pilih paket **Free**. Catat nama akun karena alamat Laravel menjadi:

```text
https://NAMA_AKUN.alwaysdata.net
```

## 2. Buat database MariaDB/MySQL

Di dashboard alwaysdata:

1. Buka **Databases > MySQL**.
2. Buat database bernama `NAMA_AKUN_ppe_vision`.
3. Buat user database dan password yang kuat.
4. Catat hostname `mysql-NAMA_AKUN.alwaysdata.net`, nama database, user, dan password.

Jangan mengirimkan password database kepada orang lain dan jangan memasukkannya ke GitHub.

## 3. Aktifkan SSH dan clone repository

Di **Remote access > SSH**, aktifkan akses password atau SSH key. Sambungkan melalui terminal:

```bash
ssh NAMA_AKUN@ssh-NAMA_AKUN.alwaysdata.net
mkdir -p ~/www
git clone https://github.com/silviadela/ppe-vision.git ~/www/ppe-vision
cd ~/www/ppe-vision
cp .env.alwaysdata.example .env
```

Edit `.env`:

```bash
nano .env
```

Ganti semua placeholder berikut dengan nilai dari akun alwaysdata:

```text
NAMA_AKUN
NAMA_USER_DATABASE
PASSWORD_DATABASE
```

Simpan dengan `Ctrl+O`, Enter, lalu keluar dengan `Ctrl+X`.

## 4. Jalankan instalasi Laravel

```bash
cd ~/www/ppe-vision
bash deploy/alwaysdata/deploy.sh
```

Skrip memasang dependency PHP, membangun asset Vite, menjalankan migrasi dan seeder, membuat storage link, serta mengoptimalkan Laravel.

## 5. Tambahkan situs PHP

Di dashboard alwaysdata buka **Web > Sites > Add a site**, lalu isi:

```text
Name        : PPE Vision
Addresses   : NAMA_AKUN.alwaysdata.net
Type        : PHP
PHP version : 8.3
Root        : /home/NAMA_AKUN/www/ppe-vision/public
```

Aktifkan SSL/HTTPS dan pengalihan HTTP ke HTTPS. Laravel akan menggunakan `public/.htaccess` untuk routing.

## 6. Verifikasi

Buka:

```text
https://NAMA_AKUN.alwaysdata.net
https://NAMA_AKUN.alwaysdata.net/up
https://NAMA_AKUN.alwaysdata.net/detection
```

Halaman pertama adalah dashboard Laravel. Halaman deteksi menampilkan Streamlit di dalam layout Laravel; link Streamlit tidak perlu dibagikan kepada penguji.

## Deployment pembaruan

Setelah kode baru didorong ke branch `main`, sambungkan lagi melalui SSH dan jalankan:

```bash
cd ~/www/ppe-vision
bash deploy/alwaysdata/deploy.sh
```

Database MariaDB tidak dihapus saat pembaruan. Seeder menggunakan record training yang sama sehingga aman dijalankan kembali.

## Jika terjadi error

- Periksa **Web > Sites > Logs** di dashboard alwaysdata.
- Pastikan root situs berakhir dengan `/public`.
- Pastikan PHP menggunakan versi 8.2 atau lebih baru.
- Pastikan nama database selalu menggunakan prefix nama akun alwaysdata.
- Jalankan `php artisan optimize:clear` setelah mengubah `.env`.
