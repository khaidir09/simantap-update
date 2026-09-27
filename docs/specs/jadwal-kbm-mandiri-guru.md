# Spesifikasi: Jadwal KBM Mandiri oleh Guru dan Isi Otomatis Jam Jurnal

| | |
|---|---|
| Status | Diimplementasikan (patch lokal) — lihat `docs/patches/jadwal-kbm-mandiri-guru.md` |
| Versi bundle acuan | SIMANTAP 1.0.2 (`api/version.php`) + patch lokal hingga tampilan jurnal |
| Bundle frontend acuan | `assets/index-CGWL69P7-tampiljurnal2.js` (SHA-256 `7d5fa4f15a349b9a1d52bf27eea56a11c1fb3af1c51ee973ef0a0658d99dea2c`), direferensikan `app.html` saat ini |
| Tanggal | 2026-09-27 |
| Jalur rilis | Patch lokal pada bundle ini **dan** paket usulan perubahan ke upstream BTIKP |
| Terkait | `docs/specs/penugasan-mapel-mandiri-guru.md` (sumber pilihan kelas dan mapel), `docs/usulan-btikp/RINGKASAN-TAMPILAN-JURNAL.md` (waktu jurnal memakai jam pelajaran) |

## 1. Latar belakang dan kebutuhan bisnis

Jadwal KBM guru (hari, kelas, jam pelajaran) disimpan per pegawai di `gtk_pegawai.jadwal_mengajar_json`. Saat ini jadwal hanya bisa diinput oleh Tata Usaha dari tab Kepegawaian (GTK). Dari 61 GTK di data lokal, baru **1** yang punya jadwal.

Masalah:
1. **TU kewalahan.** TU harus menginput jadwal semua guru satu per satu.
2. **Kolom "Jam Ke / Waktu KBM" berupa teks bebas** (contoh data nyata: `08 - 11 (13.15 - 16.30)`), jadi nilainya tidak bisa dipakai sistem dengan andal.
3. **Jam jurnal diketik ulang.** Saat mengisi Jurnal Mengajar, guru mengetik ulang *Jam Ke-*, *Jam Mulai*, dan *Jam Selesai*, padahal informasinya sudah ada di jadwal. Salah ketik juga terjadi di data nyata, misalnya `jam_ke = 4-7`, `jam_mulai = 10:10`, `jam_selesai = 01:55`.
4. **Informasi ruang belajar tidak diperlukan** pada jadwal KBM.

Tujuan:
- **GURU** dapat menambah dan menghapus sesi jadwal KBM miliknya sendiri dari halaman **Profil**. Perubahan **langsung berlaku** tanpa persetujuan.
- **TATA_USAHA** dan **ADMIN** tetap dapat mengelola jadwal semua GTK dari tab Kepegawaian, dengan format dan endpoint yang sama.
- Jam pelajaran disimpan dalam **field terstruktur** (jam ke mulai–selesai dan jam mulai–selesai), sehingga form Jurnal Mengajar dapat **mengisi otomatis** *Jam Ke-*, *Jam Mulai*, *Jam Selesai*, dan (bila tercatat) *Mata Pelajaran* ketika tanggal dan kelas jurnal cocok dengan satu sesi jadwal.
- Field **ruang** dihapus dari form dan tampilan jadwal.
- Celah otorisasi `gtk_update_atribut` (lihat §2.1) ditutup.

## 2. Perilaku saat ini (hasil analisis kode)

### 2.1 Backend

| Action | Lokasi | Auth saat ini | Catatan |
|---|---|---|---|
| `gtk_list` | `api/endpoints_tu.php` ~14 | **tanpa auth** | Mengembalikan semua GTK, termasuk `jadwal_mengajar_json` yang sudah di-decode. Dipakai tab GTK dan widget Jadwal KBM di dashboard. |
| `gtk_create` / `gtk_update` | `api/endpoints_tu.php` ~90 | (tidak diubah spesifikasi ini) | Satu case bersama. Menulis `jadwal_mengajar_json = json_encode($body['jadwal_mengajar_json'] ?? [])` lewat `INSERT … ON DUPLICATE KEY UPDATE … jadwal_mengajar_json=VALUES(jadwal_mengajar_json)` (~122–153). Saat TU menyimpan biodata, bundle mengirim seluruh daftar jadwal dari state klien, sehingga **jadwal di DB tertimpa oleh data klien yang mungkin sudah basi**. |
| `gtk_update_atribut` | `api/endpoints_tu.php` ~273 | **hanya `requireAuth()`** | Update parsial `beban_mengajar`, `kelas_ajar`, `mapel`, `class_homeroom`, `is_bk`, dan `jadwal_mengajar_json`. **Guru mana pun dapat mengubah jadwal, wali kelas (sekaligus `users.class_homeroom`), dan status BK (`users.is_bk`) GTK siapa pun.** `jadwal_mengajar_json` diterima tanpa validasi. Sinkronisasi ke `users` memakai SQL yang disusun dengan `real_escape_string`. Method HTTP tidak dicek. |
| `save_user_v2` | `api/endpoints_v3.php` ~524 | — | Saat membuat baris GTK baru, jadwal diisi `[]`. Tidak diubah. |

Format item jadwal saat ini (`install/schema.sql` ~196, komentar kolom): `{id, hari, kelas, jamKe, ruang}`:
- `id`: `JM-<Date.now()>`, dibuat di klien;
- `hari`: `Senin`–`Jumat`;
- `kelas`: **nama** kelas (`XII TKJ 2`, diambil dari teks `gtk_pegawai.kelas_ajar`), bukan `classes.id` (`XII_TKJ_2`) seperti yang dipakai form jurnal;
- `jamKe`: teks bebas;
- `ruang`: nama ruangan dari master sarpras.

Jurnal (`journal_entries`) menyimpan `jam_ke`, `jam_mulai`, dan `jam_selesai` sebagai varchar lewat `save_journal` (`api/index.php` ~493). `presensiAngkaJam()` (`api/presensi_harian.php` ~73) mem-parse angka dari `jam_ke` (misalnya `1-2` → `[1,2]`) untuk presensi harian dan dispensasi. Karena itu, format isi otomatis harus tetap bisa di-parse oleh fungsi ini.

Helper yang sudah ada dan akan dipakai ulang:
- `loadFreshUser()` dan `resolveClassRef()`/`normalizeClassKey()` di `api/kesiswaan_access.php`;
- `tsLoadRows()`, `tsLoadSubjects()`, dan `tsLoadClasses()` di `api/teacher_subject_access.php`.

### 2.2 Frontend (`assets/index-CGWL69P7-tampiljurnal2.js`)

- **Tab GTK** (komponen `pot`, menu `kepegawaian-gtk`). Flag edit adalah `un = ["TATA_USAHA","ADMIN"].includes(role)`. Bagian "Jadwal KBM & Tatap Muka Terintegrasi" berisi:
  - grid Senin–Jumat berisi kartu sesi: `Kls <kelas>`, badge `ruang`, dan `⏱️ <jamKe>`, dengan tombol hapus yang muncul saat hover;
  - form "Alokasi Sesi Jadwal Kelas Baru": *Pilih Hari*, *Target Kelas* (dari `kelasAjar.split(",")`), *Jam Ke / Waktu KBM* (input teks, placeholder `Contoh: 01 - 03 (07.30 - 09.45)`, id `schedule-form-jamke`), dan *Ruang Belajar / Lokasi* (dari `et.tu.sarpras.ruangan()`).

  Tambah (`fn`) dan hapus (`Pn`) memanggil `et.tu.gtk.updateAtribut({id, jadwal_mengajar_json: <daftar penuh>})`. Kegagalan hanya dicatat di `console.error`, tanpa pesan ke pengguna.
