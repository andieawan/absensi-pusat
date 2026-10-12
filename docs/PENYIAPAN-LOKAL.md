# Mencoba Absensi Siswa di komputer pribadi (Windows)

Pasang **Pusat** lebih dulu dan siapkan alat (Laragon, Git) mengikuti `docs/PENYIAPAN-LOKAL.md` di repo `pusat-sekolah`. Aplikasi ini dijalankan di **port 8001**; semua perintah di Terminal Laragon, satu per satu.
Selalu pakai alamat `http://127.0.0.1:8001` (bukan `localhost`).

## 1. Ambil kode
```bash
cd C:\laragon\www
git clone https://github.com/andieawan/absensi-pusat.git
cd absensi-pusat
composer install
```
(Repo privat: *Password* = token GitHub, lihat panduan VM.)

## 2. Database & `.env`
```bash
mysql -u root -e "CREATE DATABASE absensi_siswa CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
copy .env.example .env
php artisan key:generate
notepad .env
```
Ubah:
```ini
APP_URL=http://127.0.0.1:8001
DB_DATABASE=absensi_siswa
DB_USERNAME=root
DB_PASSWORD=
SESSION_SECURE_COOKIE=false
SESSION_COOKIE=absensi_session
TRUSTED_PROXIES=
```
`SESSION_COOKIE` harus beda dari aplikasi lain (cookie tidak membedakan port).

## 3. Tabel & akun admin
```bash
php artisan migrate
php artisan absensi:admin admin.sekolah      # password ditanya (min. 10 karakter)
php artisan db:seed --class=DemoSeeder   # opsional: data contoh
```

## 4. Jalankan
```bash
php artisan serve --port=8001
```
Buka http://127.0.0.1:8001/login

## 5. Sambungkan ke Pusat (opsional)
1. Di folder Pusat: `php artisan pusat:aplikasi-baru absensi --base-url=http://127.0.0.1:8001 --redirect=http://127.0.0.1:8001/sso/callback --webhook=http://127.0.0.1:8001/api/pusat/webhook`
2. Isi `.env` Absensi, lalu `php artisan migrate` dan `php artisan config:clear`:
```ini
PUSAT_ENABLED=true
PUSAT_URL=http://127.0.0.1:8000
PUSAT_CLIENT_ID=...
PUSAT_CLIENT_SECRET=...
PUSAT_WEBHOOK_SECRET=...
```
3. Admin → **Pusat**: *Uji koneksi*, lalu *Periksa* (simulasi) sebelum *Terapkan*. Pusat dan Absensi harus sama-sama sedang di-serve.

Masalah umum (database, cookie, redirect_uri, port): lihat bagian terakhir `docs/PENYIAPAN-LOKAL.md` di repo `pusat-sekolah`.
