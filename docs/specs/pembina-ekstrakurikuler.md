# Spesifikasi: Pengelolaan Ekstrakurikuler oleh Admin dan Guru Pembina

| | |
|---|---|
| Status | Diimplementasikan (patch lokal) — lihat `docs/patches/pembina-ekstrakurikuler.md` |
| Versi bundle acuan | SIMANTAP 1.0.2 (`api/version.php`) + patch lokal wali kelas & rekap bulanan |
| Bundle frontend acuan | `assets/index-CGWL69P7-wali2.js` (yang direferensikan `app.html` saat ini) |
| Tanggal | 2026-09-14 |
| Jalur rilis | Patch lokal pada bundle ini **dan** usulan perubahan ke upstream BTIKP |

## 1. Latar belakang dan kebutuhan bisnis

Data ekstrakurikuler (ekskul) saat ini hanya dikelola lewat akun Kepala Sekolah. Pembina ditulis sebagai teks bebas (`coachName`), tidak terhubung ke akun guru, sehingga:

1. guru pembina tidak dapat login untuk mencatat anggota, jurnal kegiatan, dan presensi ekskul yang ia bina;
2. Kepala Sekolah menjadi operator data, padahal perannya seharusnya memantau;
3. wali kelas tidak bisa melihat keikutsertaan ekskul siswa perwaliannya dari menu ekskul;
4. endpoint baca ekskul (termasuk nomor HP orang tua anggota) terbuka tanpa login, dan beberapa mutasi tidak memiliki auth sama sekali.

Tujuan perubahan:
- **ADMIN** mendapat akses penuh ke data ekskul, termasuk menunjuk (assign) guru pembina dari daftar akun guru.
- **Guru pembina** mengelola ekskul yang ia bina lewat akunnya sendiri.
- **Wali kelas** dapat melihat (baca saja) ekskul yang diikuti siswa perwaliannya, terbatas pada data kelasnya.
- **Kepala Sekolah** menjadi pemantau (baca saja).
- Semua pembatasan **ditegakkan di backend**, bukan hanya di UI.

## 2. Perilaku saat ini (hasil analisis kode)

### 2.1 Frontend (`assets/index-CGWL69P7-wali2.js`)

- **Menu.** Item `{id:"extracurricular",label:"Ekstrakurikuler",…,roles:["KEPALA_SEKOLAH","GURU"]}` ada di grup "Umum & Ekskul" bersama Literasi, Keagamaan, dan Kebersihan. ADMIN tidak punya item ini (menu ADMIN hanya grup "Manajemen Data").
- **Filter sidebar** untuk GURU:
  ```js
  if(r==="GURU"){if(M){ /* BK */ if(Y.title==="Pusat Dashboard"||Y.title==="Akademik"||Y.title==="Kesiswaan")return!1}
                 else if(Y.title==="Kesiswaan"&&!(s!=null&&s.class_homeroom)||Y.title==="Umum & Ekskul")return!1}
  ```
  Akibatnya GURU non-BK tidak pernah melihat menu ekskul, sedangkan GURU BK melihat seluruh grup "Umum & Ekskul".
- **Halaman ekskul (komponen `tot`).**
  - Memuat `et.extracurriculars.list()` (semua ekskul) dan `listJournals` (semua jurnal).
  - Flag pengelola `Y = role==="KEPALA_SEKOLAH"||role==="ADMIN"||(role==="GURU"&&BK)` mengatur tombol "Tambah Ekskul" serta tombol edit/hapus di kartu Daftar Ekskul.
  - Tombol "Input Kegiatan" dan aksi di kartu jurnal tampil untuk `GURU||KEPALA_SEKOLAH||ADMIN`. Picker ekskul (`rot`) menampilkan semua ekskul.
  - Tab "Laporan" berisi halaman statis "Pusat Laporan Ekskul".
- **Form ekskul (komponen `not`).**
  - Pembina diisi lewat input teks `value:o.coachName`.
  - Tidak ada input `coachId`. Saat simpan, frontend mengirim `coachId:o.coachId||e.uid`, sehingga `coachId` data lama umumnya berisi **uid pembuatnya** (KS atau guru BK), bukan uid pembina.
- **Detail ekskul (komponen `sot`, tab INFO/MEMBERS/ACTIVITIES).**
  - Tombol "Tambah Anggota" dan "Keluarkan siswa" **tidak di-gate** oleh role.
  - Aksi edit/hapus aktivitas tampil untuk `GURU||ADMIN||KEPALA_SEKOLAH`.
- **Daftar guru.** `et.users.list()` sudah memanggil `get_users`, yang tersedia untuk ADMIN, KEPALA_SEKOLAH, dan TATA_USAHA.

### 2.2 Backend

Semua handler di bawah adalah satu-satunya copy (tidak ada duplikat action).

| Action | File | Auth saat ini | Catatan |
|---|---|---|---|
| `get_extracurriculars` | `api/index.php` | **tanpa auth** | `SELECT *` semua ekskul |
| `save_extracurricular` | `api/index.php` | **tanpa auth** | `REPLACE INTO`: mereset `createdAt` dan mengosongkan kolom JSON `students`/`activities` karena frontend tidak mengirimnya |
| `delete_extracurricular` | `api/index.php` | **tanpa auth** | Cascade hapus anggota dan aktivitas dalam transaksi |
| `get_ekskul_journals` | `api/index.php` | **tanpa auth** | Parameter `uid` diabaikan |
| `save_ekskul_journal` | `api/index.php` | **tanpa auth** | Pelapor diambil dari body. Tidak dipanggil bundle |
| `verify_ekskul_journal` | `api/index.php` | tanpa auth | No-op. Tidak dipanggil bundle |
| `get_extracurricular_members` | `api/endpoints_v3.php` | **tanpa auth** | Mengembalikan `parent_name`/`parent_phone`. Tanpa `extracurricular_id` mengembalikan semua anggota |
| `save_extracurricular_member` | `api/endpoints_v3.php` | `requireAuth()` saja | Nama/kelas siswa dari body |
| `delete_extracurricular_member` | `api/endpoints_v3.php` | `requireAuth()` saja | |
| `get_extracurricular_activities` | `api/endpoints_v3.php` | **tanpa auth** | |
| `save_extracurricular_activity` | `api/endpoints_v3.php` | `requireAuth()` saja | `REPLACE INTO` dengan `id` dari body, sehingga bisa menimpa aktivitas ekskul lain |
| `delete_extracurricular_activity` | `api/endpoints_v3.php` | `requireAuth()` saja | |
| `get_homeroom_dashboard` (bagian `ekskul`) | `api/endpoints_v3.php` | tanpa auth | Membaca kolom `coachName` langsung |

Sinkronisasi akun yang relevan:
- `delete_user` (`api/index.php`) dan `gtk_delete` (`api/endpoints_tu.php`) menghapus akun, tetapi tidak menyentuh `extracurriculars`.
- `save_user` (`api/index.php`) dan `save_user_v2` (`api/endpoints_v3.php`) dapat mengubah `users.role`.
- `gtk_create`/`gtk_update`/`gtk_update_atribut` tidak mengubah role akun yang sudah ada.

## 3. Expected behavior

### 3.1 Definisi

