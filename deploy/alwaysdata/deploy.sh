#!/usr/bin/env bash
set -Eeuo pipefail

APP_DIR="${APP_DIR:-$HOME/www/ppe-vision}"

if [[ ! -d "$APP_DIR/.git" ]]; then
    echo "Repository belum tersedia di $APP_DIR."
    echo "Clone terlebih dahulu: git clone https://github.com/silviadela/ppe-vision.git $APP_DIR"
    exit 1
fi

cd "$APP_DIR"

if [[ ! -f .env ]]; then
    cp .env.alwaysdata.example .env
    echo "File .env telah dibuat. Ganti seluruh nilai NAMA_AKUN, NAMA_USER_DATABASE, dan PASSWORD_DATABASE, lalu jalankan skrip ini kembali."
    exit 1
fi

if grep -Eq 'NAMA_AKUN|NAMA_USER_DATABASE|PASSWORD_DATABASE' .env; then
    echo "Konfigurasi .env masih berisi placeholder alwaysdata. Lengkapi kredensial database terlebih dahulu."
    exit 1
fi

echo "[1/8] Mengambil perubahan terbaru dari GitHub..."
git pull --ff-only origin main

echo "[2/8] Memasang dependency PHP production..."
if command -v composer2 >/dev/null 2>&1; then
    composer2 install --no-dev --prefer-dist --no-interaction --optimize-autoloader
else
    composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
fi

echo "[3/8] Membangun asset antarmuka..."
npm ci --no-audit --no-fund
npm run build
rm -rf node_modules

echo "[4/8] Menyiapkan direktori Laravel..."
mkdir -p \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache
chmod -R u+rwX storage bootstrap/cache

if grep -Eq '^APP_KEY=$' .env; then
    echo "[5/8] Membuat application key..."
    php artisan key:generate --force
else
    echo "[5/8] Application key sudah tersedia."
fi

echo "[6/8] Menjalankan migrasi dan seeder..."
php artisan migrate --force
php artisan db:seed --force

echo "[7/8] Menyiapkan public storage..."
php artisan storage:link --force || true

echo "[8/8] Mengoptimalkan Laravel..."
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "Deployment selesai. Pastikan root situs alwaysdata mengarah ke: $APP_DIR/public"
