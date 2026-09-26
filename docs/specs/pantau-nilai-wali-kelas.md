# Spesifikasi: Pantau Nilai Kelas untuk Wali Kelas dan Guru BK

| | |
|---|---|
| Status | Diimplementasikan (patch lokal) — lihat `docs/patches/pantau-nilai-wali-kelas.md` |
| Versi bundle acuan | SIMANTAP 1.0.2 (`api/version.php`) + patch lokal terakhir `assets/index-CGWL69P7-tanpapresensi1.js` |
| Tanggal | 2026-09-26 |
| Jalur rilis | Patch lokal pada bundle ini **dan** usulan perubahan ke upstream BTIKP (pola sama dengan patch wali kelas & rekap bulanan) |
| Spesifikasi terkait | `docs/specs/menu-kesiswaan-wali-kelas.md` (definisi wali kelas, helper scope), `docs/specs/rekap-bulanan-wali-kelas.md` (pola halaman HTML siap cetak), `docs/specs/penugasan-mapel-mandiri-guru.md` (`teacher_subjects`) |

## 1. Latar belakang dan kebutuhan bisnis

Guru mapel mengisi nilai tugas/kuis per pertemuan di jurnal mengajar dan nilai ujian (UTS/UAS) di halaman Penilaian Siswa. Sejak commit `43dd2d0`, nilai hanya bisa dibuka oleh guru pengampu mapel+kelas itu (serta ADMIN/KS/TU). **Wali kelas tidak bisa melihat nilai mapel guru lain di kelas perwaliannya**, sehingga tidak bisa memantau siswa yang tertinggal.

Tujuan fitur:

1. **Deteksi tugas/kuis tertinggal:** wali kelas melihat siswa yang tidak punya nilai pada pertemuan yang sudah dinilai guru mapel.
2. **Deteksi nilai di bawah KKM** per mapel.
3. **Bahan panggilan orang tua:** wali kelas bisa mencetak laporan nilai per siswa (A4 ber-Kop) untuk dibahas bersama orang tua.

Guru BK mendapat tampilan yang sama untuk semua kelas, sebagai bahan pendampingan siswa.

### 1.1 Keputusan wawancara

| Topik | Keputusan |
|---|---|
| Kedalaman akses wali | Rekap lintas mapel **+ detail per pertemuan** dan nilai ujian. **Hanya baca**; tidak bisa mengubah nilai |
| Akses siswa/orang tua | **Tahap 2, spesifikasi terpisah.** Tidak termasuk scope ini (§9) |
| Guru BK | Semua kelas, lewat menu baru di bagian "Bimbingan & Konseling" dengan pemilih kelas |
| KEPALA_SEKOLAH / ADMIN | Endpoint baru mengizinkan semua kelas (mereka sudah punya akses nilai penuh). Tanpa menu UI baru |
| TATA_USAHA | Ditolak di endpoint baru. Akses TU ke endpoint nilai lama tidak diubah |
| Definisi "tertinggal" | Pada satu pertemuan, jenis nilai (tugas/kuis) dianggap diberikan jika **minimal satu siswa** punya nilai itu. Siswa aktif yang nilainya kosong = tertinggal. Status presensi (H/S/I/A) ditampilkan sebagai keterangan, **tidak** mengecualikan |
| Penanda "perlu perhatian" | ≥ 1 tugas/kuis tertinggal **atau** ≥ 1 mapel di bawah KKM |
| Daftar mapel | Gabungan mapel yang diampu di kelas itu (`teacher_subjects`) + mapel yang punya data penilaian di semester itu. Mapel tanpa data tampil "Belum ada penilaian" |
| Catatan guru per siswa | Ditampilkan ke wali/BK dan ikut tercetak |
| Periode | Semester Ganjil/Genap pada **tahun ajaran aktif** saja |
| Lokasi UI wali | Tab baru **"📝 Nilai"** di Dashboard Kelas Saya |
| Cetak | Laporan per siswa: tabel nilai semua mapel, daftar tertinggal, ringkasan presensi semester, TTD Orang Tua/Wali dan Wali Kelas. A4 ber-Kop |
| Pengujian | Skrip uji API `.mjs` + `php -l` |

## 2. Perilaku saat ini (hasil analisis kode)

### 2.1 Penyimpanan nilai

- **Nilai harian** ada di `journal_entries.assessment_data` (JSON, kolom `utf8mb4_bin` dengan `CHECK json_valid`). Isinya array `[{student_id, student_name, tugas_score, kuis_score, notes}]`. Jurnal dengan penilaian ditandai `has_assessment = 1`. Jurnal draf (`isDraft = 1`) tidak dihitung.
- **Presensi per pertemuan** ada di `journal_entries.studentAttendance` (JSON). Formatnya tidak seragam:
  - map `{ "<student_id>": "HADIR" }` (dipakai `get_journals`, `api/index.php:447-450`);
  - array `[{studentId|student_id, status}]` (diasumsikan `get_daily_grades`, `api/index.php:1880-1885`).
  - Endpoint baru wajib mendukung **keduanya**.
- **Nilai ujian** ada di tabel `ujian_scores`, dengan kunci unik `(student_id, class_id, subject_id, academic_year, semester, ujian_type)`. `ujian_type` ∈ `UTS`, `UAS`, `SUMATIF_AKHIR` (disamakan dengan UAS).
- **Bobot dan KKM** ada di `system_config`: `bobot_tugas` (30), `bobot_kuis` (30), `bobot_ujian` (40), `kkm_default` (75). KKM per mapel belum ada.
- **Kolom kelas/mapel:** `journal_entries.classId`, `ujian_scores.class_id`, dan `students.class_id` berisi `classes.id` (mis. `X_AKT_1`). `subjectId`/`subject_id` berisi `subjects.id` (= `teacher_subjects.subject_code`).

### 2.2 Endpoint nilai yang ada (`api/index.php`)

| Action | Baris | Isi |
|---|---|---|
| `get_student_grades` | 1697 | Rekap semester **satu** kelas+mapel: rata-rata tugas/kuis, UTS/UAS, nilai akhir, status `TUNTAS`/`REMEDI`/`BELUM_LENGKAP`, plus `journal_assessments` mentah |
| `get_daily_grades` | 1849 | Nilai satu pertemuan (satu tanggal) |
| `get_monthly_grades` | 1918 | Agregasi per bulan |
| `save_ujian_score` | 1999 | Simpan UTS/UAS massal |
| `get_ujian_scores` | 2047 | Baris mentah `ujian_scores` |

