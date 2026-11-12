# Spesifikasi: Profil Murid 360°

| | |
|---|---|
| Status | Diimplementasikan (patch lokal) — lihat `docs/patches/profil-murid-360.md` |
| Versi bundle acuan | SIMANTAP 1.0.2 (`api/version.php`), bundle aktif `assets/index-CGWL69P7-harisekolah1.js` |
| Tanggal | 2026-11-12 |
| Jalur rilis | Patch lokal pada bundle ini **dan** usulan perubahan ke upstream BTIKP |

## 1. Latar belakang dan kebutuhan bisnis

Wali kelas dan guru BK harus memahami murid secara utuh: siapa dia (biodata/latar keluarga), bagaimana ia belajar (asesmen non-kognitif), ke mana ia ingin melangkah (rencana karir), dan bagaimana kondisinya sekarang (nilai, kehadiran, perilaku). Saat ini data itu tersebar atau belum ada di SIMANTAP. Dashboard Kelas Saya hanya menampilkan rekap kehadiran, dan wali kelas tidak punya halaman detail per murid.

Tujuan fitur: **satu halaman per murid** yang menggabungkan tiga lampiran (Identifikasi Profil Murid, Asesmen Non-Kognitif, Rencana Karir) dengan ringkasan data yang sudah ada (riwayat jurnal, akademik, kehadiran, perilaku, ekskul). Guru BK atau wali kelas cukup membuka satu halaman untuk memahami seorang murid, lalu bisa mencetaknya.

Prinsip "tidak mengetik ulang":
- biodata diimpor dari file Excel ekspor **Dapodik**;
- asesmen non-kognitif dan rencana karir diimpor satu kelas sekaligus dari **template Excel SIMANTAP** (hasil angket kertas atau Google Form yang sudah diskor di luar SIMANTAP).

### 1.1 Keputusan wawancara

| Topik | Keputusan |
|---|---|
| Akses baca | Ikut pola Pantau Nilai: wali kelas → murid kelas perwaliannya; guru BK → semua murid; KEPALA_SEKOLAH/ADMIN → semua murid; TATA_USAHA dan guru mapel biasa → ditolak |
| Pengisi asesmen + karir | Murid **tidak** login. Hasil angket diimpor dari template Excel SIMANTAP oleh wali kelas (kelasnya) atau guru BK (semua kelas). KS/ADMIN juga boleh. Edit manual per murid tersedia untuk koreksi |
| Format impor asesmen | Template SIMANTAP berisi **hasil** (skor R/I/A/S/E/C, gaya belajar, dst.). Penskoran instrumen di luar SIMANTAP |
| Riwayat asesmen + karir | Satu catatan per murid per **tahun ajaran + semester**. Impor/simpan ulang di periode yang sama menimpa catatan itu. Halaman menampilkan yang terbaru plus riwayat periode sebelumnya |
| Sumber biodata | File Excel ekspor Peserta Didik dari aplikasi Dapodik. Tanpa koneksi ke server/web service Dapodik |
| Aturan impor biodata | Hanya TATA_USAHA/ADMIN. Cocokkan lewat NISN (cadangan NIK). Data Dapodik **menimpa** field biodata. Murid yang tidak ada di SIMANTAP **dilewati** dan dilaporkan (tidak dibuat) |
| Field biodata | Subset inti disimpan terstruktur. Kolom Dapodik lain disimpan apa adanya di JSON dan tampil di bagian "Data Dapodik lainnya" |
| Data sensitif | Kolom bank/rekening dan lintang/bujur **dibuang** (tidak disimpan sama sekali). NIK dan No. KK (murid dan orang tua) **disamarkan** untuk wali kelas, tampil penuh untuk BK/KS/ADMIN |
| Grafik kompetensi | Radar 8 dimensi profil lulusan dari kuesioner bulanan murid adalah **scope berikutnya** (fitur Bimbingan Guru Wali). Di scope ini hanya ada slot "Belum tersedia" |
| Aspek akademik | Dari nilai yang sudah ada: tren nilai acuan per mapel **per semester sejak murid masuk**, dan daftar mapel di bawah KKTP semester aktif |
| Target akademik | **Ditunda** ke scope Bimbingan Guru Wali. Slot "Belum tersedia" |
| Ringkasan riwayat | Kehadiran per mapel (dari jurnal), catatan guru per siswa di jurnal, pelanggaran/poin/panggilan ortu/dispensasi, ekskul yang diikuti |
| Navigasi | Wali: klik nama murid di Dashboard Kelas Saya. BK: menu "Profil Murid 360°" di blok Bimbingan & Konseling dengan pemilih kelas + pencarian. Tombol **Cetak A4 ber-Kop** |
| Rilis | Patch lokal + usulan upstream |
| Pengujian | Skrip uji API `.mjs` + seed/cleanup SQL, uji unit PHP parser Excel, `php -l` + `node --check`, checklist E2E manual |

## 2. Perilaku saat ini (hasil analisis kode)

### 2.1 Data murid

- Tabel `students` (`install/schema.sql`) menyimpan identitas dasar: `id`, `nisn`, `name`, `class_id`, `class_name`, `status`, `total_violation_points`, `parent_name`, `parent_phone`, `pekerjaan_ortu`, `address`/`alamat`, `nik`, `tempat_lahir`, `tanggal_lahir`, `jenis_kelamin`, `agama`, dan `docs_json` (berisi `pas_foto`, dll.). Belum ada data keluarga lengkap, KIP/PKH, atau kolom Dapodik lain.
- `students.id` berformat `std_<nisn>` jika diimpor dengan NISN (`import_students`, `api/endpoints_v2.php:94`), selain itu `uniqid('std_')`. NISN **tidak** dijamin unik di skema.
- `students.status` bercampur huruf (`AKTIF` dari skema, `Aktif`/`Pindah`/… dari modul TU). Perbandingan memakai `UPPER(status)` atau kolasi case-insensitive.
- Impor yang ada: `import_students` (ADMIN/TU) hanya NISN, nama, kelas. Halaman TU "Data Siswa" (`data-siswa`) punya tab Buku Induk/Berkas/Riwayat/Cetak Surat. Tabel `siswa_riwayat` (prestasi/pelanggaran/beasiswa) dipakai modul TU.
- Asesmen non-kognitif dan rencana karir: **belum ada sama sekali**.

### 2.2 Data yang akan diringkas (semuanya sudah ada)

| Data | Sumber |
|---|---|
| Nilai harian | `journal_entries.assessment_data` (JSON `[{student_id, student_name, tugas_score, kuis_score, notes}]`, `has_assessment = 1`, `isDraft = 0`) |
| Catatan guru per siswa | `assessment_data[].notes` |
| Nilai ujian | `ujian_scores` (`UTS`, `UAS`, `SUMATIF_AKHIR`) |
| Aturan hitung nilai, KKM | `api/pantau_nilai.php` → `pantauNilaiCollect($db, $classId, $academicYear, $semester)`, bobot/KKM dari `system_config` (`kkm_default`, ditampilkan sebagai **KKTP**) |
| Presensi per pertemuan | `journal_entries.studentAttendance` (format map **atau** array; parser `pantauNilaiParseAttendance`) |
| Presensi harian | `attendance_records` (`HADIR`/`SAKIT`/`IZIN`/`ALFA`, per `academic_year` + `semester`) |
| Pelanggaran, terlambat, izin keluar | `incidents` (`studentId`, `type`, `points`, `status`) dan `students.total_violation_points` |
| Panggilan ortu | `parent_calls` (`student_id`) |
| Dispensasi | `student_dispensations` (`student_id`, dibuat saat pertama dipakai oleh `api/dispensasi.php`) |
| Ekskul | `extracurricular_members` (`student_id`, `status`) + `extracurriculars` |

### 2.3 Otorisasi yang bisa dipakai ulang