- **Widget Jadwal KBM di dashboard.** Untuk non-admin, widget memanggil `et.tu.gtk.list()`, mencari baris `id === user.uid`, lalu menampilkan sesi hari ini (atau hari yang dipilih) berisi `kelas`, `jamKe`, dan `ruang` (bila ada). Widget ini read-only.
- **Halaman Profil.** Sudah memiliki kartu "Penugasan Mata Pelajaran" untuk GURU (prefiks identifier `__tm`).
- **Form Jurnal Mengajar**:
  - state awal: `{kelas:"", mapel:"", mapelId:"", jam:"", jamMulai:"07:00", jamSelesai:"08:30", tanggal:<hari ini>, …}`;
  - *Pilih Kelas* (`name:"kelas"`, value `classes.id`, handler generik `Yt`);
  - *Mata Pelajaran* (value `subject_code` dari penugasan, atau `subjects.id` sebagai fallback);
  - *Tanggal* (`type=date`);
  - *Jam Ke-* (teks, placeholder `Contoh: 1-2`, wajib untuk lanjut ke langkah 2);
  - *Jam Mulai* dan *Jam Selesai* (`type=time`).

  Saat mengedit jurnal (`editingJournalId`), nilai dimuat dari data jurnal.

## 3. Expected behavior

### 3.1 Definisi

- **Sesi**: satu item di `jadwal_mengajar_json`.
- **Format baru sesi (tersimpan)**:

  ```json
  {
    "id": "JM-<uniqid>",
    "hari": "Senin",
    "classId": "XII_TKJ_2",
    "kelas": "XII TKJ 2",
    "subjectCode": "MTK" ,
    "mapel": "Matematika",
    "jamKeMulai": 1,
    "jamKeSelesai": 4,
    "jamMulai": "07:30",
    "jamSelesai": "10:45",
    "jamKe": "1 - 4 (07:30 - 10:45)"
  }
  ```

  - `kelas` = `classes.name`, dan `mapel` = `subjects.name`. Keduanya diambil dari master oleh server.
  - `subjectCode` dan `mapel` boleh string kosong (sesi tanpa mapel).
  - `jamKe` adalah teks turunan yang dibentuk server (`a - b (HH:MM - HH:MM)`, atau `a (HH:MM - HH:MM)` bila `a = b`). Teks ini dipertahankan agar bundle lama, grid TU, dan widget dashboard tetap bisa menampilkan sesi.
  - Sesi baru **tidak** memiliki `ruang`.
- **Sesi lama (legacy)**: item tanpa `jamKeMulai` numerik. Saat dibaca, server menghitung **nilai efektif** (tanpa mengubah data tersimpan):
  - `classId` dari `kelas` lewat `resolveClassRef()`;
  - `jamKeMulai`/`jamKeSelesai`/`jamMulai`/`jamSelesai` dari `jamKe` lewat parser §3.4;
  - `subjectCode`/`mapel` kosong.
- **Sesi lengkap**: nilai efektifnya punya `hari` valid, `classId` yang ada di `classes`, jam ke valid, serta jam mulai dan selesai valid. Hanya sesi lengkap yang dipakai untuk isi otomatis jurnal dan pengecekan bentrok.
- **Perlu dilengkapi**: sesi yang tidak lengkap. Respons menyertakan `perluDilengkapi: true` dan `alasan: string[]` (misalnya `Kelas "XII TKJ 9" tidak ditemukan`, `Jam pelajaran tidak dapat dibaca dari "pagi"`, `Hari tidak valid`).
- **Sesi tersimpan tidak berubah**: item di body yang `id`-nya ada di data tersimpan **dan** nilai efektif `hari`, `classId`, `subjectCode`, `jamKeMulai`, `jamKeSelesai`, `jamMulai`, dan `jamSelesai`-nya sama dengan nilai efektif item tersimpan itu. Server menyimpan kembali **item tersimpan apa adanya** (termasuk `ruang` dan `jamKe` lama), tanpa validasi aturan baru.
- **Sesi baru**: setiap item di body yang bukan sesi tersimpan tidak berubah. Server memberinya `id` baru (`uniqid('JM-')`) bila `id` kosong atau belum ada di data tersimpan.
- **Versi jadwal**: `sha1` dari nilai `jadwal_mengajar_json` mentah di DB (string kosong/NULL diperlakukan sebagai `[]`). Dipakai untuk deteksi konflik simpan (§3.3).
- **Hari**: `Senin`, `Selasa`, `Rabu`, `Kamis`, `Jumat`. Sabtu dan Minggu tidak didukung.
- **Rentang jam ke**: bilangan bulat 1–11.

### 3.2 Matriks akses

| Aksi | GURU (milik sendiri) | GURU (GTK lain) | TATA_USAHA | ADMIN | KEPALA_SEKOLAH | Role lain | Tanpa login |
|---|---|---|---|---|---|---|---|
| `jadwal_kbm_get` | Ya | 403 | Semua | Semua | Semua | 403 | 401 |
| `jadwal_kbm_save` | Ya | 403 | Semua | Semua | 403 | 403 | 401 |
| `gtk_update_atribut` (semua field) | 403 | 403 | Ya | Ya | 403 | 403 | 401 |

- "Milik sendiri" berarti `gtk_id === users.uid` aktor (`gtk_pegawai.id` = `users.uid`).
- GURU dengan `is_bk=1` atau `class_homeroom` terisi diperlakukan sama seperti GURU biasa.
- Role dibaca ulang dari DB setiap request (`loadFreshUser`), sehingga perubahan role dan penghapusan user langsung berlaku.

### 3.3 Perilaku detail per endpoint

Semua endpoint baru ditempatkan di file baru `api/jadwal_kbm.php`. File ini diawali guard `SIMANTAP_API`, di-`require_once` dari `api/index.php` setelah `teacher_subject_access.php`, dan dipanggil lewat `case` eksplisit di `api/index.php`, sama seperti pola `dispensasi_*`. Nama helper memakai prefiks `jk`:
- `jkUser($db, array $roles)`;
- `jkLoad($db, $gtkId)` → `['ada' => bool, 'nama' => string, 'raw' => string, 'items' => array]`;
- `jkVersi(string $raw)`;
- `jkParseJamKe(string $teks)` (§3.4);
- `jkEfektif($db, array $item, array $kelas)`;
- `jkValidasiBaru(...)`;
- `jkRentangBeririsan(...)`;
- `jkTeksJamKe(...)`;
- `jkPeringatanAntarGuru(...)`.

Semua validasi dijalankan sebelum query mutasi. Exception DB di-`error_log`, lalu dikembalikan sebagai 500 dengan pesan generik tanpa detail DB.

#### `jadwal_kbm_get` (GET)

1. `jkUser($db, ['GURU','TATA_USAHA','ADMIN','KEPALA_SEKOLAH'])`.
2. `gtk_id` opsional, default uid aktor. GURU dengan `gtk_id !== uid` → 403 `Anda hanya dapat melihat jadwal KBM milik sendiri`.
3. Bila baris `gtk_pegawai` tidak ada, kembalikan 200 `{gtk_id, gtkAda: false, versi: null, items: []}`.
4. Respons 200:

   ```
   { gtk_id, gtkAda: true, nama, versi,
     items: [ { …item tersimpan…, efektif: { hari, classId, kelas, subjectCode, mapel,
                jamKeMulai, jamKeSelesai, jamMulai, jamSelesai },
                legacy: bool, perluDilengkapi: bool, alasan: string[] } ] }
   ```

   Item diurutkan menurut hari (Senin→Jumat), lalu `jamKeMulai` efektif (null di akhir). Item tersimpan dikembalikan apa adanya. Klien memakai `efektif` untuk tampilan dan isi otomatis.

#### `jadwal_kbm_save` (POST)

