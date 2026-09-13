# Spesifikasi: Menu Kesiswaan untuk Guru Wali Kelas

| | |
|---|---|
| Status | Diimplementasikan (patch lokal) — lihat `docs/patches/wali-kelas-kesiswaan.md` |
| Versi bundle acuan | SIMANTAP 1.0.2 (`api/version.php`) |
| Tanggal | 2026-09-13 |
| Jalur rilis | Patch lokal pada bundle ini **dan** usulan perubahan ke upstream BTIKP |

## 1. Latar belakang dan kebutuhan bisnis

Wali kelas bertanggung jawab atas kehadiran dan pembinaan siswa di kelas perwaliannya. Saat ini hanya Kepala Sekolah yang bisa membuka grup menu **Kesiswaan**, sehingga wali kelas tidak bisa:

1. mencatat presensi harian kelasnya (Hadir/Sakit/Izin/Alpha);
2. memantau dan mencetak rekap presensi kelasnya;
3. menangani siswa Terlambat, Izin Keluar, Pelanggaran, dan Panggilan Orang Tua untuk kelasnya, termasuk alur otomatis *Alpha → poin pelanggaran ALFA → panggilan orang tua*.

Tujuan perubahan: guru dengan tugas tambahan wali kelas mendapat grup menu Kesiswaan. Data yang bisa dilihat dan diproses dibatasi pada kelas perwaliannya, dan pembatasan itu **ditegakkan di backend**, bukan hanya di UI.

## 2. Perilaku saat ini (hasil analisis kode)

### 2.1 Frontend (`assets/index-CGWL69P7.js`, bundle React hasil minify)

- Grup sidebar "Kesiswaan" berisi item `attendance` (Presensi Kelas), `attendance-ceremony` (Absensi Upacara, khusus KS), `student-late`, `student-out`, `parent-call`, dan `student-violation`. Semua item selain Absensi Upacara sudah punya `roles:["KEPALA_SEKOLAH","GURU"]`.
- Filter sidebar menyembunyikan grup untuk **semua** GURU, tanpa melihat `class_homeroom`:
  ```js
  if(r==="GURU"){if(M){ /* BK */ if(Y.title==="Pusat Dashboard"||Y.title==="Akademik"||Y.title==="Kesiswaan")return!1}
                 else if(Y.title==="Kesiswaan"||Y.title==="Umum & Ekskul")return!1}
  ```
  Satu-satunya menu khusus wali adalah "Kelas Saya → Dashboard <kelas>" (`classroom-dashboard`).