Rumus nilai akhir di `get_student_grades` (§4.4 wajib identik):

- `ujian_combined` = rata-rata UTS dan UAS jika keduanya ada, jika tidak pakai yang tersedia;
- `final_score` = `avg_tugas·bt + avg_kuis·bk + ujian_combined·bu` (nilai null dihitung 0), dibulatkan 1 desimal, **hanya jika `ujian_combined` ada**.

### 2.3 Otorisasi saat ini

- `requireGradeAccess()` (`api/db.php:157`): role `GURU` hanya boleh membuka mapel+kelas yang cocok di `teacher_subjects` (`teacherTeachesClassSubject`, `api/db.php:128`). Role lain lolos tanpa batas. Data role dibaca dari **session**.
- Wali kelas (`users.class_homeroom`) dan guru BK (`users.is_bk = 1`) tidak punya pengecualian.
- Helper wali kelas di `api/kesiswaan_access.php`:
  - `loadFreshUser` membaca ulang user dari DB;
  - `homeroomClassScope` menghasilkan `['mode'=>'CLASS','classKeys'=>…,'classId'=>classes.id]`;
  - `resolveClassRef`, `normalizeClassKey`, `classInScope`, `activeAcademicPeriod`.
  - Matriks `kesiswaanScope` **tidak cocok** untuk fitur ini (BK hanya dapat insiden/panggilan ortu), jadi dibuat helper scope baru (§4.1).

### 2.4 Frontend (`assets/index-CGWL69P7-tanpapresensi1.js`)

- **Dashboard Kelas Saya** = komponen `function dot({user:e,onNavigate:t,activeSemester:n})`. Isinya:
  - tab `[{id:"overview",label:"📊 Rekap Bulanan"},{id:"students",label:"👥 Per Siswa"},{id:"incidents",label:"⚠️ Insiden"}]`;
  - toggle semester yang mengirim `semester=<1|2>`;
  - tombol "Cetak Rekap Bulanan" yang membuka tab baru ke `cetak_rekap_bulanan`.
- **Sidebar BK:** bagian "Bimbingan & Konseling" berisi satu item `Ox` dengan label "Insiden BK", `active:t==="bk-incidents"`, `onClick:()=>n("bk-incidents")`.
- **Halaman Penilaian Siswa** memanggil `get_student_grades&class_id&subject_id&semester=<GANJIL|GENAP>`. Halaman ini **tidak diubah**.

### 2.5 Siswa dan orang tua

Role `SISWA` ada di enum `users.role`, tetapi tidak dipakai. `students` tidak punya kredensial, hanya `parent_name` dan `parent_phone`. Belum ada jalur akses sama sekali.

## 3. Expected behavior

### 3.1 Alur wali kelas

1. Wali membuka **Kelas Saya → Dashboard <kelas>** dan memilih tab baru **"📝 Nilai"**. Semester mengikuti toggle yang sudah ada.
2. Tab memuat `get_class_grade_overview&class_id=<class_homeroom>&semester=<1|2>` dan menampilkan:
   - **Kartu ringkasan:** jumlah siswa, jumlah siswa perlu perhatian, total tugas/kuis tertinggal, dan jumlah mapel yang belum ada penilaian.
   - **Daftar mapel** (chip/baris): nama mapel, guru pengampu, jumlah pertemuan bernilai (tugas/kuis), ada/tidaknya nilai ujian. Mapel tanpa data diberi label "Belum ada penilaian".
   - **Tabel siswa × mapel:**
     - satu baris per siswa aktif;
     - satu kolom per mapel yang menampilkan nilai acuan (§4.4) dan badge jumlah tertinggal;
     - kolom "Tertinggal" (total), kolom "< KKM" (jumlah mapel), dan penanda **"Perlu perhatian"**;
     - bisa diurutkan per kolom; default: perlu perhatian dulu, lalu total tertinggal menurun, lalu nama;
     - filter cepat "Hanya yang perlu perhatian";
     - sel di bawah KKM berwarna merah, sel `BELUM_LENGKAP` abu-abu.
3. Klik header/nama mapel membuka **detail mapel**: `get_class_subject_grade_detail&class_id&subject_id&semester`. Tampilannya:
   - matriks siswa × pertemuan, dengan header tanggal, topik, dan guru;
   - sel berisi nilai tugas/kuis, sel tertinggal ditandai merah "–" dengan status presensi;
   - catatan guru muncul saat sel diklik (atau lewat `title`);
   - kolom UTS, UAS, dan nilai akhir;
   - tombol kembali ke rekap.
4. Klik nama siswa membuka **panel siswa** (dari data overview): nilai per mapel dan daftar tertinggal (tanggal, mapel, topik, jenis, presensi, catatan). Panel ini punya tombol **"Cetak Laporan Nilai"** yang membuka tab baru ke `api/index.php?action=cetak_rekap_nilai_siswa&student_id=<id>&semester=<1|2>`.
5. Tidak ada tombol simpan atau edit di mana pun di tampilan ini.

### 3.2 Alur guru BK

1. Di sidebar, bagian "Bimbingan & Konseling", ada item baru **"Pantau Nilai Kelas"** (`id: "bk-grades"`) di bawah "Insiden BK". Item ini hanya tampil untuk `is_bk = 1`.
2. Halaman berisi dropdown kelas (dari `get_classes`) dan toggle semester (default `active_semester`), lalu komponen yang sama dengan tab wali (§3.1 langkah 2–4).
3. Jika BK juga wali kelas, dropdown default ke kelas perwaliannya.

### 3.3 KEPALA_SEKOLAH dan ADMIN

Mereka bisa memanggil ketiga endpoint untuk kelas mana pun lewat URL/API. Tidak ada menu baru.

## 4. Kontrak backend

Semua kode baru ada di file baru **`api/pantau_nilai.php`**, diawali guard `if (!defined('SIMANTAP_API')) { http_response_code(403); exit('Forbidden'); }` dan di-`require_once` di `api/index.php` setelah `rekap_bulanan.php` (baris 41). Tiga `case` baru ditambahkan di `switch` `api/index.php`, masing-masing hanya memanggil fungsi handler. Nama action sudah dicek belum dipakai di keempat file endpoint.