- `pantauNilaiScope($db, $sessionUser)` (`api/pantau_nilai.php:15`): `ALL` untuk ADMIN/KS/GURU BK, `CLASS` untuk wali kelas non-BK, `DENY` untuk lainnya. User dibaca ulang dari DB (`loadFreshUser`), sehingga perubahan role/wali langsung berlaku tanpa login ulang.
- `studentInScope($db, $scope, $studentId)` (`api/kesiswaan_access.php:135`) mencocokkan kelas **saat ini** murid dengan scope.
- `resolveClassRef`, `classInScope`, `homeroomClassScope`, `KESISWAAN_MSG_KELAS` di `api/kesiswaan_access.php`.
- Renderer cetak: `rekapSendHeaders`, `rekapHtmlError`, `rekapEsc`, `rekapLoadKop`, `rekapAssetUrl`, `rekapTanggalIndo` di `api/rekap_bulanan.php`.

### 2.4 Frontend

- Dashboard Kelas Saya adalah komponen `dot` (`classroom-dashboard`). `get_homeroom_dashboard` sudah mengembalikan `per_siswa[].student_id` (`api/endpoints_v3.php:107`), tetapi pemetaan di frontend **membuang** `student_id` (hanya `studentName`, `pas_foto`, `hadir`, …). Nama murid di tabel tidak bisa diklik.
- Router halaman berbasis string (`e==="bk-grades"&&…`); `onNavigate`/`n(...)` hanya menerima nama halaman, tanpa parameter.
- Blok sidebar BK (`is_bk`) berisi "Insiden BK" (`bk-incidents`) dan "Pantau Nilai Kelas" (`bk-grades`).
- Bundle sudah memuat SheetJS (`sheet_to_json`, `aoa_to_sheet`, `book_new`, `writeFile`) dan recharts (`LineChart`, `BarChart`, `RadarChart`). Identifier hasil minify harus dicari saat membuat patch.

## 3. Expected behavior

### 3.1 Alur wali kelas

1. Di Dashboard Kelas Saya, nama murid pada tabel rekap per siswa menjadi tautan. Klik membuka halaman **Profil Murid 360°** murid itu.
2. Halaman terdiri dari header + tujuh bagian (§3.4). Tombol "← Kembali" kembali ke Dashboard Kelas Saya.
3. Wali bisa: impor asesmen kelasnya (§3.3), mengedit asesmen/karir per murid untuk periode aktif, dan mencetak profil.
4. NIK/No. KK tampil tersamar (`6371********0001`: 4 digit awal dan 4 digit akhir, sisanya `*`).
5. Wali hanya bisa membuka murid **aktif** yang kelas saat ini = kelas perwaliannya. Murid lain → 403 `KESISWAAN_MSG_KELAS`.

### 3.2 Alur guru BK (dan KS/ADMIN)

1. Menu sidebar baru "Profil Murid 360°" (`bk-profil-murid`) di blok BK, tepat setelah "Pantau Nilai Kelas". KS/ADMIN membuka halaman yang sama lewat menu yang sama. Blok itu saat ini hanya untuk `is_bk`, sehingga untuk KS/ADMIN item ditambahkan juga di grup menu mereka (lihat §5).
2. Halaman daftar: pemilih kelas (`get_classes`, default kelas pertama atau `class_homeroom` bila ada), kotak pencarian nama/NISN (lintas kelas, minimal 3 karakter), dan tabel murid dengan kolom: Nama, NISN, Biodata Dapodik (✓/–), Asesmen periode aktif (✓/–), Poin, Kehadiran semester (%).
3. Tombol "Unduh Template Asesmen" dan "Impor Asesmen" untuk kelas terpilih.
4. Klik nama → halaman profil. NIK/No. KK tampil penuh.
5. Pencarian BK/KS/ADMIN menyertakan murid nonaktif (pindah/lulus) dengan badge status. Profil murid nonaktif bisa dibuka (hanya baca untuk bagian akademik semester aktif, yang kosong).

### 3.3 Impor asesmen non-kognitif + rencana karir (wali/BK/KS/ADMIN)

1. Pengguna memilih kelas (wali: terkunci ke kelasnya) dan periode (default tahun ajaran + semester aktif; boleh memilih semester lain di **tahun ajaran aktif** saja).
2. "Unduh Template Asesmen" membuat file `Template-Asesmen-<kelas>-<TA>-<semester>.xlsx` di browser (SheetJS) dari daftar murid aktif kelas itu. Baris 1 = header (§4.6), kolom NISN dan Nama terisi, sisanya kosong. Jika periode itu sudah punya data, sel terisi data yang ada.
3. "Impor Asesmen": pilih file `.xlsx`/`.xls`/`.csv`. Frontend membaca sheet pertama menjadi array 2D (`sheet_to_json(ws, {header:1, raw:false, defval:''})`), lalu mengirim `mode: "pratinjau"`.
4. Backend memvalidasi dan mengembalikan pratinjau per baris: `BARU`, `TIMPA` (periode sudah ada data), `LEWATI` (semua sel asesmen kosong), atau `GALAT` + pesan. Tidak ada yang ditulis.
5. Pengguna menekan "Simpan N baris". Frontend mengirim ulang dengan `mode: "simpan"`. Backend mengulang validasi lalu menulis dalam satu transaksi. Baris `GALAT`/`LEWATI` tidak ditulis, baris lain tetap disimpan (galat per baris tidak membatalkan baris valid).
6. Hasil: ringkasan "X baru, Y ditimpa, Z dilewati, W galat" + daftar galat yang bisa diunduh sebagai `.xlsx`.

### 3.4 Isi halaman profil

Header: pas foto (dari `docs_json.pas_foto`), nama, NISN, kelas, status, tahun ajaran/semester aktif, total poin pelanggaran, tombol **Cetak**.

| # | Bagian | Isi |
|---|---|---|
| 1 | **Identifikasi Profil Murid** (biodata) | Identitas (NISN, NIPD, NIK*, No. KK*, tempat/tanggal lahir, JK, agama, anak ke-, jumlah saudara kandung, kebutuhan khusus), alamat & kontak (alamat, RT/RW, dusun, kelurahan, kecamatan, kode pos, jenis tinggal, transportasi, jarak ke sekolah, HP, email), fisik (berat, tinggi, lingkar kepala), bantuan (KIP, PKH/KPS, KKS, layak PIP + alasan), sekolah asal, data Ayah/Ibu/Wali (nama, NIK*, tahun lahir, pendidikan, pekerjaan, penghasilan). Bagian lipat "Data Dapodik lainnya" (JSON). Footer: "Sumber: Dapodik, diimpor <tanggal> oleh <nama>". Jika belum diimpor: tampil data `students` yang ada + pesan "Biodata Dapodik belum diimpor. Hubungi Tata Usaha." (*: disamarkan untuk wali) |
| 2 | **Asesmen Non-Kognitif** | Periode terbaru: grafik batang skor R/I/A/S/E/C + kode RIASEC 3 huruf (mis. "SAI") dengan label tipe (Realistic, Investigative, Artistic, Social, Enterprising, Conventional), gaya belajar (Visual/Auditori/Kinestetik + skor bila ada), kesulitan belajar, mapel termudah, mapel tersulit. Dropdown "Periode" untuk melihat riwayat. Tombol "Edit" (yang berwenang) membuka form periode aktif. Kosong: "Belum ada asesmen." |
| 3 | **Rencana Karir** | Cita-cita, rencana setelah lulus, jurusan tujuan, institusi/tempat tujuan, catatan. Ikut periode yang sama dengan bagian 2 |
| 4 | **Akademik** | (a) Tabel mapel semester aktif: nilai acuan, status final/sementara, KKTP, jumlah tugas/kuis tertinggal; mapel di bawah KKTP disorot merah dan dirangkum di atas tabel ("3 mapel di bawah KKTP: …"). (b) Grafik garis tren nilai acuan per semester sejak murid masuk: satu garis per mapel (maks. 8 garis tampil, sisanya via pemilih mapel) + garis "Rata-rata". (c) Slot "Target akademik — tersedia pada fitur Bimbingan Guru Wali" |
| 5 | **Kompetensi** | Slot "Grafik kompetensi 8 dimensi profil lulusan — tersedia pada fitur Bimbingan Guru Wali". Tanpa data |
| 6 | **Kehadiran** | Semester aktif: (a) presensi harian H/S/I/A + persentase hadir + grafik per bulan; (b) presensi per mapel dari jurnal (H/S/I/A per mapel); (c) 20 tanggal tidak hadir terakhir (tanggal, status, sumber). Dispensasi tampil di bagian 7 |
| 7 | **Riwayat & Perilaku** | Tab: **Catatan Guru** (tanggal, mapel, guru, catatan; tahun ajaran aktif, terbaru dulu, maks. 100), **Pelanggaran** (rekap per jenis + daftar insiden tahun ajaran aktif + total poin), **Panggilan Ortu** (semua, terbaru dulu), **Dispensasi** (tahun ajaran aktif), **Ekskul** (ekskul aktif + riwayat keanggotaan) |

