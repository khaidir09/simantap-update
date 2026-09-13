# Panduan Instalasi Aplikasi SIMANTAP

Aplikasi Jurnal Mengajar Digital — BTIKP Dinas Pendidikan & Kebudayaan Prov. Kalimantan Selatan.

## Kebutuhan Server

- Hosting/VPS dengan **PHP 8.0 atau lebih baru**
- Ekstensi PHP: `mysqli`, `fileinfo`, `json`, `session`
- **cURL** atau `allow_url_fopen` aktif (untuk aktivasi token ke Portal SIMANTAP)
- Database **MySQL 5.7+ / MariaDB 10.3+**
- Akses internet keluar dari server (wajib saat aktivasi)

## Langkah Instalasi

1. **Unggah & ekstrak** file `simantap.zip` ke folder web hosting Anda
   (bisa di root domain, subdomain, maupun subfolder — bebas).
2. **Buka domain Anda** di browser. Anda akan otomatis diarahkan ke
   halaman instalasi (`/install/`).
3. Ikuti wizard 4 langkah:
   1. **Pengecekan server** — penuhi semua kebutuhan bertanda merah.
   2. **Database** — masukkan kredensial database dari panel hosting,
      klik *Tes Koneksi*, lalu impor otomatis akan berjalan.
   3. **Aktivasi token** — masukkan **NPSN** dan **token akses**
      (`SMTP-XXXX-XXXX-XXXX`) yang Anda terima melalui email dari
      Portal SIMANTAP BTIKP. Langkah ini membutuhkan internet dan token
      yang masih berlaku (30 hari sejak diterbitkan).
   4. **Finalisasi** — isi identitas sekolah dan buat akun administrator.
4. Selesai! Login menggunakan akun administrator yang baru dibuat.
   Status sekolah Anda di Portal SIMANTAP otomatis menjadi
   **Terimplementasi**.

## Setelah Instalasi

- **Hapus folder `/install`** dari server demi keamanan.
- Kelola mata pelajaran, kelas, guru, dan kop surat melalui menu
  admin di dalam aplikasi.

## Kendala Umum

| Masalah | Solusi |
|---|---|
| "Tidak dapat menghubungi Portal SIMANTAP" | Pastikan server memiliki akses internet keluar; hubungi penyedia hosting bila cURL diblokir. |
| "NPSN atau Token tidak valid" | Periksa kembali penulisan token dari email. Bila kadaluwarsa, minta token baru ke BTIKP. |
| Impor database gagal | Pastikan database kosong dan user database memiliki hak CREATE/ALTER/INSERT. |

Bantuan: hubungi BTIKP Disdikbud Prov. Kalimantan Selatan.