### 4.1 Scope akses: `pantauNilaiScope(mysqli $db, array $sessionUser): array`

Hasilnya berformat sama dengan `homeroomClassScope`: `['mode' => 'ALL'|'CLASS'|'DENY', 'classKeys' => [...], 'classId' => ?string]`. Data user **dibaca ulang dari DB** dengan `loadFreshUser`.

| Kondisi (user segar dari DB) | Mode |
|---|---|
| `ADMIN`, `KEPALA_SEKOLAH` | `ALL` |
| `GURU` dengan `is_bk = 1` (walaupun juga wali) | `ALL` |
| `GURU` non-BK dengan `class_homeroom` terisi | `CLASS` → `homeroomClassScope($db, class_homeroom)` |
| `GURU` lain, `TATA_USAHA`, role lain | `DENY` |
| User tidak ada di DB | 401 (dari `loadFreshUser`) |

Pengecekan kelas memakai `classInScope($scope, $classId)` yang sudah ada. Pesan 403:
- `DENY` → "Anda tidak memiliki akses ke pemantauan nilai kelas."
- kelas di luar scope → `KESISWAAN_MSG_KELAS` ("Anda hanya dapat mengakses data kelas perwalian Anda.").

### 4.2 Parameter bersama

| Parameter | Aturan |
|---|---|
| Metode | Hanya `GET`. Metode lain → 405 "Metode tidak diizinkan" |
| `class_id` | Wajib, kecuali untuk wali `CLASS` (default `scope['classId']`). Diselesaikan dengan `resolveClassRef` ke `classes.id`; semua query memakai id hasil resolusi. Kosong untuk `ALL` → 400 |
| `semester` | `1`/`GANJIL` → `GANJIL`, `2`/`GENAP` → `GENAP`, kosong → `active_semester`. Nilai lain → 400 |
| Tahun ajaran | Selalu `active_academic_year` (`activeAcademicPeriod`). Parameter `academic_year` **diabaikan** |
| Rentang tanggal | `getSemesterDateRange(academicYear, semester)`. Jika format TA tidak dikenali (`null`), filter tanggal dilewati, sama seperti `get_student_grades` |

### 4.3 Sumber data per kelas + semester

- **Siswa:** `getActiveClassStudents($db, $classId)`, yaitu siswa aktif dan terurut nama. Siswa nonaktif atau pindah **tidak** ditampilkan, termasuk nilai lamanya (konsisten dengan halaman Penilaian).
- **Jurnal:**
  ```sql
  SELECT id, date, topic, subjectId, subjectName, teacherId, teacherName, assessment_data, studentAttendance
  FROM journal_entries
  WHERE classId = ? AND has_assessment = 1 AND isDraft = 0 [AND date BETWEEN ? AND ?]
  ORDER BY date ASC, createdAt ASC
  ```
  Satu query untuk semua mapel. `classId` dicocokkan **persis** (sama dengan `get_student_grades`) agar angka wali identik dengan angka guru mapel.
- **Ujian:** `SELECT student_id, subject_id, ujian_type, score FROM ujian_scores WHERE class_id = ? AND academic_year = ? AND semester = ?`.
- **Penugasan:** fungsi baru `classSubjectAssignments($db, $classId)` memindai `teacher_subjects` dengan aturan yang **sama** seperti `teacherTeachesClassSubject`: `class_ids` diutamakan; jika kosong, dicocokkan lewat `class_level` (prefiks `<level>_` atau `classes.level`). Hasilnya `subject_code => [teacher_id, …]`. Nama guru diambil dari `users.displayName`.
- **Daftar mapel** = gabungan kode dari penugasan, jurnal, dan ujian. Nama diambil dari `subjects.name`; jika tidak ada, pakai `journal_entries.subjectName` terakhir, dan jika itu pun tidak ada, pakai kode. Urutan mengikuti `subjects.sort_order`, lalu nama. Guru mapel = gabungan guru dari penugasan dan `teacherName` jurnal (unik). Jika satu mapel diajar beberapa guru di kelas yang sama, semuanya digabung dalam **satu** mapel.
- **Bobot dan KKM:** dibaca dengan query dan default yang sama seperti `get_student_grades`.

### 4.4 Aturan hitung (per siswa × mapel)

Untuk setiap jurnal J (mapel M):

- `J.has_tugas` = ada ≥ 1 entri `assessment_data` dengan `tugas_score` tidak null/tidak `''`. `J.has_kuis` dihitung dengan cara yang sama.
- Entri `assessment_data` dicari lewat `student_id`. Siswa aktif tanpa entri dianggap nilainya kosong.
- **Tertinggal tugas** = `J.has_tugas` dan nilai tugas siswa kosong. Tertinggal kuis dihitung dengan cara yang sama. Satu pertemuan bisa menghasilkan dua butir tertinggal (tugas dan kuis).
- `assessment_data` yang gagal di-decode atau bukan array dianggap `[]`. Jurnal seperti itu tidak menghasilkan tertinggal, karena `has_tugas` dan `has_kuis` bernilai false.
- **Presensi pertemuan:** status siswa dari `studentAttendance` (kedua format §2.1), dinormalisasi dengan helper lokal `pantauNilaiAttendanceStatus`. `normalizeAttendanceStatus` tidak dipakai karena membalas JSON 400 untuk status asing. Jika siswa tidak tercatat → `null`, tampil "–". Status ini hanya keterangan dan **tidak** mengubah hitungan.

Agregat per siswa × mapel:

- `avg_tugas`, `avg_kuis`, `tugas_count`, `kuis_count`, `ujian_uts`, `ujian_uas` (UAS atau SUMATIF_AKHIR), `ujian_combined`, `final_score`, dan `status`. Semuanya **identik** dengan `get_student_grades` untuk kelas+mapel+semester yang sama (acceptance criterion AC-3).
- `missing_tugas`, `missing_kuis`: jumlah butir tertinggal.
- **Nilai acuan** (`ref_score`):
  - `final_score` jika ada;
  - jika tidak, rata-rata dari `avg_tugas` dan `avg_kuis` yang tersedia, dibulatkan 1 desimal, dengan `ref_is_provisional = true`;
  - jika tidak ada keduanya, `null`.
