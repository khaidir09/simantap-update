# Spesifikasi: Kelola Guru — Tabel Penuh, Edit via Modal, Ubah Email, Status Mapel/Jadwal, dan Login Terakhir

| | |
|---|---|
| Status | Diimplementasikan (patch lokal) — lihat `docs/patches/kelola-guru-tabel-modal.md` |
| Versi bundle acuan | SIMANTAP 1.0.2 (`api/version.php`) + patch lokal hingga badge sanksi |
| Bundle frontend acuan | `assets/index-CGWL69P7-badgesanksi.js` (SHA-256 `b5e493a6178ebba2b297cfc97f77f30e6d8b8b9af0e2508cbbc03bd96195bf95`), direferensikan `app.html` saat ini |
| Tanggal | 2026-09-29 |
| Jalur rilis | Patch lokal pada bundle ini (backend PHP + patch string bundle) |
| Terkait | `docs/specs/penugasan-mapel-mandiri-guru.md` (tabel `teacher_subjects`), `docs/specs/jadwal-kbm-mandiri-guru.md` (`gtk_pegawai.jadwal_mengajar_json`) |

## 1. Latar belakang dan kebutuhan bisnis

Halaman **Kelola Guru** (menu `teacher-management`, role ADMIN dan KEPALA_SEKOLAH) dipakai untuk mengatur role, wali kelas, status BK, password, dan penugasan mata pelajaran setiap akun. Mulai breakpoint `lg`, halaman dibagi dua kolom: daftar guru yang sempit (320px) di kiri dan panel edit di kanan. Akibatnya daftar hanya memuat nama dan badge, sehingga admin tidak bisa memantau kelengkapan data guru secara sekilas.

Kebutuhan:
1. **Daftar guru memakai lebar penuh** dalam bentuk tabel agar lebih banyak informasi tampil.
2. **Edit data dilakukan di modal**, bukan di panel samping.
3. **ADMIN dapat mengubah email pengguna.** Email adalah kredensial login. Saat ini guru yang salah email atau berganti email harus ditangani langsung di database.
4. **Tabel menampilkan kelengkapan data guru**: apakah guru sudah punya penugasan mapel dan jadwal KBM. Kedua data ini dipakai oleh form Jurnal Mengajar (isi otomatis mapel/kelas/jam).
5. **Tabel menampilkan login terakhir** untuk memantau keaktifan pengguna.

## 2. Perilaku saat ini (hasil analisis kode)

### 2.1 Frontend (komponen `lot` di `assets/index-CGWL69P7-badgesanksi.js`)

- Dirender oleh `e==="teacher-management"&&a.jsx(lot,…)`. Menu diberi `roles:["ADMIN","KEPALA_SEKOLAH"]`.
- Layout: `grid grid-cols-1 lg:grid-cols-[320px_1fr] gap-8`.
  - **Kiri:** input cari (nama/email), filter role `ALL/GURU/KEPALA_SEKOLAH/ADMIN` (TATA_USAHA tidak ada), dan list tombol (avatar, nama, badge role, "Wali <kelas>", badge "BK") dengan `maxHeight: 62vh`. Footer: "N dari M pengguna".
  - **Kanan:** panel edit (`AnimatePresence`) berisi Role Akun (GURU/KEPALA_SEKOLAH/ADMIN), toggle BK, pilihan Wali Kelas, form Ganti Password yang bisa dilipat, dan editor Penugasan Mata Pelajaran (mapel + level + kelas spesifik). Tombol "Simpan Semua" ada di header panel.
- Data: `et.users.list()` → `get_users`, `get_subjects&active_only=1`, dan `get_classes`. Penugasan per guru dimuat saat guru dipilih melalui `et.teacherSubjects.get(uid)` → `get_teacher_subjects`.
- Simpan (`we`):
  1. `et.users.save({uid, email: p.email, displayName, nip, schoolName, role, class_homeroom, is_bk, [password]})` → `save_user_v2`. **Email selalu dikirim dari data lama** dan tidak ada input untuk mengubahnya.
  2. `et.teacherSubjects.save(uid, P)` → `save_teacher_subjects_bulk`.
- **Tidak ada pembedaan role di UI.** KEPALA_SEKOLAH melihat form yang sama dengan ADMIN, tetapi `save_teacher_subjects_bulk` hanya mengizinkan GURU (diri sendiri) dan ADMIN (`tsUser($db, ['GURU','ADMIN'])`). Akibatnya KS mendapat **403 saat menyimpan**, setelah `save_user_v2` telanjur tersimpan (simpan sebagian).

### 2.2 Backend