- **Data user segar.** Status role, pembina, dan wali dibaca ulang dari database pada **setiap request** ke endpoint dalam spesifikasi ini, memakai `loadFreshUser()`. Nilai di `$_SESSION` tidak dipakai untuk otorisasi.
- **Pembina ekskul E.** User dengan `users.role = 'GURU'` (termasuk BK dan wali kelas) dan `users.uid = extracurriculars.coachId` milik E. `coachId` yang menunjuk ke akun non-GURU atau akun yang sudah tidak ada dianggap **belum ada pembina**.
- **Wali kelas.** User dengan `role = 'GURU'` dan `class_homeroom` tidak kosong. Kunci kelas perwalian dihitung sama seperti `kesiswaanScope()` di `api/kesiswaan_access.php` (id dan nama kelas yang dinormalisasi).
- **Siswa perwalian.** Siswa yang `students.class_id` atau `students.class_name`-nya saat ini termasuk kunci kelas perwalian. Untuk anggota yang baris `students`-nya sudah tidak ada, dipakai `extracurricular_members.class_id`/`class_name`.
- **Ekskul terkait wali.** Ekskul yang memiliki minimal satu anggota berstatus `AKTIF` yang merupakan siswa perwalian.
- **Level akses per ekskul** (`access.level`), dievaluasi berurutan dan level pertama yang cocok berlaku:

  | Level | Syarat | Arti |
  |---|---|---|
  | `ADMIN` | `role = 'ADMIN'` | Kelola penuh semua ekskul |
  | `MONITOR` | `role = 'KEPALA_SEKOLAH'` | Baca semua ekskul |
  | `COACH` | GURU pembina ekskul ini | Kelola ekskul ini, kecuali nama, pembina, dan hapus |
  | `HOMEROOM` | GURU wali, ekskul terkait wali | Baca saja, terbatas data siswa perwalian |
  | `NONE` | selain di atas (termasuk TATA_USAHA) | Tidak ada akses |

  Guru yang merangkap pembina ekskul A dan wali kelas yang siswanya ikut ekskul B mendapat `COACH` untuk A dan `HOMEROOM` untuk B. Jika ia pembina **dan** wali terkait di ekskul yang sama, berlaku `COACH`.

### 3.2 Matriks akses

Keterangan: ✅ = semua ekskul; 🎯 = hanya ekskul dengan level `COACH`; 🏫 = hanya ekskul `HOMEROOM`, dan data anggota/presensi difilter ke siswa perwalian; ⛔ = 403; 🔒 = 401 bila belum login; ∅ = 200 dengan daftar kosong.

| Action / fitur | ADMIN | KEPALA_SEKOLAH | GURU pembina | GURU wali (bukan pembina E) | GURU lain (termasuk BK non-pembina) | TATA_USAHA | Anonim |
|---|---|---|---|---|---|---|---|
| Menu Ekstrakurikuler | **✅ baru** | ✅ (tetap) | **tampil baru** | **tampil baru** bila ada ekskul terkait | tersembunyi (**BK: baru disembunyikan**) | tersembunyi | – |
| `get_extracurriculars` | ✅ | ✅ | 🎯 + 🏫 | 🏫 | ∅ | ⛔ | 🔒 |
| `save_extracurricular` (buat baru) | ✅ | ⛔ | ⛔ | ⛔ | ⛔ | ⛔ | 🔒 |
| `save_extracurricular` (ubah) | ✅ semua field | ⛔ | 🎯 hanya `schedule`, `room`, `description` | ⛔ | ⛔ | ⛔ | 🔒 |
| `delete_extracurricular` | ✅ | ⛔ | ⛔ | ⛔ | ⛔ | ⛔ | 🔒 |
| `get_extracurricular_members` | ✅ | ✅ | 🎯 | 🏫 | ⛔ | ⛔ | 🔒 |
| `save_extracurricular_member` / `delete_extracurricular_member` | ✅ | ⛔ | 🎯 | ⛔ | ⛔ | ⛔ | 🔒 |
| `get_extracurricular_activities` | ✅ | ✅ | 🎯 | 🏫 | ⛔ | ⛔ | 🔒 |
| `save_extracurricular_activity` / `delete_extracurricular_activity` | ✅ | ⛔ | 🎯 | ⛔ | ⛔ | ⛔ | 🔒 |
| `get_ekskul_journals` | ✅ | ✅ | 🎯 + 🏫 | 🏫 | ∅ | ⛔ | 🔒 |
| `save_ekskul_journal` (legacy) | ✅ | ⛔ | 🎯 | ⛔ | ⛔ | ⛔ | 🔒 |
| Tab Laporan | ✅ | ✅ | ✅ (halaman yang ada) | ✅ (halaman yang ada) | – | – | – |

Catatan:
- KEPALA_SEKOLAH **kehilangan** kemampuan membuat, mengubah, dan menghapus ekskul, anggota, dan aktivitas. Perubahan ini disengaja.
- GURU BK tidak lagi punya akses khusus di modul ekskul. Item Literasi, Keagamaan, dan Kebersihan untuk BK **tidak berubah**.

### 3.3 Perilaku detail per endpoint

**Umum**
- Semua endpoint di §3.2 memanggil `requireAuth()` terlebih dahulu, lalu helper di `api/ekskul_access.php` (§5.1).
- Penolakan memakai `errorResponse('…', 403)` dengan pesan berbahasa Indonesia, misalnya "Anda tidak memiliki akses untuk mengelola ekstrakurikuler ini."
- Jika baris user sudah tidak ada di DB, respons 401.
- Mutasi (`save_*`, `delete_*`) menolak metode `GET` dengan 405. Bundle memanggilnya dengan `POST`/`DELETE`.
- Ekskul yang tidak ditemukan menghasilkan 404 untuk mutasi. Untuk baca oleh non-ADMIN/KS, respons tetap 403 agar keberadaan data tidak bocor.
- Actor (`reporter_id`/`reporter_name`) selalu diambil dari session (`uid`/`displayName`), bukan dari body.
- Validasi dilakukan **sebelum** `begin_transaction()`. Semua query baru memakai prepared statement.

**`get_extracurriculars`**
- ADMIN/KS: semua ekskul. GURU: gabungan ekskul `COACH` dan `HOMEROOM`. GURU tanpa keduanya: `[]`. TATA_USAHA: 403.
- Setiap baris ditambah field:
  - `coachId`: nilai kolom apa adanya.
  - `coachValid` (bool): `coachId` menunjuk akun `role='GURU'`.
  - `coachName`: `users.displayName` pembina bila `coachValid`, selain itu string label `"Belum ada pembina"`. Nama selalu mengikuti akun terbaru, jadi tidak perlu sinkronisasi saat guru ganti nama.
  - `coachNameLegacy`: nilai kolom `coachName` di DB, sebagai petunjuk bagi admin saat assign ulang data lama.
  - `access`: `{ level, canEditInfo, canAssignCoach, canDelete, canManageMembers, canManageActivities }`. Nilai boolean diturunkan dari §3.2 (ADMIN: semua `true`; COACH: `canEditInfo`/`canManageMembers`/`canManageActivities` `true`; MONITOR/HOMEROOM: semua `false`).
  - `memberCount`: jumlah anggota `AKTIF`; untuk `HOMEROOM`, hanya siswa perwalian.