- `below_kkm` = `ref_score !== null && ref_score < kkm`.

Agregat per siswa:

- `total_missing` = Σ (`missing_tugas` + `missing_kuis`);
- `below_kkm_count` = jumlah mapel dengan `below_kkm`;
- `needs_attention` = `total_missing > 0 || below_kkm_count > 0`.

### 4.5 `get_class_grade_overview` (GET, JSON)

**Respons 200:**

```json
{
  "class": {"id": "X_AKT_1", "name": "X AKT 1"},
  "academic_year": "2026/2027", "semester": "GANJIL",
  "date_range": ["2026-07-01", "2026-12-31"],
  "weights": {"bobot_tugas": 30, "bobot_kuis": 30, "bobot_ujian": 40, "kkm_default": 75},
  "kkm": 75,
  "subjects": [
    {"subject_id": "MTK", "subject_name": "Matematika",
     "teachers": [{"uid": "u_1", "name": "Budi, S.Pd"}],
     "tugas_meetings": 6, "kuis_meetings": 3, "has_ujian": true,
     "status": "ADA_DATA"}
  ],
  "students": [
    {"student_id": "s_1", "student_name": "Ani", "nisn": "00123", "pas_foto": null,
     "total_missing": 2, "below_kkm_count": 1, "needs_attention": true,
     "grades": {
       "MTK": {"avg_tugas": 70.5, "avg_kuis": 80, "tugas_count": 5, "kuis_count": 3,
               "missing_tugas": 1, "missing_kuis": 0,
               "ujian_uts": 60, "ujian_uas": null, "ujian_combined": 60,
               "final_score": 68.2, "status": "REMEDI",
               "ref_score": 68.2, "ref_is_provisional": false, "below_kkm": true}
     },
     "missing": [
       {"journal_id": "j_…", "date": "2026-08-12", "subject_id": "MTK", "subject_name": "Matematika",
        "teacher_name": "Budi, S.Pd", "topic": "Persamaan linear", "type": "TUGAS",
        "attendance_status": "SAKIT", "notes": ""}
     ]
    }
  ],
  "summary": {"student_count": 32, "needs_attention_count": 7, "total_missing": 19, "subjects_without_data": 2}
}
```

Keterangan:
- `subjects[].status`: `ADA_DATA`, atau `BELUM_ADA_PENILAIAN` jika tidak ada jurnal ber-penilaian maupun nilai ujian.
- `grades` berisi **semua** mapel di `subjects` untuk setiap siswa. Mapel tanpa data berisi null/0 dengan `status: "BELUM_LENGKAP"`.
- `missing` diurutkan per tanggal, lalu nama mapel.
- Angka dikirim sebagai number, bukan string.
- Kelas tanpa siswa aktif → `students: []`, tetap 200.

### 4.6 `get_class_subject_grade_detail` (GET, JSON)

Parameter tambahan: `subject_id` (wajib, tidak kosong, maks. 100 karakter; jika tidak → 400). Mapel yang tidak ada di daftar mapel kelas tetap dijawab 200 dengan data kosong, bukan 404.

```json
{
  "class": {...}, "academic_year": "...", "semester": "...",
  "subject": {"subject_id": "MTK", "subject_name": "Matematika", "teachers": [...]},
  "kkm": 75,
  "meetings": [
    {"journal_id": "j_…", "date": "2026-08-12", "topic": "…", "teacher_name": "…",
     "has_tugas": true, "has_kuis": false}
  ],
  "students": [
    {"student_id": "s_1", "student_name": "Ani",
     "cells": [
       {"journal_id": "j_…", "tugas_score": null, "kuis_score": null,
        "missing_tugas": true, "missing_kuis": false,
        "attendance_status": "SAKIT", "notes": ""}
     ],
     "summary": { /* sama dengan objek grades[subject_id] di overview */ }
    }
  ]
}
```

`cells` berurutan sama dengan `meetings`.

### 4.7 `cetak_rekap_nilai_siswa` (GET, HTML siap cetak)

Pola dan helper mengikuti `api/rekap_bulanan.php`:
- `rekapSendHeaders`, `rekapHtmlError`, `rekapEsc`, `rekapLoadKop`, `rekapAssetUrl`, `rekapTanggalIndo`;
- tidak memakai `requireAuth()`/`errorResponse()` agar error tetap berupa HTML;
- header `Content-Type: text/html; charset=utf-8`, `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`.

**Parameter:**

| Parameter | Aturan |
|---|---|
| `student_id` | Wajib. Siswa harus ada dan **aktif** (aturan sama dengan `getActiveClassStudents`); jika tidak → 404 "Siswa tidak ditemukan atau tidak aktif." Kelas siswa = `students.class_id` saat ini, dan harus lolos `classInScope`; jika tidak → 403 |
| `semester` | Seperti §4.2 |
| `kota` | Opsional, trim, maks. 50 karakter. Default `system_config.kop_kota`, atau kosong |
| `tanggal` | Opsional, `YYYY-MM-DD` valid; jika tidak → 400. Default hari ini (`APP_TIMEZONE`) |

**Error HTML:** anonim → 401 "Sesi Anda telah berakhir. Silakan login ulang."; `DENY` → 403; metode bukan GET → 405.

**Isi dokumen (A4 portrait, margin dan aturan cetak sama dengan rekap bulanan):**

1. **Panel kontrol** (`no-print`): isian kota dan tanggal, tombol "Terapkan" (submit GET), tombol "Cetak / Simpan PDF" (`window.print()`), petunjuk A4 dan mematikan header/footer, dan peringatan jika kop kosong.
2. **Kop sekolah**, sama persis dengan rekap bulanan (logo, teks, garis ganda).
3. **Judul:** **LAPORAN PERKEMBANGAN NILAI PESERTA DIDIK**.
4. **Identitas:** Nama, NISN, Kelas (`classes.name`), Wali Kelas, Tahun Ajaran / Semester, "Data s.d. tanggal <hari ini>".
   - Wali kelas = user dengan `class_homeroom` yang cocok dengan kelas (pencocokan kunci seperti `get_homeroom_info`). Jika tidak ada, tampil "-".