1. `jkUser($db, ['GURU','TATA_USAHA','ADMIN'])`. Hanya `POST`; method lain → 405.
2. `gtk_id` wajib (400 `gtk_id wajib diisi`). GURU dengan `gtk_id !== uid` → 403 `Anda hanya dapat mengubah jadwal KBM milik sendiri`.
3. Baris `gtk_pegawai` tidak ada → 400 `Data kepegawaian (GTK) belum tersedia. Hubungi Tata Usaha.`
4. `items` harus array (boleh kosong), maksimal 100 item. Selain itu → 400.
5. `versi` wajib. Bila berbeda dari `jkVersi()` data saat ini → **409** `Jadwal telah diubah oleh pengguna lain. Muat ulang lalu ulangi perubahan Anda.`, dan data tidak berubah.
6. Pisahkan sesi tersimpan tidak berubah dan sesi baru (§3.1).
7. Validasi setiap **sesi baru**:

   | Aturan | GURU | TU / ADMIN | Error 400 |
   |---|---|---|---|
   | `hari` salah satu Senin–Jumat | ✔ | ✔ | `Hari tidak valid` |
   | `classId` ada di `classes` | ✔ | ✔ | `Kelas <id> tidak ditemukan` (atau `Data kelas belum tersedia` bila tabel belum ada) |
   | `classId` termasuk **kelas yang diizinkan** dari penugasan guru target (lihat di bawah) | ✔ | ✘ (tidak dibatasi) | `Kelas <nama> tidak termasuk penugasan mata pelajaran Anda` |
   | `subjectCode` (bila diisi) ada di `subjects` | ✔ dan `is_active = 1` | ✔ | `Mata pelajaran <kode> tidak ditemukan atau tidak aktif` |
   | `subjectCode` (bila diisi) termasuk penugasan guru target dan penugasan itu mencakup `classId` | ✔ (hanya bila guru punya penugasan) | ✘ | `Mata pelajaran <nama> tidak ditugaskan untuk kelas <nama>` |
   | `jamKeMulai`, `jamKeSelesai` bilangan bulat 1–11 dan mulai ≤ selesai | ✔ | ✔ | `Jam ke tidak valid` |
   | `jamMulai`, `jamSelesai` format `HH:MM` (00–23:00–59; `HH.MM` dinormalisasi ke `HH:MM`) dan mulai < selesai | ✔ | ✔ | `Jam mulai harus lebih awal dari jam selesai` / `Format jam tidak valid` |

   **Kelas yang diizinkan** (GURU) dihitung dari `teacher_subjects` guru target:
   - gabungan `class_ids` dari semua baris;
   - untuk baris dengan `class_ids` kosong tetapi `class_level` terisi: semua kelas dengan `classes.level` tersebut;
   - untuk baris tanpa `class_ids` dan tanpa `class_level`, **atau** bila guru tidak punya penugasan sama sekali: semua kelas.

   Server mengabaikan nilai `kelas`, `mapel`, `jamKe`, dan `ruang` dari body untuk sesi baru, lalu membentuk ulang `kelas`/`mapel` dari master dan `jamKe` dari §3.1.
8. **Bentrok milik sendiri**: bila sebuah sesi baru beririsan rentang jam ke-nya dengan sesi **lengkap** lain (baru maupun tersimpan) pada hari yang sama → 400 `Jadwal bentrok: <hari> jam ke-<a>–<b> sudah terisi kelas <kelas>`. Irisan antar-sesi yang **seluruhnya** tersimpan tidak berubah tidak ditolak (data lama).
9. Simpan: `UPDATE gtk_pegawai SET jadwal_mengajar_json=? WHERE id=? AND COALESCE(jadwal_mengajar_json,'') = ?`, dengan parameter terakhir adalah nilai mentah yang dibaca di langkah 5 (dijalankan dalam transaksi bersama `SELECT … FOR UPDATE`). Bila baris yang terpengaruh 0 karena berubah di antaranya → 409 seperti langkah 5. Tidak ada tabel lain yang diubah.
10. **Peringatan antar guru** (tidak memblokir): untuk setiap sesi baru, cari sesi lengkap milik GTK **lain** dengan hari dan `classId` sama serta jam ke beririsan. Semua baris `gtk_pegawai` dipindai dan sesi lama di-resolve dengan cara yang sama. Setiap temuan menjadi string `Kelas <kelas> hari <hari> jam ke-<a>–<b> juga terjadwal untuk <nama GTK lain>`.
11. Respons 200: `{success: true, versi: <versi baru>, items: [<format jadwal_kbm_get>], peringatan: string[]}`.

#### `gtk_update_atribut` (diperketat)

- `jkUser`-style check: hanya `TATA_USAHA` dan `ADMIN` (fresh user). Role lain → 403. Tanpa login → 401. Hanya `POST`; method lain → 405.
- Field `jadwal_mengajar_json` **tetap diterima** tanpa validasi, sebagai jalur legacy untuk bundle tanpa patch (§7). Bundle hasil patch tidak lagi mengirim field ini.
- Sinkronisasi ke `users.class_homeroom`/`is_bk` diubah ke prepared statement. Perilakunya tidak berubah.

#### `gtk_create` / `gtk_update` (satu perubahan)

Klausa `ON DUPLICATE KEY UPDATE` **tidak lagi** memuat `jadwal_mengajar_json`. Akibatnya:
- saat update, jadwal di DB dipertahankan;
- saat insert baru, jadwal tetap diisi dari body (default `[]`).

Otorisasi dan field lain tidak diubah (di luar scope).

### 3.4 Parser `jamKe` lama (`jkParseJamKe`)

Input berupa teks bebas. Output `{jamKeMulai, jamKeSelesai, jamMulai, jamSelesai}` atau `null` per bagian yang gagal.

1. Normalisasi: huruf kecil, en/em dash → `-`, `s.d.`/`s.d`/`s/d`/`sd`/`sampai` → `-`.
2. **Waktu**: pola pertama `(\d{1,2})[.:](\d{2})\s*-\s*(\d{1,2})[.:](\d{2})`. Hasilnya dinormalisasi ke `HH:MM` dan valid bila jam 0–23, menit 0–59, dan mulai < selesai. Bagian yang cocok dihapus dari teks sebelum langkah 3.
3. **Jam ke**: di sisa teks, pola `(\d{1,2})\s*-\s*(\d{1,2})` → mulai–selesai (ditukar bila terbalik). Bila tidak cocok, dipakai angka tunggal pertama `\d{1,2}` → mulai = selesai. Valid bila di rentang 1–11.
4. Contoh:
   - `08 - 11 (13.15 - 16.30)` → 8, 11, `13:15`, `16:30`;
   - `01 - 03 (07.30 - 09.45)` → 1, 3, `07:30`, `09:45`;
   - `3 (09:00-09:45)` → 3, 3, `09:00`, `09:45`;
   - `1-2` → 1, 2, null, null (perlu dilengkapi: jam mulai/selesai);
   - `pagi` → semuanya null.

### 3.5 Perilaku UI yang diharapkan (patch bundle)

Patch diterapkan lewat skrip `docs/patches/apply-jadwal-kbm-bundle.js`, dengan sumber `-tampiljurnal2.js` dan hasil `-jadwal1.js`. Skrip mengikuti pola skrip patch sebelumnya:
- cek SHA-256 sumber;
- pakai anchor string eksak, dan setiap anchor harus ditemukan tepat 1 kali;
- gagal bila prefiks `__jk` sudah dipakai;
- validasi bahwa class Tailwind yang dipakai ada di `assets/index-CC1PBzaO.css`;
- tulis salinan baru, lalu arahkan `app.html`.

Klien API baru: `et.jadwalKbm = { get: gtkId => yt("jadwal_kbm_get&gtk_id="+encodeURIComponent(gtkId)), save: body => yt("jadwal_kbm_save","POST",body) }` (atau fungsi `__jk*` setara bila menambah ke `et` sulit di-anchor).