- `students`/`activities` (kolom JSON legacy) tetap di-decode seperti sekarang.

**`save_extracurricular`**
- Buat baru: `id` kosong atau tidak ada di DB. Hanya ADMIN; `id` baru dari `uniqid('extra_')` bila kosong.
- Validasi:
  - `name` wajib (trim, tidak kosong), atau 400.
  - `coachId` boleh kosong (belum ada pembina). Bila diisi, wajib akun `role='GURU'`; jika tidak, 400 "Pembina harus akun guru yang terdaftar."
  - Kolom `coachName` di DB diisi server dari `users.displayName`, atau `''` bila `coachId` kosong. Nilai `coachName` dari body diabaikan.
- Ubah oleh ADMIN: semua field (`name`, `coachId`, `schedule`, `room`, `description`).
- Ubah oleh COACH: hanya `schedule`, `room`, `description`. `name` dan `coachId` dari body **diabaikan** dan nilai di DB dipertahankan.
- Ganti `REPLACE INTO` menjadi `INSERT` untuk baru dan `UPDATE` kolom yang diizinkan untuk ubah, supaya `createdAt`, `students`, dan `activities` tidak ter-reset.
- Respons: `{success: true, id}`.

**`delete_extracurricular`**
- Hanya ADMIN. Cascade anggota dan aktivitas dalam transaksi tetap seperti sekarang.

**`get_extracurricular_members`**
- `extracurricular_id` wajib untuk semua role kecuali ADMIN/KS. Tanpa parameter, non-ADMIN/KS mendapat 400.
- Level `NONE`: 403. `COACH`: semua anggota. `HOMEROOM`: hanya anggota yang merupakan siswa perwalian.
- Parameter `class_id` tetap didukung sebagai filter tambahan.

**`save_extracurricular_member`**
- ADMIN atau COACH ekskul tujuan.
- `student_id` wajib ada di tabel `students`; jika tidak, 400. `student_name`, `class_id`, dan `class_name` diambil dari baris `students`, bukan dari body.
- Perilaku `ON DUPLICATE KEY UPDATE` (aktifkan kembali) tetap.

**`delete_extracurricular_member`**
- ADMIN atau COACH ekskul tujuan.

**`get_extracurricular_activities`**
- ADMIN/KS/COACH: data utuh.
- HOMEROOM: `attendance` difilter ke siswa perwalian. Dua format JSON yang ada harus didukung: map `{student_id: bool|{status}}` (yang dipakai bundle) dan array `[{student_id,…}]` (komentar skema).
- NONE: 403.

**`save_extracurricular_activity`**
- ADMIN atau COACH dari `extracurricular_id` di body.
- Bila `id` sudah ada di DB, record lama wajib milik ekskul yang sama. Jika berbeda, 403, sehingga `id` ekskul lain tidak bisa ditimpa.
- `REPLACE INTO` boleh dipertahankan. `reporter_id`/`reporter_name` tetap dari session, sehingga pengedit terakhir tercatat sebagai pelapor (perilaku lama).

**`delete_extracurricular_activity`**
- Muat record dulu; 404 bila tidak ada. Wajib ADMIN atau COACH dari ekskul record tersebut.

**`get_ekskul_journals`**
- Difilter ke ekskul yang bisa diakses user (sama seperti `get_extracurriculars`).
- Untuk HOMEROOM, `attendanceRate` dihitung hanya dari siswa perwalian. Bila tidak ada siswa perwalian di presensi pertemuan itu, nilainya `null`.
- Parameter `uid` tetap diabaikan.
- Setiap item ditambah `canManage` (bool), bernilai sama dengan `access.canManageActivities` ekskulnya.

**`save_ekskul_journal`** (legacy, tidak dipanggil bundle)
- ADMIN atau COACH `ekskulId`. Pelapor dari session, bukan `instructorId`/`instructorName` dari body.

**`verify_ekskul_journal`**: tidak diubah (lihat §6).

**`login` dan `session`** (`api/index.php`)
- Respons `user` ditambah dua field hasil hitung **segar dari DB** pada setiap panggilan:
  - `ekskul_coach_count`: jumlah ekskul yang `coachId`-nya uid user **dan** role user `GURU`;
  - `ekskul_homeroom_count`: jumlah ekskul terkait wali, dihitung hanya untuk role GURU dengan `class_homeroom`.
- Nilai 0 untuk role lain. Jika query gagal (misalnya tabel belum ada), nilainya 0 dan tidak boleh menjadi 500.
- Nilai ini **hanya untuk menampilkan menu**. Otorisasi tetap memakai perhitungan per request.

**Sinkronisasi saat pembina berubah status**
- `delete_user` dan `gtk_delete`: setelah akun dihapus, jalankan `UPDATE extracurriculars SET coachId='', coachName='' WHERE coachId = ?` untuk uid tersebut.
- `save_user` dan `save_user_v2`: jika role hasil simpan **bukan** `GURU`, jalankan pembersihan yang sama untuk uid tersebut.
- Jurnal/aktivitas lama tidak diubah; `reporter_name` tetap menampilkan pelapor aslinya.
- Karena otorisasi selalu mensyaratkan `role='GURU'`, perubahan data yang terlewat hook (misalnya edit DB langsung) tetap tidak memberi akses.

### 3.4 Perilaku UI yang diharapkan (patch bundle)

1. **Sidebar**
   - ADMIN melihat item "Ekstrakurikuler" (grup "Umum & Ekskul" tampil hanya dengan item itu, karena item lain tidak memuat role ADMIN).
   - KEPALA_SEKOLAH tidak berubah.
   - GURU (BK maupun non-BK) melihat item "Ekstrakurikuler" hanya bila `ekskul_coach_count > 0` atau `ekskul_homeroom_count > 0`.
   - Untuk GURU non-BK, item Literasi, Keagamaan, dan Kebersihan tetap tersembunyi. Untuk GURU BK, ketiga item itu tetap tampil seperti sekarang.
   - Perubahan penugasan tercermin setelah **halaman di-refresh**, karena SPA memanggil `session` saat dimuat. Tidak perlu login ulang.
2. **Daftar Ekskul (`tot`)**
   - Tombol "Tambah Ekskul" dan tombol hapus di kartu hanya untuk ADMIN.
   - Tombol edit di kartu tampil bila `access.canEditInfo`.
   - Kartu dengan `coachValid=false` menampilkan "Pembina: Belum ada pembina" (dari `coachName` backend).
3. **Form ekskul (`not`)**
   - Input teks pembina diganti `<select>` berisi akun `role==="GURU"` dari `et.users.list()`, diurutkan menurut nama, dengan opsi pertama "— Belum ada pembina —" (nilai `""`).
   - Nilai select adalah `coachId`. Bila `coachValid=false`, select berisi `""` dan teks bantu menampilkan "Tercatat sebelumnya: <coachNameLegacy>" bila tidak kosong.
   - Frontend mengirim `coachId` apa adanya. Fallback `||e.uid` dihapus.
   - Untuk non-ADMIN (pembina), field Nama dan Pembina ditampilkan read-only/disabled, dan `et.users.list()` tidak dipanggil (endpoint itu tidak tersedia untuk GURU).