5. **A. Nilai per Mata Pelajaran:** kolom No, Mata Pelajaran, Guru, Rata-rata Tugas (n), Rata-rata Kuis (n), UTS, UAS, Nilai Akhir, KKM, Keterangan.
   - Keterangan: "Tuntas"/"Remedi"/"Belum lengkap", ditambah "(sementara)" bila `ref_is_provisional`, dan "Belum ada penilaian" untuk mapel tanpa data.
   - Nilai kosong ditulis "-".
   - Catatan kaki bobot: "Nilai akhir = Tugas 30% + Kuis 30% + Ujian 40%; dihitung bila nilai ujian sudah ada."
6. **B. Tugas/Kuis yang Belum Ada Nilainya:** kolom No, Tanggal, Mata Pelajaran, Topik, Jenis, Presensi, Catatan Guru. Jika kosong, satu baris "Nihil".
7. **C. Ringkasan Presensi Semester:** jumlah Hadir/Sakit/Izin/Alfa dari `attendance_records`.
   - Filter: `student_id = ?` dan `date` dalam rentang semester.
   - Kolom `semester`/`academic_year` **tidak** dipakai karena data lama bisa berisi `2025/2026` hardcoded.
   - Status dinormalisasi dengan `pantauNilaiAttendanceStatus`.
8. **Tanda tangan:**
   - kiri: "Mengetahui, Orang Tua/Wali" dengan garis nama kosong;
   - kanan: "<kota>, <tanggal>", lalu "Wali Kelas", nama, dan "NIP. …" (jika ada; kosongkan baris NIP jika tidak ada).
9. **Judul tab/nama file:** `Laporan_Nilai_<Nama>_<Kelas>_<GANJIL|GENAP>`, dengan karakter di luar `[A-Za-z0-9_-]` diganti `_`.

Semua teks di-escape dengan `rekapEsc`, termasuk catatan guru, topik, dan nama.

### 4.8 Tidak berubah

- `requireGradeAccess`, `get_student_grades`, `get_daily_grades`, `get_monthly_grades`, `get_ujian_scores`, dan `save_ujian_score` **tidak diubah**. Wali kelas tetap **tidak** bisa membuka halaman Penilaian untuk mapel yang tidak diampunya, dan tidak bisa menyimpan nilai ujian mapel lain.
- **Tidak ada perubahan skema** dan tidak ada key `system_config` baru.

## 5. Kontrak frontend (patch bundle)

Patch berupa skrip `docs/patches/apply-pantau-nilai-bundle.js`. Skrip ini membaca `assets/index-CGWL69P7-tanpapresensi1.js`, menulis `assets/index-CGWL69P7-pantaunilai1.js`, lalu mengganti rujukan di `app.html`. Aturan patch:

- Setiap anchor string harus ditemukan **tepat sekali**. Jika tidak, skrip berhenti dengan error.
- Hanya memakai kelas Tailwind yang sudah ada di `assets/index-CC1PBzaO.css`. Warna status memakai kelas yang sudah dipakai bundle, mis. `bg-red-50 text-red-700`, `bg-emerald-50 text-emerald-700`, `bg-slate-100 text-slate-500`.
- Komponen baru ditulis dengan `a.jsx`/`a.jsxs` dan `B.useState`/`B.useEffect` seperti komponen di sekitarnya.

**Perubahan:**

1. **Komponen baru `PantauNilaiKelas`** (nama variabel unik, mis. `__pnk`), dengan props `{classId, semester, showClassPicker, defaultClassId}`. Isinya:
   - memuat overview dan menampilkan kartu, daftar mapel, tabel, filter, dan urutan (§3.1);
   - view detail mapel dan panel siswa;
   - tombol cetak yang membuka `window.open(\`${API}?action=cetak_rekap_nilai_siswa&student_id=…&semester=…\`, '_blank')`;
   - menampilkan pesan error dari JSON `error` (mis. 403) di dalam kartu, bukan `alert`;
   - status loading dan kosong: "Belum ada siswa aktif di kelas ini." / "Belum ada penilaian pada semester ini."
2. **Dashboard Kelas Saya (`dot`):** tambah `{id:"grades",label:"📝 Nilai"}` ke array tab dan render `PantauNilaiKelas` saat tab aktif, dengan `classId = class_homeroom` dan `semester` dari toggle `1|2`.
3. **Sidebar:** tambah `Ox` "Pantau Nilai Kelas" (`active:t==="bk-grades"`, `onClick:()=>n("bk-grades")`) tepat setelah item "Insiden BK", di blok yang sama (sudah dibatasi `is_bk`).
   - Blok BK itu dulu dirender di dalam grup menu "Kepegawaian", yang hilang sejak patch hapus presensi pegawai. Akibatnya, menu "Insiden BK" pun tidak tampil. Patch ini memindahkan blok BK ke grup menu pertama (`ne===0`), yang selalu ada.
4. **Router halaman:** `bk-grades` merender `PantauNilaiKelas` dengan `showClassPicker = true`. Dropdown kelas berasal dari `get_classes`, dengan default `class_homeroom` user bila ada, jika tidak kelas pertama. Semester default = `active_semester` dari config.

## 6. File dan interface terkait