Komponen form sesi dipakai bersama oleh Profil dan tab GTK (`__jkFormSesi`). Isinya: *Hari* (select Senin–Jumat), *Kelas* (select), *Mata Pelajaran* (select, opsional, "— Tanpa mapel —"), *Jam ke* mulai dan selesai (dua `input type=number` min 1 max 11), dan *Jam mulai* dan *Jam selesai* (`input type=time`). Validasi klien sama dengan server (§3.3 langkah 7–8, termasuk bentrok dengan sesi lengkap yang ada di daftar). Pesan inline tampil di bawah form, tanpa `alert`.

| # | Lokasi | Perubahan |
|---|---|---|
| J1 | Halaman Profil | Tambah kartu **"Jadwal Mengajar (KBM)"** di bawah kartu Penugasan Mata Pelajaran, hanya bila `user.role === "GURU"`. Teks bantu: "Jadwal ini dipakai untuk mengisi otomatis jam pelajaran di Jurnal Mengajar. Perubahan langsung berlaku." |
| J2 | Kartu J1: pemuatan | Saat mount, panggil paralel `jadwal_kbm_get` (uid sendiri), `get_teacher_subjects&teacher_id=<uid>`, `get_classes`, dan `get_subjects&active_only=1`. Tampilkan status memuat. Bila gagal, tampilkan pesan error dan tombol "Coba lagi". Bila `gtkAda === false`, tampilkan pesan "Data kepegawaian (GTK) Anda belum tersedia. Hubungi Tata Usaha." dan sembunyikan form. |
| J3 | Kartu J1: daftar | Kelompokkan per hari (Senin–Jumat, hari kosong tidak ditampilkan). Tiap sesi menampilkan kelas, mapel (bila ada), `Jam ke-a–b · HH:MM–HH:MM` dari `efektif`, dan tombol hapus dengan konfirmasi inline. Sesi `perluDilengkapi` diberi badge kuning **"Perlu dilengkapi"** beserta `alasan` dan teks `jamKe` aslinya. Bila kosong: "Belum ada jadwal mengajar". |
| J4 | Kartu J1: form tambah | `__jkFormSesi`. Opsi *Kelas* adalah kelas yang diizinkan (§3.3), diurutkan menurut nama. Opsi *Mata Pelajaran* adalah mapel penugasan yang mencakup kelas terpilih, atau semua mapel aktif bila guru tanpa penugasan. Mengganti kelas mengosongkan mapel yang tidak lagi valid. Tombol "Tambah Sesi" nonaktif sampai hari, kelas, jam ke, dan jam mulai–selesai terisi. |
| J5 | Kartu J1: simpan | Setiap tambah dan hapus **langsung** memanggil `jadwal_kbm_save` dengan `{gtk_id, versi, items}`, di mana `items` adalah daftar tersimpan (item tersimpan dikirim apa adanya) ditambah atau dikurangi satu sesi. Berhasil: daftar dan `versi` diganti dari respons, lalu tampil pesan "Jadwal tersimpan". Bila `peringatan` tidak kosong, peringatan ditampilkan dalam kotak kuning. Gagal: tampilkan `error` dari backend. Pada 409, muat ulang data otomatis dan tampilkan pesan konflik. Tombol nonaktif selama menyimpan. |
| J6 | Tab GTK (`pot`), bagian jadwal | Grid Senin–Jumat dipertahankan. Kartu sesi menampilkan `Kls <kelas>`, mapel (bila ada), dan `⏱️ Jam ke-a–b (HH:MM–HH:MM)` dari data efektif (fallback ke `jamKe`), serta badge "Perlu dilengkapi". **Badge ruang dihapus.** Data jadwal dimuat lewat `jadwal_kbm_get&gtk_id=<GTK terpilih>` saat detail GTK dibuka, beserta `get_teacher_subjects&teacher_id=<GTK terpilih>`. |
| J7 | Tab GTK, form "Alokasi Sesi Jadwal Kelas Baru" | Diganti dengan `__jkFormSesi` (hanya untuk `TATA_USAHA`/`ADMIN`). Kelas dan mapel diambil dari penugasan GTK terpilih dengan aturan fallback yang sama. Field **Ruang Belajar / Lokasi dihapus**, dan master ruangan tidak lagi dimuat untuk form ini. Tambah dan hapus memakai `jadwal_kbm_save` (bukan `updateAtribut`), dengan penanganan pesan sukses, peringatan, error, dan 409 seperti J5. |
| J8 | Tab GTK, simpan biodata | Payload `gtk_update` tidak berubah. Server kini mengabaikan jadwal saat update (§3.3), sehingga tidak ada perubahan wajib di klien. |
| J9 | Widget Jadwal KBM di dashboard | Baris ruang (`I.ruang && …`) dihapus. Teks jam memakai format dari data efektif bila field terstruktur ada (`Jam ke-a–b (HH:MM–HH:MM)`), atau jatuh ke `jamKe`. Sumber data tetap `gtk_list`. |
| J10 | Form Jurnal Mengajar: pemuatan | Saat form dibuka untuk **jurnal baru** (`editingJournalId` kosong), panggil `jadwal_kbm_get` untuk uid sendiri. Kegagalan diabaikan secara diam-diam (form berfungsi seperti sebelumnya). |
| J11 | Form Jurnal: isi otomatis | Setiap kali `tanggal` atau `kelas` berubah pada jurnal baru, hitung hari dari `tanggal` (parse sebagai tanggal lokal `YYYY-MM-DD`, bukan UTC), lalu cari sesi **lengkap** dengan `efektif.hari` = hari itu dan `efektif.classId` = `kelas`. **Tepat 1 sesi cocok:** isi `jam` = `a-b` (atau `a` bila a = b, tanpa nol di depan), `jamMulai`, dan `jamSelesai`, kecuali guru sudah mengubah salah satu dari ketiga field itu secara manual (flag manual, J13). Bila sesi punya `subjectCode` yang ada di opsi mapel form **dan** mapel belum dipilih atau masih berisi hasil isi otomatis sebelumnya, isi juga `mapelId`/`mapel`. Tampilkan teks info kecil "Diisi dari jadwal <hari>, jam ke-a–b." |
| J12 | Form Jurnal: lebih dari 1 sesi cocok | Tidak mengisi apa pun secara otomatis. Tampilkan chip pilihan "Jam ke-a–b (HH:MM–HH:MM) · <mapel>" di bawah field jam. Klik chip mengisi jam (dan mapel, dengan aturan J11) **walaupun flag manual aktif**, karena klik adalah aksi eksplisit. Chip juga tampil (sebagai satu pilihan) saat flag manual aktif dan tepat 1 sesi cocok, agar guru bisa mengembalikan nilai jadwal. |
| J13 | Form Jurnal: flag manual | Perubahan manual pada input `jam`, `jamMulai`, atau `jamSelesai` menyalakan flag manual untuk sesi pengisian form itu. Isi otomatis dari J11 tidak menimpa nilai manual. Tidak ada sesi yang cocok → tidak ada perubahan dan tidak ada info. Default `07:00`/`08:30` tetap berlaku. |
| J14 | Form Jurnal: edit jurnal lama | Tidak ada isi otomatis dan tidak ada chip. |

Menu sidebar **tidak** diubah. Isi form jurnal selain jam dan mapel tidak berubah, begitu pula payload `save_journal`.

## 4. Role, authorization, dan data model

- **Role:**
  - mutasi jadwal oleh `GURU` (milik sendiri), `TATA_USAHA`, dan `ADMIN`;
  - baca oleh keempat role (GURU hanya miliknya sendiri);
  - `gtk_update_atribut` hanya untuk `TATA_USAHA` dan `ADMIN`.
