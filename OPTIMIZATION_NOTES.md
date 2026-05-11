# Snailly Optimization Notes

Versi ini sudah digabung dari patch optimasi terbaru.

## Backend

- Dashboard, log list, statistic year/month, clear logs, dan report memakai query SQL langsung.
- `report()` tidak lagi mentok 100 data; total report dihitung dari seluruh data sesuai filter.
- `saveSnapshot()` tidak lagi delete-insert semua tabel untuk operasi ringan.
- Tracking URL dari extension memakai insert langsung ke `activity_logs`.
- Duplicate URL pendek dicegah di backend. URL sama dalam window pendek tidak disimpan berkali-kali, tapi policy tetap dicek ulang.
- Endpoint baru `/policy-check` via `/api/snailly/proxy?path=/policy-check` untuk cek rule/schedule tanpa menulis log.
- Report dan dashboard menampilkan `topHosts` dan `topRiskyHosts`.
- Auto cleanup log lama tersedia melalui `SNAILLY_LOG_RETENTION_DAYS` di `.env`.
- Blocklist besar memakai cache dan path diarahkan ke `base_path('data')`.
- Schema SQL ditambah index untuk query log/report yang sering dipakai.
- File opsional `database/snailly_indexes_upgrade.sql` disediakan untuk upgrade database lama.

## Frontend Dashboard

- Parent token disimpan sebagai fallback di `localStorage` supaya session idle tidak gampang menjadi `Unauthorized`.
- Logout ikut menghapus `snailly_parent_token`.
- Dashboard memakai endpoint overview gabungan.
- Dashboard menampilkan top visited website dan top risky/blocked website.
- Export CSV mengambil semua halaman log, bukan hanya 100 data pertama.
- Child dashboard polling status tracker dikurangi dari 5 detik menjadi 30 detik.
- Blocked page bisa membongkar URL nested `/snailly/blocked?url=...` supaya URL tidak makin panjang.

## Chrome Extension

- Extension tidak lagi mencatat halaman internal Snailly, `/api/snailly`, `/blocked`, localhost, atau IP LAN Snailly ke activity log.
- Redirect blocked memakai builder URL khusus agar tidak membuat URL nested panjang.
- Policy recheck memakai `/policy-check`, bukan `/track`, sehingga tidak menambah log baru.
- Blocked page polling juga memakai `/policy-check`, jadi menunggu approval tidak menambah activity log.
- Sync tracker status ditrottle maksimal 1x per 60 detik dan hanya dipaksa saat setting penting berubah.
- `chrome.storage.onChanged` tidak lagi mengirim status setiap `lastTracked` berubah.

## `.env` lokal/demo

- `APP_DEBUG=false`
- `SESSION_DRIVER=file`
- `CACHE_STORE=file`
- `QUEUE_CONNECTION=sync`
- `SNAILLY_AUTO_MIGRATE=missing_only`
- `SNAILLY_LOG_RETENTION_DAYS=30`

Setelah replace project, jalankan:

```bash
php artisan optimize:clear
php artisan config:clear
```

Reload extension di `chrome://extensions` setelah mengganti file extension.
