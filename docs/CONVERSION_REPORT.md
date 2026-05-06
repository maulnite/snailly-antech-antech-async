# Conversion Report

## Ringkasan konversi

Source asli memakai beberapa stack:

| Bagian asli | Stack lama | Hasil konversi |
|---|---|---|
| UI renderer | Next.js + React + TypeScript + Emotion CSS | `index.php`, `assets/js/app.js`, `assets/css/style.css` |
| Routing | Next Router | Query route `index.php?page=...` + JavaScript route handler |
| State global | Zustand | `localStorage` + object state di JavaScript |
| HTTP client | Axios | `fetch()` + `api/proxy.php` |
| Chart | Recharts | CSS bar chart + CSS donut chart |
| Electron main process | TypeScript Electron | Tidak dikonversi 1:1; diganti web layout dan helper PHP |
| Python proxy | Python + mitmproxy | `lib/Blocklist.php` + `api/blocklist.php` sebagai helper cek blocklist |
| Batch/PowerShell | Windows automation | Tidak dikonversi karena butuh izin OS |

## File asli yang secara fungsi digantikan

| File/folder lama | Pengganti di versi web |
|---|---|
| `renderer/src/modules/*` | Template di `index.php` + logic di `assets/js/app.js` |
| `renderer/src/components/*` | Komponen HTML/CSS reusable di `index.php` dan `style.css` |
| `renderer/src/utils/axios` | `api/proxy.php` + helper `api()` di JS |
| `renderer/src/services/zustand` | `localStorage` state di JS |
| `py/snaily_proxy.py` | `lib/Blocklist.php`, `api/blocklist.php` |
| `main/background.ts`, `main/preload.ts` | Tidak bisa 1:1 di web; hanya sebagian perilaku app dipindah ke JS/PHP |
| `bat/*.bat`, `bat/*.ps1` | Tidak dikonversi karena mengubah setting Windows/certificate |

## Batasan hasil konversi

Versi ini adalah web app biasa. Ia bisa login, register, menarik data dari API, mengelola anak, membaca log, dan mengecek blocklist. Namun, ia tidak bisa otomatis mengambil alih proxy perangkat anak seperti versi desktop karena browser/PHP tidak punya akses level sistem operasi.

Jika tetap ingin fitur blocking penuh, arsitektur yang lebih tepat adalah:

1. UI tetap memakai versi web ini.
2. Agent desktop/mobile terpisah tetap dipakai untuk proxy/device-level filtering.
3. Agent berkomunikasi dengan API/backend yang sama.
4. Web dashboard hanya untuk monitoring, pengaturan anak, dan grant access.