| Action | Lokasi | Auth | Catatan |
|---|---|---|---|
| `get_users` | `api/index.php` ~204 | `requireRole(ADMIN, KEPALA_SEKOLAH, TATA_USAHA)` | `SELECT uid, email, displayName, role, nip, schoolId, schoolName, lastLogin, class_homeroom, is_bk FROM users ORDER BY createdAt DESC`. Dipakai 4 komponen bundle: `qat` (Pengaturan → Pengguna), `Jat`, `not`, dan `lot`. |
| `save_user_v2` | `api/endpoints_v3.php` ~423 | `requireRole(ADMIN, KEPALA_SEKOLAH, TATA_USAHA)` | Menerima `email` dari body lalu `strtolower(trim())`. Tanpa validasi format. **Siapa pun dari tiga role itu bisa mengganti email.** Email duplikat melanggar `UNIQUE idx_email` → exception mysqli → **HTTP 500 body kosong**. Menyinkronkan `email` ke `gtk_pegawai` (by id, atau by NIP untuk data lama). **Tidak** menyinkronkan `teacher_registry`. Memperbarui `$_SESSION` bila mengedit akun sendiri (`class_homeroom`, `is_bk`, `role`, tetapi tidak `email`). |
| `save_user` (v1) | `api/index.php` ~215 | `requireRole(ADMIN, KEPALA_SEKOLAH, TATA_USAHA)` | Tidak dipanggil bundle, tetapi tetap hidup. Juga bisa mengganti email. Sinkron `REPLACE INTO teacher_registry`. |
| `login` | `api/index.php` ~72 | publik | `SELECT * FROM users WHERE email = ?` + `password_verify`. **Tidak menulis waktu login apa pun.** |
| `get_teacher_subjects` | `api/index.php` ~1481 | `tsUser(GURU[milik sendiri], ADMIN, KS, TU)` | Penugasan per guru: satu baris per (`subject_code`, `class_level`). |
| `jadwal_kbm_get` | `api/jadwal_kbm.php` | GURU (milik sendiri), TU, ADMIN, KS | Jadwal dari `gtk_pegawai.jadwal_mengajar_json` (array JSON). |

### 2.3 Data

- `users.lastLogin` adalah `timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()`. Karena login tidak pernah menulisnya, nilainya adalah **waktu terakhir baris diubah** (simpan profil, ubah role, dan sebagainya). **Kolom ini tidak bisa dipakai sebagai login terakhir.**
- `users.email` memiliki `UNIQUE KEY idx_email`. Collation `utf8mb4_unicode_ci` membuat perbandingannya case-insensitive.
- `teacher_registry.email` adalah PRIMARY KEY.
- `gtk_pegawai.email` tidak unik. `gtk_pegawai.id = users.uid`.

## 3. Expected behavior

### 3.1 Definisi

- **Aktor ADMIN**: `role` hasil baca ulang dari DB (`loadFreshUser`) sama dengan `ADMIN`, bukan dari `$_SESSION`.
- **Perubahan email**: untuk `uid` yang sudah ada, `strtolower(trim(body.email))` ≠ `strtolower(users.email)` di DB.
- **Jumlah mapel** (`mapel_count`): `COUNT(DISTINCT subject_code)` di `teacher_subjects` untuk `teacher_id = uid`.
- **Jumlah sesi jadwal** (`jadwal_count`): jumlah elemen array hasil `json_decode(gtk_pegawai.jadwal_mengajar_json)` untuk `id = uid`.
  - Baris GTK tidak ada, atau kolom kosong/NULL → `0`.
  - JSON rusak atau bukan array → `null`, ditampilkan "Tidak terbaca".
- **Login terakhir** (`last_login_at`): kolom baru `users.last_login_at DATETIME NULL`, diisi waktu lokal `APP_TIMEZONE` setiap login sukses.
- **Hari sejak login** (`last_login_hari`): selisih **hari kalender** antara tanggal hari ini dan tanggal `last_login_at` di `APP_TIMEZONE`, dihitung server. `null` bila belum pernah tercatat.
- **Guru belum lengkap**: `role = 'GURU'` dan (`mapel_count = 0` atau `jadwal_count` bukan bilangan > 0).
- **Tidak aktif**: `last_login_hari` `null` atau `> 30`.

### 3.2 Matriks akses