- Halaman **Presensi Kelas** (komponen `vGe`):
  - state awal kelas di-hardcode `"X-IPA-1"`, lalu diganti kelas pertama dari `get_classes`, sehingga dropdown berisi **semua kelas**;
  - memanggil `get_attendance_records&classId&date&year=2025/2026`. Jika hasilnya kosong, halaman memuat `get_students` dan menandai semua siswa `HADIR`;
  - menyimpan lewat `save_attendance_records` dengan `academic_year:"2025/2026"`, tanpa semester;
  - memakai status `HADIR`/`SAKIT`/`IZIN`/**`ALPHA`**;
  - untuk KEPALA_SEKOLAH, perubahan status ditolak di UI ("Kepala Sekolah hanya memiliki akses baca"), tetapi tidak ditolak di backend.
- Halaman **Terlambat/Izin Keluar/Pelanggaran/Panggilan Ortu** (komponen `wp`, dibedakan oleh prop `type`):
  - memuat **semua** insiden (`get_incidents`) atau panggilan ortu (`get_parent_calls`);
  - dropdown kelas pada form tambah berisi semua kelas;
  - tombol proses sudah dibedakan per peran: wali kelas (bila `L$(class_homeroom)===L$(incident.class)`), BK, dan ADMIN/KS;
  - **tidak ada tombol hapus**.

### 2.2 Backend

Semua handler di bawah adalah copy yang aktif. Copy lain di `endpoints_v3.php` untuk panggilan ortu tidak pernah dijalankan (tertimpa copy v2).

| Action | File | Auth saat ini |
|---|---|---|
| `get_attendance_records` | `api/endpoints_v2.php` | **tanpa auth** |
| `save_attendance_records` | `api/endpoints_v2.php` | `requireAuth()` saja |
| `get_attendance_summary` | `api/endpoints_v2.php` | **tanpa auth** (tidak dipakai bundle) |
| `get_incidents` | `api/index.php` | **tanpa auth** |
| `save_incident` (buat baru & update) | `api/index.php` | `requireAuth()` saja |
| `delete_incident` | `api/index.php` | **tanpa auth** |
| `update_incident_status` | `api/endpoints_v3.php` | `requireAuth()` saja |
| `get_parent_calls` | `api/endpoints_v2.php` | **tanpa auth** |
| `save_parent_call_manual` | `api/endpoints_v2.php` | `requireAuth()` saja |
| `update_parent_call` | `api/endpoints_v2.php` | `requireAuth()` saja |

### 2.3 Bug terkait yang masuk scope

1. **ALPHA vs ALFA.** `attendance_records.status` bertipe `enum('HADIR','SAKIT','IZIN','ALFA')`, tetapi halaman Presensi mengirim `ALPHA`, sedangkan `save_attendance_records` hanya mengenali `'ALFA'`.
   - Pada MySQL strict mode, penyimpanan yang berisi ≥1 siswa Alpha gagal seluruhnya ("Gagal simpan presensi: Data truncated…").
   - Pada mode non-strict, status tersimpan sebagai `''` dan insiden/poin ALFA tidak pernah terpicu.
2. **Tahun ajaran/semester hardcoded.** Presensi manual selalu tercatat `2025/2026`/`GANJIL`, sedangkan `save_journal` memakai `system_config.active_academic_year`/`active_semester`.

## 3. Expected behavior

### 3.1 Definisi

- **Wali kelas**: user dengan `role = 'GURU'`, `is_bk ≠ 1`, dan `users.class_homeroom` tidak kosong. **Nilai dibaca ulang dari database pada setiap request** ke endpoint dalam spesifikasi ini, bukan dari `$_SESSION`.
- **Kunci kelas** (`normalizeClassKey`): lowercase, lalu hapus spasi, `_`, `-`, dan `.`. Aturan ini sama dengan fungsi `L$` di frontend dan normalisasi SQL di `get_classes`.
- **Kelas perwalian**: himpunan kunci `{ key(class_homeroom), key(classes.id), key(classes.name) }` untuk baris `classes` yang kuncinya sama dengan `class_homeroom`. Pencocokan memakai id **dan** nama karena `incidents.className` dan `parent_calls.class_name` menyimpan nama kelas, sedangkan `class_homeroom` dan `attendance_records.class_id` menyimpan id kelas.

### 3.2 Matriks akses

Keterangan: ✅ = semua kelas, 🏫 = hanya kelas perwalian, 👁 = baca saja, ⛔ = 403, 🔒 = 401 bila belum login.

| Action | ADMIN | KEPALA_SEKOLAH | GURU wali | GURU BK | GURU lain | TATA_USAHA | Anonim |
|---|---|---|---|---|---|---|---|
| Menu grup Kesiswaan | – (tidak berubah) | ✅ (tidak berubah) | **✅ baru** (tanpa Absensi Upacara) | tidak berubah (tersembunyi) | tersembunyi | tersembunyi | – |
| `get_attendance_records` | ✅ | ✅ | 🏫 | ⛔ | ⛔ | ⛔ | 🔒 |
| `get_attendance_summary` | ✅ | ✅ | 🏫 | ⛔ | ⛔ | ⛔ | 🔒 |
| `save_attendance_records` | ✅ | ⛔ (👁) | 🏫 | ⛔ | ⛔ | ⛔ | 🔒 |
| `get_incidents` | ✅ | ✅ | 🏫 | ✅ | ⛔ | ⛔ | 🔒 |
| `save_incident` (baru/update) | ✅ | ✅ | 🏫 | ✅ | ⛔ | ⛔ | 🔒 |
| `update_incident_status` | ✅ | ✅ | 🏫 | ✅ | ⛔ | ⛔ | 🔒 |
| `delete_incident` | ✅ | ✅ | ⛔ | ✅ | ⛔ | ⛔ | 🔒 |
| `get_parent_calls` | ✅ | ✅ | 🏫 | ✅ | ⛔ | ⛔ | 🔒 |
| `save_parent_call_manual` | ✅ | ✅ | 🏫 | ✅ | ⛔ | ⛔ | 🔒 |
| `update_parent_call` | ✅ | ✅ | 🏫 | ✅ | ⛔ | ⛔ | 🔒 |

Catatan:
- Peran ADMIN tidak punya menu Kesiswaan. Akses API penuh diberikan untuk koreksi data.
- `delete_incident` untuk ADMIN/KS/BK adalah **asumsi**: saat ini tidak ada UI hapus dan wali secara eksplisit tidak diberi izin hapus. Lihat §10.
- `save_journal` (yang juga membuat presensi dan insiden ALFA untuk semua guru) **tidak** diubah.

### 3.3 Perilaku detail per endpoint

**Umum**
- Semua endpoint di matriks memanggil `requireAuth()` terlebih dahulu, lalu helper scope (§5.1).
- Penolakan memakai `errorResponse('…', 403)` dengan pesan berbahasa Indonesia, misalnya "Anda hanya dapat mengakses data kelas perwalian Anda."
- Jika baris user sudah tidak ada di DB, respons 401.

**`get_attendance_records`**
- Wali: jika `classId` bukan kelas perwalian, respons 403.
- Parameter `year` dan `semester` **diabaikan**, sehingga filter hanya `class_id + date`. Id record sudah unik per siswa per tanggal, dan frontend masih mengirim `year=2025/2026` yang akan menyembunyikan data tahun ajaran aktif.
- Status `ALFA` pada respons dikirim sebagai `ALPHA` agar dikenali halaman Presensi.

**`get_attendance_summary`**
- Wali wajib memberi `classId` kelasnya. Tanpa `classId`, data dipaksa ke kelasnya; `classId` lain menghasilkan 403.
- Default `year`/`semester` diambil dari `system_config` bila parameter kosong.

**`save_attendance_records`**
- KEPALA_SEKOLAH ditolak dengan 403.
- Setiap record wajib memiliki `student_id` dan `date`; jika tidak, respons 400.
- Wali: **setiap** record wajib memenuhi dua syarat, yaitu `class_id` termasuk kelas perwalian dan `students.class_id` milik `student_id` juga termasuk kelas perwalian. Jika satu record saja melanggar, seluruh request ditolak dengan 403 sebelum transaksi dimulai.
- Status dinormalisasi: `ALPHA` → `ALFA`. Nilai di luar `HADIR/SAKIT/IZIN/ALFA/ALPHA` ditolak dengan 400.
- `academic_year` dan `semester` **selalu** diambil dari `system_config` (`active_academic_year`, `active_semester`). Nilai dari body diabaikan. Fallback `2025/2026`/`GANJIL` hanya dipakai bila config kosong, sama seperti `save_journal`.
- Logika insiden ALFA yang sudah ada (buat insiden `inc_att_<siswa>_<tanggal>`, tambah poin, panggilan ortu otomatis, serta hapus insiden dan kurangi poin bila status diubah dari ALFA) tetap berjalan, dan kini benar-benar terpicu. Wali boleh mengoreksi presensi kelasnya, termasuk ALFA → HADIR, yang otomatis mengembalikan poin.

**`get_incidents`**
- Wali: hasil **dipaksa** difilter ke kelas perwalian, dengan `className` dicocokkan ke kunci kelas perwalian. Jika parameter `class`/`classId` diberikan dan bukan kelasnya, respons 403. Dashboard kelas (`classroom-dashboard`) mengirim `class=<class_homeroom>` dan harus tetap berfungsi.

**`save_incident`**
- Buat baru (wali): kelas dari `classId`/`className` dan kelas siswa (`students.class_id` bila `studentId` ada) wajib termasuk kelas perwalian.
- Update record lama (wali): `incidents.className` record lama wajib termasuk kelas perwalian.
- Actor tetap diambil dari session (`uid`/`displayName`), seperti sekarang.

**`update_incident_status`**
- Wali: `incidents.className` record wajib termasuk kelas perwalian. Transisi status yang boleh dilakukan tidak dibatasi di backend dan mengikuti UI yang sudah ada (lihat §8).

**`delete_incident`**
- Ditambah `requireRole(['ADMIN','KEPALA_SEKOLAH','GURU'])`, dan GURU wajib `is_bk = 1`.

**`get_parent_calls`** / **`save_parent_call_manual`** / **`update_parent_call`**
- Untuk wali, sebuah panggilan termasuk kelas perwalian jika `students.class_id` milik `student_id` termasuk kelas perwalian, **atau** `parent_calls.class_name` termasuk kelas perwalian (untuk panggilan dengan `student_id` kosong atau siswa yang sudah dihapus).
- `get_parent_calls` untuk wali dipaksa difilter.
- `save_parent_call_manual` memvalidasi siswa.
- `update_parent_call` memvalidasi record yang ada.

### 3.4 Perilaku UI yang diharapkan (patch bundle)

1. **Sidebar:** GURU non-BK dengan `class_homeroom` terisi melihat grup Kesiswaan berisi Presensi Kelas, Siswa Terlambat, Izin Keluar, Panggilan Ortu, dan Pelanggaran. Absensi Upacara tetap khusus KS. GURU non-BK tanpa `class_homeroom` dan GURU BK tidak mengalami perubahan. Grup "Umum & Ekskul" tetap tersembunyi bagi GURU non-BK.
2. **Presensi Kelas**, untuk user GURU:
   - dropdown kelas hanya berisi kelas yang kuncinya sama dengan `class_homeroom`, dan kelas itu terpilih otomatis;
   - request awal tidak memakai `"X-IPA-1"`. State awal kosong, dan data baru dimuat setelah kelas perwalian terpilih;
   - wali bisa mengubah status dan menyimpan;
   - status Alpha yang tersimpan tampil kembali sebagai Alpha setelah reload.
3. **Halaman insiden dan panggilan ortu**, untuk user GURU non-BK: dropdown kelas pada form tambah hanya berisi kelas perwalian. Daftar data sudah terfilter oleh backend.
4. Penugasan wali yang baru atau dicabut baru mengubah **menu** setelah login ulang, karena sidebar membaca session. API langsung mengikuti DB.

## 4. Role, authorization, dan data model

- **Tidak ada perubahan skema.** Tabel yang dipakai: `users` (`role`, `class_homeroom`, `is_bk`), `classes` (`id`, `name`), `students` (`id`, `class_id`, `class_name`), `attendance_records`, `incidents` (`className`), `parent_calls` (`student_id`, `class_name`), dan `system_config` (`active_academic_year`, `active_semester`).
- Sumber kebenaran wali kelas adalah `users.class_homeroom`. Nilai ini disinkronkan dengan `gtk_pegawai.class_homeroom` oleh `gtk_update_atribut`, `save_user_v2`, dan `save_class`; sinkronisasi tersebut tidak diubah.
- `get_classes` tetap mengembalikan semua kelas, karena endpoint ini dipakai modul lain seperti nilai dan jurnal. Pembatasan dropdown dilakukan di UI dan ditegakkan di endpoint data.
- Status ALFA tetap disimpan sebagai `ALFA` di DB. `ALPHA` hanya ada di lapisan frontend/API.

## 5. File dan interface terkait

### 5.1 Backend (patch lokal)

| File | Perubahan |
|---|---|
| `api/kesiswaan_access.php` (**baru**) | Helper, diawali guard `if (!defined('SIMANTAP_API')) exit;`. Lihat daftar helper di bawah tabel. |
| `api/endpoints_v2.php` | `get_attendance_records`, `get_attendance_summary`, `save_attendance_records`, `get_parent_calls`, `update_parent_call`, `save_parent_call_manual` (copy aktif v2). |
| `api/index.php` | `get_incidents`, `save_incident`, `delete_incident`. Juga `require_once` helper sebelum `switch`. |
| `api/endpoints_v3.php` | `update_incident_status`. Copy mati `get_parent_calls`/`update_parent_call`/`save_parent_call_manual` **tidak** diubah. |

Helper di `api/kesiswaan_access.php`:
- `normalizeClassKey(?string): string`
- `loadFreshUser(mysqli $db, array $sessionUser): array`: 401 bila user tidak ditemukan.
- `kesiswaanScope(mysqli $db, array $user, string $feature): array`: mengembalikan `['mode' => 'ALL'|'CLASS'|'DENY', 'classKeys' => string[], 'classId' => ?string]`. Nilai `$feature` ∈ `attendance_read`, `attendance_write`, `incident`, `incident_delete`, `parent_call`.
- `requireKesiswaanScope(...)`: sama dengan `kesiswaanScope`, tetapi 403 bila `DENY`.
- `classInScope(array $scope, ?string $classIdOrName): bool`
- `studentInScope(mysqli $db, array $scope, ?string $studentId): bool`: memakai `students.class_id` atau `students.class_name`.
- `parentCallInScope(array $scope, ?string $studentClassId, ?string $className): bool`
- `normalizeAttendanceStatus(?string): string`: `ALPHA` → `ALFA`, 400 bila tidak valid.
- `activeAcademicPeriod(mysqli $db): array`: `[year, semester]`.
- Konstanta `KESISWAAN_MSG_KELAS`: pesan 403 untuk akses kelas lain.

Konvensi yang wajib diikuti: prepared statement untuk semua query baru, pesan berbahasa Indonesia, dan actor dari session. Jangan meniru `$db->query` dengan string interpolasi yang ada di handler lama.

### 5.2 Frontend (patch bundle lokal)

- Sumber: `assets/index-CGWL69P7.js`, SHA-256 `ba957b9bc049c2e95b1f7ca74cd4e993d0eff662c9bd5c7e9e0b3eb514cb04bd`.
- Hasil: **salinan baru** `assets/index-CGWL69P7-wali1.js`. File asli **tidak diubah** dan tetap ada sebagai cadangan.
- `app.html` (SHA-256 `bf4025a9fa5fb9cbcd6bbd9cd0156a2714a32c633434fc7786597bf4cbaebf87`) diubah untuk mereferensikan file baru. Nama baru sekaligus berfungsi sebagai *cache busting*.

Setiap anchor di bawah sudah diverifikasi muncul **tepat 1 kali** di bundle 1.0.2:

| # | Anchor (string persis) | Perubahan semantik |
|---|---|---|
| F1 | `else if(Y.title==="Kesiswaan"\|\|Y.title==="Umum & Ekskul")return!1` | Grup Kesiswaan hanya disembunyikan bila `!(s!=null&&s.class_homeroom)`. "Umum & Ekskul" tetap disembunyikan. `s` = objek user di komponen sidebar. |
| F2 | `o(me),me.length>0&&n(me[0].id)` (komponen `vGe`) | Bila `e.role==="GURU"`, filter `me` menjadi kelas yang `L$(id)` atau `L$(name)` sama dengan `L$(e.class_homeroom)` sebelum `o(...)` dan `n(...)`. |
| F3 | `ue.useState("X-IPA-1")` (komponen `vGe`) | Nilai awal menjadi `e&&e.role==="GURU"?"":"X-IPA-1"`. Nilai `class_homeroom` (mis. `X-AKT-1`) bisa berbeda dari `classes.id` (`X_AKT_1`), sehingga memakainya langsung memicu fetch kosong yang bisa menimpa daftar siswa (race). Efek fetch melewati kelas kosong, dan F2 memilih kelas yang benar. |
| F4 | `G(Se),Se.length>0&&Ee(wt=>({...wt,class:Se[0].id}))` (komponen `wp`) | Bila `t.role==="GURU"` dan `Number(t.is_bk)!==1`, filter `Se` ke kelas perwalian sebelum `G(...)`. |

F2 dan F4 menyisipkan normalisasi kunci kelas sendiri (setara `L$`), sehingga tidak bergantung pada nama variabel minify lain. Patch diterapkan oleh `docs/patches/apply-wali-kelas-bundle.js`.

### 5.3 Dokumen

- `docs/specs/menu-kesiswaan-wali-kelas.md` (dokumen ini).
- `docs/patches/wali-kelas-kesiswaan.md` (**baru**, dibuat saat implementasi): daftar file yang diubah, hash sebelum dan sesudah, anchor F1–F4, langkah re-apply, dan checklist verifikasi pasca-update (§9.4). Dokumen ini juga menjadi lampiran usulan ke upstream BTIKP.

## 6. Di luar scope

- Kombinasi GURU BK + wali kelas. Menurut sekolah kasus ini tidak ada; aturan BK yang sudah ada tetap berlaku.
- Wali kelas menghapus insiden, serta tombol hapus insiden di UI.
- Perubahan grup menu lain (Umum & Ekskul, Akademik, Pusat Dashboard), Absensi Upacara, dan Dashboard Kelas Saya.
- Pembatasan transisi status insiden per peran di backend (§8).
- `save_journal`: tetap boleh menulis presensi/insiden semua guru. Ada catatan bahwa handler ini juga menyimpan `ALPHA` mentah ke enum; lihat §10.
- Endpoint siswa (`get_students`, `student_list`) dan `get_classes`, termasuk masalah PII tanpa auth di sana.
- Celah keamanan lain yang dicatat di `CLAUDE.md` (`register_guru`, upload, CSRF/metode HTTP, `session_regenerate_id`, dan sebagainya).
- Sidebar yang langsung mengikuti perubahan penugasan tanpa login ulang.
- Tahun ajaran hardcoded di endpoint lain (nilai, `save_incident` default, dan sebagainya), kecuali presensi manual.
- Rilis resmi upstream. Spesifikasi hanya menyiapkan dokumen usulan.

## 7. Compatibility

- **Konsumen API lain:** berdasarkan analisis bundle, `get_attendance_records` hanya dipanggil `vGe`, `get_incidents` hanya dipanggil `wp` dan dashboard kelas, dan `get_parent_calls` serta `update_incident_status` hanya dipanggil `wp`. `get_attendance_summary` tidak dipanggil. Penambahan auth tidak memengaruhi halaman lain, karena semua dipanggil dari SPA yang sudah login dengan cookie session same-origin.
- **KS dan BK** tidak kehilangan akses apa pun dibanding sekarang, kecuali KS kini ditolak menyimpan presensi. Penolakan ini selaras dengan UI yang memang read-only.
- **Data lama:** record presensi `2025/2026` tetap terbaca karena filter tahun diabaikan. Record dengan `status = ''` akibat bug lama tampil apa adanya (bukan Alpha) dan dapat dikoreksi oleh wali.
- **Frontend upstream:** anchor F1–F4 spesifik untuk bundle 1.0.2. Jika hash bundle berbeda, patch **tidak boleh** diterapkan otomatis (§9.4).
- **Auto-updater:** update dapat menimpa `api/*.php` dan `app.html`. File baru (`kesiswaan_access.php`, bundle `-wali1.js`) mungkin tetap ada, tetapi tidak lagi direferensikan. Kondisi setelah update tertimpa: menu kembali tersembunyi, dan backend kembali ke perilaku lama yang lebih longgar. Tidak ada kerusakan data.
- **PHP 8.2:** mysqli melempar exception. Validasi dilakukan **sebelum** `begin_transaction()`, dan penolakan memakai `errorResponse` eksplisit, bukan cabang `if (!$stmt->execute())`.

## 8. Edge cases

| Kasus | Perilaku yang diharapkan |
|---|---|
| `class_homeroom` terisi tetapi tidak ada baris `classes` yang cocok | Kunci kelas perwalian = `key(class_homeroom)` saja. Dropdown Presensi kosong dan data tidak termuat. Admin/TU perlu memperbaiki data. Tidak boleh error 500. |
| Id kelas ≠ nama kelas setelah normalisasi (mis. id `X-IPA-1`, nama `X MIPA 1`) | Backend cocok via id **atau** nama. Tombol proses di `wp` memakai `L$(class_homeroom)===L$(incident.class)` (nama), sehingga bisa tidak tampil. Dicatat sebagai keterbatasan UI (§10). |
| Wali dicabut atau dipindah kelas saat masih login | API langsung menolak atau mengikuti kelas baru. Menu masih tampil sampai login ulang; halaman tampil kosong atau error 403 yang tertangkap `console.error`. |
| User dihapus saat masih login | 401 di endpoint Kesiswaan. |
| Siswa pindah kelas tetapi record lama atas nama kelas lama | Presensi: validasi memakai `students.class_id` saat ini, sehingga wali lama tidak bisa menulis record siswa itu lagi. Insiden lama tetap mengikuti `incidents.className`. |
| Payload campuran (sebagian record kelas lain) dari wali | Seluruh request 403, tidak ada yang tersimpan. |
| Presensi tanggal sama sudah diisi dari Jurnal (`source='JURNAL'`) | Simpanan wali menimpa dan menjadi `MANUAL`. Jurnal berikutnya tidak menimpa `MANUAL` (perilaku lama). |
| Alpha → Hadir → Alpha di hari yang sama | Poin dikurangi lalu ditambah lagi. Insiden `inc_att_*` dihapus lalu dibuat ulang, tidak dobel. |
| Wali mengirim `semester`/`academic_year` di body | Diabaikan; memakai `system_config`. |
| `system_config` belum punya `active_academic_year` | Fallback `2025/2026`/`GANJIL` (sama dengan `save_journal`). |
| GURU non-wali memanggil API langsung | 403. |
| TATA_USAHA memanggil API langsung | 403. |
| Transisi status insiden | Backend hanya membatasi **kelas**, bukan transisi. Wali secara teknis bisa mengirim status apa pun untuk insiden kelasnya lewat API. Dicatat sebagai risiko (§10). |

## 9. Testing dan verifikasi end-to-end

Tidak ada test otomatis di bundle ini. Verifikasi dilakukan dengan `php -l` dan pengujian manual di **staging dengan data sekolah**.

### 9.1 Persiapan staging

1. Backup database staging (`mysqldump`) dan salin folder deploy sebelum patch.
2. Pastikan lisensi staging valid untuk host staging (`installed_url` harus sama dengan host), atau aktifkan `SIMANTAP_DEV_MODE` khusus staging. Pastikan ext `sodium` aktif bila tidak memakai dev mode.
3. Catat `SELECT @@GLOBAL.sql_mode;`. Uji minimal dengan mode yang sama seperti produksi; sebaiknya juga dengan `STRICT_TRANS_TABLES`.
4. Siapkan akun uji. Pilih kelas A dan B yang punya siswa aktif; minimal satu kelas sebaiknya punya id ≠ nama.
   - `ks` (KEPALA_SEKOLAH)
   - `admin` (ADMIN)
   - `tu` (TATA_USAHA)
   - `waliA` (GURU, `class_homeroom` = kelas A, `is_bk=0`)
   - `waliB` (GURU, kelas B)
   - `guru` (GURU, tanpa wali, `is_bk=0`)
   - `bk` (GURU, `is_bk=1`)
5. Pastikan `system_config.active_academic_year` dan `active_semester` terisi, misalnya `2026/2027` dan `GANJIL`.
6. Catat poin awal: `SELECT id, total_violation_points FROM students WHERE class_id IN ('<A>','<B>');`

### 9.2 Pemeriksaan statis

- `php -l` untuk `api/kesiswaan_access.php`, `api/endpoints_v2.php`, `api/endpoints_v3.php`, dan `api/index.php`.
- `grep` bahwa tidak ada `case` duplikat baru untuk action yang diubah.
- `sha256sum assets/index-CGWL69P7.js` sama dengan hash di §5.2, yang membuktikan file asli tidak berubah.
- Anchor F1–F4 **tidak lagi** ditemukan di `-wali1.js`, dan `app.html` mereferensikan `-wali1.js`.

### 9.3 Skenario end-to-end

Jalankan di browser, dengan cache dikosongkan setelah deploy. Cek API dari DevTools Console di tab yang sudah login dengan `fetch('api/index.php?action=…')`.

| # | Akun | Langkah | Hasil yang diharapkan |
|---|---|---|---|
| E1 | waliA | Login, buka sidebar | Grup Kesiswaan tampil dengan 5 item (tanpa Absensi Upacara). Grup "Kelas Saya" tetap ada. "Umum & Ekskul" tidak tampil. |
| E2 | guru | Login, buka sidebar | Tidak ada grup Kesiswaan (sama seperti sebelum patch). |
| E3 | bk | Login, buka sidebar | Menu sama persis dengan sebelum patch. |
| E4 | ks | Login, buka sidebar | Menu sama persis dengan sebelum patch. |
| E5 | waliA | Buka Presensi Kelas | Dropdown hanya berisi kelas A dan sudah terpilih. Tab Network tidak memuat request `classId=X-IPA-1` bila kelas A ≠ X-IPA-1. |
| E6 | waliA | Set 1 siswa Sakit, 1 Izin, 1 Alpha, sisanya Hadir, lalu Simpan | Alert sukses. Di DB, `attendance_records` untuk tanggal itu berisi status `SAKIT`/`IZIN`/`ALFA`, `academic_year`/`semester` = nilai `system_config`, `recorded_by` = uid waliA, `source='MANUAL'`. Insiden `inc_att_<siswa>_<tgl>` dibuat dan poin siswa Alpha bertambah sesuai `violation_types.ALFA`. |
| E7 | waliA | Reload halaman Presensi | Status tampil kembali; siswa Alpha tampil Alpha dan rekap menghitung 1 Alpha. |
| E8 | waliA | Ubah siswa Alpha menjadi Hadir, lalu Simpan | Insiden `inc_att_*` terhapus dan poin kembali ke nilai awal (§9.1 langkah 6). |
| E9 | waliA | Buat siswa mencapai ambang `violation_threshold_parentcall` via Alpha | Baris `parent_calls` AUTO dibuat dan tampil di halaman Panggilan Ortu waliA. |
| E10 | waliA | Console: `get_attendance_records&classId=<B>&date=<tgl>` | HTTP 403. |
| E11 | waliA | Console: POST `save_attendance_records` dengan satu record kelas A dan satu record siswa kelas B | HTTP 403. Tidak ada perubahan di DB untuk kedua record. |
| E12 | waliA | Console: POST `save_attendance_records` dengan record `class_id=<A>` tetapi `student_id` siswa kelas B | HTTP 403. |
| E13 | waliA | Buka Pelanggaran, Terlambat, dan Izin Keluar | Daftar hanya berisi insiden kelas A. Dropdown kelas di form tambah hanya kelas A. |
| E14 | waliA | Tambah pelanggaran siswa kelas A, lalu proses "Terima & Proses (Wali Kelas)" | Tersimpan; status dan log berubah dengan actor waliA. |
| E15 | waliA | Console: `update_incident_status` untuk id insiden kelas B | HTTP 403; status insiden tidak berubah. |
| E16 | waliA | Console: `get_incidents` tanpa parameter, lalu `get_incidents&class=<B>` | Pertama hanya kelas A; kedua 403. |
| E17 | waliA | Buka "Dashboard <kelas A>" | Tetap berfungsi seperti sebelum patch, termasuk daftar insiden. |
| E18 | waliA | Buka Panggilan Ortu, buat panggilan manual siswa kelas A, lalu ubah status | Berhasil. Console: `update_parent_call` id panggilan kelas B menghasilkan 403. |
| E19 | waliA | Console: `delete_incident&id=<insiden kelas A>` | HTTP 403. |
| E20 | ks | Buka Presensi Kelas kelas mana pun | Data tampil dan perubahan ditolak di UI. Console: POST `save_attendance_records` menghasilkan 403. |
| E21 | ks, bk | Buka Pelanggaran dan Panggilan Ortu | Insiden/panggilan semua kelas tampil dan bisa diproses seperti sebelum patch. |
| E22 | guru, tu | Console: `get_attendance_records`, `get_incidents`, `get_parent_calls` | Semua HTTP 403. |
| E23 | (logout) | `curl` ketiga endpoint baca tanpa cookie | Semua HTTP 401. |
| E24 | admin | Di Kelola Guru, pindahkan waliA ke kelas B. Tanpa logout, waliA memanggil `get_attendance_records&classId=<A>` | 403; kelas B diizinkan. Setelah login ulang, dropdown Presensi berisi kelas B. |
| E25 | waliA | Jurnal mengajar di kelas A dengan presensi (alur jurnal lama) | Tetap berhasil dan tidak menimpa record `MANUAL` dari E6 (perilaku lama tidak regresi). |
| E26 | waliB | Ulangi E5–E7 di kelas yang id ≠ nama (bila tersedia) | Backend lolos. Catat apakah tombol proses insiden tampil di `wp` (keterbatasan §8). |

### 9.4 Checklist pasca auto-update (masuk ke `docs/patches/wali-kelas-kesiswaan.md`)

1. Periksa `api/version.php` dan hash `assets/index-*.js` yang direferensikan `app.html`.
2. Jika versi atau hash berubah, cek dulu apakah upstream sudah memuat perbaikan resmi (jalankan E1, E6, E7, E10). Jika belum, **jangan** menempel patch lama begitu saja: cari ulang anchor F1–F4 dan pastikan masing-masing muncul tepat 1 kali sebelum diterapkan.
3. Terapkan ulang backend dengan membandingkan handler baru terhadap §3.3, lalu jalankan `php -l`.
4. Jalankan subset regresi: E1, E2, E6, E7, E10, E11, E20, E22, E23.

### 9.5 Rollback

Kembalikan referensi di `app.html` ke `index-CGWL69P7.js`, pulihkan file `api/*.php` dari salinan sebelum patch, dan hapus `api/kesiswaan_access.php`. Tidak ada perubahan skema. Data presensi yang dibuat selama patch aktif tetap valid.

## 10. Risiko dan trade-off

| Risiko / trade-off | Dampak | Mitigasi |
|---|---|---|
| Patch pada JS hasil minify | Mudah salah; tidak ada source map atau build | Anchor unik + hash, salinan file terpisah, E1–E5 wajib, usulan ke upstream |
| Auto-updater menimpa patch | Menu hilang lagi, backend kembali longgar tanpa pemberitahuan | Checklist §9.4, dokumen patch, eskalasi ke BTIKP |
| Menambah auth di endpoint GET yang sebelumnya publik | Integrasi eksternal tak dikenal (jika ada) akan 401 | Analisis bundle tidak menemukan pemakai lain; dipantau di staging |
| Query DB tambahan per request (fresh user + kelas) | Beban kecil | Satu query user + satu query kelas, memakai primary key/indeks |
| Transisi status insiden tidak dibatasi per peran di backend | Wali bisa set status BK/KS untuk insiden kelasnya lewat API | Di luar scope; usulkan state machine ke upstream |
| Tombol proses `wp` membandingkan `class_homeroom` dengan nama kelas | Wali mungkin tidak melihat tombol bila id ≠ nama | Rekomendasikan penyeragaman id/nama kelas; usulkan perbaikan ke upstream |
| Menu mengikuti session, API mengikuti DB | Tampilan sementara tidak konsisten setelah penugasan berubah | Informasikan ke admin: minta guru login ulang |
| `save_journal` juga menyimpan `ALPHA` mentah ke enum `ALFA` | Pada strict mode, jurnal dengan Alpha bisa gagal simpan | Di luar scope; cek di staging dan buat tiket terpisah bila terjadi |
| `delete_incident` untuk ADMIN/KS/BK adalah asumsi, dan penghapusan tidak mengembalikan poin | Kebijakan mungkin berbeda dari keinginan sekolah | Konfirmasi ke sekolah sebelum rilis; UI hapus tidak ada sehingga dampak praktis kecil |
| Presensi manual kini memakai tahun ajaran aktif | Rekap yang memfilter `2025/2026` tidak lagi memuat data baru | Memang perilaku yang benar; pastikan `system_config` diperbarui tiap pergantian tahun ajaran |

## 11. Acceptance criteria

- **AC-1** GURU non-BK dengan `class_homeroom` terisi melihat grup Kesiswaan (Presensi Kelas, Siswa Terlambat, Izin Keluar, Panggilan Ortu, Pelanggaran) setelah login. Menu KEPALA_SEKOLAH, GURU BK, GURU non-wali, TATA_USAHA, dan ADMIN tidak berubah.
- **AC-2** Di Presensi Kelas, wali hanya dapat memilih kelas perwaliannya, dan kelas itu terpilih otomatis.
- **AC-3** Wali dapat menyimpan presensi harian kelasnya dengan status Hadir/Sakit/Izin/Alpha, dan status tampil kembali dengan benar setelah reload.
- **AC-4** Status Alpha tersimpan sebagai `ALFA`, memicu insiden dan poin ALFA serta panggilan ortu otomatis sesuai ambang, dan koreksi Alpha → status lain mengembalikan poin.
- **AC-5** Presensi manual tercatat dengan `active_academic_year` dan `active_semester` dari `system_config`, bukan nilai dari body.
- **AC-6** Semua endpoint di §3.2 mengembalikan 401 tanpa login dan menegakkan matriks akses. Wali mendapat 403 untuk kelas lain, termasuk payload campuran, dan tidak ada data yang tersimpan sebagian.
- **AC-7** `get_incidents` dan `get_parent_calls` untuk wali hanya mengembalikan data kelas perwaliannya. Wali dapat membuat dan memproses insiden serta panggilan ortu kelasnya, tetapi tidak dapat menghapus insiden.
- **AC-8** KEPALA_SEKOLAH tetap bisa melihat semua presensi, insiden, dan panggilan ortu serta memproses insiden, tetapi mendapat 403 saat menyimpan presensi.
- **AC-9** Perubahan `users.class_homeroom` langsung berlaku pada otorisasi API tanpa login ulang.
- **AC-10** Dashboard Kelas Saya dan alur presensi dari Jurnal tidak mengalami regresi (E17, E25).
- **AC-11** Tidak ada perubahan skema. File bundle asli tidak dimodifikasi (hash sama). Semua file PHP lolos `php -l`.
- **AC-12** `docs/patches/wali-kelas-kesiswaan.md` tersedia berisi hash, anchor, langkah re-apply, checklist §9.4, dan rollback §9.5.
- **AC-13** Skenario E1–E25 lulus di staging, dan hasil E26 tercatat.
