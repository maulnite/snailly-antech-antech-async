# Snailly

Snailly adalah aplikasi monitoring aktivitas internet anak yang dibuat untuk membantu orang tua memantau website yang dibuka anak. Project ini berjalan secara lokal menggunakan web dashboard, backend PHP, database MySQL, dan Chrome Extension sebagai tracker URL.

Project ini awalnya dikembangkan sebagai versi web lokal yang bisa dijalankan dengan XAMPP, sehingga cocok untuk kebutuhan demo, tugas kuliah, atau pengembangan prototype parental control sederhana.

## Gambaran Umum

Snailly punya dua sisi utama:

1. **Parent Dashboard**  
   Digunakan oleh orang tua untuk melihat aktivitas anak, mengatur rule website, membuat jadwal akses internet, melihat request access, dan membuat laporan.

2. **Child Dashboard**  
   Digunakan oleh anak untuk melihat ringkasan aktivitas, safe mode, learning streak, serta request akses ketika website tertentu diblokir.

Selain itu, Snailly juga memakai **Chrome Extension** untuk membaca URL yang sedang dibuka di browser dan mengirimkannya ke backend lokal.

## Fitur Utama

- Register dan login parent
- Akun child dengan username dan password sendiri
- Dashboard parent dan dashboard child
- Tracking URL lewat Chrome Extension
- Activity log berdasarkan child dan status
- Filter log berdasarkan anak, status, tanggal, dan pencarian URL
- Custom rule website:
  - Allow
  - Warn Only
  - Block
- Rule berdasarkan:
  - domain
  - subdomain
  - URL/path
  - keyword
  - category
- Schedule internet untuk membatasi jam akses anak
- Request access dari anak ke parent
- Approve atau deny request access
- Report harian/mingguan
- Export log ke CSV
- Print/save report ke PDF
- Learning streak calendar
- Blocked page untuk website yang tidak diizinkan

## Teknologi yang Digunakan

- HTML
- CSS
- JavaScript
- PHP
- MySQL / MariaDB
- Chrome Extension Manifest V3
- XAMPP untuk local server

## Struktur Singkat Project

```text
snailly/
├── api/
│   ├── proxy.php
│   └── track.php
├── assets/
│   ├── css/
│   └── js/
├── config/
│   └── database.php
├── database/
│   └── schema.sql
├── extension/
│   ├── manifest.json
│   ├── background.js
│   ├── popup.html
│   ├── popup.css
│   └── popup.js
├── lib/
│   ├── Database.php
│   ├── LocalBackend.php
│   ├── PolicyEngine.php
│   └── Security.php
├── blocked.php
└── index.php
```

## Cara Menjalankan di Lokal

1. Pindahkan folder project ke `htdocs` XAMPP.

```text
C:\xampp\htdocs\snailly\
```

2. Jalankan Apache dan MySQL dari XAMPP.

3. Buka aplikasi di browser.

```text
http://localhost/snailly/
```

4. Register akun parent.

5. Tambahkan data child melalui menu Children.

6. Install extension dari folder `extension`.
   - Buka `chrome://extensions/`
   - Aktifkan Developer Mode
   - Klik Load unpacked
   - Pilih folder `extension`

7. Isi Backend URL di extension.

```text
http://localhost/snailly
```

8. Login extension, pilih child, lalu aktifkan tracking.

## Database

Database yang digunakan adalah MySQL/MariaDB. Secara default database bernama:

```text
snailly_kids
```

Tabel utama yang digunakan:

- parents
- children
- activity_logs
- rules
- access_requests
- tokens

Database dan tabel akan dibuat berdasarkan struktur yang ada di `database/schema.sql`.

## Catatan Keamanan

Beberapa hal keamanan yang sudah diterapkan:

- Password disimpan menggunakan hashing
- Session menggunakan cookie `HttpOnly`
- Support cookie `Secure` jika dijalankan dengan HTTPS
- Role parent, child, dan tracker dipisahkan
- Extension memakai tracker token khusus
- Token memiliki masa berlaku
- Query database memakai prepared statement
- Child hanya bisa mengakses data miliknya sendiri
- Parent hanya bisa mengakses child yang terhubung dengan akunnya

Untuk penggunaan production, masih perlu penyesuaian tambahan seperti HTTPS resmi, konfigurasi server yang lebih aman, dan pembatasan akses database yang lebih ketat.

## Catatan

Project ini dibuat sebagai prototype aplikasi parental monitoring. Untuk perangkat laptop/PC, tracking URL dilakukan melalui Chrome Extension. Untuk perangkat seperti HP atau tablet, monitoring bisa dikembangkan lagi menggunakan pendekatan DNS-level monitoring atau aplikasi Android terpisah.
