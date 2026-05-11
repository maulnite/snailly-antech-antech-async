# Snailly Optimization Notes

Versi ini sudah dipatch agar lebih ringan untuk demo lokal/LAN.

## Perubahan utama

1. **Tracking extension tidak lagi menulis ulang seluruh database**
   - `/api/snailly/track` sekarang memakai insert langsung ke `activity_logs`.
   - Duplicate URL dalam waktu pendek tetap dicek policy-nya, tetapi tidak membuat log baru.

2. **Dashboard/log/statistik memakai query SQL langsung**
   - Endpoint log summary, statistik bulanan/tahunan, list logs, clear logs, dan report tidak lagi membaca semua log ke array PHP.
   - Ditambahkan endpoint ringkas:
     - `/dashboard/overview/{childId}`
     - `/children/overview`
   - `public/assets/js/app.js` sudah diarahkan memakai endpoint overview supaya request dashboard lebih sedikit.

3. **Snapshot legacy dibuat lebih aman**
   - Pola lama `DELETE semua tabel -> INSERT ulang semua` diganti menjadi upsert + delete missing.
   - Untuk request ringan, snapshot bisa tidak memuat logs agar tidak berat.

4. **Migrasi database tidak dicek penuh setiap request**
   - `.env` ditambah `SNAILLY_AUTO_MIGRATE=missing_only`.
   - Migrasi hanya jalan otomatis kalau tabel utama belum ada.

5. **Session/cache/queue dibuat lebih cocok untuk lokal**
   - `SESSION_DRIVER=file`
   - `CACHE_STORE=file`
   - `QUEUE_CONNECTION=sync`
   - Ini mengurangi risiko error session/cache database saat tabel Laravel default belum dibuat.

6. **Blocklist besar dibuat lebih ringan**
   - Path blocklist diperbaiki ke folder `data/` asli.
   - Hasil parsing blocklist dibuat cache di `storage/framework/cache/snailly_blocklist_lookup.php`.

7. **Index database ditambah untuk query dashboard**
   - Composite index untuk `activity_logs`, `rules`, dan `access_requests` sudah ditambahkan di `database/snailly_schema.sql`.

## Setelah unzip

Jalankan ini sekali dari root project:

```bash
php artisan optimize:clear
php artisan config:clear
```

Kalau database sudah pernah dibuat sebelum patch ini, import ulang `database/snailly_schema.sql` tidak akan menghapus data karena menggunakan `CREATE TABLE IF NOT EXISTS`, tapi index baru mungkin perlu ditambahkan manual jika MySQL tidak otomatis membaca ulang schema lama.