| File | Perubahan |
|---|---|
| `api/pantau_nilai.php` (**baru**) | `pantauNilaiScope`, `classSubjectAssignments`, `pantauNilaiParseAttendance`, `pantauNilaiCollect` (inti §4.3–4.4, dipakai ketiga action), `handleGetClassGradeOverview`, `handleGetClassSubjectGradeDetail`, `handleCetakRekapNilaiSiswa`, dan renderer HTML |
| `api/index.php` | `require_once __DIR__ . '/pantau_nilai.php';` setelah baris 41, plus tiga `case` baru |
| `api/kesiswaan_access.php` | Dipakai ulang, tidak diubah: `loadFreshUser`, `homeroomClassScope`, `resolveClassRef`, `classInScope`, `KESISWAAN_MSG_KELAS`. Tahun ajaran aktif memakai `getActiveAcademicPeriod` (db.php), sama dengan `get_student_grades` |
| `api/rekap_bulanan.php` | Dipakai ulang, tidak diubah: `rekapSendHeaders`, `rekapHtmlError`, `rekapEsc`, `rekapLoadKop`, `rekapAssetUrl`, `rekapTanggalIndo` |
| `api/db.php` | Dipakai ulang, tidak diubah: `getActiveClassStudents`, `getSemesterDateRange`, `getCurrentUser` |
| `docs/patches/apply-pantau-nilai-bundle.js` (**baru**) | Patch bundle §5 |
| `assets/index-CGWL69P7-pantaunilai1.js` (**hasil generate**), `app.html` | Hasil skrip patch |
| `docs/patches/pantau-nilai-wali-kelas.md` (**baru**) | Catatan patch: cara menerapkan, anchor, rollback (kembalikan `app.html` ke `tanpapresensi1`, hapus `require_once` dan tiga `case`), dan prasyarat akun uji |
| `docs/patches/uji-pantau-nilai-api.mjs` (**baru**) | Skrip uji API §10.1 |
| `docs/patches/uji-pantau-nilai-parser.php` (**baru**) | Uji unit fungsi murni: parser presensi (map dan array), semester, rumus ringkasan |
| `docs/patches/uji-pantau-nilai-seed.sql`, `uji-pantau-nilai-cleanup.sql` (**baru**) | Data uji §10.1 dan pembersihnya |

Tabel yang dibaca (tanpa tulis): `journal_entries`, `ujian_scores`, `students`, `teacher_subjects`, `subjects`, `classes`, `users`, `system_config`, `attendance_records`.

## 7. Edge cases

| Kasus | Perilaku |
|---|---|
| Jurnal hanya berisi nilai kuis | Tidak ada tertinggal tugas pada pertemuan itu |
| Jurnal `has_assessment = 1` tanpa satu pun nilai | Tidak menghasilkan tertinggal. Tetap tampil di `meetings` detail dengan `has_tugas = has_kuis = false` |
| Jurnal draf | Diabaikan |
| Dua jurnal mapel sama pada tanggal sama (dua jam pelajaran) | Dihitung sebagai dua pertemuan terpisah (per `journal_id`) |
| Siswa sakit/alfa pada pertemuan bernilai | Tetap dihitung tertinggal, dengan presensi "Sakit"/"Alfa" sebagai keterangan |
| Siswa baru masuk kelas di tengah semester | Pertemuan sebelum ia masuk ikut dihitung tertinggal, karena tanggal masuk tidak tercatat. Ini dicatat sebagai keterbatasan (§11); wali membaca dengan konteks |
| Siswa pindah/nonaktif | Tidak tampil. Nilai lamanya tidak ikut dihitung |
| Nilai `0` | Dianggap **ada** nilai, bukan tertinggal. Hanya `null`/`''`/tidak ada entri yang dianggap kosong |
| `assessment_data` berisi `student_id` yang bukan siswa aktif kelas | Diabaikan, tetapi tetap membuat `has_tugas`/`has_kuis` true |
| Mapel di `teacher_subjects` tanpa data | Tampil `BELUM_ADA_PENILAIAN` |
| Mapel punya data tetapi `subjects.is_active = 0` atau tidak ada di `subjects` | Tetap tampil (nama dari jurnal atau kode) |
| Jurnal lama dengan `classId` berformat lain (nama kelas) | Tidak terhitung, sama seperti di halaman Penilaian guru. Tidak ada normalisasi tambahan |
| `studentAttendance` format map atau array | Keduanya didukung. Format lain → presensi `null` |
| Wali kelas yang `class_homeroom`-nya dihapus admin setelah login | Request berikutnya 403 (user segar dari DB) |
| BK yang juga wali kelas | `ALL`. Tab Nilai di Kelas Saya tetap berfungsi |
| `KEPALA_SEKOLAH` yang punya `class_homeroom` | `ALL` |
| Kelas tidak ditemukan di `classes` | `resolveClassRef` mengembalikan input apa adanya, dan query berjalan dengan id itu (hasil kosong, 200). Untuk `CLASS`, `classInScope` tetap diterapkan |
| Format TA aktif tidak dikenali | Filter tanggal dilewati. Semua jurnal kelas ikut dihitung (sama dengan perilaku `get_student_grades`) |
| Catatan/topik berisi HTML atau skrip | Di-escape: JSON dirender React tanpa `dangerouslySetInnerHTML`, halaman cetak lewat `rekapEsc` |
| Kelas besar (±40 siswa × 15 mapel × ±60 pertemuan) | Satu query jurnal per request dan agregasi di PHP. Target respons < 2 detik di server sekolah |

## 8. Compatibility

- Hanya endpoint baru. Endpoint dan halaman lama tidak berubah perilakunya, jadi guru mapel, KS, ADMIN, dan TU tidak terdampak.
- Tanpa perubahan skema, sehingga aman terhadap migrasi upstream dan rollback updater.
- **Auto-updater portal dapat menimpa** `api/index.php`, `app.html`, dan bundle. Setelah update, patch harus diterapkan ulang: jalankan ulang skrip bundle dari bundle upstream baru (anchor bisa berubah) dan tambahkan kembali `require_once` dan `case`. File baru (`api/pantau_nilai.php`) tidak tertimpa kecuali upstream membuat file bernama sama. Semua ini dicatat di `docs/patches/pantau-nilai-wali-kelas.md`, sekaligus sebagai usulan upstream ke BTIKP.
- Bundle patch berantai: sumber `tanpapresensi1`. Patch berikutnya harus memakai `pantaunilai1` sebagai sumber.
- Frontend lama (tanpa patch bundle) tetap berjalan normal. Endpoint baru hanya tidak terpakai.

## 9. Di luar scope

- **Akses siswa/orang tua** (akun, tautan bertoken, login NISN, portal ortu). Ini tahap 2 dengan spesifikasi terpisah, karena menyangkut autentikasi publik dan data pribadi anak. Endpoint §4 sebaiknya dirancang agar `pantauNilaiCollect` bisa dipakai ulang untuk satu siswa.
- Wali kelas/BK mengubah nilai, atau membuka halaman Penilaian Siswa untuk mapel lain.
- Tahun ajaran lampau.
- KKM per mapel dan bobot per mapel.
- Notifikasi otomatis ke guru mapel/wali/ortu, serta pembuatan panggilan orang tua otomatis dari data nilai.
- Leger rapor resmi dan ekspor Excel.
- Menu UI baru untuk KS/ADMIN, dan akses TU.
- Memperbaiki parsing `studentAttendance` di `get_daily_grades` (asumsi format array). Ini dicatat sebagai temuan (§11), tidak diubah.
- Normalisasi `classId` jurnal lama yang tidak memakai `classes.id`.
- Refaktor `get_student_grades` agar memakai helper bersama.

