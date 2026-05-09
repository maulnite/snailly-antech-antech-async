# Catatan Konversi Laravel

Project asal berupa PHP lokal dengan `index.php` sebagai renderer dan `api/proxy.php` sebagai backend entry point. Pada versi Laravel, struktur dipisahkan menjadi route, controller, view Blade, asset public, dan service backend.

## Mapping utama

| Project lama | Versi Laravel |
|---|---|
| `index.php` | `resources/views/snailly/app.blade.php` + `SnaillyPageController` |
| `partials/sidebar.php` | `resources/views/snailly/partials/sidebar.blade.php` |
| `api/proxy.php` | `SnaillyApiController@proxy` |
| `api/track.php` | `SnaillyApiController@track` + `track_legacy.php` |
| `api/blocklist.php` | `SnaillyApiController@blocklist` |
| `lib/*.php` | `app/Services/SnaillyLegacy/*.php` |
| `assets/*` | `public/assets/*` |
| `database/schema.sql` | `database/snailly_schema.sql` |

## Batasan

Konversi ini mempertahankan backend lama sebagai service legacy agar fitur dashboard dan tracking tetap dekat dengan versi awal. Jadi ini belum sepenuhnya memakai Eloquent Model, migration class Laravel, Form Request, atau middleware auth Laravel bawaan.

Kalau ingin dibuat lebih Laravel-native, tahap lanjutannya adalah:

1. Membuat migration Laravel untuk tabel parents, children, activity_logs, rules, access_requests, tokens, dan tracker_status.
2. Membuat model Eloquent untuk setiap tabel.
3. Memecah `LocalBackend` menjadi controller-controller kecil.
4. Mengganti session/token custom menjadi Laravel Sanctum atau session auth.
5. Memindahkan validasi ke Form Request.