| Aksi | ADMIN | KEPALA_SEKOLAH | TATA_USAHA | GURU |
|---|---|---|---|---|
| Buka halaman Kelola Guru (menu) | ✓ | ✓ (read-only) | — (menu tidak ada) | — |
| `get_users` + field baru | ✓ | ✓ | ✓ (menu Pengaturan) | 403 |
| Buka modal detail | ✓ (edit) | ✓ (semua field disabled) | — | — |
| Ubah role/BK/wali/password/penugasan dari modal | ✓ | — (UI) | — | — |
| Ubah email via `save_user_v2` / `save_user` | ✓ | **403** | **403** | 403 |
| Simpan tanpa mengubah email via `save_user_v2` | ✓ | ✓ (tidak berubah) | ✓ (tidak berubah) | 403 |

KS read-only hanya ditegakkan di UI, kecuali untuk email (lihat §6 dan §10).

### 3.3 Backend

#### Kolom baru `users.last_login_at`

- Helper `kgPastikanKolomLogin(mysqli $db): bool` di file baru `api/kelola_guru.php`:
  - `SHOW COLUMNS FROM users LIKE 'last_login_at'`; bila belum ada, jalankan `ALTER TABLE users ADD COLUMN last_login_at DATETIME NULL DEFAULT NULL AFTER lastLogin`.
  - Hasilnya di-cache dengan `static` per request.
  - **Dibungkus `try/catch (Throwable)`**: gagal (misalnya hak ALTER tidak ada) → `error_log('[SIMANTAP] …')` dan return `false`. **Kegagalan ini tidak boleh membuat login atau `get_users` gagal.**
- Dipanggil dari `login` dan `get_users`.

#### `login` (diubah)

- Setelah `password_verify` sukses dan sebelum `$_SESSION['user'] = $user`:
  - bila `kgPastikanKolomLogin()` true, jalankan `UPDATE users SET last_login_at = ?, lastLogin = lastLogin WHERE uid = ?` dengan `date('Y-m-d H:i:s')`. `lastLogin = lastLogin` mencegah `ON UPDATE` mengubah kolom lama.
  - Dibungkus `try/catch`; gagal hanya di-log dan login tetap sukses.
- Login gagal tidak menulis apa pun.
- Respons login tidak berubah.

#### `get_users` (diperluas, aditif)

- Auth dan urutan tidak berubah.
- Setiap baris mendapat field tambahan:
  - `mapel_count` (int),
  - `jadwal_count` (int|null),
  - `last_login_at` (string `Y-m-d H:i:s`|null),
  - `last_login_hari` (int|null).
- Implementasi tanpa N+1:
  - satu query `SELECT teacher_id, COUNT(DISTINCT subject_code) FROM teacher_subjects GROUP BY teacher_id`;
  - satu query `SELECT id, jadwal_mengajar_json FROM gtk_pegawai`, di-decode di PHP (bukan `JSON_LENGTH`, supaya JSON rusak tidak melempar error);
  - `last_login_at` ikut `SELECT` utama bila kolom ada. Bila kolom tidak ada, keduanya `null`.
- Field lama (`lastLogin` dan lainnya) tetap dikirim apa adanya.

#### `save_user_v2` dan `save_user` (guard email)

Berlaku identik di kedua action, melalui helper bersama `kgValidasiEmail(mysqli $db, string $uid, string $emailBaru, bool $exists): void` di `api/kelola_guru.php`:

1. `$email = strtolower(trim(body.email))`. Bila tidak lolos `filter_var(FILTER_VALIDATE_EMAIL)` → **400** `Format email tidak valid`.
2. Bila `uid` sudah ada dan terjadi perubahan email: aktor (fresh dari DB) bukan ADMIN → **403** `Hanya ADMIN yang dapat mengubah email pengguna`.
3. Duplikat: `SELECT uid FROM users WHERE email = ? AND uid <> ? LIMIT 1` ada → **409** `Email sudah dipakai oleh pengguna lain`. Pengecekan ini juga berlaku saat **membuat** user baru, sehingga menggantikan HTTP 500 body kosong.
4. Pemeriksaan dilakukan **sebelum** `UPDATE`/`INSERT`, sehingga tidak ada perubahan parsial.

Setelah `UPDATE users` sukses dan email berubah:
- `gtk_pegawai.email` disinkronkan oleh logika yang sudah ada (tidak diubah).
- `teacher_registry`: bila sudah ada baris dengan email baru, `DELETE … WHERE email = <lama>`; bila belum, `UPDATE teacher_registry SET email = <baru> WHERE email = <lama>`. Di `save_user` v1, `REPLACE INTO` yang sudah ada tetap berjalan, dan baris email lama dihapus.
- Bila `uid` = aktor (ADMIN mengubah email sendiri): `$_SESSION['user']['email'] = <baru>`.
- Respons tetap `{success: true}`. Tambahkan `email_berubah: true|false` (aditif).

### 3.4 Frontend (patch bundle, komponen `lot`)

