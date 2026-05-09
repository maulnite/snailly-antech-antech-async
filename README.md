<p align="center">
  <img src="public/assets/img/logo.png" alt="Snailly Logo" width="220">
</p>

<h1 align="center">Snailly</h1>
<p align="center">
  <img src="public/assets/img/logo.png" alt="Snailly Logo" width="220">
</p>

<h1 align="center">Snailly</h1>

<p align="center">
  Aplikasi monitoring aktivitas internet anak berbasis Laravel, web dashboard, dan Chrome Extension.
</p>

Snailly adalah aplikasi monitoring aktivitas internet anak yang dibuat untuk membantu orang tua memantau website yang dibuka anak. Aplikasi ini menyediakan dashboard untuk parent, dashboard untuk child, sistem rule website, jadwal akses internet, request access, dan Chrome Extension sebagai tracker URL.

Project ini dibuat sebagai prototype parental monitoring untuk kebutuhan demo, tugas kuliah, dan pengembangan aplikasi keamanan digital sederhana. Versi terbaru project ini sudah dimigrasikan ke Laravel, dengan beberapa logic lama tetap dipertahankan dalam service layer agar fitur yang sudah dibuat tetap berjalan stabil.

## Gambaran Umum

Snailly punya tiga bagian utama:

1. **Parent Dashboard**  
   Digunakan oleh orang tua untuk melihat aktivitas browsing anak, mengatur rule website, membuat jadwal akses internet, melihat request access, dan membuat laporan aktivitas.

2. **Child Dashboard**  
   Digunakan oleh anak untuk melihat ringkasan aktivitas, status safe mode, learning streak, serta akses ke halaman yang aman seperti Scratch.

3. **Chrome Extension**  
   Digunakan untuk membaca URL yang sedang dibuka di browser anak, lalu mengirimkan data aktivitas tersebut ke backend Snailly.

## Fitur Utama

- Register dan login parent
- Akun child dengan username dan password sendiri
- Dashboard parent dan dashboard child
- Tracking URL melalui Chrome Extension
- Activity log berdasarkan child, status, tanggal, dan pencarian URL
- Custom website rule:
  - Allow
  - Warn Only
  - Block
- Rule berdasarkan:
  - domain
  - subdomain
  - URL/path
  - keyword
  - category
- Policy engine untuk menentukan apakah website aman, warning, atau blocked
- Schedule internet untuk membatasi jam browsing anak
- Request access dari anak ke parent
- Approve atau deny request access
- Report harian/mingguan
- Export log ke CSV
- Print/save report ke PDF
- Learning streak calendar
- Blocked page untuk website yang tidak diizinkan
- Tracker status dari extension
- Support penggunaan session dan cookie untuk web dashboard

## Teknologi yang Digunakan

- Laravel
- PHP
- Blade
- HTML
- CSS
- JavaScript
- MySQL / MariaDB
- Chrome Extension Manifest V3
- XAMPP atau Laravel development server

## Struktur Singkat Project

```text
snailly/
├── app/
│   ├── Http/
│   │   └── Controllers/
│   └── Services/
│       └── SnaillyLegacy/
├── database/
│   └── migrations/
├── public/
│   ├── assets/
│   │   ├── css/
│   │   ├── img/
│   │   └── js/
│   └── index.php
├── resources/
│   └── views/
│       └── snailly/
├── routes/
│   ├── api.php
│   └── web.php
├── extension/
│   ├── manifest.json
│   ├── background.js
│   ├── popup.html
│   ├── popup.css
│   └── popup.js
├── .env
├── artisan
└── composer.json


## Note

Project ini dibuat untuk kebutuhan tugas kuliah sebagai bentuk recreate dan pengembangan ulang dari project Snailly yang sudah ada sebelumnya. Referensi utama project ini berasal dari repository berikut:

https://github.com/unikom-codelabs/snailly-desktop

Versi ini tidak dimaksudkan untuk mengklaim sebagai project original sepenuhnya. Beberapa konsep utama seperti parental monitoring, dashboard, dan tracking aktivitas internet anak mengacu pada project tersebut, lalu dikembangkan kembali dengan pendekatan berbasis Laravel, web dashboard, MySQL, dan Chrome Extension.