4. **Input Kegiatan dan jurnal (`tot`, `rot`)**
   - Tombol "Input Kegiatan" tampil bila ada minimal satu ekskul dengan `access.canManageActivities`.
   - Picker `rot` hanya berisi ekskul tersebut (logika "langsung buka bila hanya satu" memakai daftar terfilter).
   - Aksi edit/hapus pada kartu jurnal tampil bila `canManage` item jurnal bernilai `true`.
5. **Detail ekskul (`sot`)**
   - Tombol "Tambah Anggota" dan "Keluarkan siswa" tampil bila `ekskul.access.canManageMembers`.
   - Tombol catat, edit, dan hapus aktivitas tampil bila `ekskul.access.canManageActivities`.
   - Wali (HOMEROOM) dan KS melihat tab INFO/MEMBERS/ACTIVITIES tanpa tombol aksi. Untuk wali, daftar anggota dan presensi hanya berisi siswa perwaliannya (difilter backend).
6. **Tab Laporan**: tidak berubah.

## 4. Role, authorization, dan data model

- **Tidak ada perubahan skema.** Relasi pembina memakai kolom yang sudah ada, `extracurriculars.coachId → users.uid` (1 pembina per ekskul). `coachName` tetap diisi saat simpan sebagai cache/fallback, tetapi tampilan memakai `users.displayName`.
- Tabel yang dipakai: `users` (`uid`, `displayName`, `role`, `class_homeroom`, `is_bk`), `extracurriculars`, `extracurricular_members`, `extracurricular_activities`, `students` (`id`, `name`, `class_id`, `class_name`), dan `classes` (`id`, `name`).
- Relasi `gtk_pegawai.id = users.uid` tidak berubah. Pembina ditunjuk berdasarkan **akun users**, sehingga pegawai GTK tanpa akun tidak bisa dipilih.
- **Tidak ada migrasi data otomatis.** Ekskul lama yang `coachId`-nya bukan akun GURU tampil "Belum ada pembina" sampai ADMIN memilih pembina. Ekskul lama yang `coachId`-nya kebetulan akun GURU (misalnya dibuat guru BK) **langsung dianggap sah**, jadi harus ditinjau admin (§9.1 langkah 6).
- Indeks yang tersedia sudah cukup: `extracurriculars` PK `id`, `extracurricular_members` indeks `extracurricular_id`/`student_id`/`class_id`, `extracurricular_activities` indeks `extracurricular_id`. Kueri `WHERE coachId = ?` berjalan tanpa indeks, tetapi jumlah ekskul per sekolah kecil (§10).

## 5. File dan interface terkait

### 5.1 Backend (patch lokal)

| File | Perubahan |
|---|---|
| `api/ekskul_access.php` (**baru**) | Helper otorisasi ekskul. Diawali guard `SIMANTAP_API`, dan memakai `normalizeClassKey`/`loadFreshUser`/`kesiswaanScope` dari `api/kesiswaan_access.php`. |
| `api/index.php` | `require_once` helper sebelum `switch`; `login`, `session`, `get_extracurriculars`, `save_extracurricular`, `delete_extracurricular`, `get_ekskul_journals`, `save_ekskul_journal`, `save_user`, `delete_user`. |
| `api/endpoints_v3.php` | `get_extracurricular_members`, `save_extracurricular_member`, `delete_extracurricular_member`, `get_extracurricular_activities`, `save_extracurricular_activity`, `delete_extracurricular_activity`, `save_user_v2`. |
| `api/endpoints_tu.php` | `gtk_delete`: pembersihan pembina. |
| `api/kesiswaan_access.php` | Refactor tanpa perubahan perilaku: perhitungan kelas perwalian dipindah ke `homeroomClassScope()` agar dipakai ulang oleh helper ekskul (guru BK yang juga wali tidak tercakup `kesiswaanScope`). |

Helper di `api/ekskul_access.php` (nama boleh disesuaikan saat implementasi, tetapi perilakunya wajib sama):
- `ekskulContext(mysqli $db, array $sessionUser): array`: memuat user segar dan mengembalikan `['user' => …, 'role' => …, 'homeroomScope' => array|null]`. 401 bila user tidak ada.
- `ekskulAccessLevel(mysqli $db, array $ctx, array $ekskulRow): string`: `ADMIN|MONITOR|COACH|HOMEROOM|NONE`.
- `ekskulAccessFlags(string $level): array`: flag `can*` untuk respons.
- `requireEkskulLevel(mysqli $db, array $ctx, string $ekskulId, array $allowedLevels): array`: memuat baris ekskul; 404/403 sesuai §3.3; mengembalikan `[$ekskulRow, $level]`.
- `accessibleEkskulIds(mysqli $db, array $ctx): ?array`: `null` berarti semua (ADMIN/KS); array berarti `[id => level]`.
- `homeroomStudentIds(mysqli $db, array $ctx): array`: id siswa perwalian.
- `isHomeroomMember(array $ctx, array $memberRow, ?array $studentRow): bool`.
- `filterAttendanceToStudents($attendance, array $studentIds)`: mendukung format map dan array.
- `clearCoachAssignment(mysqli $db, string $uid): void`.
- `ekskulMenuCounts(mysqli $db, array $sessionUser): array`: `['ekskul_coach_count' => int, 'ekskul_homeroom_count' => int]`, dengan try/catch mengembalikan 0.
- `requireMutationMethod(): void`: 405 bila `$_SERVER['REQUEST_METHOD'] === 'GET'`.

Konvensi yang wajib diikuti: prepared statement untuk semua query baru, pesan berbahasa Indonesia, actor dari session, dan tidak meniru pola `$db->query` dengan interpolasi string di handler lama. Sebelum menambah atau mengubah `case`, grep nama action di keempat file endpoint.

### 5.2 Frontend (patch bundle lokal)

- Sumber: `assets/index-CGWL69P7-wali2.js`, SHA-256 `b4b4a68973c30eb13d1b7d6ea6d69141dbd5b3418caacce596cee7810f30c1f3`.
- Hasil: **salinan baru** `assets/index-CGWL69P7-ekskul1.js`. File sumber tidak diubah dan tetap ada sebagai cadangan.
- `app.html` (SHA-256 `03994e50ac08324939095d2e02beb93e264b2fd2cc7fc906cdd8003f300adb9d`) diubah untuk mereferensikan file baru.
- Patch diterapkan oleh `docs/patches/apply-pembina-ekskul-bundle.js`. Skrip wajib gagal bila hash sumber berbeda atau bila ada anchor yang tidak muncul tepat 1 kali.

Anchor di bawah sudah diverifikasi muncul **tepat 1 kali** di bundle sumber. Anchor bertanda * perlu dipersempit atau diperluas saat implementasi, dan hasilnya tetap harus unik.