**Layout**
- Grid dua kolom dihapus. Header `zi` tetap ada.
- Di bawah header ada **toolbar**:
  - cari (nama, email, **NIP**);
  - filter role `Semua / GURU / KEPALA SEKOLAH / TATA USAHA / ADMIN`;
  - filter status `Semua / Belum lengkap / Tidak aktif >30 hari`;
  - urutkan `Nama A–Z` (default) / `Login terbaru` / `Login terlama`, dengan `null` selalu di akhir.
- Semua pencarian, filter, dan sort berjalan di klien. **Tidak ada paginasi.**
- Footer tetap "N dari M pengguna". Tambahkan ringkasan "X guru belum lengkap".

**Tabel (≥ `md`, 768px)** — kartu `md:hidden`, tabel `hidden md:block` di dalam `overflow-x-auto`.
- Catatan: CSS precompiled **tidak** memuat `lg:hidden`/`lg:block`, jadi peralihan kartu↔tabel memakai breakpoint `md`. Ini keputusan desain, bukan kekurangan.

| Kolom | Isi |
|---|---|
| Pengguna | Avatar `Ci`, nama (bold). Di bawahnya badge kecil: role, "Wali <nama kelas>", "BK" (seperti list sekarang). |
| Email / NIP | Email (truncate + `title`), NIP (mono) atau "—". |
| Mapel | GURU: `mapel_count > 0` → badge emerald "N mapel"; `0` → badge merah "Belum ada". Non-GURU: "—". |
| Jadwal KBM | GURU: `jadwal_count > 0` → emerald "N sesi"; `0` → merah "Belum ada"; `null` → amber "Tidak terbaca". Non-GURU: "—". |
| Login Terakhir | Titik warna + label relatif. `0` → "Hari ini", `1` → "Kemarin", `n` → "n hari lalu"; `null` → "Belum tercatat". Warna: ≤7 hari emerald, 8–30 amber, >30 atau `null` slate. `title` berisi tanggal-jam lengkap (`last_login_at`). |
| Aksi | Tombol "Edit" (ADMIN) atau "Lihat" (KS). Seluruh baris juga bisa diklik. |

**Kartu (< `md`)**: informasi yang sama dalam satu kartu per pengguna. Nama dan badge di atas, lalu baris badge Mapel/Jadwal/Login, lalu email/NIP.

**Modal edit**
- Overlay `fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-sm flex items-center justify-center p-4`. Panel `bg-white rounded-[2rem] w-full max-w-3xl max-h-[90vh] overflow-y-auto`. Ikuti gaya modal yang sudah ada di bundle.
- Header: avatar, nama, email saat ini, NIP, tombol tutup (X).
- Isi, berurutan (field lama dipindahkan tanpa perubahan perilaku):
  1. **Email Login** (baru), `type="email"`:
     - Hanya ADMIN yang bisa mengedit; KS melihatnya disabled.
     - Helper: "Email dipakai untuk login. Beri tahu pengguna bila email diubah."
     - Bila nilainya berbeda dari email awal, tampil peringatan amber: "Login berikutnya harus memakai email baru: <email>".
     - Validasi klien: tidak kosong dan cocok regex email sederhana. Bila tidak valid, tombol Simpan disabled dan pesan merah tampil di bawah field.
  2. Role Akun + toggle BK, lalu Wali Kelas.
  3. Ganti Password (lipat) — ADMIN saja; disembunyikan untuk KS.
  4. Penugasan Mata Pelajaran. Untuk KS, form tambah dan tombol hapus disembunyikan; hanya daftar yang tampil.
- Footer: "Batal" dan "Simpan Semua" (ADMIN). KS hanya melihat "Tutup".
- Tutup lewat X, Esc, klik backdrop, atau Batal. Bila ada perubahan belum tersimpan (dirty), minta `confirm("Buang perubahan yang belum disimpan?")`.
- Simpan:
  - `save_user_v2` dengan `email` dari field;
  - bila sukses, `save_teacher_subjects_bulk`;
  - bila keduanya sukses, modal ditutup, daftar dimuat ulang (`get_users`, sehingga hitungan dan badge ter-update), dan tampil pesan "Profil & penugasan guru berhasil disimpan!".
  - Gagal di `save_user_v2` (400/403/409): modal **tetap terbuka**, pesan server ditampilkan, tidak ada yang tersimpan.
  - Gagal di penugasan: modal tetap terbuka, pesan "Profil tersimpan, tetapi penugasan mapel gagal disimpan: <pesan>". Daftar tetap dimuat ulang.
- Tombol Simpan disabled selama menyimpan. Klik ganda tidak mengirim dua kali.