- **Persetujuan:** tidak ada. Perubahan langsung berlaku.
- **Skema:** **tidak ada kolom, tabel, index, atau migrasi baru.** Format JSON di `gtk_pegawai.jadwal_mengajar_json` diperluas (§3.1). Komentar kolom di `install/schema.sql` tidak diubah (file digenerate); format baru didokumentasikan di spesifikasi ini dan paket usulan.
- **Audit:** tidak ada audit siapa yang mengubah jadwal.
- **Data lama:** tidak dimigrasi. Data lama di-parse saat dibaca dan tetap tersimpan apa adanya sampai sesi dihapus. Mengubah sesi dilakukan dengan menghapus lalu menambah.
- **Jurnal lama:** tidak disentuh. Isi otomatis hanya berlaku untuk jurnal baru, dan nilainya bisa diubah guru sebelum disimpan.

## 5. File dan interface terkait

### 5.1 Backend (patch lokal)

| File | Perubahan |
|---|---|
| `api/jadwal_kbm.php` (**baru**) | Guard `SIMANTAP_API`. Berisi helper `jk*` (§3.3, §3.4) dan fungsi handler `jadwalKbmGet($db)` serta `jadwalKbmSave($db)`. Bergantung pada `api/kesiswaan_access.php` (`loadFreshUser`, `resolveClassRef`) dan `api/teacher_subject_access.php` (`tsLoadRows`, `tsLoadSubjects`, `tsLoadClasses`). |
| `api/index.php` | `require_once __DIR__ . '/jadwal_kbm.php';` setelah `teacher_subject_access.php`. Tambah `case 'jadwal_kbm_get'` dan `case 'jadwal_kbm_save'` di `switch` utama, sebelum `default:`. |
| `api/endpoints_tu.php` | `gtk_update_atribut`: otorisasi TU/ADMIN, POST saja, dan prepared statement untuk sinkronisasi `users`. `gtk_create`/`gtk_update`: hapus `jadwal_mengajar_json` dari `ON DUPLICATE KEY UPDATE`. |
| `api/presensi_harian.php`, `api/db.php`, `api/endpoints_v2.php`, `api/endpoints_v3.php` | Tidak berubah. |

Interface API:

```
GET  api/index.php?action=jadwal_kbm_get[&gtk_id=<uid>]
     → 200 { gtk_id, gtkAda, nama?, versi, items: [ { …tersimpan…, efektif:{…}, legacy, perluDilengkapi, alasan[] } ] }
     → 401 | 403
POST api/index.php?action=jadwal_kbm_save
     body: { gtk_id, versi, items: [ { id?, hari, classId, subjectCode?, jamKeMulai, jamKeSelesai, jamMulai, jamSelesai } | <item tersimpan apa adanya> ] }
     → 200 { success: true, versi, items: [ …format get… ], peringatan: string[] }
     → 400 (validasi) | 401 | 403 | 405 | 409 (versi berubah) | 500
POST api/index.php?action=gtk_update_atribut      (TATA_USAHA, ADMIN) → 200 | 401 | 403 | 405
```

Format error tetap `{"error": "<pesan>"}` dari `errorResponse()`.

### 5.2 Frontend (patch bundle lokal)

- `docs/patches/apply-jadwal-kbm-bundle.js` (**baru**): anchor J1–J14, sumber `assets/index-CGWL69P7-tampiljurnal2.js` (hash di header), prefiks identifier `__jk`.
- `assets/index-CGWL69P7-jadwal1.js` (**baru**, hasil skrip).
- `app.html`: `src` diarahkan ke `-jadwal1.js`.
- Komponen yang disentuh:
  - halaman Profil (setelah kartu `__tm`);
  - `pot` (tab GTK, bagian jadwal: grid, form `fn`, dan hapus `Pn`);
  - widget Jadwal KBM di dashboard;
  - form Jurnal Mengajar (state awal, handler `Yt` untuk `kelas`/`tanggal`/`jam*`, dan area di bawah field jam).

### 5.3 Pengujian

- `docs/patches/uji-jadwal-kbm-unit.php` (**baru**): unit test `jkParseJamKe`, `jkTeksJamKe`, dan `jkRentangBeririsan` (tanpa DB), mengikuti `uji-presensi-harian-unit.php`.
- `docs/patches/uji-jadwal-kbm-seed.sql` / `uji-jadwal-kbm-cleanup.sql` (**baru**): akun uji `uji_jk_*@uji.test` (GURU A, GURU B, GURU tanpa GTK, TU, ADMIN, KS), kelas `k_uji_jk_*`, mapel `m_uji_jk_*`, dan penugasan GURU A.
- `docs/patches/uji-jadwal-kbm-api.mjs` (**baru**): uji API mengikuti pola `uji-dispensasi-api.mjs` (cookie jar per aktor, `BASE` dari env), mencakup matriks akses §3.2, validasi §3.3, 409, sesi lama, peringatan antar guru, dan `gtk_update` yang tidak menimpa jadwal.

### 5.4 Dokumen

- `docs/specs/jadwal-kbm-mandiri-guru.md` (dokumen ini).
- `docs/patches/jadwal-kbm-mandiri-guru.md` (**baru**): file yang diubah, hash sebelum dan sesudah, anchor final, langkah re-apply, checklist pasca auto-update (§9.5), dan rollback (§9.6).
- `docs/usulan-btikp/RINGKASAN-JADWAL-KBM.md`, `docs/usulan-btikp/backend-jadwal-kbm.diff`, `docs/usulan-btikp/usulan-jadwal-kbm.zip` (**baru**).
- `CLAUDE.md`: tambahkan baris `api/jadwal_kbm.php` di tabel domain, dan perbarui catatan keamanan (`gtk_update_atribut` sudah dibatasi).

## 6. Di luar scope

- Master jam pelajaran/bel sekolah (jam ke-n = HH:MM) dan perhitungan waktu otomatis dari jam ke.
- Hari Sabtu/Minggu, jadwal per minggu ganjil/genap, per semester, atau per tahun ajaran.
- Informasi ruang belajar pada jadwal (dihapus dari UI; tidak ada pemetaan ke booking ruangan).
- Alur persetujuan, notifikasi ke TU, dan audit trail perubahan jadwal.
- Edit inline sesi (ubah dilakukan dengan hapus lalu tambah).
- Migrasi massal data lama ke format baru, serta penghapusan key `ruang` dari data lama.
- Blokir (bukan peringatan) bentrok kelas antar guru, dan validasi bentrok berdasarkan jam dinding (hanya jam ke yang dicek).
- Isi otomatis kelas dari jadwal (misalnya daftar "jadwal hari ini" yang mengisi kelas), pengingat jurnal belum diisi, dan perubahan pada validasi `save_journal`.
- Mengubah sumber data widget dashboard dari `gtk_list`, serta menutup akses `gtk_list` tanpa login.
- Otorisasi `gtk_create`/`gtk_update`/`gtk_delete`/`gtk_update_docs` dan celah keamanan lain di `CLAUDE.md`.
- Mengubah ekspor CSV rekap bulanan.
- Rilis resmi upstream. Spesifikasi ini hanya menyiapkan paket usulan.

## 7. Compatibility

- **Bundle lama tanpa patch, dengan backend baru:**
  - tab GTK tetap bisa menambah dan menghapus jadwal lewat `gtk_update_atribut` (TU/ADMIN), dengan teks bebas dan ruang;
  - data tersebut dibaca sebagai sesi lama oleh endpoint baru;
  - guru tidak lagi bisa memanggil `gtk_update_atribut` (tidak ada UI guru untuk itu sebelumnya).