| # | Anchor (string persis) | Komponen | Perubahan semantik |
|---|---|---|---|
| F1 | `{id:"extracurricular",label:"Ekstrakurikuler",icon:a.jsx(R_,{size:20}),roles:["KEPALA_SEKOLAH","GURU"]}` | menu | Tambah `"ADMIN"` ke `roles`. |
| F2 | `else if(Y.title==="Kesiswaan"&&!(s!=null&&s.class_homeroom)\|\|Y.title==="Umum & Ekskul")return!1` | sidebar | Grup "Umum & Ekskul" tetap disembunyikan untuk GURU non-BK, **kecuali** `ne.id==="extracurricular"` dan user punya akses ekskul (`coach_count>0 \|\| homeroom_count>0`). |
| F3 | `if(M){if(Y.title==="Pusat Dashboard"\|\|Y.title==="Akademik"\|\|Y.title==="Kesiswaan")return!1}` | sidebar | Untuk BK, sembunyikan `ne.id==="extracurricular"` bila user tidak punya akses ekskul. Item lain tidak berubah. |
| F4 | `Y=(e==null?void 0:e.role)==="KEPALA_SEKOLAH"\|\|(e==null?void 0:e.role)==="ADMIN"\|\|(e==null?void 0:e.role)==="GURU"&&H` | `tot` | `Y` menjadi `role==="ADMIN"` (Tambah Ekskul, hapus). |
| F5* | `Y?a.jsxs("div",{className:"flex gap-2",children:[a.jsx("button",{onClick:ie=>{ie.stopPropagation(),N(X),b(!0)}` | `tot` kartu daftar | Wrapper tampil bila `Y\|\|X.access&&X.access.canEditInfo`. Tombol hapus di dalamnya tetap mensyaratkan `Y`. |
| F6 | `((e==null?void 0:e.role)==="GURU"\|\|(e==null?void 0:e.role)==="KEPALA_SEKOLAH"\|\|(e==null?void 0:e.role)==="ADMIN")&&a.jsxs("button"` | `tot` tombol Input Kegiatan | Syarat menjadi `Y\|\|i.some(x=>x.access&&x.access.canManageActivities)`. Pada `onClick`, `i` untuk picker diganti daftar terfilter `canManageActivities` (bila Y: semua). |
| F7 | `((e==null?void 0:e.role)==="GURU"\|\|(e==null?void 0:e.role)==="ADMIN"\|\|(e==null?void 0:e.role)==="KEPALA_SEKOLAH")&&a.jsxs("div",{className:"flex items-center gap-2 mt-4"` | `tot` kartu jurnal | Syarat menjadi `X.canManage===!0`. |
| F8 | `value:o.coachName,onChange:u=>l({...o,coachName:u.target.value})` (beserta elemen `input` pembungkusnya*) | `not` | Ganti menjadi `select` akun GURU (§3.4 butir 3). Daftar dimuat via `et.users.list()` hanya bila `e.role==="ADMIN"`. |
| F9 | `coachName:o.coachName,coachId:o.coachId\|\|e.uid` | `not` submit | Menjadi `coachId:o.coachId` (tanpa fallback). `coachName` boleh tetap dikirim karena backend mengabaikannya. |
| F10* | `children:"Daftar Anggota Ekskul"}),a.jsxs("button",{onClick:()=>A(!0)` | `sot` | Tombol Tambah Anggota tampil bila `e.access&&e.access.canManageMembers`. |
| F11* | tombol pemanggil handler `window.confirm("Keluarkan siswa dari ekskul ini?")` | `sot` | Tombol keluarkan tampil bila `canManageMembers`. Anchor handler muncul 1 kali; lokasi tombol dicari saat implementasi. |
| F12 | `((t==null?void 0:t.role)==="GURU"\|\|(t==null?void 0:t.role)==="ADMIN"\|\|(t==null?void 0:t.role)==="KEPALA_SEKOLAH")` | `sot` aksi aktivitas | Syarat menjadi `e.access&&e.access.canManageActivities`. |
| F13* | tombol pembuka form `Catat Aktivitas Ekskul` / `Input Jurnal & Presensi Anggota` | `sot` | Tampil bila `canManageActivities`. |
| F14* | pembuka form edit ekskul dari detail/INFO (bila ada) | `sot`/`not` | Field Nama dan Pembina disabled untuk non-ADMIN. |

**Catatan implementasi:** anchor final (termasuk yang bertanda *) ada di `docs/patches/apply-pembina-ekskul-bundle.js` dan dirangkum di `docs/patches/pembina-ekstrakurikuler.md` §3. F14 tidak diperlukan karena form edit hanya dibuka dari kartu Daftar Ekskul (`not`, dicakup F8c/F8d). Ada tambahan F7b: `attendanceRate` bernilai `null` tampil `--`.

Semua sisipan memakai optional check (`x&&x.access`) agar bundle tidak crash bila backend belum di-patch. Dalam kondisi itu tombol aksi tersembunyi (fail-closed), kecuali untuk ADMIN.

### 5.3 Dokumen

- `docs/specs/pembina-ekstrakurikuler.md` (dokumen ini).
- `docs/patches/pembina-ekstrakurikuler.md` (**baru**): daftar file yang diubah, hash sebelum dan sesudah, anchor F1–F14 final, langkah re-apply, checklist pasca-update (§9.4), dan rollback (§9.5).
- `docs/patches/apply-pembina-ekskul-bundle.js` (**baru**).
- `docs/patches/uji-pembina-ekskul-api.mjs` (**baru**): skrip uji matriks akses API untuk dev/staging.
- `docs/usulan-btikp/RINGKASAN-PEMBINA-EKSKUL.md`, `docs/usulan-btikp/backend-pembina-ekskul.diff`, dan `docs/usulan-btikp/usulan-pembina-ekskul.zip` (**baru**): paket usulan ke upstream, dengan format mengikuti usulan sebelumnya.

## 6. Di luar scope

- Lebih dari satu pembina per ekskul, pembina pendamping, dan pembina eksternal tanpa akun.
- Assign ekskul dari halaman Kelola Guru atau Kepegawaian (GTK). Assign hanya dari form ekskul.
- Migrasi atau pencocokan otomatis `coachName` lama ke akun guru.
- Isi baru untuk tab Laporan (tetap halaman yang ada), fitur verifikasi jurnal (`verify_ekskul_journal` tetap no-op), dan statistik "Menunggu Verif".
- Wali kelas mengelola anggota atau aktivitas; akses wali hanya baca.
- Perubahan item Literasi, Keagamaan, dan Kebersihan untuk role mana pun.
- Bagian `ekskul` di `get_homeroom_dashboard`, yang tetap membaca kolom `coachName` (termasuk masalah tanpa auth di endpoint itu).
- `get_users` untuk GURU; guru tidak butuh daftar guru.
- Kolom JSON legacy `extracurriculars.students`/`activities`: hanya dipertahankan, tidak dipakai.
- Celah keamanan lain yang dicatat di `CLAUDE.md` (`register_guru`, upload, CSRF di endpoint lain, `session_regenerate_id`, `save_user` yang membolehkan TU/KS memberi role ADMIN, dan sebagainya).
- Rilis resmi upstream. Spesifikasi ini hanya menyiapkan paket usulan.

## 7. Compatibility