## 10. Pengujian dan verifikasi

### 10.1 Skrip uji API `docs/patches/uji-pantau-nilai-api.mjs`

Pola sama dengan `uji-persetujuan-jurnal-api.mjs`: `call()`, `check()`, dan cookie jar per akun. Hanya untuk dev/staging.

**Prasyarat** (didokumentasikan di catatan patch beserta SQL seed):
- Akun `@uji.test` dengan password `UJI_PASSWORD`:
  - `uji_admin` (ADMIN), `uji_ks` (KEPALA_SEKOLAH), `uji_tu` (TATA_USAHA);
  - `uji_guru` (GURU, mengampu `UJI_A` di kelas `UJI`);
  - `uji_guru2` (GURU, mengampu `UJI_B` di kelas `UJI`);
  - `uji_wali` (GURU, `class_homeroom = UJI`, tidak mengampu apa pun);
  - `uji_bk` (GURU, `is_bk = 1`);
  - `uji_lain` (GURU biasa).
- Kelas `UJI` dan `UJI2`.
- Siswa aktif `s_uji_1..3` di `UJI`, siswa nonaktif `s_uji_4` di `UJI`, dan siswa `s_uji2_1` di `UJI2`.
- Mapel `UJI_A`, `UJI_B`, `UJI_C`, dengan `UJI_C` hanya ditugaskan (tanpa data).

**Data uji** dibuat oleh skrip dengan `save_journal` (tanggal di semester aktif, `classId: 'UJI'`, dengan `assessment_data` dan `attendance`) dan `save_ujian_score`. Skrip berhenti jika kelas `UJI` sudah punya jurnal saat mulai, lalu menghapus jurnal uji di akhir (`delete_journal`). Baris `ujian_scores` uji dibersihkan dengan SQL di catatan patch.

**Kasus:**

| ID | Kasus | Harapan |
|---|---|---|
| T1 | Anonim → ketiga action | 401 (JSON untuk dua action pertama, HTML untuk cetak) |
| T2 | `uji_tu`, `uji_lain`, `uji_guru` → overview `UJI` | 403 |
| T3 | `uji_wali` → overview `UJI` / tanpa `class_id` / `UJI2` | 200 / 200 (kelas `UJI`) / 403 |
| T4 | `uji_bk`, `uji_ks`, `uji_admin` → overview `UJI2` | 200 |
| T5 | POST ke overview | 405 |
| T6 | Jurnal A1 (tugas: s1=80, s2=kosong, s3=0) | s2 `missing_tugas=1`; s3 **tidak** tertinggal (nilai 0) |
| T7 | Jurnal A2 hanya kuis (s1=90) | s2 dan s3 `missing_kuis +1`, tidak ada tertinggal tugas |
| T8 | Jurnal A3 draf berisi nilai | Tidak dihitung |
| T9 | s2 berstatus `SAKIT` di A1 | Butir tertinggal punya `attendance_status = "SAKIT"`, dan tetap dihitung |
| T10 | Siswa nonaktif `s_uji_4` punya nilai di A1 | Tidak ada di `students`, tetapi A1 tetap `has_tugas` |
| T11 | Mapel `UJI_C` | Ada di `subjects` dengan `BELUM_ADA_PENILAIAN` |
| T12 | Kesamaan angka | Untuk setiap mapel, `avg_tugas`, `avg_kuis`, `ujian_*`, `final_score`, dan `status` di overview sama persis dengan `get_student_grades` (dipanggil sebagai `uji_admin`) |
| T13 | `below_kkm` dan `ref_is_provisional` | Siswa tanpa ujian dengan rata-rata < KKM → `below_kkm = true`, `ref_is_provisional = true`, `needs_attention = true` |
| T14 | Detail `UJI_A` | `meetings` berurutan tanggal, dan `cells` sejajar dengan `meetings` |
| T15 | Format `studentAttendance` map vs array | Keduanya menghasilkan status yang benar. Format array tidak bisa dibuat lewat `save_journal` (penulisan presensi gagal), sehingga diuji di `uji-pantau-nilai-parser.php` |
| T16 | Cetak `s_uji_1` sebagai `uji_wali` | 200 `text/html`, memuat judul, nama siswa, bagian A/B/C, "Orang Tua/Wali" |
| T17 | Cetak `s_uji2_1` sebagai `uji_wali`, dan `s_uji_4` | 403, dan 404 |
| T18 | Catatan guru berisi `<script>alert(1)</script>` | Halaman cetak memuat `&lt;script&gt;`, bukan tag mentah |
| T19 | Regresi: `uji_wali` → `get_student_grades` `UJI_A` dan `save_ujian_score` `UJI_A` | 403 (perilaku lama tetap) |
| T20 | Regresi: `uji_guru` → `get_student_grades` `UJI_A` | 200 |
| T21 | Hapus `class_homeroom` `uji_wali` di DB tanpa logout | Overview → 403 (lalu dipulihkan skrip atau SQL) |

### 10.2 Lint

```
php -l api/pantau_nilai.php
php -l api/index.php
node --check docs/patches/apply-pantau-nilai-bundle.js
node --check assets/index-CGWL69P7-pantaunilai1.js
```

### 10.3 Verifikasi end-to-end (manual, di browser)

Prasyarat: `api/config.local.php` dengan `SIMANTAP_DEV_MODE`, `api/install.lock`, patch bundle diterapkan, dan data uji §10.1 (tanpa menjalankan tahap hapus).

1. Login `uji_guru`: buat jurnal ber-penilaian mapel `UJI_A` kelas `UJI` lewat UI (nilai s2 dikosongkan). Isi UTS di halaman Penilaian Siswa.
2. Login `uji_wali`: buka Kelas Saya → tab **📝 Nilai**.
   - s2 bertanda "Perlu perhatian" dengan badge tertinggal di kolom `UJI_A`;
   - `UJI_C` berlabel "Belum ada penilaian";
   - angka sama dengan yang dilihat `uji_guru` di halaman Penilaian.