### 3.5 Impor biodata Dapodik (TATA_USAHA/ADMIN)

1. Di halaman TU "Data Siswa" (`data-siswa`) ditambahkan tombol **"Impor Biodata Dapodik"**. ADMIN juga melihat tombol itu.
2. Pengguna memilih file Excel ekspor Peserta Didik dari Dapodik. Frontend mengirim seluruh isi sheet pertama sebagai array 2D (header belum dipotong) dengan `mode: "pratinjau"`.
3. Backend mendeteksi baris header (§4.5), memetakan kolom, mencocokkan murid, lalu mengembalikan pratinjau per baris: `PERBARUI`, `TIDAK_DITEMUKAN`, `KONFLIK` (NISN/NIK cocok ke lebih dari satu murid), `GALAT`, plus peringatan (`nama berbeda`, `rombel berbeda`). Juga mengembalikan daftar kolom yang dikenali, yang masuk JSON, dan yang **dibuang**.
4. "Simpan" → `mode: "simpan"`, ditulis dalam satu transaksi. Hanya baris `PERBARUI` yang ditulis. Ringkasan + daftar `TIDAK_DITEMUKAN` bisa diunduh `.xlsx`.

## 4. Kontrak backend

Semua kode baru ada di file baru **`api/profil_murid.php`**, diawali `if (!defined('SIMANTAP_API')) { http_response_code(403); exit('Forbidden'); }`, dan di-`require_once` di `api/index.php` setelah `bimbingan_pkl.php` (baris 54). `case` baru di `switch` `api/index.php` hanya memanggil fungsi handler. **Sebelum implementasi**, grep setiap nama action di keempat file endpoint (saat ini tidak ada action berawalan `profil_murid`).

### 4.1 Tabel baru (dibuat saat pertama dipakai, `profilMuridPastikanTabel`)

Mengikuti pola `dispensasiPastikanTabel`: `CREATE TABLE IF NOT EXISTS`, dipanggil di awal setiap handler.