## 4. Role, authorization, dan data model

- Otorisasi email di backend memakai role **fresh dari DB**, bukan `$_SESSION`.
- Perubahan skema tunggal: `users.last_login_at DATETIME NULL DEFAULT NULL`, dibuat idempoten saat runtime. Tidak ada backfill: semua pengguna mulai dari "Belum tercatat". `lastLogin` lama tidak diubah dan tidak dipakai UI.
- Tidak ada tabel baru dan tidak ada perubahan pada `teacher_subjects` atau `gtk_pegawai`.

## 5. File dan interface terkait

### 5.1 Backend

| File | Perubahan |
|---|---|
| `api/kelola_guru.php` (baru) | `if (!defined('SIMANTAP_API')) exit;` + `kgPastikanKolomLogin()`, `kgValidasiEmail()`, `kgSinkronRegistryEmail()`, `kgStatusGuru()` (hitungan mapel/jadwal + `last_login_hari`). Komentar berbahasa Indonesia. |
| `api/index.php` | `require_once` helper; `login`: tulis `last_login_at`; `get_users`: field tambahan; `save_user`: guard email + sinkron registry. |
| `api/endpoints_v3.php` | `save_user_v2`: guard email, sinkron registry, `$_SESSION['user']['email']`, dan `email_berubah`. |

Interface:
- `GET api/index.php?action=get_users` → `[{uid, email, displayName, role, nip, schoolId, schoolName, lastLogin, class_homeroom, is_bk, mapel_count, jadwal_count, last_login_at, last_login_hari}]`
- `POST api/index.php?action=save_user_v2` body sama seperti sebelumnya → `{success, email_berubah}` | 400/403/409 `{error}`

### 5.2 Frontend

| File | Perubahan |
|---|---|
| `docs/patches/apply-kelola-guru-bundle.js` (baru) | Patch string dari `index-CGWL69P7-badgesanksi.js` → `index-CGWL69P7-kelolaguru.js`; mengganti isi komponen `lot` dan memperbarui `app.html`. Setiap penggantian mengecek jumlah kemunculan (harus tepat 1) dan gagal keras bila tidak cocok. |
| `assets/index-CGWL69P7-kelolaguru.js` (hasil) | Bundle baru. |
| `app.html` | Menunjuk bundle baru. |

Hanya kelas Tailwind yang ada di `assets/index-CC1PBzaO.css` yang boleh dipakai. Kelas yang sudah diverifikasi ada: `fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-sm max-w-3xl max-h-[90vh] md:hidden md:block overflow-x-auto bg-emerald-50 text-emerald-700 bg-amber-50 text-amber-700 bg-red-50 text-red-600 bg-emerald-500 bg-amber-500 bg-slate-300 divide-y sticky whitespace-nowrap`. Kelas yang **tidak** ada: `lg:hidden`, `lg:block`, `min-w-full`, `table-fixed`, `align-middle`. Setiap kelas baru wajib dicek dengan `grep -F` sebelum dipakai.

### 5.3 Pengujian dan dokumen

| File | Isi |
|---|---|
| `docs/patches/uji-kelola-guru-api.mjs` | Skrip uji API (§9.3). |
| `docs/patches/uji-kelola-guru-seed.sql` / `-cleanup.sql` | Akun uji `kg_uji_admin`, `kg_uji_ks`, `kg_uji_tu`, `kg_uji_guru1`, `kg_uji_guru2`, beserta penugasan dan jadwal contoh. |
| `docs/patches/kelola-guru-tabel-modal.md` | Catatan patch, checklist pasca auto-update, rollback. |
| `CLAUDE.md` | Tambah baris tentang `api/kelola_guru.php` dan kolom `last_login_at` (catatan `lastLogin` tidak andal). |

## 6. Di luar scope

- Paksa logout / invalidasi sesi pengguna yang emailnya diubah. `requireAuth()` tetap hanya membaca sesi.
- Riwayat login (IP, user agent, log per kejadian). Hanya waktu login terakhir yang disimpan.
- Backfill atau perbaikan nilai `users.lastLogin` lama.
- **`gtk_update` (tab Kepegawaian TU) masih bisa mengubah `users.email`** untuk aktor mana pun yang login, melalui SQL yang disusun dengan `real_escape_string`. Celah ini melewati aturan "email hanya oleh ADMIN" dan dicatat sebagai tindak lanjut terpisah.
- Menutup celah KS/TU memberi role ADMIN atau mengubah field lain via `save_user_v2` langsung (hanya email yang ditegakkan di backend).
- Mengubah nama, NIP, atau foto dari modal ini.
- Hapus/tambah akun dari halaman ini, aksi massal, ekspor, paginasi server.
- Perubahan pada editor penugasan mapel atau jadwal KBM itu sendiri (jadwal tetap diedit dari Profil / Kepegawaian).
- `register_guru` (signup publik). Setelah email diganti, email lama bisa didaftarkan ulang lewat signup; lihat §10.