- **Konsumen API.** Berdasarkan analisis bundle, setiap method `et.extracurriculars.*` dipanggil hanya dari `tot`/`sot` (`deleteActivity` 2 kali, keduanya di modul ekskul). `createJournal` dan `verifyJournal` tidak dipanggil. Penambahan auth tidak memengaruhi halaman lain karena semua dipanggil dari SPA yang login dengan cookie session same-origin.
- **Field respons baru** (`access`, `coachValid`, `coachNameLegacy`, `memberCount`, `canManage`, `ekskul_*_count`) bersifat aditif. Bundle lama yang tidak di-patch tetap berjalan, tetapi tombolnya mengikuti logika role lama dan backend yang menolak.
- **Kepala Sekolah** kehilangan akses tulis. Beri tahu sekolah sebelum rilis, dan pastikan ADMIN sudah siap mengambil alih.
- **Guru BK** yang sebelumnya membuat atau mengedit ekskul kehilangan akses bila bukan pembina.
- **Data lama.**
  - `coachId` berisi uid KS/TU/ADMIN tampil "Belum ada pembina".
  - `coachId` berisi uid GURU (termasuk BK pembuat) langsung dianggap pembina sah.
  - Aktivitas, anggota, dan `reporter_name` tidak berubah.
- **Kolom JSON `students`/`activities`.** Dengan `UPDATE` alih-alih `REPLACE`, isi lama tidak lagi terhapus saat ekskul diedit. Kolom yang sudah terlanjur kosong tidak dipulihkan.
- **Patch lokal sebelumnya.** Spesifikasi ini dibangun di atas `-wali2.js` dan `api/kesiswaan_access.php`. Patch wali kelas dan rekap bulanan harus tetap berfungsi (regresi E30).
- **Auto-updater.** Update dapat menimpa `api/*.php` dan `app.html`. File baru (`ekskul_access.php`, `-ekskul1.js`) tetap ada tetapi tidak direferensikan. Setelah tertimpa, menu dan otorisasi kembali ke perilaku lama tanpa kerusakan data. Pembina yang sudah di-assign tetap tersimpan di `coachId`.
- **PHP 8.2.** mysqli melempar exception, jadi penolakan memakai `errorResponse` eksplisit, bukan cabang `if (!$stmt->execute())`.
- **Collation.** `users` dan `extracurriculars` sama-sama `utf8mb4_unicode_ci` di skema. Bila JOIN gagal karena collation berbeda di DB produksi, tambahkan `COLLATE utf8mb4_unicode_ci` seperti pola `get_lesson_rpps`.

## 8. Edge cases

| Kasus | Perilaku yang diharapkan |
|---|---|
| Admin assign pembina ke akun yang ternyata bukan GURU (API langsung) | 400, tidak tersimpan. |
| Admin mengosongkan pembina | Tersimpan dengan `coachId=''`. Ekskul hilang dari menu/daftar mantan pembina setelah refresh, dan API mantan pembina langsung 403. |
| Satu guru menjadi pembina beberapa ekskul | Diizinkan; semua tampil dengan level COACH. |
| Guru BK atau wali kelas ditunjuk sebagai pembina | Diizinkan (role GURU). Menu ekskul tampil. |
| Akun pembina dihapus (`delete_user`/`gtk_delete`) | `coachId`/`coachName` ekskulnya dikosongkan. Jurnal lama tetap atas nama pelapor. |
| Role pembina diubah dari GURU (`save_user`/`save_user_v2`) | `coachId`/`coachName` dikosongkan. Jika akses sempat tersisa (misalnya edit DB langsung), otorisasi tetap menolak karena role bukan GURU. |
| Pembina ganti nama (profil/GTK) | Nama baru langsung tampil karena `coachName` dibaca dari `users`. |
| Pembina dicabut saat masih membuka halaman | Aksi berikutnya 403 (tertangkap `alert`/`console.error`); menu hilang setelah refresh. |
| Pembina mengirim `name`/`coachId` saat edit (API langsung) | Diabaikan; nilai DB dipertahankan. |
| Pembina membuat ekskul baru atau menghapus ekskul (API langsung) | 403. |
| `save_extracurricular_activity` dengan `id` milik ekskul lain | 403; data ekskul lain tidak berubah. |
| Tambah anggota dengan `student_id` tidak ada | 400. |
| Tambah anggota yang sudah terdaftar | Diaktifkan kembali (perilaku lama `ON DUPLICATE KEY`). |
| Wali: siswa perwalian keluar dari ekskul (anggota dihapus) | Bila tidak ada siswa perwalian tersisa, ekskul hilang dari daftar wali dan `get_extracurricular_members` wali untuk ekskul itu 403. |
| Wali: siswa pindah kelas | Ekskul mengikuti kelas siswa saat ini, jadi pindah dari wali lama ke wali baru. |
| `class_homeroom` terisi tetapi tidak ada baris `classes` yang cocok | Kunci kelas = `class_homeroom` saja (sama dengan `kesiswaanScope`). Tidak boleh 500. |
| Guru pembina ekskul A dan wali terkait ekskul A | Level COACH (kelola penuh, semua anggota). |
| Pertemuan tanpa siswa perwalian di presensi, dilihat wali | `attendanceRate` = `null`; UI menampilkan apa adanya tanpa crash (cek E19). |
| Format `attendance` array vs map | Keduanya terfilter benar untuk wali. |
| Ekskul tanpa pembina | Hanya ADMIN (kelola) dan KS (baca) yang melihat, ditambah wali terkait (baca). |
| Ekskul lama dengan `coachId` = uid guru BK pembuatnya | Dianggap pembina sah. Admin meninjau lewat kueri audit §9.1 langkah 6. |
| KEPALA_SEKOLAH memanggil mutasi via API | 403. |
| TATA_USAHA memanggil endpoint ekskul | 403. |
| Mutasi dipanggil dengan GET | 405. |
| `session` dipanggil saat tabel ekskul bermasalah | Hitungan 0, respons tetap 200. |

## 9. Testing dan verifikasi end-to-end

Tidak ada test otomatis di bundle ini. Verifikasi dilakukan dengan `php -l` dan pengujian manual di **staging dengan data sekolah**.

### 9.1 Persiapan staging

1. Backup database staging (`mysqldump`) dan salin folder deploy sebelum patch.
2. Pastikan lisensi staging valid untuk host staging, atau aktifkan `SIMANTAP_DEV_MODE` khusus staging.
3. Siapkan dua ekskul, **EA** dan **EB**, serta kelas **A** dan **B** yang punya siswa aktif. Siswa `sA1` (kelas A) menjadi anggota EA; siswa `sB1` (kelas B) menjadi anggota EB.
4. Siapkan akun uji:
   - `admin` (ADMIN), `ks` (KEPALA_SEKOLAH), `tu` (TATA_USAHA);
   - `pembinaA` (GURU non-wali, is_bk=0), yang akan jadi pembina EA;
   - `waliA` (GURU, `class_homeroom` = A, bukan pembina);
   - `rangkap` (GURU, `class_homeroom` = B), yang akan jadi pembina EA, sehingga ia COACH di EA dan HOMEROOM di EB;
   - `guru` (GURU biasa), `bk` (GURU is_bk=1, bukan pembina), `bkPembina` (GURU is_bk=1);
   - akun `hapusUji` (GURU) untuk skenario hapus.
5. Catat kondisi awal: `SELECT id, name, coachId, coachName, createdAt, students, activities FROM extracurriculars;`
6. Jalankan kueri audit data lama, lalu simpan hasilnya sebagai daftar tinjauan untuk admin:
   ```sql
   SELECT e.id, e.name, e.coachId, e.coachName AS coach_tercatat,
          u.displayName AS akun, u.role
   FROM extracurriculars e LEFT JOIN users u ON u.uid = e.coachId
   ORDER BY u.role, e.name;
   ```