- **Bundle baru dengan backend lama** (misalnya backend tertimpa auto-update): `jadwal_kbm_*` jatuh ke rantai fallback dan menghasilkan respons kosong atau error. Kartu Profil dan tab GTK menampilkan pesan gagal memuat. Form jurnal tetap berfungsi tanpa isi otomatis. Tidak ada kerusakan data.
- **Data yang ditulis format baru dibaca bundle lama:** key `kelas`, `jamKe`, dan `hari` tetap ada, sehingga grid TU dan widget dashboard lama tetap menampilkan sesi.
- **`gtk_update` tidak lagi mengubah jadwal saat update.** Alat atau skrip lain yang mengandalkan `gtk_update` untuk mengubah jadwal harus beralih ke `jadwal_kbm_save`. Di bundle, jadwal diubah hanya lewat form jadwal, dan biodata mengirim jadwal hanya sebagai salinan state.
- **`gtk_update_atribut` diperketat:** KS dan GURU kini mendapat 403. Di bundle, pemanggilnya hanya tab GTK dengan flag `un` (TU/ADMIN), jadi tidak ada fitur yang hilang. Pemanggilan via GET → 405 (bundle sudah memakai POST).
- **Format `jam_ke` jurnal** hasil isi otomatis (`1-4`, `3`) kompatibel dengan `presensiAngkaJam()`, dispensasi, dan helper tampilan `__smtJamJurnal`.
- **Auto-updater:** update dapat menimpa `api/index.php`, `api/endpoints_tu.php`, dan `app.html`. File baru tetap ada tetapi tidak direferensikan. Setelah tertimpa, perilaku kembali seperti sekarang, dan data format baru tetap terbaca.
- **Patch lokal sebelumnya:** dibangun di atas `-tampiljurnal2.js`. Wali kelas, rekap bulanan, pembina ekskul, perangkat belajar, penugasan mapel, persetujuan jurnal, dispensasi, portal nilai, dan tampilan jurnal harus tetap berfungsi (regresi E24).
- **Collation:** perbandingan ke `subjects`/`classes` memakai parameter bind per id (pola `tsLoadSubjects`/`tsLoadClasses`), tanpa JOIN lintas collation.

## 8. Edge cases

| Kasus | Perilaku yang diharapkan |
|---|---|
| Guru tanpa baris `gtk_pegawai` | `get` → `gtkAda:false`. Profil menampilkan pesan hubungi TU. `save` → 400. Jurnal tanpa isi otomatis. |
| Guru tanpa penugasan mapel | Semua kelas diizinkan. Opsi mapel = semua mapel aktif, dan validasi "mapel ditugaskan" dilewati (mapel tetap harus aktif). |
| Penugasan Admin tanpa level dan kelas | Semua kelas diizinkan (§3.3). |
| Guru mengirim `gtk_id` guru lain via console | 403, data tidak berubah. |
| KS memanggil `jadwal_kbm_save` | 403. Baca → 200. |
| Guru memanggil `gtk_update_atribut` untuk mengubah `class_homeroom` atau `is_bk` | 403, data tidak berubah. |
| Dua sesi baru pada hari yang sama dengan jam ke beririsan (1–3 dan 3–4) | 400 bentrok. Sesi dengan hari berbeda atau jam ke bersebelahan (1–2 dan 3–4) diterima. |
| Data lama sudah berisi sesi beririsan | Diterima selama keduanya sesi tersimpan tidak berubah. Sesi baru yang beririsan dengan sesi lama yang lengkap → 400. |
| Sesi lama tidak ter-parse (`pagi`) atau kelasnya tidak ditemukan | Tetap tersimpan. Badge "Perlu dilengkapi". Tidak ikut dicek bentrok dan tidak dipakai isi otomatis. Bisa dihapus. |
| Sesi lama `1-2` tanpa waktu | Perlu dilengkapi (jam mulai/selesai), sehingga tidak dipakai isi otomatis. |
| Sesi lama dengan `ruang` | `ruang` tetap tersimpan selama sesi tidak berubah. Tidak ditampilkan di mana pun di bundle baru. |
| `jamMulai >= jamSelesai`, atau jam ke 0/12 | 400. Klien mencegah lebih dulu. |
| Input waktu `07.30` via API | Dinormalisasi menjadi `07:30`. |
| Kelas dihapus dari `classes` setelah sesi dibuat | Sesi menjadi "Perlu dilengkapi" (kelas tidak ditemukan). |
| Mapel sesi dinonaktifkan setelah sesi dibuat | Sesi tetap lengkap. Isi otomatis mapel hanya terjadi bila mapel ada di opsi form jurnal; bila tidak ada, hanya jam yang diisi. |
| Guru mengajar kelas yang sama dua kali sehari (jam 1–2 dan 5–6) | Form jurnal menampilkan dua chip (J12), tanpa isi otomatis. |
| Kelas sama, jam beririsan, guru lain (team teaching) | Tersimpan dengan `peringatan`. |
| Guru dan TU mengubah jadwal yang sama hampir bersamaan | Penyimpan kedua mendapat 409 dan UI memuat ulang. Tidak ada perubahan yang hilang diam-diam. |
| TU menyimpan biodata GTK saat guru baru saja menambah sesi | Jadwal guru tetap utuh (`gtk_update` tidak menyentuh jadwal). |
| TU memakai bundle lama (`gtk_update_atribut`) saat guru memakai bundle baru | `updateAtribut` menimpa tanpa cek versi (jalur legacy). Simpan guru berikutnya mendapat 409 lalu memuat ulang. Dicatat sebagai risiko. |
| Tanggal jurnal jatuh di Sabtu/Minggu | Tidak ada sesi yang cocok, sehingga tidak ada isi otomatis. |
| Guru mengubah jam secara manual lalu mengganti kelas | Jam tidak ditimpa. Chip tetap tersedia (J12). |
| Guru memilih kelas dulu, lalu mengganti tanggal ke hari lain | Isi ulang dari sesi hari baru bila flag manual mati. Bila hari baru tidak punya sesi, nilai sebelumnya dibiarkan. |
| Jurnal lama dibuka untuk diedit | Tidak ada isi otomatis maupun chip. |
| Zona waktu browser berbeda | Hari dihitung dari string tanggal lokal, jadi tidak bergeser. |
| Role GURU diubah menjadi TU saat masih login | Request berikutnya memakai role TU (boleh mengelola semua jadwal). |
| User dihapus saat masih login | 401. |
| `items` > 100 atau bukan array | 400. |
| `jadwal_kbm_save` via GET | 405. |
| `jadwal_mengajar_json` di DB berisi JSON rusak | `get` mengembalikan `items: []` plus `alasan` global `Data jadwal tidak dapat dibaca`. `save` dari daftar kosong menimpa data rusak, dan UI meminta konfirmasi lewat pesan sebelum menambah sesi pertama. |

## 9. Testing dan verifikasi end-to-end

### 9.1 Persiapan staging

1. Salin database produksi ke staging (atau pakai data dev), dengan `api/config.local.php` berisi `SIMANTAP_DEV_MODE` dan `api/install.lock` tersedia.
2. Jalankan `docs/patches/uji-jadwal-kbm-seed.sql`. Isinya:
   - akun GURU A (punya penugasan: mapel `M1` untuk kelas `K1`, `K2`, dan mapel `M2` untuk `K3`), GURU B (tanpa penugasan), GURU C (tanpa baris GTK), TU, ADMIN, dan KS;
   - untuk GURU A, satu sesi lama `{"id":"JM-lama1","hari":"Senin","kelas":"<nama K1>","jamKe":"01 - 02 (07.30 - 08.50)","ruang":"R1"}` dan satu sesi lama `{"id":"JM-lama2","hari":"Selasa","kelas":"<nama K2>","jamKe":"pagi","ruang":"R2"}`.
3. Catat kondisi awal: `SELECT id, jadwal_mengajar_json, class_homeroom, is_bk FROM gtk_pegawai WHERE id IN (…)`.

### 9.2 Pemeriksaan statis dan otomatis

