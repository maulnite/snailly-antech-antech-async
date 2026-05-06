# Laporan Backend Lokal

Backend asli sebelumnya dipanggil melalui domain eksternal. Pada versi ini, request eksternal tersebut dihapus dan diganti dengan backend lokal berbasis PHP.

## Perubahan utama

- `api/proxy.php` tidak lagi meneruskan request ke domain asli.
- `api/proxy.php` sekarang menjadi entry point backend lokal.
- `lib/LocalBackend.php` berisi logic register, login, child management, log activity, statistik, dan profile update.
- `data/app_db.json` digunakan sebagai database sederhana agar project langsung jalan di XAMPP tanpa setup MySQL.

## Alasan memakai JSON storage

Untuk project kuliah berbasis XAMPP, JSON storage dipakai supaya pengguna tidak perlu import database atau konfigurasi username/password MySQL. Struktur backend tetap dipisah seperti API sungguhan, sehingga nanti masih bisa dimigrasikan ke MySQL.

## Fitur yang sudah berjalan lokal

1. Registrasi akun parent.
2. Login parent dengan token sederhana.
3. Menampilkan data user aktif.
4. Tambah, edit, hapus data anak.
5. Generate contoh log aktivitas saat anak dibuat.
6. Menampilkan dashboard statistik.
7. Menampilkan log activity dengan filter harian/bulanan/all.
8. Mengubah status akses URL melalui tombol lock/unlock.
9. Mengubah password parent.
10. Menyediakan daftar website berbahaya untuk helper blocklist.

## Catatan etis

Backend ini ditulis ulang agar project tidak bergantung pada server orang lain. Namun, agar benar-benar menjadi karya sendiri, bagian brand, logo, copywriting, dan desain antarmuka juga sebaiknya disesuaikan lagi.