### 9.2 Pemeriksaan statis

- `php -l` untuk `api/ekskul_access.php`, `api/index.php`, `api/endpoints_v3.php`, dan `api/endpoints_tu.php`.
- `grep` bahwa tidak ada `case` duplikat baru untuk action yang diubah di keempat file endpoint.
- `sha256sum assets/index-CGWL69P7-wali2.js` sama dengan hash di §5.2.
- Anchor F1–F14 **tidak lagi** ditemukan di `-ekskul1.js`, dan `app.html` mereferensikan `-ekskul1.js`.
- `node --check assets/index-CGWL69P7-ekskul1.js` lolos (bila Node tersedia).

### 9.3 Skenario end-to-end

Jalankan di browser dengan cache dikosongkan setelah deploy. Cek API dari DevTools Console di tab yang sudah login, misalnya dengan `fetch('api/index.php?action=…', {method:'POST', body: JSON.stringify({...})})`.

| # | Akun | Langkah | Hasil yang diharapkan |
|---|---|---|---|
| E1 | admin | Login, buka sidebar | Item "Ekstrakurikuler" tampil (grup "Umum & Ekskul" hanya berisi item itu). Menu Manajemen Data tetap. |
| E2 | admin | Buka Daftar Ekskul | Semua ekskul tampil. Ekskul data lama dengan `coachId` bukan GURU menampilkan "Pembina: Belum ada pembina". |
| E3 | admin | Tambah Ekskul baru EC tanpa pembina | Tersimpan; `coachId=''`. Dropdown pembina hanya berisi akun GURU dan opsi "— Belum ada pembina —". |
| E4 | admin | Edit EA, pilih `pembinaA`, lalu simpan | DB: `coachId` = uid pembinaA dan `coachName` = displayName-nya. `createdAt`, `students`, `activities` EA sama dengan catatan §9.1 langkah 5. |
| E5 | admin | Edit ekskul data lama | Select kosong dan teks "Tercatat sebelumnya: <nama lama>" tampil. |
| E6 | admin | Console: `save_extracurricular` EA dengan `coachId` = uid `ks` | HTTP 400; DB tidak berubah. |
| E7 | pembinaA | Login (atau refresh bila sudah login) | Item "Ekstrakurikuler" tampil. Literasi, Keagamaan, dan Kebersihan tidak tampil. |
| E8 | pembinaA | Buka menu Ekstrakurikuler | Daftar hanya berisi EA. Tidak ada tombol "Tambah Ekskul" maupun hapus; tombol edit ada. |
| E9 | pembinaA | Edit EA: ubah jadwal, ruang, deskripsi | Tersimpan. Field Nama dan Pembina tidak bisa diubah. |
| E10 | pembinaA | Console: `save_extracurricular` EA dengan `name` dan `coachId` lain | 200, tetapi `name` dan `coachId` di DB tidak berubah. |
| E11 | pembinaA | Console: `save_extracurricular` tanpa `id`, lalu `delete_extracurricular&id=EA` (DELETE) | Keduanya HTTP 403. |
| E12 | pembinaA | Detail EA: tambah anggota siswa kelas B, lalu keluarkan | Berhasil. DB: `student_name`/`class_id` sesuai tabel `students`. |
| E13 | pembinaA | "Input Kegiatan" → catat aktivitas EA dengan presensi, lalu edit dan hapus | Picker langsung membuka EA (satu-satunya). Aktivitas tersimpan dengan `reporter_id` = uid pembinaA; edit dan hapus berhasil. |
| E14 | pembinaA | Console: `get_extracurricular_members&extracurricular_id=EB`, `save_extracurricular_member` ke EB, `save_extracurricular_activity` dengan `id` aktivitas milik EB | Semua HTTP 403; data EB tidak berubah. |
| E15 | pembinaA | Buka tab Laporan | Halaman Laporan tampil seperti sebelumnya. |
| E16 | waliA | Login | Item "Ekstrakurikuler" tampil karena `sA1` anggota EA. Grup Kesiswaan (patch wali) tetap tampil. |
| E17 | waliA | Buka daftar dan detail EA | Hanya EA tampil. Tidak ada tombol tambah, edit, hapus, input kegiatan, tambah/keluarkan anggota, atau aksi aktivitas. |
| E18 | waliA | Tab MEMBERS EA | Hanya anggota siswa kelas A (siswa kelas B dari E12, jika belum dikeluarkan, tidak tampil). |
| E19 | waliA | Tab ACTIVITIES dan Jurnal Kegiatan | Presensi hanya memuat siswa kelas A. Pertemuan tanpa siswa kelas A tidak membuat halaman crash. |
| E20 | waliA | Console: `save_extracurricular_member`, `save_extracurricular_activity`, `delete_extracurricular_activity` untuk EA; `get_extracurricular_members&extracurricular_id=EB` | Semua HTTP 403. |
| E21 | waliA | Pembina mengeluarkan `sA1` dari EA, lalu waliA me-refresh | Item menu ekskul hilang (bila tidak ada ekskul terkait lain); `get_extracurriculars` = `[]`. |
| E22 | rangkap | Admin menjadikan `rangkap` pembina EA; `sB1` anggota EB. Login | Daftar berisi EA (tombol kelola) dan EB (baca saja, anggota kelas B saja). |
| E23 | guru | Login; Console: `get_extracurriculars`, `get_extracurricular_members&extracurricular_id=EA` | Menu ekskul tidak tampil; respons pertama `[]`, kedua 403. |
| E24 | bk | Login | Item "Ekstrakurikuler" tidak tampil. Literasi, Keagamaan, dan Kebersihan tetap tampil seperti sebelum patch. |
| E25 | bkPembina | Admin assign sebagai pembina EB. bkPembina me-refresh | Item "Ekstrakurikuler" tampil dan EB bisa dikelola. |
| E26 | ks | Buka Ekstrakurikuler | Semua ekskul, anggota, dan jurnal terlihat. Tidak ada tombol tambah, edit, hapus, input kegiatan, atau aksi anggota/aktivitas. Console: `save_extracurricular`, `save_extracurricular_member`, dan `delete_extracurricular_activity` menghasilkan 403. |
| E27 | tu | Console: `get_extracurriculars`, `get_ekskul_journals` | HTTP 403. |
| E28 | (logout) | `curl` `get_extracurriculars`, `get_extracurricular_members&extracurricular_id=EA`, `get_extracurricular_activities&extracurricular_id=EA`, `get_ekskul_journals` tanpa cookie | Semua HTTP 401. |
| E29 | admin | (a) Hapus akun `hapusUji` yang menjadi pembina EC lewat Kelola Guru. (b) Ubah role `pembinaA` menjadi TATA_USAHA lewat form user, lalu kembalikan ke GURU | (a) EC: `coachId=''`, tampil "Belum ada pembina". (b) EA: `coachId=''`; pembinaA yang dikembalikan ke GURU **tidak** otomatis menjadi pembina lagi. |
| E30 | waliA, admin | Regresi patch sebelumnya: Presensi Kelas, Dashboard Kelas Saya (termasuk kartu ekskul), dan cetak rekap bulanan | Berfungsi seperti sebelum patch. |
| E31 | pembinaA | Console: `delete_extracurricular_activity&id=<id EA>` dengan metode GET | HTTP 405; aktivitas tidak terhapus. |
| E32 | admin | Hapus ekskul EC | Anggota dan aktivitas EC ikut terhapus (perilaku lama). |