```
php -l api/jadwal_kbm.php
php -l api/index.php
php -l api/endpoints_tu.php
php docs/patches/uji-jadwal-kbm-unit.php            # semua PASS
node docs/patches/apply-jadwal-kbm-bundle.js        # sukses; jalankan ulang → gagal karena hash/prefiks
BASE=http://localhost/simantap/api/index.php node docs/patches/uji-jadwal-kbm-api.mjs   # semua PASS
```

Minimal kasus unit parser: kelima contoh §3.4, ditambah `3 s.d. 5 (10.00-12.15)`, `11-8 (13:00 - 12:00)` (jam ke ditukar, waktu invalid), `12 (07.30 - 08.10)` (jam ke di luar rentang), dan `25:00`.

### 9.3 Skenario end-to-end (browser, DevTools → Network terbuka)

| # | Aktor | Langkah | Hasil yang diharapkan |
|---|---|---|---|
| E1 | GURU A | Buka Profil | Kartu "Jadwal Mengajar (KBM)" tampil di bawah kartu penugasan. Senin berisi `JM-lama1` sebagai `Jam ke-1–2 · 07:30–08:50` (dari hasil parse). Selasa berisi `JM-lama2` dengan badge "Perlu dilengkapi" dan alasan jam tidak terbaca. Ruang tidak tampil. |
| E2 | GURU A | Buka dropdown Kelas di form | Hanya `K1`, `K2`, `K3`. Pilih `K3` → opsi mapel hanya `M2`. |
| E3 | GURU A | Tambah Senin, `K2`, `M1`, jam ke 2–3, 08:10–09:30 | Pesan bentrok inline (beririsan dengan `JM-lama1` jam ke-2), dan tidak ada request. Setelah diubah ke jam ke 3–4, 08:50–10:10 → tersimpan. DB: `JM-lama1` dan `JM-lama2` persis seperti semula (masih ada `ruang`), ditambah sesi baru format §3.1 tanpa `ruang`. |
| E4 | GURU A | Buka Jurnal Mengajar, jurnal baru, tanggal = Senin terdekat, kelas `K2` | Jam Ke- `3-4`, Jam Mulai `08:50`, Jam Selesai `10:10`, dan mapel `M1` terisi. Tampil info "Diisi dari jadwal Senin, jam ke-3–4." |
| E5 | GURU A | Lanjutan E4: ganti kelas ke `K1` | Terisi dari `JM-lama1`: `1-2`, `07:30`–`08:50`. Mapel tidak diubah dari `M1`, karena sesi lama tidak punya mapel dan nilai `M1` hasil isi otomatis sebelumnya tetap dibiarkan. |
| E6 | GURU A | Ubah Jam Selesai secara manual ke `09:00`, lalu ganti kelas ke `K2` | Jam tidak ditimpa. Chip "Jam ke-3–4 (08:50–10:10) · M1" tampil. Klik chip → jam terisi dari sesi. |
| E7 | GURU A | Simpan jurnal dari E6, lalu buka ulang untuk diedit | Jurnal tersimpan dengan `jam_ke = 3-4`. Kartu jurnal menampilkan `Jam ke-3-4 (08:50 - 10:10)`. Saat diedit, tidak ada isi otomatis maupun chip. Presensi harian untuk jurnal ini terbentuk seperti biasa. |
| E8 | GURU A | Tambah sesi kedua Senin untuk `K2` jam ke 6–7. Buat jurnal baru Senin `K2` | Tidak ada isi otomatis. Dua chip tampil. |
| E9 | GURU A | Jurnal baru dengan tanggal Sabtu | Tidak ada isi otomatis maupun chip. Default `07:00`/`08:30`. |
| E10 | GURU B | Buka Profil lalu tambah sesi | Opsi kelas = semua kelas, dan opsi mapel = semua mapel aktif. Simpan sukses. |
| E11 | GURU B | Tambah sesi pada hari, kelas, dan jam ke yang beririsan dengan sesi GURU A dari E3 | Tersimpan. Kotak kuning menampilkan peringatan dengan nama GURU A. |
| E12 | GURU C | Buka Profil | Pesan "Data kepegawaian (GTK) Anda belum tersedia…". Form tidak tampil. |
| E13 | GURU A | Console: `jadwal_kbm_save` dengan `gtk_id` GURU B | 403. DB B tidak berubah. |
| E14 | GURU A | Console: sesi baru dengan `classId` = kelas di luar penugasan; `subjectCode` = `M2` untuk `K1`; `jamKeMulai: 5, jamKeSelesai: 3`; `jamMulai: "10:00", jamSelesai: "09:00"` (masing-masing terpisah) | 400 dengan pesan spesifik sesuai §3.3. DB tidak berubah. |
| E15 | GURU A | Console: `jadwal_kbm_save` dengan `versi` lama | 409. DB tidak berubah. |
| E16 | GURU A | Console: `gtk_update_atribut` `{id:<uid A>, is_bk:1}` dan `{id:<uid B>, class_homeroom:"X"}`; lalu `jadwal_kbm_save` via GET | 403, 403, 405. `gtk_pegawai`/`users` tidak berubah. |
| E17 | Tanpa login / KS | Incognito: `jadwal_kbm_get`. KS: `jadwal_kbm_get&gtk_id=<uid A>`, lalu `jadwal_kbm_save` | 401. KS baca → 200, simpan → 403. |
| E18 | TU | Tab GTK → buka GURU A | Grid menampilkan semua sesi A, badge "Perlu dilengkapi" pada `JM-lama2`, tanpa badge ruang. Form tidak memiliki field ruang. Opsi kelas dan mapel mengikuti penugasan A. |
| E19 | TU | Hapus `JM-lama2`, lalu tambah sesi Selasa `K2` jam ke 1–2, 07:30–08:50 | Tersimpan lewat `jadwal_kbm_save` (cek Network, bukan `gtk_update_atribut`). GURU A melihat perubahan di Profil setelah memuat ulang. |
| E20 | TU + GURU A | Buka detail A di tab GTK (TU) dan Profil (A). A menambah sesi, lalu TU menambah sesi tanpa memuat ulang | Simpan TU → 409 dengan pesan konflik. Data dimuat ulang, dan sesi A tetap ada. |
| E21 | TU | Buka GURU A, ubah biodata (misalnya nomor HP), lalu simpan | Jadwal A di DB tidak berubah (bandingkan JSON sebelum dan sesudah). |
| E22 | ADMIN | Tab GTK: tambah dan hapus sesi GURU B | Berhasil, dengan perilaku sama seperti TU. |
| E23 | GURU A | Dashboard | Widget Jadwal KBM hari ini menampilkan kelas dan jam dari format baru, tanpa ruang. |
| E24 | Semua role | Regresi: Kesiswaan wali kelas, rekap bulanan, Ekstrakurikuler, Perangkat Belajar, Penugasan Mapel (Profil dan modal Admin), persetujuan jurnal, dispensasi (presensi harian mengikuti `jam_ke` dari E7), Nilai, dan tampilan/cetak jurnal | Berfungsi seperti sebelum patch. |
| E25 | GURU A, TU | DevTools → Console setelah membuka Profil, Jurnal, Dashboard, dan tab GTK | Tidak ada error JS baru. |

### 9.4 Cakupan skrip uji API

`uji-jadwal-kbm-api.mjs` minimal mengotomatiskan E3 (bagian DB), E11, E13–E17, E19–E22 di tingkat API, ditambah:
- respons `get` untuk sesi lama (nilai efektif dan `perluDilengkapi`);
- round-trip sesi tersimpan tidak berubah (byte-identik, termasuk `ruang`);
- batas 100 item.

Skrip mencetak `PASS`/`FAIL` per kasus dan keluar dengan kode ≠ 0 bila ada yang gagal. Setelah selesai, jalankan `uji-jadwal-kbm-cleanup.sql`.

