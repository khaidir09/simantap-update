# Usulan Perbaikan SIMANTAP: Kelola Guru — Tabel Penuh, Edit via Modal, Ubah Email, Status Mapel/Jadwal, Login Terakhir

| | |
|---|---|
| Sekolah | SMK Negeri 1 Amuntai (HSU) |
| ID Distribusi | 9E8DC8FBF4 |
| Versi | 1.0.2 |
| Tanggal | 2026-09-29 |
| Lampiran | `backend-kelola-guru.diff` |
| Patch bundle lokal | `docs/patches/apply-kelola-guru-bundle.js` |
| Prasyarat | Diff dibuat di atas usulan-usulan sekolah sebelumnya. Yang dipakai: `loadFreshUser()` (`api/kesiswaan_access.php`), tabel `teacher_subjects` (usulan penugasan mapel), dan `gtk_pegawai.jadwal_mengajar_json` (usulan jadwal KBM). Bila `loadFreshUser()` belum ada di sumber resmi, padanannya adalah membaca ulang `role` dari tabel `users` berdasarkan `uid` di sesi. |

## 1. Masalah

Halaman **Kelola Guru** (ADMIN dan Kepala Sekolah) dipakai untuk mengatur role, wali kelas, status BK, password, dan penugasan mapel setiap akun. Ada beberapa kendala di pemakaian sehari-hari:

1. **Daftar guru sempit.** Mulai layar laptop, halaman dibagi dua kolom: daftar selebar 320px di kiri dan panel edit di kanan. Daftar hanya memuat nama dan badge, jadi admin tidak bisa memantau kelengkapan data guru sekilas.
2. **Email tidak bisa diubah.** Guru yang salah ketik email saat didaftarkan, atau berganti email, harus ditangani langsung di database.
3. **Kelengkapan data guru tidak terlihat.** Penugasan mapel dan jadwal KBM dipakai form Jurnal Mengajar untuk isi otomatis. Admin tidak bisa melihat guru mana yang belum mengisinya.
4. **Keaktifan pengguna tidak terpantau.** Kolom `users.lastLogin` memakai `ON UPDATE CURRENT_TIMESTAMP`, dan action `login` tidak pernah menulisnya. Isinya adalah **waktu terakhir baris diubah** (simpan profil, ganti role, dan sebagainya), bukan waktu login.
5. **Kepala Sekolah mendapat 403 saat menyimpan.** Menu terbuka untuk KS dan formnya sama dengan ADMIN, tetapi `save_teacher_subjects_bulk` hanya mengizinkan GURU/ADMIN. Profil KS telanjur tersimpan, lalu penugasan gagal (simpan sebagian).
6. **Email duplikat menghasilkan HTTP 500 body kosong.** `save_user_v2` dan `save_user` tidak memvalidasi email, dan pelanggaran `UNIQUE idx_email` jatuh sebagai exception mysqli.

## 2. Kondisi saat ini (1.0.2)

| Bagian | Perilaku |
|---|---|
| Frontend Kelola Guru | Grid `lg:grid-cols-[320px_1fr]`. Filter role tanpa TATA USAHA. Saat menyimpan, email dikirim dari data lama (tidak ada input email). |
| `get_users` | Mengembalikan kolom `users` saja, tanpa status penugasan, jadwal, atau login. |
| `save_user_v2` / `save_user` | ADMIN, KS, dan TU sama-sama bisa mengganti email. Tidak ada validasi format atau duplikat. `teacher_registry` (PK email) tidak ikut dipindah oleh `save_user_v2`. |
| `login` | Tidak mencatat waktu login. |

## 3. Perbaikan yang diusulkan

### 3.1 Aturan