```sql
CREATE TABLE IF NOT EXISTS `student_biodata` (
  `student_id` varchar(128) NOT NULL,           -- = students.id
  `nipd` varchar(30) DEFAULT NULL,
  `no_kk` varchar(20) DEFAULT NULL,
  `anak_ke` tinyint UNSIGNED DEFAULT NULL,
  `jml_saudara` tinyint UNSIGNED DEFAULT NULL,
  `kebutuhan_khusus` varchar(100) DEFAULT NULL,
  `rt` varchar(5) DEFAULT NULL, `rw` varchar(5) DEFAULT NULL,
  `dusun` varchar(100) DEFAULT NULL, `kelurahan` varchar(100) DEFAULT NULL,
  `kecamatan` varchar(100) DEFAULT NULL, `kode_pos` varchar(10) DEFAULT NULL,
  `jenis_tinggal` varchar(50) DEFAULT NULL, `alat_transportasi` varchar(50) DEFAULT NULL,
  `jarak_rumah_km` decimal(6,2) DEFAULT NULL,
  `telepon` varchar(20) DEFAULT NULL, `hp` varchar(20) DEFAULT NULL, `email` varchar(150) DEFAULT NULL,
  `berat_badan` smallint UNSIGNED DEFAULT NULL, `tinggi_badan` smallint UNSIGNED DEFAULT NULL,
  `lingkar_kepala` smallint UNSIGNED DEFAULT NULL,
  `sekolah_asal` varchar(150) DEFAULT NULL,
  `penerima_kps` varchar(10) DEFAULT NULL, `no_kps` varchar(50) DEFAULT NULL,
  `penerima_kip` varchar(10) DEFAULT NULL, `no_kip` varchar(50) DEFAULT NULL, `nama_di_kip` varchar(150) DEFAULT NULL,
  `no_kks` varchar(50) DEFAULT NULL,
  `layak_pip` varchar(10) DEFAULT NULL, `alasan_layak_pip` varchar(150) DEFAULT NULL,
  `ayah_json` text DEFAULT NULL,    -- {nama, nik, tahun_lahir, pendidikan, pekerjaan, penghasilan}
  `ibu_json` text DEFAULT NULL,
  `wali_json` text DEFAULT NULL,
  `rombel_dapodik` varchar(100) DEFAULT NULL,
  `dapodik_lain_json` mediumtext DEFAULT NULL,  -- {"<label kolom asli>": "<nilai>"}
  `diimpor_oleh` varchar(128) NOT NULL, `diimpor_oleh_nama` varchar(255) NOT NULL,
  `diimpor_pada` timestamp NOT NULL DEFAULT current_timestamp(),
  `impor_id` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `student_asesmen` (
  `id` varchar(64) NOT NULL,                    -- uniqid('asm_')
  `student_id` varchar(128) NOT NULL,
  `academic_year` varchar(20) NOT NULL,
  `semester` enum('GANJIL','GENAP') NOT NULL,
  `class_id` varchar(50) NOT NULL,              -- kelas murid saat data disimpan
  `class_name` varchar(100) NOT NULL,
  `riasec_r` decimal(5,2) DEFAULT NULL, `riasec_i` decimal(5,2) DEFAULT NULL,
  `riasec_a` decimal(5,2) DEFAULT NULL, `riasec_s` decimal(5,2) DEFAULT NULL,
  `riasec_e` decimal(5,2) DEFAULT NULL, `riasec_c` decimal(5,2) DEFAULT NULL,
  `riasec_kode` varchar(3) DEFAULT NULL,
  `gaya_belajar` enum('VISUAL','AUDITORI','KINESTETIK') DEFAULT NULL,
  `skor_visual` decimal(5,2) DEFAULT NULL, `skor_auditori` decimal(5,2) DEFAULT NULL,
  `skor_kinestetik` decimal(5,2) DEFAULT NULL,
  `kesulitan_belajar` text DEFAULT NULL,
  `mapel_termudah` text DEFAULT NULL,           -- JSON array string, maks. 3
  `mapel_tersulit` text DEFAULT NULL,           -- JSON array string, maks. 3
  `cita_cita` varchar(150) DEFAULT NULL,
  `rencana_lulus` enum('KULIAH','KERJA','WIRAUSAHA','KULIAH_KERJA','BELUM_TAHU','LAINNYA') DEFAULT NULL,
  `jurusan_tujuan` varchar(150) DEFAULT NULL,
  `institusi_tujuan` varchar(150) DEFAULT NULL,
  `catatan_karir` text DEFAULT NULL,
  `sumber` enum('IMPOR','MANUAL') NOT NULL,
  `logs` longtext DEFAULT NULL,                 -- JSON [{aksi, oleh, oleh_nama, waktu, sumber, impor_id}]
  `dibuat_oleh` varchar(128) NOT NULL, `dibuat_oleh_nama` varchar(255) NOT NULL,
  `diubah_oleh` varchar(128) NOT NULL, `diubah_oleh_nama` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_asesmen_periode` (`student_id`, `academic_year`, `semester`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `student_import_log` (
  `id` varchar(64) NOT NULL,                    -- uniqid('imp_')
  `jenis` enum('DAPODIK','ASESMEN') NOT NULL,
  `nama_file` varchar(255) DEFAULT NULL,
  `class_id` varchar(50) DEFAULT NULL,
  `academic_year` varchar(20) DEFAULT NULL, `semester` varchar(10) DEFAULT NULL,
  `jml_baris` int NOT NULL, `jml_tulis` int NOT NULL, `jml_lewati` int NOT NULL, `jml_galat` int NOT NULL,
  `oleh` varchar(128) NOT NULL, `oleh_nama` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Biodata disimpan **1 baris per murid tanpa riwayat** (Dapodik menimpa). Asesmen disimpan per periode.

### 4.2 Scope dan otorisasi

| Fungsi | Aturan |
|---|---|
| `profilMuridScope($db, $user)` | = `pantauNilaiScope` (ALL: ADMIN, KS, GURU BK; CLASS: wali non-BK; DENY: lainnya, termasuk TATA_USAHA). Pesan DENY: "Anda tidak memiliki akses ke Profil Murid." |
| `profilMuridWajibMurid($db, $scope, $studentId)` | Murid harus ada (404 "Murid tidak ditemukan."). Mode CLASS: murid harus **aktif** dan `studentInScope` → jika tidak, 403 `KESISWAAN_MSG_KELAS` |
| Akses tulis asesmen | Scope ALL atau CLASS (wali untuk kelasnya). Selalu untuk periode di **tahun ajaran aktif**. Mode CLASS hanya untuk murid aktif kelasnya |
| Impor Dapodik | `requireRole(['TATA_USAHA','ADMIN'])` + user dibaca ulang (`loadFreshUser`) dan role dicek ulang |
| Penyamaran | `profilMuridSamarkan(?string)`: ≤ 8 karakter → semua `*`; selain itu 4 awal + `*` + 4 akhir. Diterapkan di **backend** untuk mode CLASS pada `nik`, `no_kk`, `ayah.nik`, `ibu.nik`, `wali.nik`, termasuk di cetak dan di `dapodik_lain` jika labelnya mengandung "NIK" atau "KK" |
| Metode | GET untuk baca/cetak, POST untuk tulis. Salah metode → 405 "Metode tidak diizinkan" |
| Aktor | Selalu dari session (`uid`, `displayName`), tidak pernah dari body |

### 4.3 Action

| Action | Metode | Ringkas |
|---|---|---|
| `profil_murid_daftar` | GET | `class_id` (wajib untuk ALL; CLASS default kelas perwalian) **atau** `q` (≥ 3 karakter, cari nama/NISN lintas kelas; hanya scope ALL). Hasil `[{student_id, name, nisn, class_id, class_name, status, pas_foto, ada_biodata, ada_asesmen_aktif, poin, persen_hadir}]`. Maks. 50 hasil untuk `q` |
| `profil_murid_get` | GET | `student_id` wajib. Respons §4.4 |
| `profil_murid_asesmen_simpan` | POST | Body `{student_id, semester?, …field §4.6}`. Upsert periode (TA aktif + semester, default semester aktif). Validasi sama dengan impor. `sumber = MANUAL`, log ditambah |
| `profil_murid_asesmen_hapus` | POST | Body `{student_id, academic_year, semester}`. Hanya periode di TA aktif. Untuk koreksi impor yang salah murid. Karena barisnya (beserta `logs`) ikut terhapus, penghapusan dicatat di `error_log` server dengan aktor, `student_id`, dan periode (lihat §11) |
| `profil_murid_asesmen_impor` | POST | Body `{class_id, semester?, nama_file, rows: string[][], mode: "pratinjau"|"simpan"}`. §4.6 |
| `profil_murid_asesmen_template` | GET | `class_id`, `semester?` → `{header: [...], rows: [[nisn, nama, …nilai yang ada]]}` untuk dibuat jadi xlsx di browser |
| `profil_murid_dapodik_impor` | POST | Body `{nama_file, rows: string[][], mode}`. §4.5 |
| `cetak_profil_murid` | GET | `student_id` → HTML A4 ber-Kop (§4.7) |

Batas umum: `rows` maks. 2.000 baris × 120 kolom untuk Dapodik, 100 baris × 30 kolom untuk asesmen. Sel dipotong ke 500 karakter. Lebih dari itu → 400 "File terlalu besar…".

### 4.4 Respons `profil_murid_get`

```json
{
  "akses": {"mode": "ALL|CLASS", "bisa_edit_asesmen": true, "disamarkan": false},
  "periode_aktif": {"academic_year": "2026/2027", "semester": "GANJIL"},
  "murid": {"student_id", "name", "nisn", "class_id", "class_name", "status", "pas_foto", "total_poin",
            "nik", "tempat_lahir", "tanggal_lahir", "jenis_kelamin", "agama", "alamat",
            "parent_name", "parent_phone", "pekerjaan_ortu"},
  "biodata": null | {…kolom student_biodata, "ayah": {}, "ibu": {}, "wali": {}, "dapodik_lain": {}, "diimpor_pada", "diimpor_oleh_nama"},
  "asesmen": [ {…student_asesmen, "mapel_termudah": [], "mapel_tersulit": []} ],   // terbaru dulu
  "akademik": {
    "kkm": 75,
    "semester_aktif": [ {"subject_id", "subject_name", "ref_score", "ref_is_provisional", "final_score",
                         "below_kkm", "missing_tugas", "missing_kuis", "teachers": []} ],
    "tren": {"periode": ["2025/2026 GANJIL", …], "mapel": [{"subject_id", "subject_name", "nilai": [n|null, …]}],
             "rata_rata": [n|null, …]},
    "target": {"tersedia": false}
  },
  "kompetensi": {"tersedia": false},
  "kehadiran": {
    "harian": {"hadir", "sakit", "izin", "alfa", "persen_hadir", "per_bulan": [{"bulan": "2026-08", …}]},
    "per_mapel": [{"subject_id", "subject_name", "hadir", "sakit", "izin", "alfa", "pertemuan"}],
    "tidak_hadir_terakhir": [{"tanggal", "status", "sumber"}]
  },
  "riwayat": {
    "catatan_guru": [{"tanggal", "subject_name", "teacher_name", "catatan"}],
    "pelanggaran": {"rekap": [{"type", "jumlah", "poin"}], "daftar": [{"id", "date", "type", "details", "points", "status"}]},
    "panggilan_ortu": [{"id", "scheduled_date", "status", "total_points", "trigger_type", "notes"}],
    "dispensasi": [{"tanggal_mulai", "tanggal_selesai", "kegiatan", "nomor_surat"}],
    "ekskul": [{"extracurricular_id", "name", "status", "joined_at"}]
  }
}
```

Aturan hitung:

- **Akademik semester aktif** = `pantauNilaiCollect($db, kelas saat ini, TA aktif, semester aktif)`, lalu ambil baris murid ini. Angka harus **identik** dengan tab Nilai Pantau Nilai (AC-6). Murid nonaktif → `semester_aktif: []`.
- **Tren antar semester:**
  1. Kumpulkan pasangan (TA, semester, `class_id`) yang punya data murid: `ujian_scores WHERE student_id = ?` dan `journal_entries WHERE has_assessment = 1 AND isDraft = 0 AND JSON_SEARCH(assessment_data, 'one', ?, NULL, '$[*].student_id') IS NOT NULL` (periode diturunkan dari `date`: Jul–Des → GANJIL `Y/Y+1`, Jan–Jun → GENAP `Y-1/Y`; kebalikan `getSemesterDateRange`).
  2. Untuk setiap pasangan (maks. 12 periode terbaru, urut kronologis), panggil `pantauNilaiCollect` dengan parameter baru opsional `?array $studentsOverride` berisi murid ini saja. Tanpa override, kelas lama tidak memuat murid yang sudah naik kelas.
  3. Jika satu periode punya > 1 `class_id` (pindah kelas di tengah semester), nilai per mapel dari kelas yang punya data terbanyak untuk mapel itu.
  4. `rata_rata` = rata-rata `ref_score` mapel yang tidak null, 1 desimal.
- **Kehadiran per mapel:** jurnal non-draf kelas saat ini di rentang semester aktif, parse `studentAttendance` dengan `pantauNilaiParseAttendance`. Murid tidak tercatat → tidak dihitung.
- **Tidak hadir terakhir:** `attendance_records` status ≠ HADIR, semester aktif, terbaru dulu, maks. 20.
- **Catatan guru:** `assessment_data[].notes` tidak kosong untuk murid ini di jurnal non-draf tahun ajaran aktif (kedua semester), memakai `JSON_SEARCH` sebagai prefilter, lalu decode di PHP.
- **Pelanggaran:** `incidents WHERE studentId = ? AND (academic_year = TA aktif OR (academic_year IS NULL AND date di rentang TA))`. `total_poin` = `students.total_violation_points`.
- **Dispensasi:** jika tabel `student_dispensations` belum ada → `[]` (jangan membuat tabel dari sini).

### 4.5 Impor Dapodik

**Deteksi header** (`profilMuridDapodikHeader(array $rows): ?array`, fungsi murni):
- Cari di 15 baris pertama baris yang memuat sel "Nama" dan "NISN" (perbandingan dinormalisasi: huruf kecil, spasi tunggal, tanpa titik). Tidak ketemu → 400 "Format file tidak dikenali sebagai ekspor Peserta Didik Dapodik."
- Header dua tingkat: sel induk yang di-merge ("Data Ayah", "Data Ibu", "Data Wali") hanya terisi di sel pertama → **dibawa ke kanan** selama sel induk kosong dan baris bawahnya berisi sub-header. Kunci kolom = `"<induk> <sub>"` (mis. "data ayah nik"). Data dimulai setelah baris sub-header (atau langsung setelah header bila satu tingkat).
- Baris tanpa NISN dan tanpa NIK → `GALAT` "NISN dan NIK kosong". Baris yang semua selnya kosong diabaikan.

**Pemetaan kolom** (label Dapodik yang dinormalisasi → tujuan). Daftar lengkap ada sebagai konstanta `PROFIL_MURID_DAPODIK_PETA` di PHP dan dibagikan ke uji unit:

| Label Dapodik | Tujuan |
|---|---|
| NISN | kunci cocok |
| NIK | kunci cadangan + `students.nik` |
| Nama | hanya pembanding (nama **tidak** ditimpa; beda → peringatan) |
| NIPD | `nipd` |
| JK | `students.jenis_kelamin` (`L` → "Laki-laki", `P` → "Perempuan") |
| Tempat Lahir, Tanggal Lahir | `students.tempat_lahir`, `students.tanggal_lahir` |
| Agama | `students.agama` |
| Alamat | `students.alamat` (+ `students.address`) |
| RT, RW, Dusun, Kelurahan, Kecamatan, Kode Pos | kolom sejenis |
| Jenis Tinggal, Alat Transportasi | kolom sejenis |
| Telepon, HP, E-Mail | `telepon`, `hp`, `email` |
| Penerima KPS, No. KPS, Penerima KIP, Nomor KIP, Nama di KIP, Nomor KKS | kolom sejenis |
| Layak PIP (usulan dari sekolah), Alasan Layak PIP | `layak_pip`, `alasan_layak_pip` |
| Kebutuhan Khusus, Sekolah Asal, Anak ke-berapa, No KK | kolom sejenis |
| Berat Badan, Tinggi Badan, Lingkar Kepala, Jml. Saudara Kandung, Jarak Rumah ke Sekolah (KM) | kolom sejenis |
| Data Ayah/Ibu/Wali: Nama, Tahun Lahir, Jenjang Pendidikan, Pekerjaan, Penghasilan, NIK | `ayah_json`/`ibu_json`/`wali_json`. Data Ayah (bila kosong: Ibu, lalu Wali) juga mengisi `students.parent_name` dan `students.pekerjaan_ortu`. `parent_phone` **tidak** ditimpa (Dapodik tidak punya HP ortu) |
| Rombel Saat Ini | `rombel_dapodik` (pembanding kelas; beda → peringatan, kelas **tidak** diubah) |
| **Bank, Nomor Rekening Bank, Rekening Atas Nama, Lintang, Bujur** | **dibuang** (tidak disimpan di mana pun, tidak dikirim balik di pratinjau) |
| No, kolom lain | `dapodik_lain_json` (label asli → nilai) |

**Normalisasi:**
- NISN/NIK/No. KK: hanya digit. NISN 9 digit (nol depan hilang di Excel) → pad kiri ke 10. NIK/KK harus 16 digit, jika tidak → disimpan apa adanya + peringatan. Nilai notasi ilmiah (`6.37E+15`) → `GALAT` "NIK rusak (format angka Excel)".
- Tanggal: `YYYY-MM-DD`, `DD/MM/YYYY`, `DD-MM-YYYY`, serial Excel (angka 1–60000). Tidak valid → field dikosongkan + peringatan.
- Angka (berat, tinggi, jarak): koma desimal diterima. Tidak valid → null.
- Sel kosong di Dapodik **mengosongkan** field tujuan (Dapodik menimpa sepenuhnya), kecuali `students.*` yang wajib atau punya default (`jenis_kelamin`, `agama`): kosong → tidak diubah.

**Pencocokan:**
1. NISN cocok tepat ke satu `students.nisn` → murid itu.
2. Jika NISN kosong atau tidak cocok, cari `students.nik` = NIK → harus tepat satu.
3. Lebih dari satu murid → `KONFLIK`. Tidak ada → `TIDAK_DITEMUKAN`. Dua baris file mengarah ke murid yang sama → baris kedua `GALAT` "Duplikat dalam file".
4. Murid berstatus nonaktif tetap diperbarui (peringatan "status <x>").

**Penulisan (`mode: simpan`):** satu transaksi: `INSERT … ON DUPLICATE KEY UPDATE` ke `student_biodata` + `UPDATE students SET …` kolom yang dipetakan + satu baris `student_import_log`. Galat DB → rollback, 500 dengan pesan umum (tanpa stack trace).

### 4.6 Impor dan simpan asesmen

**Header template** (baris 1, urutan bebas saat impor, dicocokkan dengan label dinormalisasi):

`NISN | Nama | R | I | A | S | E | C | Kode RIASEC | Gaya Belajar | Skor Visual | Skor Auditori | Skor Kinestetik | Kesulitan Belajar | Mapel Termudah | Mapel Tersulit | Cita-cita | Rencana Setelah Lulus | Jurusan Tujuan | Institusi Tujuan | Catatan Karir`

Validasi per baris (`profilMuridValidasiAsesmen(array $data): array`, fungsi murni, dipakai impor dan simpan manual):

| Field | Aturan |
|---|---|
| NISN | Wajib (impor). Dinormalisasi seperti §4.5. Harus murid **aktif** kelas terpilih → jika tidak, `GALAT` "Bukan murid aktif kelas ini" |
| Nama | Diabaikan (informasi saja) |
| R/I/A/S/E/C | Opsional, angka 0–100 (koma desimal diterima). Di luar rentang → `GALAT` |
| Kode RIASEC | Jika ada ≥ 1 skor: **dihitung** = 3 huruf skor tertinggi, seri dipecah dengan urutan R,I,A,S,E,C; nilai di file diabaikan (peringatan bila berbeda). Jika tidak ada skor: diambil dari file, harus 1–3 huruf unik dari `RIASEC` |
| Gaya Belajar | `V`/`Visual` → VISUAL, `A`/`Auditori`/`Auditorial` → AUDITORI, `K`/`Kinestetik`/`Kinestetis` → KINESTETIK. Kosong + ada skor V/A/K → skor tertinggi (seri: urutan V,A,K). Lainnya → `GALAT` |
| Skor V/A/K | Opsional, 0–100 |
| Kesulitan Belajar, Catatan Karir | Teks bebas, maks. 1.000 karakter |
| Mapel Termudah/Tersulit | Dipisah `,` atau `;`, dirapikan, maks. 3 masing-masing (lebih → `GALAT`). Disimpan sebagai teks (tidak wajib cocok dengan `subjects`) |
| Cita-cita, Jurusan, Institusi | Maks. 150 karakter |
| Rencana Setelah Lulus | `Kuliah`, `Kerja`, `Wirausaha`, `Kuliah sambil kerja`, `Belum tahu`, `Lainnya` (huruf bebas) → enum. Lainnya → `GALAT` |
| Baris tanpa satu pun field asesmen/karir | `LEWATI` (tidak menghapus data yang ada) |

Upsert berdasarkan `(student_id, academic_year, semester)`. Data periode yang sama **ditimpa seluruhnya** oleh isi baris (sel kosong → null). `class_id`/`class_name` = kelas murid saat ini. Log `{aksi: "IMPOR"|"BUAT"|"UBAH", oleh, oleh_nama, waktu, impor_id}` ditambahkan ke `logs` (maks. 50 entri terakhir).

### 4.7 `cetak_profil_murid` (HTML)

- Akses = `profil_murid_get`. Galat dirender dengan `rekapHtmlError`.
- A4 portrait ber-Kop (`rekapLoadKop`). Isi: identitas + foto; biodata inti (penyamaran sama dengan layar); asesmen periode terbaru (tabel skor RIASEC + kode, gaya belajar, kesulitan, mapel); rencana karir; tabel akademik semester aktif (mapel di bawah KKTP ditandai); tabel tren (periode × rata-rata, tanpa grafik); kehadiran semester aktif; rekap pelanggaran + jumlah panggilan ortu; ekskul aktif. Tidak memuat catatan guru lengkap (hanya 5 terbaru) dan tidak memuat "Data Dapodik lainnya".
- TTD: "Wali Kelas" (dari `classes.homeroom_teacher`/users) dan "Guru BK" (kosong untuk ditandatangani), tanggal cetak dalam zona `APP_TIMEZONE`.
- Semua nilai dari DB di-escape dengan `rekapEsc`.

### 4.8 Perubahan pada file yang ada

- `api/pantau_nilai.php`: `pantauNilaiCollect(…, ?string $forceSubject = null, ?array $studentsOverride = null)`. Override menggantikan hasil `pantauNilaiStudents`. Tanpa argumen baru, perilaku **tidak berubah** (AC-6 dan uji Pantau Nilai yang ada tetap lulus).
- `api/index.php`: `require_once` + 8 `case`.
- Tidak ada perubahan pada `get_homeroom_dashboard`, `import_students`, atau endpoint siswa lainnya.

## 5. Kontrak frontend (patch bundle)

Skrip `docs/patches/apply-profil-murid-bundle.js` membaca `assets/index-CGWL69P7-harisekolah1.js`, menulis `assets/index-CGWL69P7-profilmurid2.js`, lalu mengganti rujukan di `app.html`. Aturan sama dengan patch sebelumnya: setiap anchor harus ditemukan **tepat sekali** (jika tidak, skrip berhenti), hanya kelas Tailwind yang sudah ada di `assets/index-CC1PBzaO.css`, komponen ditulis dengan `a.jsx`/`a.jsxs` dan `ue.useState`/`ue.useEffect` seperti `dot`.

1. **State navigasi murid:** `window.__pmSid` + `sessionStorage['simantap_pm_sid']` (dibungkus try/catch) menyimpan `student_id` dan halaman asal. `n("profil-murid")` dipanggil setelah mengisinya. Router aplikasi tidak menyimpan halaman aktif, sehingga refresh kembali ke Dashboard.
2. **Komponen `__ProfilMurid`** (`{user, studentId, onBack}`): memuat `profil_murid_get`, merender header + tujuh bagian §3.4, form edit asesmen (modal), dan tombol Cetak (`window.open(\`${API}?action=cetak_profil_murid&student_id=…\`, '_blank')`). Grafik memakai komponen recharts yang sudah ada di bundle (identifier minify dicari saat menulis patch); jika tidak bisa dijangkau dengan aman, fallback ke SVG inline sederhana. Galat (403/404) ditampilkan di kartu, bukan `alert`.
3. **Komponen `__DaftarProfilMurid`** (`{user}`): pemilih kelas (hanya scope ALL), pencarian, tabel, serta tombol Unduh Template dan Impor Asesmen (modal pratinjau → simpan, §3.3).
4. **Dashboard Kelas Saya (`dot`):** pemetaan `per_siswa` menyimpan `student_id`. Sel nama murid di tabel rekap menjadi tombol yang membuka profil (asal = `classroom-dashboard`). Tambah tombol "👤 Profil & Asesmen Kelas" di header tab yang membuka `__DaftarProfilMurid` terkunci ke kelas perwalian (untuk impor asesmen).
5. **Sidebar:** item "Profil Murid 360°" (`bk-profil-murid`) setelah "Pantau Nilai Kelas" di blok BK. Untuk KEPALA_SEKOLAH/ADMIN, item yang sama di grup menu Kesiswaan (atau grup pertama jika anchor Kesiswaan tidak tersedia untuk role itu).
6. **Router:** `bk-profil-murid` → `__DaftarProfilMurid`; `profil-murid` → `__ProfilMurid` dengan `onBack` ke halaman asal. Kedua id ditambahkan ke daftar halaman valid (array yang berisi `"bk-grades"`).
7. **Data Siswa TU (`data-siswa`):** tombol "Impor Biodata Dapodik" (TATA_USAHA/ADMIN) + modal pratinjau (tabel status per baris, kolom yang dibuang ditampilkan sebagai info) → simpan → ringkasan + unduh daftar tidak ditemukan.

## 6. File dan interface terkait

| File | Perubahan |
|---|---|
| `api/profil_murid.php` (**baru**) | `profilMuridPastikanTabel`, `profilMuridScope`, `profilMuridWajibMurid`, `profilMuridSamarkan`, fungsi murni parser (`profilMuridNormalLabel`, `profilMuridDapodikHeader`, `profilMuridPetakanDapodik`, `profilMuridNormalNomor`, `profilMuridNormalTanggal`, `profilMuridValidasiAsesmen`, `profilMuridKodeRiasec`, `profilMuridPeriodeDariTanggal`), handler 8 action, renderer cetak |
| `api/index.php` | `require_once __DIR__ . '/profil_murid.php';` setelah baris 54 + 8 `case` |
| `api/pantau_nilai.php` | Parameter opsional `$studentsOverride` di `pantauNilaiCollect` (§4.8) |
| `api/kesiswaan_access.php`, `api/rekap_bulanan.php`, `api/db.php` | Dipakai ulang, tidak diubah (`loadFreshUser`, `studentInScope`, `resolveClassRef`, `KESISWAAN_MSG_KELAS`, `rekap*`, `getActiveAcademicPeriod`, `getSemesterDateRange`) |
| `docs/patches/apply-profil-murid-bundle.js` (**baru**) | Patch bundle §5 |
| `assets/index-CGWL69P7-profilmurid2.js` (**hasil generate**), `app.html` | Hasil skrip patch |
| `docs/patches/profil-murid-360.md` (**baru**) | Catatan patch: cara menerapkan, anchor, rollback (kembalikan `app.html` ke `harisekolah1`, hapus `require_once` + `case`, tabel baru boleh dibiarkan), prasyarat akun uji |
| `docs/patches/uji-profil-murid-api.mjs` (**baru**) | Skrip uji API §10.1 |
| `docs/patches/uji-profil-murid-unit.php` (**baru**) | Uji unit fungsi murni §10.2 |
| `docs/patches/uji-profil-murid-seed.sql`, `uji-profil-murid-cleanup.sql` (**baru**) | Data uji + pembersih |
| `docs/patches/uji-profil-murid-dapodik.json`, `uji-profil-murid-asesmen.json` (**baru**) | Contoh `rows` 2D (header dua tingkat, NISN tanpa nol depan, notasi ilmiah, kolom bank/koordinat) |
| `CLAUDE.md` | Baris tabel domain untuk `api/profil_murid.php` |

Tabel baru: `student_biodata`, `student_asesmen`, `student_import_log`. Tabel yang ditulis: `students` (kolom biodata, hanya oleh impor Dapodik). Tabel yang dibaca: `journal_entries`, `ujian_scores`, `attendance_records`, `incidents`, `parent_calls`, `student_dispensations`, `extracurricular_members`, `extracurriculars`, `classes`, `users`, `subjects`, `teacher_subjects`, `system_config`.

## 7. Edge cases

| Kasus | Perilaku |
|---|---|
| Wali membuka murid yang sudah pindah kelas/nonaktif | 403 `KESISWAAN_MSG_KELAS` |
| User kehilangan status wali/BK tanpa login ulang | Scope dibaca ulang dari DB → 403 |
| Guru yang juga wali dan BK | Mode ALL (BK menang), NIK tampil penuh |
| Murid tanpa biodata Dapodik | `biodata: null`, tampil data `students` + pesan |
| Murid tanpa asesmen | Bagian 2–3 kosong + tombol "Isi asesmen" (bila boleh edit) |
| Murid belum punya nilai | Tabel akademik "Belum ada penilaian semester ini", tren kosong |
| Tren dengan satu periode | Ditampilkan sebagai titik, bukan garis |
| `assessment_data`/`studentAttendance` rusak | Dianggap kosong, tidak menghentikan request |
| Tahun ajaran dengan format tak dikenal | `getSemesterDateRange` null → filter tanggal dilewati (seperti Pantau Nilai); periode tren dari jurnal tidak bisa diturunkan → hanya dari `ujian_scores` |
| Excel Dapodik dengan NISN kehilangan nol depan | Dipad ke 10 digit |
| NIK dalam notasi ilmiah | `GALAT` baris, tidak menimpa NIK yang ada |
| Dua murid SIMANTAP dengan NISN sama | `KONFLIK`, tidak ditulis |
| File Dapodik dari sekolah lain | Mayoritas `TIDAK_DITEMUKAN`. Jika > 80% baris tidak ditemukan, pratinjau menampilkan peringatan besar "Periksa apakah file ini milik sekolah Anda" |
| Format ekspor Dapodik berubah (label kolom baru) | Kolom tak dikenal masuk `dapodik_lain_json`; kolom wajib (Nama, NISN) tidak ada → 400 |
| Impor asesmen ke periode yang sudah diisi manual | Status `TIMPA` di pratinjau; setelah simpan, `sumber = IMPOR` dan log mencatat keduanya |
| Murid berpindah kelas di tengah semester lalu asesmen diimpor kelas baru | Periode yang sama ditimpa; `class_id` asesmen = kelas baru |
| Simpan ulang setelah pratinjau, data berubah di antaranya | `mode: simpan` memvalidasi ulang; hasil bisa berbeda dari pratinjau dan dilaporkan apa adanya |
| Dua pengguna mengimpor kelas yang sama bersamaan | Upsert per baris dalam transaksi; yang terakhir menang (diterima, tercatat di log) |
| Pencarian `q` < 3 karakter | 400 |
| `student_dispensations` belum ada | Bagian dispensasi kosong |
| Pas foto tidak ada | Avatar inisial |

## 8. Compatibility

- Tidak mengubah kontrak endpoint yang ada. `pantauNilaiCollect` hanya mendapat parameter opsional baru.
- Tabel baru dibuat saat pertama dipakai, sehingga tidak perlu migrasi untuk patch lokal. Untuk usulan upstream, DDL §4.1 diusulkan masuk `install/schema.sql` + migrasi bertanda di `schema_migrations` (dikerjakan upstream; file DIGENERATE tidak diedit lokal).
- Impor Dapodik **menimpa** kolom `students` (`nik`, `tempat_lahir`, `tanggal_lahir`, `jenis_kelamin`, `agama`, `alamat`/`address`, `parent_name`, `pekerjaan_ortu`). Buku Induk TU ikut berubah. Ini disengaja (Dapodik sebagai sumber kebenaran).
- Auto-updater portal bisa menimpa `app.html`, `api/index.php`, dan `api/pantau_nilai.php`. Setelah update, jalankan ulang skrip patch dan periksa `require_once`/`case` (dicatat di `docs/patches/profil-murid-360.md`).
- Bundle dasar `harisekolah1`. Jika patch lain diterapkan lebih dulu, ganti file sumber di skrip patch dan pastikan anchor tetap unik.
- Ekspor Dapodik yang didukung: file `.xlsx`/`.xls` "Daftar Peserta Didik" dari aplikasi Dapodik versi 2024c ke atas (header dua tingkat untuk data orang tua). Versi lain didukung selama label kolom inti sama.

## 9. Di luar scope

- Radar kompetensi 8 dimensi profil lulusan, kuesioner bulanan murid, peran "guru wali", dan target akademik → spesifikasi **Bimbingan Guru Wali** (berikutnya). Di sini hanya slot "Belum tersedia".
- Login murid/orang tua dan pengisian angket langsung oleh murid.
- Penskoran instrumen RIASEC/gaya belajar dari jawaban butir angket, serta penyediaan instrumen baku.
- Sinkronisasi langsung dengan web service Dapodik, dan pembuatan murid baru atau pemindahan kelas dari file Dapodik.
- Impor biodata oleh wali kelas; edit manual biodata Dapodik di halaman profil (tetap lewat Buku Induk TU untuk kolom `students`).
- Akses TATA_USAHA dan guru mapel ke halaman profil.
- Catatan konseling BK (sesi bimbingan, tindak lanjut).
- Ekspor profil ke PDF/Word selain cetak HTML browser.
- Perbaikan celah yang sudah ada (mis. `student_list`/`get_student_registry` tanpa login, `tu_file_*`). Dicatat sebagai temuan di §11.

## 10. Pengujian dan verifikasi

### 10.1 Skrip uji API `docs/patches/uji-profil-murid-api.mjs`

Prasyarat: `api/config.local.php` dengan `SIMANTAP_DEV_MODE`, `api/install.lock`, seed `uji-profil-murid-seed.sql` (kelas `UJI_PM_A`, `UJI_PM_B`; 4 murid termasuk satu nonaktif dan satu yang pindah dari `UJI_PM_A` ke `UJI_PM_B` dengan nilai di semester lalu; akun ADMIN, KS, TU, GURU wali `UJI_PM_A`, GURU BK, GURU mapel biasa; jurnal ber-penilaian dengan catatan; insiden; panggilan ortu; ekskul). Skrip login per akun dan memeriksa:

1. **Otorisasi baca:** `profil_murid_get` → wali kelasnya 200; wali murid kelas lain 403; wali murid nonaktif 403; BK/KS/ADMIN 200 untuk semua; TU 403; guru mapel 403; tanpa login 401.
2. **Penyamaran:** wali menerima `nik`/`no_kk`/`ayah.nik` tersamar, BK menerima penuh. Cetak wali juga tersamar.
3. **Metode:** POST ke action GET dan sebaliknya → 405.
4. **Impor Dapodik:** TU pratinjau `uji-profil-murid-dapodik.json` → status per baris sesuai harapan (`PERBARUI`, `TIDAK_DITEMUKAN`, `KONFLIK`, `GALAT` notasi ilmiah, NISN dipad); tidak ada perubahan DB setelah pratinjau; simpan → `student_biodata` terisi, `students.nik` diperbarui, `students.name` dan `class_id` tidak berubah; kolom bank/lintang/bujur tidak ada di DB maupun respons; wali/BK → 403.
5. **Impor asesmen:** wali pratinjau + simpan untuk kelasnya → baris tersimpan dengan `riasec_kode` hasil hitung; baris NISN kelas lain → `GALAT`; wali ke kelas lain → 403; impor ulang → `TIMPA` dan data tertimpa; baris kosong → `LEWATI` dan data lama tetap; semester di luar TA aktif → 400.
6. **Simpan/hapus manual:** validasi (skor 101 → 400, rencana tidak dikenal → 400), log bertambah, aktor = session.
7. **Isi ringkasan:** `akademik.semester_aktif` identik dengan baris murid di `get_class_grade_overview`; `tren` memuat periode lalu di kelas lama; kehadiran per mapel dan catatan guru sesuai seed.
8. **Daftar:** `profil_murid_daftar` wali tanpa `class_id` → kelasnya; `q` oleh wali → 403; `q` 2 karakter → 400.

### 10.2 Uji unit `docs/patches/uji-profil-murid-unit.php`

Jalankan `php docs/patches/uji-profil-murid-unit.php`. Kasus: normalisasi label; deteksi header satu dan dua tingkat (merge "Data Ayah" dibawa ke kanan); header di baris ke-5; NISN 9 → 10 digit; NIK notasi ilmiah → galat; tanggal (`YYYY-MM-DD`, `DD/MM/YYYY`, serial Excel 45000, tak valid); kode RIASEC termasuk seri dan tanpa skor; gaya belajar sinonim; mapel > 3 → galat; periode dari tanggal (Juli, Desember, Januari, Juni); penyamaran (panjang 16, 8, kosong).

### 10.3 Lint

```
php -l api/profil_murid.php
php -l api/pantau_nilai.php
php -l api/index.php
node --check docs/patches/apply-profil-murid-bundle.js
node --check assets/index-CGWL69P7-profilmurid2.js
```

Uji Pantau Nilai yang ada (`docs/patches/uji-pantau-nilai-api.mjs`) dijalankan ulang dan tetap lulus.

### 10.4 Verifikasi end-to-end (manual, di browser)

1. Jalankan skrip patch; `app.html` merujuk `profilmurid2`; aplikasi termuat tanpa error konsol.
2. **TU:** Data Siswa → Impor Biodata Dapodik → pilih file ekspor Dapodik asli sekolah → pratinjau menampilkan jumlah per status dan kolom yang dibuang → Simpan → ringkasan cocok; unduh daftar tidak ditemukan.
3. **Wali kelas:** Dashboard Kelas Saya → "Profil & Asesmen Kelas" → Unduh Template → isi 3 baris di Excel (satu baris salah NISN) → Impor → pratinjau (2 BARU, 1 GALAT) → Simpan.
4. Klik nama murid di tabel rekap → profil terbuka: biodata (NIK tersamar), asesmen + grafik RIASEC, rencana karir, akademik + tren, slot kompetensi/target "Belum tersedia", kehadiran, tab riwayat. "← Kembali" → dashboard. (Refresh browser mengembalikan aplikasi ke Dashboard, sesuai perilaku router aplikasi; murid terakhir tetap tersimpan di `sessionStorage`.)
5. Edit asesmen murid (ubah gaya belajar) → tersimpan dan tampil.
6. Cetak → A4 ber-Kop, NIK tersamar, TTD Wali Kelas/Guru BK; print preview muat tanpa elemen terpotong.
7. **Guru BK:** menu Profil Murid 360° → ganti kelas → cari nama murid kelas lain → buka profil → NIK tampil penuh; riwayat periode asesmen bisa dipilih.
8. **KS/ADMIN:** menu tampil dan profil bisa dibuka. **TU** dan **guru mapel biasa:** menu tidak tampil; membuka URL action langsung → 403.
9. Ubah `class_homeroom` wali lewat Kelola Guru tanpa logout → profil murid kelas lama → 403.
10. Tampilan ponsel (lebar 375px): bagian bertumpuk satu kolom, tabel bisa digeser horizontal di dalam kartu.

## 11. Risiko, trade-off, dan temuan

| Risiko / trade-off | Mitigasi / catatan |
|---|---|
| **PII bertambah** (data keluarga, penghasilan, KIP, kebutuhan khusus) | Kolom bank dan koordinat dibuang; NIK/KK disamarkan untuk wali di backend; halaman hanya untuk wali/BK/KS/ADMIN dengan scope dibaca ulang dari DB; tidak ada endpoint baru tanpa login |
| **Temuan yang ada:** `student_list` dan `get_student_registry` bisa dibaca tanpa login dan mengembalikan kolom `students` (termasuk `nik`, `tanggal_lahir`, `alamat`) | Impor Dapodik akan **mengisi** kolom itu untuk lebih banyak murid, sehingga kebocoran yang sudah ada membesar. Sangat disarankan memperbaiki autentikasi `student_list`/`get_student_registry` **sebelum** impor Dapodik pertama di produksi (spesifikasi terpisah) |
| Dapodik menimpa data `students` yang sudah dikoreksi manual | Keputusan wawancara. Pratinjau menunjukkan baris yang akan diperbarui; nama dan kelas tidak pernah ditimpa |
| Format ekspor Dapodik bisa berubah antar versi | Pemetaan berbasis label (bukan posisi), kolom tak dikenal masuk JSON, contoh file uji disimpan di repo |
| Parsing Excel di browser (SheetJS) vs server | Dipilih browser karena SheetJS sudah ada di bundle dan PHP di sini tanpa Composer. Logika pemetaan tetap di PHP agar teruji unit dan tidak bisa dilewati klien |
| Tren antar semester memanggil `pantauNilaiCollect` hingga 12 kali | Diterima untuk satu murid (kelas ~36 murid, query terindeks). Jika lambat (> 2 detik), tren dipindah ke action terpisah yang dimuat setelah halaman (lazy) |
| `JSON_SEARCH` pada `assessment_data` memindai banyak baris | Dibatasi `has_assessment = 1 AND isDraft = 0` dan rentang tanggal TA untuk catatan guru; tren tanpa rentang tetapi hanya sekali per halaman |
| Asesmen dari skala instrumen berbeda antar sekolah | Skor disimpan 0–100 dan dibandingkan relatif di dalam satu murid; tidak ada perbandingan antar murid di scope ini |
| Wali bisa menghapus/menimpa asesmen kelasnya | Log per catatan + `student_import_log`; penghapusan ditulis ke `error_log` server dengan aktor dan id |
| Update portal menimpa patch | Didokumentasikan di catatan patch; diusulkan ke upstream |
| `KKTP` vs `kkm_default` | UI memakai istilah KKTP, nilai dari `kkm_default` yang sama dengan Pantau Nilai |

## 12. Acceptance criteria

- **AC-1** Wali kelas membuka profil murid aktif kelasnya dari Dashboard Kelas Saya dengan sekali klik pada nama. Murid di luar kelasnya, nonaktif, atau lewat URL langsung → 403.
- **AC-2** Guru BK, KS, dan ADMIN membuka profil murid mana pun lewat menu "Profil Murid 360°" (pemilih kelas + pencarian). TATA_USAHA, guru mapel biasa, dan pengguna tanpa login ditolak di backend.
- **AC-3** Halaman memuat header + tujuh bagian §3.4. Bagian kompetensi dan target akademik tampil sebagai slot "Belum tersedia".
- **AC-4** Impor Dapodik (TU/ADMIN) melalui pratinjau tanpa menulis DB, lalu simpan transaksional; cocok via NISN lalu NIK; murid yang tidak ditemukan dilewati dan dilaporkan; nama dan kelas tidak berubah; kolom bank/rekening/lintang/bujur tidak tersimpan di mana pun.
- **AC-5** NIK dan No. KK (murid dan orang tua) tersamar untuk wali kelas di JSON maupun cetak, dan tampil penuh untuk BK/KS/ADMIN.
- **AC-6** Angka akademik semester aktif identik dengan baris murid yang sama di `get_class_grade_overview`. Uji Pantau Nilai yang ada tetap lulus.
- **AC-7** Tren menampilkan nilai acuan per mapel per semester, termasuk semester ketika murid berada di kelas lain.
- **AC-8** Impor asesmen (wali untuk kelasnya, BK/KS/ADMIN untuk semua) dari template SIMANTAP: pratinjau per baris, galat per baris tidak membatalkan baris valid, kode RIASEC dihitung otomatis, impor ulang periode yang sama menimpa, baris kosong tidak menghapus data.
- **AC-9** Asesmen + rencana karir disimpan per TA + semester. Halaman menampilkan yang terbaru dan riwayat periode sebelumnya. Edit manual hanya untuk periode di TA aktif dan tercatat di log dengan aktor dari session.
- **AC-10** Cetak A4 ber-Kop memuat ringkasan §4.7 dan TTD Wali Kelas/Guru BK.
- **AC-11** Kehadiran harian, kehadiran per mapel, catatan guru, pelanggaran/poin, panggilan ortu, dispensasi, dan ekskul sesuai data seed.
- **AC-12** `php -l` dan `node --check` lulus untuk semua file yang berubah; skrip uji API dan uji unit lulus; checklist §10.4 selesai.