3. Klik mapel `UJI_A` dan pastikan matriks per pertemuan dan catatan guru tampil. Pastikan tidak ada tombol simpan.
4. Klik s2 → **Cetak Laporan Nilai** dan pastikan tab baru berisi dokumen ber-Kop dengan bagian A/B/C dan TTD. "Simpan sebagai PDF" menghasilkan A4 rapi (header tabel berulang, baris tidak terpotong).
5. Ganti toggle semester dan pastikan data mengikuti.
6. Login `uji_bk`: pastikan menu **Pantau Nilai Kelas** ada di bawah "Insiden BK", lalu pilih kelas `UJI2` dan pastikan data tampil.
7. Login `uji_lain`: pastikan menu BK dan tab Kelas Saya tidak ada, dan akses URL cetak langsung → halaman 403.
8. Login `uji_ks`: pastikan tidak ada menu baru dan halaman lain (Dashboard, Penilaian) tetap normal.

## 11. Risiko, trade-off, dan temuan

| Risiko / trade-off | Mitigasi / keputusan |
|---|---|
| **Privasi:** wali/BK melihat catatan guru lain | Keputusan eksplisit dari wawancara. Hanya baca, dan terbatas pada kelas perwalian (BK semua kelas) |
| Penanda "tertinggal" false positive: siswa sakit, siswa baru masuk, atau guru sengaja tidak menilai sebagian siswa | Presensi ditampilkan per butir, dan istilah UI "Belum ada nilai" (bukan "tidak mengumpulkan"). Tanggal masuk siswa tidak tercatat, sehingga tidak bisa dikecualikan otomatis |
| Rumus nilai akhir diduplikasi dari `get_student_grades` | Uji T12 menjaga kesamaan. Refaktor ke helper bersama di luar scope, dan disarankan untuk upstream |
| Guru BK mendapat akses nilai semua kelas | Sesuai keputusan. Tidak termasuk hak ubah |
| Patch bundle rapuh terhadap perubahan minified code | Anchor harus unik, skrip gagal keras bila tidak ditemukan, dan catatan patch menjelaskan cara menerapkan ulang |
| Auto-updater menimpa patch | §8, plus usulan upstream |
| Scope membaca user dari DB (1 query ekstra per request) | Diterima, konsisten dengan modul Kesiswaan, dan perubahan wali/BK langsung berlaku |
| Jurnal lama dengan `classId` non-standar tidak terhitung | Konsisten dengan halaman Penilaian guru. Normalisasi di luar scope |

**Temuan dari analisis (tidak diubah di scope ini):**

- `get_daily_grades` (`api/index.php:1880-1885`) mengasumsikan `studentAttendance` berupa array objek, padahal `get_journals` memperlakukannya sebagai map `student_id => status`. Jika formatnya map, semua siswa tampil `HADIR`.
- `requireGradeAccess` membaca role dari session, bukan dari DB. Perubahan role (mis. GURU dinaikkan menjadi KEPALA_SEKOLAH atau sebaliknya) baru berlaku setelah login ulang, walaupun `teacher_subjects` sendiri dibaca segar setiap request.
- Endpoint nilai lama tidak memeriksa metode HTTP, dan `save_ujian_score` dapat dipicu lintas situs (CSRF) oleh guru yang sedang login.

## 12. Acceptance criteria

- **AC-1:** Wali kelas (GURU non-BK dengan `class_homeroom`) dapat melihat rekap nilai **semua mapel** kelas perwaliannya pada semester aktif/terpilih TA aktif lewat tab "📝 Nilai" di Dashboard Kelas Saya. Kelas lain → 403.
- **AC-2:** Guru BK dapat melihat tampilan yang sama untuk kelas mana pun lewat menu "Pantau Nilai Kelas". KS/ADMIN dapat memanggil endpoint untuk kelas mana pun. TU dan guru biasa → 403.
- **AC-3:** Untuk setiap kelas+mapel+semester, nilai `avg_tugas`, `avg_kuis`, `ujian_uts`, `ujian_uas`, `final_score`, dan `status` identik dengan `get_student_grades`.
- **AC-4:** Tertinggal dihitung sesuai §4.4: hanya pada pertemuan yang jenis nilainya diberikan ke ≥ 1 siswa, nilai 0 bukan tertinggal, jurnal draf diabaikan, siswa nonaktif tidak tampil, dan status presensi ditampilkan tanpa mengubah hitungan.
- **AC-5:** Siswa bertanda "perlu perhatian" jika `total_missing > 0` atau `below_kkm_count > 0`. Tabel bisa difilter dan diurutkan, dengan default perlu perhatian di atas.
- **AC-6:** Mapel yang ditugaskan di `teacher_subjects` tetapi belum punya data tampil sebagai "Belum ada penilaian".
- **AC-7:** Detail mapel menampilkan nilai per pertemuan, status presensi, catatan guru, dan nilai ujian. Tidak ada kontrol ubah.
- **AC-8:** `cetak_rekap_nilai_siswa` menghasilkan halaman HTML A4 ber-Kop dengan bagian A (nilai per mapel), B (tertinggal atau "Nihil"), C (presensi semester), dan TTD Orang Tua/Wali + Wali Kelas. Error ditampilkan sebagai HTML dengan status 401/403/404/405 yang sesuai, dan semua teks di-escape.
- **AC-9:** Endpoint nilai lama berperilaku sama seperti sebelumnya: wali tetap tidak bisa membaca atau menyimpan nilai mapel yang tidak diampu lewat endpoint lama.
- **AC-10:** Perubahan `class_homeroom`/`is_bk`/role di DB berlaku pada request berikutnya tanpa login ulang.
- **AC-11:** Tidak ada perubahan skema. `php -l` bersih, dan skrip `uji-pantau-nilai-api.mjs` lulus semua kasus T1–T21.
- **AC-12:** Patch bundle hanya memakai kelas Tailwind yang sudah ada, menulis `index-CGWL69P7-pantaunilai1.js`, dan memperbarui `app.html`. Halaman lain tetap berfungsi (§10.3 langkah 8).