## 7. Compatibility

- `get_users` hanya menambah field. `qat`, `Jat`, dan `not` mengabaikan field baru.
- `save_user_v2` tanpa perubahan email berperilaku sama untuk ADMIN/KS/TU. Perilaku yang berubah hanya: email tidak valid → 400, duplikat → 409 (sebelumnya 500), dan perubahan email oleh non-ADMIN → 403. Form Pengguna (`qat`) milik KS/TU yang mengedit email akan menerima 403; itu perilaku yang diinginkan.
- Kolom baru dibuat otomatis. Instalasi baru dari `install/schema.sql` (DIGENERATE, tidak diedit) tetap berjalan karena kolom dibuat saat login pertama.
- **Auto-updater** dapat menimpa `api/index.php`, `api/endpoints_v3.php`, `app.html`, dan bundle. Setelah update, jalankan ulang checklist di `docs/patches/kelola-guru-tabel-modal.md`. Kolom `last_login_at` dan datanya tetap ada.
- Browser: tidak ada API baru selain `confirm` dan event `keydown`, yang sudah dipakai di bundle.

## 8. Edge cases

| # | Kasus | Perilaku |
|---|---|---|
| E1 | Email baru sama dengan lama, beda huruf besar/kecil atau ada spasi | Dinormalisasi → bukan perubahan. KS/TU tidak kena 403. |
| E2 | Email baru dipakai akun lain (termasuk beda kapital) | 409, tidak ada yang tersimpan, modal tetap terbuka. |
| E3 | Email kosong / format salah | Klien: Simpan disabled. Server (bila dipanggil langsung): 400. |
| E4 | ADMIN mengubah email sendiri | Sesi diperbarui. Login berikutnya memakai email baru. |
| E5 | Pengguna lain sedang login saat emailnya diubah | Sesi tetap berjalan sampai logout/kedaluwarsa (24 jam). Login berikutnya memakai email baru. |
| E6 | `gtk_pegawai` lain (id ≠ uid) punya email yang sama | Tidak diblokir. Dicatat sebagai data lama. Login mencari pas foto `WHERE email = ? OR id = ?`, jadi foto bisa tertukar; di luar scope. |
| E7 | `teacher_registry` sudah punya baris email baru | Baris email lama dihapus, baris baru dipertahankan. |
| E8 | Hak `ALTER TABLE` tidak ada | Login dan `get_users` tetap sukses. `last_login_*` = `null`, UI "Belum tercatat". Error di-log. |
| E9 | Dua request pertama bersamaan menjalankan `ALTER` | ALTER kedua gagal karena kolom duplikat → ditangkap dan diabaikan. Cek ulang `SHOW COLUMNS`. |
| E10 | `jadwal_mengajar_json` rusak / bukan array | `jadwal_count = null` → badge amber "Tidak terbaca". Guru dihitung "belum lengkap". |
| E11 | Akun GURU tanpa baris `gtk_pegawai` | `jadwal_count = 0` → "Belum ada". |
| E12 | Non-GURU (ADMIN/KS/TU) | Mapel/Jadwal "—". Tidak masuk filter "Belum lengkap", tetapi tetap bisa masuk "Tidak aktif". |
| E13 | Login tepat sebelum tengah malam | `last_login_hari` dihitung per tanggal kalender `APP_TIMEZONE`, jadi besoknya "Kemarin". |
| E14 | Jam perangkat klien salah / zona waktu lain | Label relatif memakai `last_login_hari` dari server, tidak bergantung jam klien. |
| E15 | KS mencoba simpan via console (email tetap) | Backend menerima (perilaku lama, §6). UI tidak menyediakan tombol. |
| E16 | Penugasan gagal setelah profil tersimpan | Pesan simpan-sebagian yang jelas, modal tetap terbuka, daftar dimuat ulang. |
| E17 | Nama/email sangat panjang | Truncate dengan `title`. Tabel scroll horizontal di `md`. |
| E18 | Daftar kosong / hasil filter kosong | "Tidak ada pengguna ditemukan." |
| E19 | Modal dibuka lalu data guru dihapus pihak lain | Simpan → `save_user_v2` melakukan INSERT (perilaku lama). Tidak ditangani khusus; dicatat. |

## 9. Testing dan verifikasi end-to-end

### 9.1 Persiapan staging