| Topik | Keputusan |
|---|---|
| Ubah email | **Hanya ADMIN.** Role dibaca ulang dari database, bukan dari sesi. Berlaku di `save_user_v2` dan `save_user`. |
| Email duplikat | 409 `Email sudah dipakai oleh pengguna lain` (tidak peka huruf besar/kecil), juga saat membuat akun baru. |
| Format email | 400 `Format email tidak valid`. |
| Email sama, beda kapital/spasi | Bukan perubahan. KS/TU tetap bisa menyimpan data lain seperti sebelumnya. |
| Setelah email berubah | `gtk_pegawai.email` disinkronkan (logika lama). Baris `teacher_registry` dipindah ke email baru. Bila ADMIN mengubah emailnya sendiri, email di sesi ikut diperbarui. Sesi pengguna lain tetap berjalan; login berikutnya memakai email baru. |
| Login terakhir | Kolom baru `users.last_login_at DATETIME NULL`, diisi setiap login sukses (waktu `APP_TIMEZONE`). Login gagal tidak menulis. `lastLogin` lama tidak diubah dan tidak dipakai. |
| Keaktifan | ≤7 hari hijau, 8–30 hari kuning, >30 hari atau belum tercatat abu-abu. Hari dihitung server per tanggal kalender. |
| Guru "belum lengkap" | Role GURU dan belum punya penugasan mapel **atau** jadwal KBM. |
| Kepala Sekolah | Hanya bisa melihat (read-only) di halaman ini. |

### 3.2 Backend (lihat `backend-kelola-guru.diff`)

- **File baru `api/kelola_guru.php`** berisi helper; tidak ada action baru.
  - `kgPastikanKolomLogin()` membuat kolom `last_login_at` sekali, secara idempoten. Kegagalan (misalnya tanpa hak `ALTER`) hanya di-log dan **tidak menggagalkan login**.
  - `kgCatatLogin()` menulis `last_login_at` dengan `lastLogin = lastLogin`, agar `ON UPDATE` tidak mengubah kolom lama.
  - `kgStatusGuru()` menambah `mapel_count`, `jadwal_count`, `last_login_at`, dan `last_login_hari` ke setiap baris `get_users`. Implementasinya tiga query total, tanpa N+1.
    - `mapel_count` adalah `COUNT(DISTINCT subject_code)` dari `teacher_subjects`.
    - `jadwal_count` adalah jumlah elemen `jadwal_mengajar_json`, di-decode di PHP. JSON rusak menghasilkan `null`.
  - `kgValidasiEmail()` menjalankan aturan §3.1 **sebelum** `UPDATE`/`INSERT`, sehingga tidak ada simpan sebagian.
  - `kgSetelahEmailBerubah()` memindahkan `teacher_registry` dan memperbarui sesi.
- **`api/index.php`**: `require_once` helper; `login` memanggil `kgCatatLogin`; `get_users` memanggil `kgStatusGuru`; `save_user` memakai guard email.
- **`api/endpoints_v3.php`**: `save_user_v2` memakai guard email.
- **Respons simpan** mendapat field tambahan `email_berubah`.

**Saran untuk sumber resmi:** tambahkan `last_login_at` sebagai migrasi bertanda tangan (`schema_migrations`), bukan DDL saat runtime.

### 3.3 Frontend (patch bundle di sekolah kami)

- **Tabel lebar penuh** tampil mulai `md` (768px), dan berubah jadi kartu di layar HP.
  - Kolom: Pengguna (dengan badge role/Wali/BK), Email/NIP, Mapel ("N mapel" atau "Belum ada"), Jadwal KBM ("N sesi", "Belum ada", atau "Tidak terbaca"), Login Terakhir (label relatif + titik warna + tooltip tanggal lengkap), Aksi.
  - Non-GURU menampilkan "—" di kolom Mapel dan Jadwal.
- **Toolbar**: cari nama/email/NIP, filter role (termasuk TATA USAHA), filter *Belum lengkap* dan *Tidak aktif >30 hari*, urutan nama / login terbaru / login terlama. Footer menampilkan jumlah guru belum lengkap.
- **Modal edit**: berisi field lama (role, BK, wali kelas, ganti password, penugasan mapel) ditambah **Email Login** yang hanya bisa diubah ADMIN.
  - Muncul peringatan kuning bila email berubah: login berikutnya memakai email baru.
  - Modal ditutup lewat X, Esc, klik latar, atau Batal. Bila ada perubahan belum tersimpan, muncul konfirmasi.
  - Bila server menolak (400/403/409), modal tetap terbuka dengan pesan dari server.