### 9.5 Checklist pasca auto-update (masuk ke `docs/patches/jadwal-kbm-mandiri-guru.md`)

1. Cek apakah `app.html` masih mereferensikan `-jadwal1.js`.
2. Cek apakah `api/index.php` masih me-`require` `jadwal_kbm.php` dan masih memiliki kedua `case`.
3. Cek apakah `gtk_update_atribut` masih dibatasi TU/ADMIN, dan apakah `gtk_update` tidak memuat `jadwal_mengajar_json` di `ON DUPLICATE KEY UPDATE`.
4. Bila ada yang tertimpa, terapkan ulang diff backend, jalankan skrip bundle terhadap bundle baru (anchor mungkin perlu dicari ulang), lalu jalankan `uji-jadwal-kbm-api.mjs` serta E1, E4, E18, dan E23.

### 9.6 Rollback

1. Kembalikan `app.html` ke `-tampiljurnal2.js`.
2. Kembalikan `api/index.php` dan `api/endpoints_tu.php` dari git (`git checkout <commit sebelum patch> -- api/index.php api/endpoints_tu.php`).
3. `api/jadwal_kbm.php` dan `-jadwal1.js` boleh dibiarkan (tidak direferensikan).
4. Tidak ada perubahan skema. Sesi format baru tetap terbaca oleh bundle lama lewat `kelas` dan `jamKe`.

## 10. Risiko dan trade-off

| Risiko / trade-off | Dampak | Mitigasi |
|---|---|---|
| Tanpa persetujuan dan tanpa audit | Guru dapat mengisi jadwal yang tidak sesuai dengan jadwal resmi sekolah | Sesuai keputusan bisnis. TU/Admin/KS bisa melihat dan mengoreksi jadwal. Kelas dan mapel guru dibatasi oleh penugasan. |
| Jam mulai dan selesai diketik per sesi (tanpa master bel) | Salah ketik tetap mungkin terjadi, dan jam yang sama bisa berbeda antar guru | Validasi format dan urutan jam. Master jam pelajaran menjadi fitur lanjutan (§6). |
| Sesi lama di-parse saat dibaca, bukan dimigrasi | Parser bisa salah menafsirkan teks yang tidak lazim | Hasil parse terlihat di Profil dan tab GTK. Teks asli ditampilkan pada sesi "Perlu dilengkapi". Unit test mencakup contoh data nyata. Guru atau TU bisa hapus lalu tambah ulang. |
| Pengecualian validasi untuk sesi tersimpan tidak berubah | Data warisan tidak valid atau bentrok dapat bertahan lama | Badge "Perlu dilengkapi". Alternatifnya (menolak simpan) memblokir guru karena data buatan TU. |
| `gtk_update_atribut` masih menerima jadwal tanpa validasi dan tanpa cek versi | Bundle lama milik TU dapat menimpa jadwal buatan guru | Hanya terjadi bila bundle tidak dipatch. Setelah itu simpan guru mendapat 409 lalu memuat ulang. Jalur ini bisa dihapus setelah upstream mengadopsi endpoint baru. |
| `gtk_update` tidak lagi menulis jadwal saat update | Integrasi pihak ketiga yang mengandalkannya berhenti mengubah jadwal | Tidak ada pemakai lain di bundle. Dicatat di paket usulan. |
| Pengetatan `gtk_update_atribut` | KS/GURU yang (secara tidak resmi) memakainya lewat API ditolak | Tidak ada UI untuk mereka. Diberitahukan ke sekolah. |
| Isi otomatis mapel dapat mengganti pilihan hasil isi otomatis sebelumnya | Guru bisa tidak sadar mapel berubah saat mengganti kelas | Mapel yang dipilih manual tidak pernah ditimpa, dan teks info menjelaskan sumber pengisian. |
| Peringatan antar guru memindai semua `gtk_pegawai` | Beban kecil per simpan (±60 baris) | Diterima. Hanya dijalankan saat simpan. |
| Widget dashboard tetap membaca `gtk_list` tanpa login | Jadwal (dan PII lain) tetap terbuka tanpa login | Di luar scope dan sudah tercatat di `CLAUDE.md`. |
| Patch bundle berbasis anchor string di empat komponen | Auto-update dengan bundle baru mematahkan skrip | Cek SHA-256 sumber, skrip gagal eksplisit, dan checklist §9.5. |
| Auto-updater menimpa backend | Otorisasi dan perlindungan jadwal hilang diam-diam | Checklist §9.5 dan paket usulan upstream. |

## 11. Acceptance criteria

1. **AC1:** GURU melihat kartu "Jadwal Mengajar (KBM)" di Profil dan dapat menambah serta menghapus sesi miliknya sendiri. Perubahan tersimpan langsung, tanpa persetujuan (E1–E3, E10).
2. **AC2:** Sesi baru disimpan dalam format terstruktur §3.1 (`classId`, `subjectCode` opsional, jam ke mulai–selesai, jam mulai–selesai, `jamKe` turunan) tanpa `ruang`, dengan nama kelas dan mapel diambil dari master (E3).
3. **AC3:** Opsi kelas dan mapel guru mengikuti penugasan mapel dengan fallback §3.3, dan server menolak kelas/mapel di luar penugasan untuk GURU (E2, E10, E14).
4. **AC4:** Sesi baru yang jam ke-nya beririsan dengan sesi lengkap lain milik GTK yang sama pada hari yang sama ditolak (400) dan dicegah di UI. Irisan dengan guru lain hanya menghasilkan peringatan (E3, E11).
5. **AC5:** Pada jurnal baru, bila tanggal dan kelas cocok dengan tepat satu sesi lengkap, Jam Ke- (`a-b`), Jam Mulai, Jam Selesai, dan (bila ada) mapel terisi otomatis dan tetap bisa diedit (E4, E5).
6. **AC6:** Isi otomatis tidak menimpa jam yang diubah manual, tidak berjalan saat mengedit jurnal lama, dan menampilkan chip pilihan bila lebih dari satu sesi cocok (E6–E9).
7. **AC7:** Sesi lama teks bebas di-parse saat dibaca. Sesi yang ter-parse lengkap dipakai untuk isi otomatis, sedangkan yang gagal ditandai "Perlu dilengkapi". Sesi lama yang tidak diubah tersimpan byte-identik, termasuk `ruang` (E1, E3, E5, §9.4).
8. **AC8:** Field ruang tidak lagi tampil di form maupun tampilan jadwal (Profil, tab GTK, dashboard) (E1, E18, E23).
9. **AC9:** `jadwal_kbm_save` hanya menerima GURU (milik sendiri), TU, dan ADMIN, hanya lewat POST, dan menolak versi basi dengan 409. `jadwal_kbm_get` tanpa login → 401, dan GURU hanya bisa membaca miliknya sendiri (E13, E15–E17, E20).
10. **AC10:** `gtk_update_atribut` hanya menerima TU/ADMIN lewat POST (E16). `gtk_update` tidak mengubah jadwal (E21).
11. **AC11:** TU dan ADMIN mengelola jadwal GTK mana pun dari tab GTK dengan form dan endpoint yang sama (E18, E19, E22).
12. **AC12:** Error server tidak membocorkan detail DB, dan UI menampilkan pesan `error` dari backend (E14, E20).
13. **AC13:** `php -l` lolos, unit test parser PASS, skrip uji API PASS, dan skrip patch bundle sukses pada sumber yang benar serta gagal eksplisit pada sumber yang salah (§9.2).
14. **AC14:** Tidak ada regresi pada fitur patch lokal sebelumnya, presensi harian, dan tampilan/cetak jurnal (E24), serta tidak ada error console baru (E25).
15. **AC15:** Dokumen patch, paket usulan BTIKP, dan pembaruan `CLAUDE.md` tersedia sesuai §5.4.