1. Backup DB (`mysqldump simantap users teacher_registry gtk_pegawai teacher_subjects`).
2. Pastikan `api/config.local.php` (dengan `SIMANTAP_DEV_MODE` untuk lokal) dan `api/install.lock` ada.
3. Import `docs/patches/uji-kelola-guru-seed.sql`.

### 9.2 Pemeriksaan statis

- `php -l api/kelola_guru.php api/index.php api/endpoints_v3.php`
- `node docs/patches/apply-kelola-guru-bundle.js` sukses, dan setiap penggantian cocok tepat satu kali.
- `node --check assets/index-CGWL69P7-kelolaguru.js`
- `grep -F` untuk setiap kelas Tailwind baru di `assets/index-CC1PBzaO.css`.

### 9.3 Uji API otomatis (`node docs/patches/uji-kelola-guru-api.mjs <base-url>`)

| # | Skenario | Harapan |
|---|---|---|
| A1 | Login `kg_uji_guru1` | 200. `get_users` (sebagai admin) → `last_login_hari = 0`, `last_login_at` terisi. `lastLogin` tidak berubah. |
| A2 | Login dengan password salah | 401/400. `last_login_at` tidak berubah. |
| A3 | ADMIN `get_users` | Setiap baris punya `mapel_count` (int), `jadwal_count` (int/null), `last_login_at`, `last_login_hari`. Nilai guru1 cocok dengan seed (mis. 2 mapel, 5 sesi); guru2 = 0/0. |
| A4 | GURU `get_users` | 403. |
| A5 | ADMIN ubah email guru1 → email baru | 200, `email_berubah: true`. Login email baru sukses, email lama gagal. `gtk_pegawai.email` dan `teacher_registry.email` ikut berubah. |
| A6 | KS / TU ubah email guru2 | 403. Email tidak berubah. Field lain di body juga tidak tersimpan. |
| A7 | KS simpan guru2 dengan email sama (beda kapital) | 200 (E1). |
| A8 | ADMIN ubah email guru2 ke email guru1 (huruf besar) | 409. |
| A9 | ADMIN email `bukan-email` | 400. |
| A10 | ADMIN buat user baru dengan email yang sudah ada | 409 (bukan 500). |
| A11 | `save_user` (v1): KS ubah email → 403; ADMIN → 200 + registry lama terhapus | sesuai. |
| A12 | ADMIN ubah email sendiri lalu panggil `session` | Email di sesi = email baru. |
| A13 | Seed `jadwal_mengajar_json = '{rusak'` untuk guru2 | `jadwal_count = null`. |

Akhiri dengan `uji-kelola-guru-cleanup.sql`.

### 9.4 Skenario end-to-end di browser (DevTools → Network terbuka)

1. **ADMIN, desktop ≥1280px**: buka Kelola Guru. Tabel lebar penuh, tanpa panel kanan. Kolom sesuai §3.4. guru1 "2 mapel / 5 sesi / Hari ini" (emerald); guru2 "Belum ada / Belum ada" (merah).
2. Filter "Belum lengkap" → hanya GURU yang belum lengkap. "Tidak aktif >30 hari" → pengguna `null`/lama. Sort "Login terbaru" → guru1 di atas, `null` di akhir. Cari NIP → cocok.
3. Klik baris guru2 → modal terbuka. Ubah email → peringatan amber muncul. Isi email salah → Simpan disabled.
4. Isi email milik guru1 → Simpan → pesan "Email sudah dipakai…", modal tetap terbuka, tidak ada perubahan.
5. Isi email unik + tambah penugasan mapel → Simpan → modal tertutup, badge guru2 menjadi "1 mapel", email baru tampil.
6. Logout → login guru2 dengan email baru → sukses. Login email lama → gagal. Buka lagi sebagai ADMIN → guru2 "Hari ini".
7. Buka modal, ubah role, tekan Esc → konfirmasi "Buang perubahan…" muncul. Pilih batal → modal tetap.
8. **KS**: buka halaman → tombol "Lihat". Di modal semua field disabled, tanpa form password, tanpa form tambah/hapus mapel, hanya tombol "Tutup". Tidak ada request `save_*` di Network.
9. **Mobile 375px** (DevTools): tampil kartu, tanpa scroll horizontal halaman. Modal tidak melebihi layar (`max-h-[90vh]`, bisa di-scroll).
10. **Tablet 800px**: tabel tampil dengan scroll horizontal di dalam wadahnya.
11. **Pengguna → Pengaturan (`qat`)**: tetap memuat daftar tanpa error.

### 9.5 Checklist pasca auto-update