### 9.4 Checklist pasca auto-update (masuk ke `docs/patches/pembina-ekstrakurikuler.md`)

1. Periksa `api/version.php` dan bundle yang direferensikan `app.html`.
2. Jika versi atau hash berubah, cek dulu apakah upstream sudah memuat fitur resmi (jalankan E1, E4, E8, E17, E28). Jika belum, jangan menempel patch lama begitu saja: cari ulang anchor F1–F14 dan pastikan masing-masing unik. Patch ini bergantung pada patch wali kelas (F2 memuat perubahan wali), jadi terapkan ulang patch wali kelas lebih dulu.
3. Terapkan ulang backend dengan membandingkan handler baru terhadap §3.3, lalu jalankan `php -l`.
4. Jalankan subset regresi: E1, E4, E7, E8, E11, E14, E17, E18, E20, E24, E26, E28, E29, E30.

### 9.5 Rollback

1. Kembalikan referensi di `app.html` ke `index-CGWL69P7-wali2.js`.
2. Pulihkan `api/index.php`, `api/endpoints_v3.php`, dan `api/endpoints_tu.php` dari salinan sebelum patch.
3. Hapus `api/ekskul_access.php`.

Tidak ada perubahan skema. `coachId` yang sudah di-assign tetap tersimpan dan valid bila fitur dipasang lagi.

## 10. Risiko dan trade-off

| Risiko / trade-off | Dampak | Mitigasi |
|---|---|---|
| Patch pada JS hasil minify, dengan 14 anchor dan sebagian (*) belum final | Mudah salah; tidak ada source map | Anchor unik + hash, skrip gagal bila anchor tidak unik, salinan file terpisah, optional check fail-closed, E1–E26 wajib |
| Mengganti input teks menjadi `select` di komponen minify | Perubahan JSX paling besar di patch ini | Uji E3–E5 dan E9; pertahankan class CSS yang sama |
| Auto-updater menimpa patch | Menu dan otorisasi kembali longgar tanpa pemberitahuan | Checklist §9.4, paket usulan BTIKP |
| KS kehilangan akses tulis | Operasional terganggu bila admin belum siap | Komunikasikan sebelum rilis; admin melakukan assign ulang dahulu |
| `coachId` lama berisi uid guru BK pembuat dianggap sah | Guru yang bukan pembina sebenarnya bisa mengelola ekskul | Kueri audit §9.1 langkah 6; admin meninjau setelah rilis |
| Menutup endpoint baca yang sebelumnya publik | Integrasi eksternal tak dikenal (jika ada) akan 401 | Analisis bundle tidak menemukan pemakai lain; dipantau di staging |
| Label "Belum ada pembina" dikirim sebagai nilai `coachName` | Mencampur data dan teks tampilan, tetapi mengurangi jumlah anchor UI | `coachValid` dan `coachNameLegacy` tersedia untuk konsumen yang butuh nilai asli |
| Hitungan menu dihitung tiap `session`/`login` | Tambahan 1–2 kueri ringan per load SPA | Kueri kecil; tabel ekskul per sekolah berukuran kecil |
| Filter wali per request (kelas + siswa) | Kueri tambahan di endpoint ekskul | Memakai indeks `student_id`/`class_id` yang ada |
| `WHERE coachId = ?` tanpa indeks | Full scan tabel kecil | Dapat ditambah indeks lewat patch skema upstream bila perlu |
| Hook pembersihan hanya di `delete_user`, `gtk_delete`, `save_user`, `save_user_v2` | Jalur lain yang mengubah role (edit DB langsung) tidak membersihkan `coachId` | Otorisasi tetap mensyaratkan role GURU; tampilan menunjukkan "Belum ada pembina" |
| Pengedit aktivitas menjadi pelapor (`REPLACE` + session) | Nama pelapor asli hilang saat admin mengedit aktivitas | Perilaku lama; dicatat, tidak diubah |
| Mutasi menolak GET | Pemanggil lama yang memakai GET gagal | Bundle memakai POST/DELETE; hanya endpoint ekskul yang terdampak |

## 11. Acceptance criteria

- **AC-1** ADMIN melihat menu Ekstrakurikuler dan dapat membuat, mengubah, dan menghapus ekskul, mengelola anggota, serta mencatat, mengubah, dan menghapus aktivitas semua ekskul.
- **AC-2** Di form ekskul, ADMIN memilih pembina dari dropdown akun `role='GURU'` (atau "Belum ada pembina"); input teks manual tidak ada lagi. Backend menolak `coachId` yang bukan akun GURU (400) dan mengisi `coachName` dari akun.
- **AC-3** GURU pembina (termasuk BK dan wali kelas) melihat menu Ekstrakurikuler setelah login atau refresh, hanya melihat ekskul binaannya (plus ekskul terkait wali bila merangkap), dan dapat mengelola anggota, aktivitas/presensi, serta jadwal/ruang/deskripsi ekskul binaannya.
- **AC-4** GURU pembina tidak dapat membuat atau menghapus ekskul, mengubah nama/pembina, atau mengakses data ekskul lain lewat API (403, atau diabaikan untuk field `name`/`coachId`).
- **AC-5** GURU wali kelas yang bukan pembina melihat menu Ekstrakurikuler hanya bila ada siswa perwaliannya yang menjadi anggota aktif, melihat ekskul terkait tanpa tombol aksi, dan data anggota/presensi hanya berisi siswa perwaliannya. Semua mutasi mendapat 403.
- **AC-6** KEPALA_SEKOLAH dapat melihat semua data ekskul tanpa tombol aksi, dan semua mutasi ekskul mendapat 403.
- **AC-7** GURU tanpa peran pembina/wali terkait (termasuk BK) tidak melihat menu Ekstrakurikuler. Item Literasi, Keagamaan, dan Kebersihan untuk BK tidak berubah. TATA_USAHA mendapat 403 dan anonim mendapat 401 di semua endpoint ekskul.
- **AC-8** Menghapus akun pembina atau mengubah role-nya dari GURU mengosongkan pembina ekskulnya. Otorisasi selalu dibaca segar dari DB, sehingga pencabutan langsung berlaku di API tanpa login ulang.
- **AC-9** Menyimpan ekskul tidak lagi mereset `createdAt`, `students`, maupun `activities`, dan aktivitas tidak dapat ditimpa lintas ekskul.
- **AC-10** Tidak ada perubahan skema. Bundle sumber tidak dimodifikasi (hash sama). Semua file PHP lolos `php -l`. Patch wali kelas dan rekap bulanan tidak mengalami regresi (E30).
- **AC-11** Dokumen `docs/patches/pembina-ekstrakurikuler.md`, skrip `docs/patches/apply-pembina-ekskul-bundle.js`, dan paket usulan di `docs/usulan-btikp/` tersedia.
- **AC-12** Skenario E1–E32 lulus di staging, dan hasil kueri audit §9.1 langkah 6 diserahkan ke admin sekolah.
