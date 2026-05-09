# Snailly versi Laravel

Folder ini adalah hasil konversi project `snailly` PHP lokal menjadi struktur Laravel.

## Isi konversi

- UI utama dipindah ke Blade: `resources/views/snailly/app.blade.php`
- Sidebar dipindah ke Blade partial: `resources/views/snailly/partials/sidebar.blade.php`
- Routing Laravel disiapkan di `routes/snailly.php`
- Controller halaman: `app/Http/Controllers/SnaillyPageController.php`
- Controller API: `app/Http/Controllers/SnaillyApiController.php`
- Backend lama dipertahankan sebagai service legacy di `app/Services/SnaillyLegacy`
- Asset CSS/JS/logo dipindah ke `public/assets`
- Schema database dipindah ke `database/snailly_schema.sql`
- Data blocklist dipindah ke `data/`
- Extension sudah diarahkan ke endpoint Laravel `/api/snailly/proxy` dan `/api/snailly/track`

## Cara pasang ke project Laravel yang sudah ada

1. Copy semua folder/file dari paket ini ke root project Laravel kamu.

2. Buka `routes/web.php`, lalu tambahkan baris ini di bagian bawah:

```php
require __DIR__ . '/snailly.php';
```

3. Pastikan `.env` memakai database MySQL/MariaDB Laragon. Contoh:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=snailly_kids
DB_USERNAME=root
DB_PASSWORD=
```

4. Jalankan server Laravel:

```bash
php artisan serve
```

5. Buka aplikasi:

```text
http://127.0.0.1:8000/snailly
```

6. Register akun parent, lalu tambahkan child dari menu Children.

## Catatan penting

Backend Snailly masih memakai logic legacy berbasis service PHP supaya fitur aslinya tetap jalan. Bedanya, sekarang entry point-nya sudah lewat route dan controller Laravel, bukan `index.php`, `api/proxy.php`, dan `api/track.php` langsung.

Karena endpoint API memakai request JSON dari JavaScript dan extension, route Snailly API sengaja dibuat tanpa CSRF middleware. Ini cocok untuk local demo/praktikum. Untuk production, perlu hardening lagi seperti HTTPS resmi, rate limit, CSRF/CORS yang lebih ketat, dan validasi request lebih lengkap.

## URL penting

```text
/snailly                  Halaman utama Snailly
/api/snailly/proxy        Backend dashboard
/api/snailly/track        Endpoint tracking extension
/api/snailly/blocklist    Helper cek blocklist
```

## Extension

Folder `extension/` sudah ikut dikonversi. Untuk menjalankan:

1. Buka `chrome://extensions/`
2. Aktifkan Developer Mode
3. Klik Load unpacked
4. Pilih folder `extension`
5. Isi Backend URL dengan:

```text
http://127.0.0.1:8000
```