Dicatat di `docs/patches/kelola-guru-tabel-modal.md`:
- `app.html` masih menunjuk bundle `kelolaguru`? Bila ditimpa, jalankan ulang patch dari bundle upstream baru.
- `grep -n "kgValidasiEmail\|last_login_at" api/*.php` masih ada.
- Ulangi A1, A5, A6.

### 9.6 Rollback

- Kembalikan `app.html` ke `index-CGWL69P7-badgesanksi.js`.
- Kembalikan tiga file PHP dari backup.
- Kolom `last_login_at` boleh dibiarkan karena tidak dibaca kode lama.

## 10. Risiko dan trade-off

| Risiko / trade-off | Mitigasi / keputusan |
|---|---|
| Salah ketik email oleh ADMIN mengunci guru dari akun | Peringatan amber + pesan helper. ADMIN bisa memperbaiki kembali. Tidak ada verifikasi email (tidak ada infrastruktur mail). |
| Sesi lama tetap aktif setelah email diubah | Diterima (§6). Sesi berakhir dalam 24 jam. |
| Email lama bisa diklaim lewat `register_guru` publik | Celah yang sudah ada (signup publik tanpa verifikasi). Dicatat, di luar scope. |
| `gtk_update` melewati aturan email-khusus-ADMIN | Dicatat di §6 sebagai tindak lanjut prioritas. |
| KS read-only hanya di UI | Konsisten dengan keputusan scope. Backend tetap menegakkan email. |
| DDL saat runtime (`ALTER TABLE users`) | Idempoten, dibungkus try/catch, sekali per instalasi. Alternatif migrasi bertanda tangan butuh upstream. |
| Data login mulai kosong | Diterima. Label "Belum tercatat" dibedakan dari "Tidak aktif" hanya lewat teks; keduanya masuk filter tidak aktif. |
| Breakpoint tabel `md`, bukan `lg` | Batasan CSS precompiled. Tabel tetap nyaman di tablet dengan scroll horizontal. |
| Tanpa paginasi | Jumlah akun per sekolah < ~150. Filter/sort klien cukup. |
| Patch bundle ditimpa auto-update | Skrip patch idempoten + checklist §9.5. |

## 11. Acceptance criteria

- **AC-1** Di ≥ `md`, Kelola Guru menampilkan tabel lebar penuh dengan kolom Pengguna, Email/NIP, Mapel, Jadwal KBM, Login Terakhir, dan Aksi. Tidak ada lagi layout dua kolom. Di < `md` tampil kartu tanpa scroll horizontal halaman.
- **AC-2** Edit/lihat data dilakukan di modal. Modal bisa ditutup lewat X, Esc, backdrop, atau Batal, dengan konfirmasi bila ada perubahan belum disimpan.
- **AC-3** ADMIN dapat mengubah email pengguna dari modal. Setelah tersimpan, login dengan email baru berhasil, email lama gagal, dan `gtk_pegawai.email` serta `teacher_registry.email` ikut berubah.
- **AC-4** Perubahan email oleh non-ADMIN ditolak backend dengan 403 di `save_user_v2` dan `save_user`, tanpa perubahan data.
- **AC-5** Email duplikat (case-insensitive) → 409 dan email tidak valid → 400, dengan pesan Indonesia. Tidak ada lagi HTTP 500 body kosong untuk kasus ini.
- **AC-6** Kolom Mapel dan Jadwal KBM menampilkan badge jumlah (emerald) atau "Belum ada" (merah) untuk GURU, "Tidak terbaca" (amber) untuk jadwal rusak, dan "—" untuk non-GURU. Nilainya sesuai `teacher_subjects` dan `gtk_pegawai.jadwal_mengajar_json`.
- **AC-7** Setiap login sukses mengisi `users.last_login_at`. Tabel menampilkan label relatif dengan warna ≤7 emerald, 8–30 amber, >30/belum tercatat slate, dan tooltip tanggal lengkap. Login gagal tidak mengubahnya.
- **AC-8** Filter "Belum lengkap", "Tidak aktif >30 hari", filter role (termasuk TATA USAHA), pencarian nama/email/NIP, dan sort nama/login bekerja.
- **AC-9** KEPALA_SEKOLAH melihat modal read-only tanpa tombol simpan, form password, atau form penugasan. Tidak ada request simpan yang terkirim.
- **AC-10** Kegagalan membuat kolom `last_login_at` tidak membuat login atau `get_users` gagal.
- **AC-11** `get_users` tetap mengembalikan semua field lama. Halaman Pengaturan → Pengguna tetap berfungsi.
- **AC-12** `php -l` bersih, skrip patch sukses, dan seluruh skenario §9.3 lulus.