- **Kepala Sekolah**: tombol "Lihat", semua field disabled, tanpa form password, tanpa tambah/hapus penugasan, hanya tombol Tutup.
- **Catatan breakpoint**: CSS bundle precompiled tidak memuat `lg:hidden`/`lg:block`, sehingga peralihan kartu↔tabel memakai `md`. Di sumber resmi, `lg` bisa dipakai bila diinginkan.

## 4. Kompatibilitas

- `get_users` hanya **menambah** field. Pemakai lain (Pengaturan → Pengguna dan lainnya) tidak terpengaruh.
- Satu kolom baru, `users.last_login_at`. Tidak ada tabel baru dan tidak ada perubahan data lama.
- Tidak ada backfill: semua akun tampil "Belum tercatat" sampai login lagi.
- Perilaku yang berubah hanya: KS/TU yang mengubah email (sekarang 403), email tidak valid (400), dan email duplikat (409, sebelumnya 500).

## 5. Hasil uji di sekolah

- `php -l` lulus untuk `kelola_guru.php`, `index.php`, dan `endpoints_v3.php`. Bundle hasil patch lulus `node --check`, dan semua kelas Tailwind yang dipakai terverifikasi ada di CSS precompiled.
- **Uji API otomatis: 26/26 lulus** (MySQL 5.7, PHP 8.2), dengan skrip `docs/patches/uji-kelola-guru-api.mjs`. Cakupan:
  - login mencatat `last_login_at` dan tidak mengubah `lastLogin`; login gagal tidak menulis;
  - nilai `mapel_count`/`jadwal_count`, termasuk jadwal rusak (`null`) dan guru tanpa baris GTK (`0`);
  - GURU ditolak saat memanggil `get_users`;
  - KS/TU ditolak saat mengubah email (tanpa simpan sebagian);
  - email beda kapital diterima; duplikat → 409; format salah → 400; akun baru dengan email terpakai → 409;
  - setelah email diubah ADMIN: login email baru berhasil, email lama gagal, `gtk_pegawai` dan `teacher_registry` ikut pindah;
  - aturan yang sama di `save_user` v1;
  - sesi diperbarui saat ADMIN mengubah email sendiri.
- **Uji browser (Chrome): lulus.**
  - Tabel, filter, urutan, dan badge sesuai data.
  - Modal menangani email tidak valid, email duplikat, dan simpan email baru + penugasan.
  - Konfirmasi perubahan belum tersimpan berjalan lewat Esc dan klik latar.
  - Mode lihat KS berjalan.
  - Tampilan kartu di 375px dan tabel di 800px tanpa scroll horizontal halaman.

## 6. Permintaan ke pengembang

1. Mengadopsi tampilan tabel + modal di halaman Kelola Guru beserta kolom status mapel, jadwal KBM, dan login terakhir (§3.3).
2. Mencatat waktu login di kolom terpisah (`last_login_at`) melalui migrasi resmi, dan tidak lagi memakai `lastLogin` yang ber-`ON UPDATE` sebagai waktu login.
3. Menegakkan aturan email di backend (§3.1): hanya ADMIN yang boleh mengubah, disertai validasi format dan duplikat dengan pesan yang jelas.
4. Menutup jalur lain yang masih bisa mengubah `users.email` tanpa aturan ini (§7).
5. Menyelaraskan hak Kepala Sekolah di Kelola Guru: read-only seperti usulan ini, **atau** diberi hak simpan penugasan secara konsisten di backend.

## 7. Catatan tambahan

- **`gtk_update`** (tab Kepegawaian TU) masih bisa mengubah `users.email` untuk aktor mana pun yang login, lewat SQL yang disusun dengan `real_escape_string`. Jalur ini melewati aturan "email hanya oleh ADMIN" dan belum kami ubah.
- **KS/TU masih bisa mengubah role dan field lain** (termasuk memberi role ADMIN) lewat `save_user_v2` bila dipanggil langsung. Usulan ini hanya menegakkan email di backend; mode lihat KS berlaku di tampilan.
- **Email lama bisa didaftarkan ulang.** Setelah email diganti, email lama bisa didaftarkan lewat `register_guru` (signup publik tanpa verifikasi). Celah ini sudah ada sebelumnya.
- **Tidak ada paksa logout.** Sesi pengguna yang emailnya diubah tetap berlaku sampai logout atau kedaluwarsa (24 jam).
