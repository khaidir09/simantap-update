# Spesifikasi: Penugasan Murid Dampingan Guru Wali

| | |
|---|---|
| Status | Diimplementasikan (patch lokal) — lihat `docs/patches/guru-wali.md` |
| Versi bundle acuan | SIMANTAP 1.0.2 (`api/version.php`), bundle aktif `assets/index-CGWL69P7-profilmurid2.js` |
| Tanggal | 2026-11-12 |
| Jalur rilis | Patch lokal pada bundle ini **dan** usulan perubahan ke upstream BTIKP |
| Rangkaian fitur | **Bimbingan Guru Wali**, tahap 1 dari 3: (1) penugasan murid dampingan ← *spesifikasi ini*; (2) impor hasil instrumen digital (Google Form) yang diisi murid; (3) jurnal pendampingan |

## 1. Latar belakang dan kebutuhan bisnis

Sekolah menugaskan setiap guru sebagai **guru wali** bagi sekelompok murid. Berbeda dari wali kelas:

- murid ditautkan ke **guru**, bukan ke kelas;
- pendampingan berjalan sejak murid terdaftar hingga lulus, jadi murid dampingan satu guru bisa berasal dari beberapa kelas dan angkatan;
- penugasan bertahan lintas tahun ajaran dan semester (kenaikan kelas tidak mengubahnya);
- jika guru wali diganti, riwayatnya tetap tersimpan: siapa mendampingi, sejak kapan, sampai kapan, dan alasannya.

Penugasan ini menjadi fondasi untuk tahap berikutnya. Instrumen digital dan jurnal pendampingan hanya bisa dicatat oleh atau dikaitkan ke guru wali yang sah pada tanggal tersebut. Karena itu model datanya harus menjawab: *"Siapa guru wali murid M pada tanggal T?"*

Profil Murid 360° (`docs/specs/profil-murid-360.md`) sudah menyisakan slot "Bimbingan Guru Wali". Scope ini mengisi identitas guru wali di profil dan memberi guru wali akses baca ke profil murid dampingannya.

### 1.1 Keputusan wawancara

| Topik | Keputusan |
|---|---|
| Penugas | **ADMIN + KEPALA_SEKOLAH** boleh menugaskan, mengganti, mengakhiri, dan membatalkan. TATA_USAHA dan GURU (termasuk BK) tidak boleh |
| Calon guru wali | **Semua akun berperan `GURU`**, termasuk wali kelas dan guru BK. Peran lain ditolak |
| Kardinalitas | **Tepat satu** guru wali aktif per murid. Mengganti = menutup penugasan lama dan membuka yang baru |
| Kuota | **Peringatan, tidak memblokir**. Ambang diatur ADMIN (`system_config.guru_wali_kuota_peringatan`, bawaan 20). Guru di atas ambang ditandai |
| Riwayat penggantian | Wajib **tanggal efektif** (bawaan hari ini, boleh mundur, tidak boleh ke masa depan) dan **alasan** dari daftar + catatan opsional. Pengubah dicatat dari session |
| Murid tidak aktif lagi | **Dianggap selesai saat dibaca.** Tidak ada hook di modul siswa. Penugasan terbuka milik murid nonaktif tidak dihitung dan tidak tampil di daftar dampingan. ADMIN/KS punya tombol "Tutup penugasan murid nonaktif" (alasan `MURID_NONAKTIF`) |
| Akun guru dihapus | `delete_user`/`gtk_delete` **ditolak (409)** selama guru masih punya dampingan aktif (murid aktif). Penugasan terbuka milik murid nonaktif ditutup otomatis sebelum penghapusan |
| Input massal | (a) Centang murid → pilih guru; (b) **Impor Excel** (NISN + email guru, cadangan NIP) dengan pratinjau |
| Konflik impor | Pratinjau menandai baris "akan mengganti X". Diganti hanya jika pengguna mencentang "Ganti guru wali yang sudah ada" (satu alasan + tanggal efektif untuk semua). Tanpa centang, baris itu dilewati |
| Salah input | **Batalkan** (bukan hapus): status `DIBATALKAN` + siapa/kapan/alasan. Disembunyikan dari riwayat resmi. Jika penugasan itu menggantikan penugasan sebelumnya, penugasan sebelumnya dipulihkan |
| Murid yang bisa ditugaskan | **Hanya murid aktif** (400 untuk yang lain) |
| Akses guru wali | Menu **"Murid Dampingan"** (daftar) + akses baca **Profil Murid 360°** murid dampingan aktif. NIK/KK disamarkan seperti untuk wali kelas. Guru wali **tidak** bisa mengedit asesmen di scope ini |
| Tampil di tempat lain | Header + tab Identitas **Profil Murid 360°**; kolom "Guru Wali" di **Dashboard Kelas Saya** |
| Menu pengelolaan | Menu baru **"Guru Wali"** untuk ADMIN/KS (tab Per Guru, Murid, Impor, Riwayat) |
| Rilis & uji | Patch lokal + usulan upstream. Skrip uji API `.mjs` + seed/cleanup SQL, uji unit PHP, `php -l` + `node --check`, checklist E2E manual |

## 2. Perilaku saat ini (hasil analisis kode)

### 2.1 Murid dan kelas

- `students.id` adalah identitas tetap murid. Kenaikan kelas mengubah `class_id`/`class_name` **di baris yang sama** (tidak ada fitur kenaikan kelas yang membuat baris baru), jadi tautan ke `students.id` otomatis bertahan lintas tahun ajaran.
- `students.status` bercampur huruf: `AKTIF` (enum skema) dan `Aktif`/`Pindah`/`Lulus`/… (VARCHAR setelah modul TU). Kriteria "aktif" yang sudah dipakai: `profilMuridAktif()` di `api/profil_murid.php:631` (`''` atau `UPPER(status) = 'AKTIF'`).
- `students.enrollment_year` ada di skema tetapi tidak diisi konsisten. Tidak dipakai untuk logika.

### 2.2 Peran guru

- Hanya ada dua penanda peran guru: wali kelas (`users.class_homeroom`) dan guru BK (`users.is_bk`). Konsep **guru wali (pendamping murid) belum ada sama sekali**, baik tabel, kolom, maupun action.
- `gtk_pegawai.id = users.uid`. `delete_user` (`api/index.php:295`, ADMIN/KS) dan `gtk_delete` (`api/endpoints_tu.php:372`) **menghapus permanen** baris `users` beserta `teacher_subjects`. Tidak ada soft delete.
- `save_user`/`save_user_v2` bisa mengubah `users.role`. `gtk_update` tidak mengubah role.
- `requireAuth()`/`requireRole()` hanya membaca session. `loadFreshUser()` (`api/kesiswaan_access.php:43`) membaca ulang user dari DB dan dipakai fitur kesiswaan.

### 2.3 Profil Murid 360°

- `profilMuridScope()` (`api/profil_murid.php:626`) = `pantauNilaiScope()`: `ALL` (ADMIN, KS, GURU BK), `CLASS` (wali kelas non-BK), `DENY` (lainnya). Fungsi ini **juga dipakai** Pantau Nilai, jadi `pantauNilaiScope` tidak boleh diubah.
- `profilMuridAmbilMurid()` (`:641`) mengecek murid ada, lalu untuk mode CLASS murid harus aktif dan berada di kelas perwalian.
- Penyamaran NIK/KK diterapkan bila `$scope['mode'] === 'CLASS'` (`:1005`).
- `profilMuridKumpulkan()` menyusun respons `profil_murid_get`. Tab Identitas menampilkan biodata.
- Bundle membuka profil lewat `__pmBuka(studentId, asal, navigasi)` (`window.__pmSid` + `sessionStorage simantap_pm_sid`), lihat `docs/patches/apply-profil-murid-bundle.js:171`.

### 2.4 Dashboard Kelas Saya

- `get_homeroom_dashboard` (`api/endpoints_v3.php:80`) mengembalikan `per_siswa[]` (dengan `student_id`). Action ini **tidak memeriksa login** (lihat bagian keamanan di `CLAUDE.md`).

### 2.5 Konfigurasi

- Key ADMIN-only disimpan lewat `save_system_config` (`api/index.php:1671`, dengan validasi). `save_config` (`api/endpoints_v2.php:198`) menolak key tertentu (`VJ_KEY_MODE`, `PK_KEY_HARI`) agar validasi tidak dilewati.

## 3. Expected behavior

### 3.1 Istilah

| Istilah | Arti |
|---|---|
| Penugasan | Satu baris `guru_wali_penugasan`: murid M didampingi guru G dari `tanggal_mulai` sampai `tanggal_selesai` (NULL = masih berjalan) |
| Penugasan aktif | `status = 'AKTIF'` (tanggal_selesai NULL). Maksimal satu per murid (dijamin unique key) |
| Penugasan efektif | Penugasan aktif **dan** murid berstatus aktif. Hanya ini yang dihitung kuota, tampil di "Murid Dampingan", dan memberi akses profil |
| Penugasan menggantung | Penugasan aktif milik murid **nonaktif**. Ditampilkan sebagai "Selesai (murid nonaktif)" dan bisa ditutup massal |
| Riwayat resmi | Penugasan berstatus `AKTIF` + `SELESAI`. `DIBATALKAN` hanya tampil bila diminta (`termasuk_batal=1`) |

### 3.2 Alur ADMIN/KS: menugaskan lewat centang

1. Buka menu **Guru Wali** → tab **Murid**.
2. Saring: kelas (opsional), status penugasan (`Semua` / `Belum punya guru wali` / `Sudah punya`), guru wali tertentu, pencarian nama/NISN. Hanya murid aktif yang tampil.
3. Centang beberapa murid (maks. 200 per simpan) → **Tugaskan ke guru…** → modal: pilih guru (dropdown semua GURU, menampilkan jumlah dampingan efektif/kuota), tanggal mulai (bawaan hari ini).
4. Jika ada murid terpilih yang sudah punya guru wali **lain**, server membalas 409 `perlu_konfirmasi` berisi daftar murid dan guru lamanya. Modal menampilkan daftar itu dan meminta alasan penggantian (wajib) + catatan, lalu kirim ulang dengan `ganti: true`.
5. Murid yang sudah didampingi guru yang **sama** dilewati (dilaporkan `sama`).
6. Jika setelah simpan jumlah dampingan guru melebihi kuota, respons berisi `peringatan_kuota` dan UI menampilkan toast kuning. Simpan tetap berhasil.
7. Simpan bersifat **all-or-nothing** dalam satu transaksi.

### 3.3 Alur ADMIN/KS: per guru

Tab **Per Guru** menampilkan tabel semua akun GURU: nama, NIP, wali kelas (jika ada), BK (badge), **jumlah dampingan efektif**, dan badge kuning "Melebihi kuota" bila > ambang. Klik baris → panel daftar dampingan guru itu (nama, NISN, kelas saat ini, sejak). Dari panel:

- **Pindahkan ke guru lain** (centang sebagian/semua) → sama dengan 3.2 langkah 3–4, dengan `ganti: true` dan alasan wajib.
- **Akhiri penugasan** (centang) → tanggal selesai + alasan → murid menjadi "belum punya guru wali".

Di atas tab ada ringkasan: total murid aktif, murid aktif **tanpa** guru wali (tautan ke tab Murid dengan filter "Belum punya"), dan bila ada, banner "N penugasan masih terbuka untuk murid yang sudah tidak aktif" + tombol **Tutup sekarang**.

### 3.4 Alur ADMIN/KS: impor Excel

1. Tab **Impor** → **Unduh template** (`Template-Guru-Wali-<kelas|semua>.xlsx`, dibuat di browser dengan SheetJS). Kolom: `NISN`, `Nama Murid`, `Kelas`, `Email Guru Wali`, `NIP Guru Wali`, `Nama Guru Wali`. Baris berisi murid aktif (opsional per kelas). Kolom guru terisi guru wali aktif saat ini bila ada.
2. Pengguna mengisi lalu mengunggah file. Browser mem-parse dengan SheetJS (sheet pertama, baris 1 = header, header dicocokkan tanpa memedulikan huruf besar/kecil dan spasi) lalu mengirim baris ke `guru_wali_impor` dengan `pratinjau: true`.
3. Server mengembalikan status per baris:

   | Status | Arti | Tindakan saat simpan |
   |---|---|---|
   | `BARU` | Murid belum punya guru wali | Dibuat |
   | `GANTI` | Murid sudah punya guru wali lain (nama disertakan) | Dibuat hanya jika `ganti: true`, selain itu dilewati |
   | `SAMA` | Sudah didampingi guru yang sama | Dilewati |
   | `KOSONG` | Kolom email dan NIP sama-sama kosong | Dilewati, **tidak** mengakhiri penugasan yang ada |
   | `GALAT` | Pesan: NISN tidak ditemukan / NISN ganda / murid nonaktif / guru tidak ditemukan / bukan GURU / NISN muncul dua kali di file | Dilewati |

4. Pratinjau menampilkan tabel + ringkasan (jumlah per status) + daftar guru yang akan melebihi kuota. Jika ada `GANTI`, muncul centang "Ganti guru wali yang sudah ada" yang mewajibkan alasan.
5. Tanggal mulai (bawaan hari ini) berlaku untuk semua baris. **Simpan** mengirim ulang baris yang sama dengan `pratinjau: false`. Server memvalidasi ulang (data bisa berubah sejak pratinjau), menyimpan baris `BARU`/`GANTI` dalam satu transaksi, lalu mengembalikan ringkasan akhir. Baris `GALAT` tidak menggagalkan baris lain.
6. Pencocokan: murid lewat `students.nisn` (dinormalisasi: hanya digit, nol depan dipulihkan sampai 10 digit seperti di impor Dapodik). Guru lewat `users.email` (case-insensitive, trim). Bila email kosong, lewat `users.nip` (hanya digit). Nama guru tidak pernah dicocokkan. Maksimal 1.500 baris per file.

### 3.5 Alur ADMIN/KS: membatalkan dan riwayat

- Tab **Riwayat**: tabel penugasan (murid, kelas saat mulai, guru, mulai, selesai, alasan selesai, oleh), filter murid/guru/kelas, centang "Tampilkan yang dibatalkan".
- Tombol **Batalkan** hanya ada pada penugasan **aktif**. Alasan pembatalan wajib. Efeknya:
  - penugasan → `DIBATALKAN` (+ `dibatalkan_oleh`, `dibatalkan_pada`, `alasan_batal`);
  - jika `menggantikan_id` mengarah ke penugasan `SELESAI` yang **ditutup oleh penggantian ini** (`diakhiri_oleh_penugasan = id ini`) dan guru lamanya masih akun GURU, penugasan lama dipulihkan (`AKTIF`, `tanggal_selesai`/alasan dikosongkan). Jika tidak, murid menjadi tanpa guru wali dan respons menyebutkannya.
- Penugasan `SELESAI` tidak bisa dibatalkan atau diubah. Koreksi riwayat lama dicatat dengan catatan di penugasan berikutnya. Ini keputusan sadar demi jejak audit.

### 3.6 Alur guru wali

- Semua akun GURU mendapat menu **"Murid Dampingan"** (halaman `murid-dampingan`). Jika kosong: "Anda belum memiliki murid dampingan. Penugasan guru wali diatur oleh Kepala Sekolah/Admin."
- Tabel murid dampingan efektif: foto, nama, NISN, kelas saat ini, sejak (tanggal mulai), poin pelanggaran, % hadir harian semester aktif, penanda biodata/asesmen (seperti `profil_murid_daftar`). Urut kelas lalu nama. Ada pencarian lokal.
- Klik nama → `__pmBuka(id, 'murid-dampingan', navigasi)` → Profil Murid 360° murid itu (baca saja; tombol edit/impor asesmen disembunyikan, NIK/KK disamarkan, cetak boleh). Tombol kembali menuju "Murid Dampingan".
- Guru wali yang juga wali kelas/BK tetap memiliki akses lamanya. Aksesnya adalah **gabungan** (lihat 4.3).

### 3.7 Profil Murid 360° dan Dashboard Kelas Saya

- Header profil: "Guru wali: **Nama** (sejak 14 Juli 2025)" atau "Guru wali: belum ditetapkan" (abu-abu).
- Tab Identitas: kartu "Riwayat Guru Wali" berisi riwayat resmi, terbaru di atas (guru, mulai, selesai, alasan). Penugasan menggantung ditampilkan dengan label "selesai (murid nonaktif)".
- Cetak profil (`cetak_profil_murid`) memuat baris "Guru wali" di bagian identitas.
- Dashboard Kelas Saya: kolom **Guru Wali** (nama atau "–") di tabel per siswa.

### 3.8 Pengaturan kuota (ADMIN)

Di Pengaturan, tab konfigurasi, di bawah "Hari Sekolah": input angka **"Ambang peringatan murid dampingan per guru wali"** (1–100, bawaan 20) → `save_system_config` key `guru_wali_kuota_peringatan`.

## 4. Kontrak backend

File baru: **`api/guru_wali.php`** (`if (!defined('SIMANTAP_API')) exit;`), di-`require_once` dari `api/index.php` setelah `profil_murid.php`. Action-nya dipanggil lewat `case` eksplisit di `api/index.php` sebelum blok `profil_murid_*`. Sebelum implementasi, **grep setiap nama action di keempat file endpoint**: semua harus baru (awalan `guru_wali_` saat ini tidak dipakai).

### 4.1 Tabel (dibuat saat pertama dipakai, `guruWaliPastikanTabel`)

```sql
CREATE TABLE IF NOT EXISTS `guru_wali_penugasan` (
  `id` varchar(40) NOT NULL,                       -- uniqid('gwp_', true) tanpa titik
  `student_id` varchar(128) NOT NULL,              -- students.id
  `guru_uid` varchar(128) NOT NULL,                -- users.uid (= gtk_pegawai.id)
  `guru_nama` varchar(255) NOT NULL,               -- snapshot displayName saat penugasan dibuat
  `kelas_saat_mulai` varchar(100) DEFAULT NULL,    -- snapshot students.class_name
  `tanggal_mulai` date NOT NULL,
  `tanggal_selesai` date DEFAULT NULL,
  `status` enum('AKTIF','SELESAI','DIBATALKAN') NOT NULL DEFAULT 'AKTIF',
  `aktif_student_id` varchar(128) GENERATED ALWAYS AS (IF(`status` = 'AKTIF', `student_id`, NULL)) STORED,
  `sumber` enum('MANUAL','IMPOR') NOT NULL DEFAULT 'MANUAL',
  `impor_id` varchar(40) DEFAULT NULL,             -- sama untuk semua baris satu kali impor
  `menggantikan_id` varchar(40) DEFAULT NULL,      -- penugasan yang ditutup karena penugasan ini dibuat
  `alasan_selesai` enum('MUTASI_GURU','PENSIUN','BEBAN_KERJA','PERMINTAAN_MURID_ORTU','KOREKSI_DATA','MURID_NONAKTIF','LAINNYA') DEFAULT NULL,
  `catatan_selesai` varchar(500) DEFAULT NULL,
  `diakhiri_oleh_penugasan` varchar(40) DEFAULT NULL, -- id penugasan pengganti (NULL bila diakhiri tanpa pengganti)
  `dibuat_oleh` varchar(128) NOT NULL,
  `dibuat_oleh_nama` varchar(255) NOT NULL,
  `dibuat_pada` datetime NOT NULL,
  `diakhiri_oleh` varchar(128) DEFAULT NULL,
  `diakhiri_oleh_nama` varchar(255) DEFAULT NULL,
  `diakhiri_pada` datetime DEFAULT NULL,
  `dibatalkan_oleh` varchar(128) DEFAULT NULL,
  `dibatalkan_oleh_nama` varchar(255) DEFAULT NULL,
  `dibatalkan_pada` datetime DEFAULT NULL,
  `alasan_batal` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_gw_aktif` (`aktif_student_id`),
  KEY `idx_gw_guru` (`guru_uid`, `status`),
  KEY `idx_gw_murid` (`student_id`, `tanggal_mulai`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Catatan:
- `uq_gw_aktif` menjamin satu penugasan aktif per murid di level DB (NULL boleh berulang). Kolom generated `STORED` didukung MySQL ≥ 5.7 dan MariaDB ≥ 10.2. Jika `CREATE TABLE` gagal di server lama, `guruWaliPastikanTabel` membuat tabel tanpa kolom generated (fallback) dan mengandalkan `GET_LOCK` + `SELECT … FOR UPDATE`. Ini dicatat di `error_log`.
- Tidak ada FOREIGN KEY (konsisten dengan skema yang ada). Nama guru dan kelas di-snapshot agar riwayat tetap terbaca walau akun dihapus atau murid naik kelas.
- Label alasan (UI): Mutasi guru, Pensiun, Beban kerja, Permintaan murid/orang tua, Koreksi data, Murid tidak aktif, Lainnya (catatan wajib jika `LAINNYA`).

Key `system_config` baru: `guru_wali_kuota_peringatan` (integer 1–100; hilang/tidak valid → 20). Disisipkan saat pertama disimpan (`INSERT … ON DUPLICATE KEY UPDATE`, ikuti pola `school_days_per_week`).

### 4.2 Aturan tanggal

`T` = tanggal efektif (`YYYY-MM-DD`, divalidasi `checkdate`), `hariIni` = `date('Y-m-d')` di `APP_TIMEZONE`.

| Operasi | Aturan | Galat |
|---|---|---|
| Tugaskan murid tanpa penugasan aktif | `T ≤ hariIni`. `T` > `tanggal_selesai` penugasan `SELESAI` terakhir murid itu (tidak boleh tumpang tindih) | 400 "Tanggal mulai tidak boleh di masa depan." / 400 "Tanggal mulai harus setelah <tgl> (akhir penugasan sebelumnya)." |
| Ganti guru wali | `T ≤ hariIni` dan `T > lama.tanggal_mulai`. Lama: `tanggal_selesai = T − 1 hari`, `SELESAI`, alasan dari body, `diakhiri_oleh_penugasan = baru.id`. Baru: `tanggal_mulai = T`, `menggantikan_id = lama.id` | 400 "Tanggal efektif harus setelah <tgl mulai lama>. Jika penugasan lama salah input, gunakan Batalkan." |
| Akhiri tanpa pengganti | `lama.tanggal_mulai ≤ T ≤ hariIni`. `tanggal_selesai = T` | 400 |
| Tutup murid nonaktif | `tanggal_selesai = max(tanggal_mulai, hariIni)`, alasan `MURID_NONAKTIF` | – |

Fungsi murni (diuji unit): `guruWaliValidasiTanggal(string $t, string $hariIni): ?string`, `guruWaliTanggalSebelum(string $t): string`, `guruWaliNormalisasiAlasan($v): ?string`, `guruWaliNormalisasiNisn($v): string`, `guruWaliKuota($v): int`, `guruWaliKlasifikasiBarisImpor(array $baris, array $peta…): array`.

### 4.3 Scope dan otorisasi

| Fungsi | Aturan |
|---|---|
| `guruWaliWajibPengelola($db, $metode)` | `requireAuth()` → `loadFreshUser` → role harus `ADMIN` atau `KEPALA_SEKOLAH`, jika tidak 403 "Hanya Kepala Sekolah dan Admin yang dapat mengatur guru wali." Cek metode (405) |
| `guruWaliWajibGuru($db, $uid)` | User ada (404 "Guru tidak ditemukan.") dan `role = 'GURU'` (400 "<nama> bukan akun guru.") |
| `guruWaliDampinganIds($db, $uid): array` | `student_id` penugasan `AKTIF` milik guru itu yang muridnya aktif (efektif) |
| `profilMuridScope` (diubah) | Hitung `pantauNilaiScope` seperti sekarang. Jika mode bukan `ALL` dan user `GURU`, tambahkan `$scope['dampinganIds'] = guruWaliDampinganIds(...)`. Jika mode `DENY` dan `dampinganIds` tidak kosong → mode baru `'DAMPINGAN'`. `pantauNilaiScope` **tidak** diubah |
| `profilMuridGuard` | `DENY` → 403 seperti sekarang (`DAMPINGAN` lolos) |
| `profilMuridAmbilMurid` (diubah) | Diizinkan bila: `ALL`; **atau** (`CLASS` dan murid aktif di kelas perwalian); **atau** `student_id ∈ dampinganIds` (murid aktif tersirat). Selain itu 403 `KESISWAAN_MSG_KELAS` |
| Penyamaran (diubah) | `$samar = $scope['mode'] !== 'ALL'` (sebelumnya `=== 'CLASS'`). Untuk mode CLASS hasilnya sama |
| Tulis asesmen (`profil_murid_asesmen_simpan`/`_hapus`) | Tidak berubah: hanya `ALL` atau `CLASS` untuk murid kelasnya. Akses lewat dampingan saja → 403. `akses.bisa_edit_asesmen` = false bila akses ke murid ini hanya lewat dampingan |
| `profil_murid_daftar`, `_asesmen_template`, `_asesmen_impor` | Mode `DAMPINGAN` → 403 (guru wali memakai `guru_wali_dampingan_saya`). Mode CLASS/ALL tidak berubah |
| `cetak_profil_murid` | Mengikuti `profilMuridAmbilMurid` (guru wali boleh mencetak, tersamarkan) |
| Aktor | Selalu dari session (`uid`, `displayName`), tidak pernah dari body |
| Metode | GET untuk baca, POST untuk tulis, salah metode → 405 |

**Matriks akses**

| Action / fitur | ADMIN | KEPALA_SEKOLAH | GURU (guru wali) | GURU tanpa dampingan | TATA_USAHA | Anonim |
|---|---|---|---|---|---|---|
| Menu & semua action pengelolaan `guru_wali_*` (kecuali di bawah) | ✔ | ✔ | 403 | 403 | 403 | 401 |
| `guru_wali_dampingan_saya` | ✔ (daftar kosong kecuali `guru_uid`) | ✔ (idem) | ✔ milik sendiri | ✔ (kosong) | 403 | 401 |
| Profil Murid 360° murid dampingan | ✔ (ALL) | ✔ (ALL) | ✔ baca, tersamarkan | 403 (kecuali wali kelas/BK) | 403 | 401 |
| Ubah `guru_wali_kuota_peringatan` | ✔ | ✘ | ✘ | ✘ | ✘ | ✘ |

### 4.4 Action

| Action | Metode | Akses | Input | Output |
|---|---|---|---|---|
| `guru_wali_ringkasan` | GET | ADMIN/KS | – | `{kuota, total_murid_aktif, murid_tanpa_guru_wali, penugasan_menggantung, guru:[{uid, nama, nip, class_homeroom, is_bk, jumlah_dampingan, melebihi_kuota}]}`. Semua akun `GURU`, urut nama |
| `guru_wali_murid` | GET | ADMIN/KS | `class_id?`, `status_penugasan?` (`SEMUA`\|`BELUM`\|`SUDAH`), `guru_uid?`, `q?` (≥ 3 huruf) | `{murid:[{id, nisn, name, class_id, class_name, pas_foto, penugasan:{id, guru_uid, guru_nama, tanggal_mulai}\|null}]}`. Hanya murid aktif, urut kelas lalu nama |
| `guru_wali_tugaskan` | POST | ADMIN/KS | `{guru_uid, student_ids[] (1–200), tanggal_mulai, ganti?:bool, alasan?, catatan?}` | 200 `{dibuat, diganti, sama:[…], peringatan_kuota:{uid,nama,jumlah,kuota}\|null}`; 409 `{perlu_konfirmasi:[{student_id, name, guru_lama_nama, tanggal_mulai_lama}]}` bila ada yang perlu diganti dan `ganti !== true`; 400 bila murid nonaktif/tidak ada (daftar disertakan) atau aturan tanggal dilanggar |
| `guru_wali_akhiri` | POST | ADMIN/KS | `{penugasan_ids[] (1–200), tanggal_selesai, alasan, catatan?}` | `{diakhiri}`. Hanya penugasan `AKTIF`, jika tidak 409 |
| `guru_wali_tutup_nonaktif` | POST | ADMIN/KS | – | `{ditutup}`. Semua penugasan menggantung |
| `guru_wali_batalkan` | POST | ADMIN/KS | `{penugasan_id, alasan_batal (wajib, ≤ 500)}` | `{dibatalkan:true, dipulihkan:{id, guru_nama}\|null}`. Selain `AKTIF` → 409 |
| `guru_wali_riwayat` | GET | ADMIN/KS | `student_id?`, `guru_uid?`, `class_id?`, `termasuk_batal?`, `halaman?` (50/halaman) | `{total, baris:[…semua kolom + nama murid, kelas saat ini, status_murid, efektif_status ('AKTIF'\|'SELESAI'\|'SELESAI_MURID_NONAKTIF'\|'DIBATALKAN')]}` urut `tanggal_mulai DESC` |
| `guru_wali_template` | GET | ADMIN/KS | `class_id?` | `{header:[…6 kolom], baris:[[nisn, nama, kelas, email, nip, nama_guru]…]}`. File dibuat di browser |
| `guru_wali_impor` | POST | ADMIN/KS | `{baris:[{baris, nisn, email, nip}] (≤ 1.500), pratinjau:bool, tanggal_mulai, ganti?:bool, alasan?, catatan?, nama_file?}` | `{ringkasan:{BARU, GANTI, SAMA, KOSONG, GALAT}, baris:[{baris, status, pesan?, student_id?, nama_murid?, guru_nama?, guru_lama_nama?}], peringatan_kuota:[…], impor_id?}` |
| `guru_wali_dampingan_saya` | GET | GURU (sendiri); ADMIN/KS boleh `guru_uid` | `guru_uid?` | `{guru:{uid,nama}, murid:[{id, nisn, name, class_id, class_name, pas_foto, tanggal_mulai, total_violation_points, persen_hadir, ada_biodata, ada_asesmen}]}` |

Semua mutasi:
- dibungkus `GET_LOCK('simantap_guru_wali', 10)` (gagal → 503 "Sedang ada perubahan penugasan lain, coba lagi.") + transaksi + `SELECT … FOR UPDATE` atas penugasan aktif murid terkait;
- memakai pembungkus try/catch seperti `profilMuridDalamTry` (500 berpesan umum, detail ke `error_log`, **tanpa** stack trace ke klien);
- mencatat aktor dari session.

Kuota dihitung **setelah** simpan: jumlah dampingan efektif guru tujuan > `guru_wali_kuota_peringatan` → `peringatan_kuota`.

### 4.5 Perubahan pada file yang ada

| File | Perubahan |
|---|---|
| `api/index.php` | `require_once __DIR__ . '/guru_wali.php';` + 10 `case` eksplisit. `delete_user`: sebelum `DELETE`, panggil `guruWaliCegahHapusGuru($db, $uid)` → 409 "Guru ini masih mendampingi N murid aktif. Pindahkan murid dampingannya di menu Guru Wali terlebih dahulu." dengan `{jumlah_dampingan}`; penugasan menggantung ditutup otomatis (`MURID_NONAKTIF`). `save_system_config`: validasi `guru_wali_kuota_peringatan` (bilangan bulat 1–100, selain itu 400) |
| `api/endpoints_tu.php` | `gtk_delete`: panggilan yang sama sebelum menghapus |
| `api/index.php` (`save_user`) dan `api/endpoints_v3.php` (`save_user_v2`) | Jika role user yang **sudah ada** berubah dari `GURU` ke selain `GURU` dan ia punya dampingan efektif → 409 pesan serupa. Pembuatan user baru tidak terpengaruh |
| `api/endpoints_v2.php` (`save_config`) | Tolak key `guru_wali_kuota_peringatan`: "Gunakan Pengaturan Sistem untuk mengubah ambang guru wali." |
| `api/profil_murid.php` | `profilMuridScope`, `profilMuridAmbilMurid`, kondisi penyamaran, `bisa_edit_asesmen`, guard `profil_murid_daftar`/`_asesmen_*` (lihat 4.3). `profilMuridKumpulkan` menambah `guru_wali: {aktif:{guru_uid, guru_nama, tanggal_mulai}\|null, riwayat:[{guru_nama, tanggal_mulai, tanggal_selesai, alasan_selesai, efektif_status}]}`. Cetak menambah baris "Guru wali" |
| `api/endpoints_v3.php` (`get_homeroom_dashboard`) | `per_siswa[]` mendapat `guru_wali_nama` (dan `guru_wali_uid`) **hanya jika ada session login** (`$_SESSION['user']`). Tanpa login field tidak ditambahkan, agar endpoint yang sudah bocor ini tidak menambah PII baru. Query satu kali (`IN (...)`), bukan per murid. Jika tabel belum ada, field `null` |
| `api/get_config` (`endpoints_v2.php`) | Ikut mengembalikan `guru_wali_kuota_peringatan` (sudah otomatis jika get_config mengembalikan semua key; verifikasi saat implementasi) |

## 5. Kontrak frontend (patch bundle)

Skrip `docs/patches/apply-guru-wali-bundle.js` menulis `assets/index-CGWL69P7-guruwali1.js` dari bundle aktif (`-profilmurid2.js`) dan mengarahkan `app.html` ke sana. Skrip harus **gagal** (tanpa menulis) bila salah satu jangkar tidak ditemukan tepat sekali, atau bila penanda patch sudah ada. Hanya kelas Tailwind yang ada di `assets/index-CC1PBzaO.css` yang boleh dipakai.

| Kode | Patch |
|---|---|
| G1 | Sidebar ADMIN/KS: item **"Guru Wali"** → halaman `guru-wali` |
| G2 | Sidebar GURU: item **"Murid Dampingan"** → halaman `murid-dampingan` |
| G3 | Komponen halaman `guru-wali`: kartu ringkasan + banner menggantung, tab Per Guru / Murid / Impor / Riwayat, modal Tugaskan (dengan tahap konfirmasi 409), modal Akhiri, modal Batalkan. Impor: unduh template dan parse unggahan dengan SheetJS yang sudah ada di bundle |
| G4 | Komponen halaman `murid-dampingan` (3.6). Klik nama → `__pmBuka(id, 'murid-dampingan', navigasi)` |
| G5 | Profil Murid 360°: header guru wali, kartu riwayat di tab Identitas, sembunyikan tombol edit/impor asesmen bila `akses.bisa_edit_asesmen === false`, tombol kembali mengenali asal `murid-dampingan` |
| G6 | Dashboard Kelas Saya: pemetaan `per_siswa` menyimpan `guru_wali_nama`, tambah kolom "Guru Wali" |
| G7 | Pengaturan: input ambang kuota di bawah "Hari Sekolah" |

Semua teks UI berbahasa Indonesia. Tanggal ditampilkan "14 Juli 2025".

## 6. File dan interface terkait

| File | Peran |
|---|---|
| `api/guru_wali.php` (baru) | Tabel, fungsi murni, scope, 10 handler |
| `api/index.php` | Router, `delete_user`, `save_user`, `save_system_config` |
| `api/endpoints_v2.php` | `save_config`, `get_config` |
| `api/endpoints_v3.php` | `get_homeroom_dashboard`, `save_user_v2` |
| `api/endpoints_tu.php` | `gtk_delete` |
| `api/profil_murid.php` | Scope dampingan, respons `guru_wali`, cetak |
| `api/pantau_nilai.php` | `pantauNilaiScope` (dipakai, **tidak** diubah) |
| `api/kesiswaan_access.php` | `loadFreshUser`, `classInScope`, `KESISWAAN_MSG_KELAS` (dipakai) |
| `api/rekap_bulanan.php` | `rekapFetchAll` dan helper cetak (dipakai) |
| `api/db.php` | `getDB`, `jsonResponse`, `errorResponse`, `requireAuth`, `getRequestBody`, `getActiveAcademicPeriod` |
| `assets/index-CGWL69P7-profilmurid2.js` → `-guruwali1.js`, `app.html` | Bundle |
| `docs/patches/apply-guru-wali-bundle.js`, `docs/patches/guru-wali.md` | Patch + catatan patch (daftar file + SHA-256) |
| `docs/patches/uji-guru-wali-*.{mjs,php,sql}` | Pengujian |
| `docs/usulan-btikp/RINGKASAN-GURU-WALI.md` | Ringkasan usulan upstream |
| `CLAUDE.md` | Tambah baris `api/guru_wali.php` di tabel domain |

## 7. Edge cases

| Kasus | Perilaku |
|---|---|
| Dua admin menugaskan murid yang sama bersamaan | `GET_LOCK` + `FOR UPDATE` + `uq_gw_aktif`. Yang kalah mendapat 409 (bukan 500): duplicate key ditangkap dan diterjemahkan |
| Murid naik kelas / pindah kelas | Penugasan tidak berubah. `kelas_saat_mulai` tetap, tampilan memakai kelas saat ini |
| Murid nonaktif lalu diaktifkan kembali | Penugasan menggantung yang belum ditutup menjadi efektif lagi. Jika sudah ditutup, murid tampil di "Belum punya guru wali" |
| Murid pindah lalu ditugaskan ulang | Penugasan baru dengan `tanggal_mulai` setelah `tanggal_selesai` terakhir |
| Guru wali juga wali kelas | Scope `CLASS` + `dampinganIds`. Profil murid kelasnya bisa diedit asesmennya, murid dampingan dari kelas lain hanya bisa dibaca |
| Guru wali juga BK | Scope `ALL` (dampingan tidak menambah apa pun). Menu "Murid Dampingan" tetap tampil |
| Guru dihapus via `delete_user`/`gtk_delete` | 409 bila masih punya dampingan efektif. Riwayat lama tetap memakai `guru_nama` snapshot |
| Role guru diubah ke TU/KS/ADMIN | 409 bila punya dampingan efektif |
| Akun guru dihapus langsung di DB (di luar aplikasi) | Penugasan aktif dengan `guru_uid` yatim ditampilkan "(akun dihapus)" + ditandai merah di tab Murid. Pengelola bisa mengganti atau mengakhirinya. `guru_wali_dampingan_saya` tidak terpengaruh |
| `displayName` guru diubah | Tampilan memakai nama terkini dari `users` bila akun ada, `guru_nama` hanya cadangan |
| NISN ganda di `students` | Baris impor `GALAT` "NISN dimiliki lebih dari satu murid". Penugasan lewat centang tetap bisa |
| NISN muncul dua kali di file impor | Kedua baris `GALAT` |
| Email cocok tapi role bukan GURU | `GALAT` "<email> bukan akun guru" |
| Penugasan ke diri sendiri oleh KS | Tidak mungkin: KS bukan GURU |
| Tanggal efektif sama dengan hari mulai penugasan lama | 400, arahkan ke Batalkan |
| Membatalkan penugasan yang menggantikan, sementara guru lama sudah dihapus atau bukan GURU | Tidak dipulihkan. Murid menjadi tanpa guru wali, `dipulihkan: null` + pesan |
| Membatalkan penugasan hasil impor | Per baris (tidak ada "batalkan seluruh impor" di scope ini) |
| Session guru wali masih aktif setelah penugasannya diakhiri | Akses profil langsung hilang, karena scope dihitung dari DB setiap request |
| Tabel belum ada saat `get_homeroom_dashboard`/`profil_murid_get` dipanggil | Tidak membuat tabel di jalur baca orang lain. Cek `SHOW TABLES LIKE` (di-cache per request) → `guru_wali` null/kosong |
| Kuota diubah lebih kecil dari jumlah dampingan sebagian guru | Hanya mengubah tanda "Melebihi kuota". Tidak ada yang diblokir |
| 200+ murid satu kelas angkatan dipilih | Batasi 200 per simpan (400 di server, UI menahan) |

## 8. Compatibility

- **Additive**: satu tabel baru, satu key config baru, action baru berawalan `guru_wali_`. Tidak ada kolom yang diubah di tabel lama.
- Perilaku lama berubah hanya di:
  - (a) `delete_user`/`gtk_delete`/`save_user`/`save_user_v2` bisa membalas 409 untuk guru yang punya dampingan;
  - (b) `get_homeroom_dashboard` mendapat field tambahan bila login;
  - (c) Profil Murid 360° mendapat field `guru_wali` dan mode scope `DAMPINGAN`.

  Klien lama yang tidak mengenal field baru tidak terpengaruh. Penyamaran untuk mode CLASS identik dengan sebelumnya.
- Pantau Nilai dan fitur lain yang memakai `pantauNilaiScope` tidak berubah.
- **Auto-updater upstream** bisa menimpa `api/index.php`, `endpoints_*.php`, `profil_murid.php`, dan bundle. Setelah update, jalankan ulang patch (lihat `docs/patches/guru-wali.md`). Tabel dan data tidak hilang.
- **Tahap 2 dan 3** cukup memanggil `guruWaliPadaTanggal($db, $studentId, $tanggal): ?array` (penugasan non-batal dengan `tanggal_mulai ≤ T ≤ COALESCE(tanggal_selesai, '9999-12-31')`). Fungsi ini disediakan dan diuji di scope ini walau belum dipakai UI.
- Server MySQL/MariaDB tanpa kolom generated: fallback dari 4.1.

## 9. Di luar scope

- Impor hasil instrumen digital (Google Form) yang diisi murid, radar kompetensi 8 dimensi, target akademik → **tahap 2**.
- Jurnal/catatan pendampingan, jadwal pertemuan, tindak lanjut → **tahap 3**.
- Login murid atau orang tua. Notifikasi ke guru saat ditugaskan.
- Lebih dari satu guru wali per murid, guru pendamping cadangan.
- Pembagian otomatis merata (round-robin), aksi khusus "pindahkan semua dampingan A ke B" (cukup lewat Per Guru → centang semua → Pindahkan).
- Nomor SK, cetak SK/daftar pembagian guru wali, ekspor riwayat ke Excel.
- Kolom jumlah dampingan di halaman Kelola Guru.
- Mengedit penugasan `SELESAI` atau menghapus permanen penugasan.
- Penutupan otomatis saat status murid berubah (sengaja: dianggap selesai saat dibaca).
- Memperbaiki celah keamanan lama (`get_homeroom_dashboard` tanpa login, dll.). Fitur ini hanya tidak memperburuknya.

## 10. Pengujian dan verifikasi

Prasyarat lingkungan lokal: `api/config.local.php` dengan `define('SIMANTAP_DEV_MODE', true);` dan `api/install.lock`.

### 10.1 Seed `docs/patches/uji-guru-wali-seed.sql` / `-cleanup.sql`

Prefiks `uji_gw_`/`UJI_GW_`/`s_gw_`. Data: kelas `UJI_GW_A` dan `UJI_GW_B`; 10 murid (aktif tersebar di dua kelas, 1 `Lulus`, 2 aktif dengan NISN sama setelah normalisasi nol depan — DB lokal punya unique key `idx_nisn` — dan 1 dengan NIK untuk uji penyamaran); akun ADMIN, KS, TU, GURU `uji_gw_1` (biasa), `uji_gw_2` (wali kelas `UJI_GW_A`), `uji_gw_3` (BK), `uji_gw_4` (biasa, tanpa NIP). Cleanup menghapus semua baris uji termasuk `guru_wali_penugasan`; skrip uji API sendiri mengembalikan `guru_wali_kuota_peringatan` ke nilai sebelum uji.

### 10.2 Skrip uji API `docs/patches/uji-guru-wali-api.mjs`

Login per akun (cookie session), lalu memeriksa:

| Kode | Pemeriksaan |
|---|---|
| T1 Otorisasi | Setiap action pengelolaan: ADMIN/KS 200; GURU/TU 403; anonim 401; GET pada action POST → 405 |
| T2 Tugaskan | ADMIN menugaskan 3 murid dari dua kelas ke `gw1` → 200 `dibuat=3`; ulang → `sama`; murid `Lulus` → 400; tanggal besok → 400; `guru_uid` = TU → 400 |
| T3 Ganti | Tugaskan murid yang sama ke `gw4` tanpa `ganti` → 409 `perlu_konfirmasi`; dengan `ganti:true` tanpa alasan → 400; dengan alasan dan T = kemarin → lama `SELESAI` dengan `tanggal_selesai` = T−1, baru `AKTIF`; T = hari mulai lama → 400 |
| T4 Satu aktif | Dua permintaan `tugaskan` paralel untuk murid yang sama ke guru berbeda → tepat satu berhasil, lainnya 409. DB: satu baris `AKTIF` |
| T5 Batalkan | Batalkan penugasan pengganti dari T3 → `dipulihkan` = penugasan `gw1`. Riwayat default tidak menampilkan yang dibatalkan, `termasuk_batal=1` menampilkannya. Batalkan penugasan `SELESAI` → 409 |
| T6 Akhiri & menggantung | Akhiri satu penugasan → murid muncul di `status_penugasan=BELUM`. Ubah status satu murid dampingan menjadi `Lulus` lewat SQL → hilang dari `dampingan_saya` dan dari hitungan ringkasan, `penugasan_menggantung` = 1; `tutup_nonaktif` → 0 |
| T7 Kuota | Set kuota 2 → tugaskan murid ke-3 → 200 + `peringatan_kuota`. `save_config` key kuota → 400. `save_system_config` kuota 0/101/"abc" → 400 |
| T8 Impor | Pratinjau 7 baris (BARU, GANTI, SAMA, KOSONG, NISN tak dikenal, NISN ganda di DB, email bukan GURU, NISN berulang di file) → status sesuai. Simpan tanpa `ganti` → GANTI dilewati. Simpan dengan `ganti` + alasan → diganti. Semua baris impor berbagi `impor_id`. Data DB tidak berubah setelah pratinjau |
| T9 Profil Murid | `gw1` membuka `profil_murid_get` murid dampingan dari kelas `UJI_GW_B` → 200, NIK tersamarkan, `bisa_edit_asesmen=false`; `asesmen_simpan` → 403; murid bukan dampingan → 403; `profil_murid_daftar` → 403; `cetak_profil_murid` → 200. `gw2` (wali `UJI_GW_A` + dampingan di `UJI_GW_B`) bisa membaca keduanya, tetapi hanya bisa mengedit asesmen murid `UJI_GW_A`. Setelah penugasan diakhiri → 403 tanpa login ulang. Respons berisi `guru_wali.aktif` dan `riwayat` |
| T10 Hapus/ubah guru | `delete_user gw1` (masih punya dampingan) → 409 `jumlah_dampingan`, user masih ada. `gtk_delete` → 409. `save_user_v2` role `gw1` → TATA_USAHA → 409. Setelah dipindahkan semua → `delete_user` 200 dan riwayat tetap menampilkan nama `gw1` |
| T11 Dashboard | `get_homeroom_dashboard UJI_GW_A` dengan login → `per_siswa[].guru_wali_nama` terisi; tanpa login → field tidak ada |
| T12 Regresi | `pantau_nilai_*` untuk `gw1` (bukan wali/BK) tetap 403; untuk `gw2` tetap mode CLASS; `profil_murid_get` untuk `gw2` atas murid kelasnya tetap tersamarkan dan bisa diedit |

### 10.3 Uji unit `docs/patches/uji-guru-wali-unit.php`

Fungsi murni dari 4.2: validasi tanggal (format, masa depan, kabisat, `T−1` melewati akhir bulan/tahun), normalisasi alasan (label dan kode), normalisasi NISN (spasi, tanda petik, notasi ilmiah, nol depan), `guruWaliKuota` (kosong/0/101/"15"), klasifikasi baris impor (semua status di 3.4). Dijalankan dengan `php docs/patches/uji-guru-wali-unit.php` dan keluar dengan kode ≠ 0 bila ada yang gagal.

### 10.4 Lint

```
php -l api/guru_wali.php api/index.php api/endpoints_v2.php api/endpoints_v3.php api/endpoints_tu.php api/profil_murid.php
node --check assets/index-CGWL69P7-guruwali1.js
node --check docs/patches/apply-guru-wali-bundle.js docs/patches/uji-guru-wali-api.mjs
```

### 10.5 Verifikasi end-to-end (manual, di browser)

1. Login ADMIN → Pengaturan → set ambang kuota 3 → simpan → muat ulang, nilai bertahan.
2. Menu **Guru Wali** → kartu ringkasan menunjukkan murid tanpa guru wali = jumlah murid aktif seed.
3. Tab Murid → filter "Belum punya guru wali" → centang 4 murid dari dua kelas → Tugaskan ke `gw1` → toast kuning kuota (4 > 3). Tab Per Guru: `gw1` = 4 dengan badge "Melebihi kuota".
4. Per Guru → `gw1` → centang 2 murid → Pindahkan ke `gw4` → dialog konfirmasi menampilkan guru lama → isi alasan "Beban kerja", tanggal kemarin → simpan. Tab Riwayat menampilkan 2 baris `SELESAI` (selesai = kemarin−1) dan 2 baris baru.
5. Batalkan salah satu penugasan `gw4` dengan alasan → murid kembali ke `gw1`. Centang "Tampilkan yang dibatalkan" → baris dibatalkan tampil dengan nama pembatal.
6. Tab Impor → unduh template kelas `UJI_GW_A` → isi email `gw2` untuk satu murid, email TU untuk satu murid, kosongkan sisanya → unggah → pratinjau menunjukkan BARU/GANTI/GALAT/KOSONG → simpan tanpa centang ganti → hanya BARU tersimpan.
7. Login `gw1` → menu **Murid Dampingan** → daftar berisi murid dari dua kelas → klik satu → Profil Murid 360° tampil, header "Guru wali: gw1 (sejak …)", NIK tersamarkan, tanpa tombol edit asesmen → Cetak → halaman cetak memuat guru wali → Kembali → kembali ke Murid Dampingan.
8. Login `gw2` (wali `UJI_GW_A`) → Dashboard Kelas Saya → kolom Guru Wali terisi.
9. Login ADMIN → Kelola Guru → hapus `gw1` → muncul pesan 409 yang menyebut jumlah murid dampingan. Pindahkan semua dampingan `gw1` → hapus berhasil → Riwayat masih menampilkan nama `gw1`.
10. Ubah status satu murid dampingan menjadi Lulus di Data Siswa → murid hilang dari Murid Dampingan guru tersebut → banner "penugasan masih terbuka untuk murid tidak aktif" muncul di Guru Wali → Tutup sekarang → banner hilang.
11. Login TU dan GURU tanpa dampingan → menu Guru Wali tidak tampil. GURU melihat Murid Dampingan dengan pesan kosong.
12. Jalankan `uji-guru-wali-cleanup.sql`.

### 10.6 Hasil (2026-11-12)

`uji-guru-wali-api.mjs` 142 lulus, `uji-guru-wali-unit.php` 55 lulus, lint bersih, regresi Profil Murid 360° (92 + 56) lulus. E2E di Chrome: langkah 2–8 dan 10.5 langkah 11 lulus; langkah 1 hanya sampai field tampil (simpan teruji di T7), langkah 9–10 teruji di T6/T10. Rincian dan penyesuaian implementasi: `docs/patches/guru-wali.md`.

Penyesuaian implementasi terhadap spesifikasi ini:
- Riwayat guru wali di Profil Murid tampil sebagai kartu "Guru Wali" di bawah "1. Identifikasi Profil Murid" (halaman profil tidak memakai tab).
- Tambahan filter `status_penugasan=YATIM` dan hitungan `penugasan_yatim` di ringkasan untuk edge case akun guru terhapus.
- Impor membaca file sebagai teks (`__gwBacaFile`) agar NIP 18 digit tidak dibulatkan (temuan E2E).

## 11. Risiko, trade-off, dan temuan

| Topik | Catatan |
|---|---|
| "Dianggap selesai saat dibaca" vs hook status | Menghindari menyentuh banyak jalur yang mengubah `students.status` (TU, v2, impor). Konsekuensinya, riwayat murid nonaktif bisa tetap `AKTIF` sampai ditutup. Semua pembaca harus memakai definisi "efektif" (4.3). `guruWaliPadaTanggal` untuk tahap 2–3 harus memperhitungkannya |
| Tepat satu guru wali | Model dan otorisasi sederhana. Jika kelak dibutuhkan pendamping kedua, perlu kolom `peran` dan unique key baru (migrasi additive) |
| Batalkan vs hapus | Jejak audit utuh dengan sedikit kompleksitas (pemulihan penugasan sebelumnya). Riwayat `SELESAI` tidak bisa diedit, jadi koreksi tanggal lama harus lewat catatan |
| Hard delete akun guru | Ditahan dengan 409. Jika ada jalur hapus lain di upstream yang tidak di-patch, penugasan yatim ditangani di tampilan (Edge cases) |
| Perluasan scope Profil Murid | Mode baru `DAMPINGAN` mengubah titik keamanan sensitif. Mitigasinya uji T9/T12 dan penyamaran berbasis `!== 'ALL'` (default aman: semua non-ALL tersamarkan) |
| `get_homeroom_dashboard` tanpa auth | Nama guru wali hanya ditambahkan bila ada session. Endpoint tetap bocor seperti sebelumnya (temuan lama, di luar scope) |
| `save_user`/`save_user_v2` membolehkan TU/KS memberi role ADMIN | Temuan lama. Guard 409 role-change ditambahkan tanpa memperbaiki itu |
| Kolom generated untuk unique aktif | Jaminan di level DB, dengan fallback untuk server lama |
| Impor di browser | SheetJS sudah ada di bundle, PHP tanpa Composer. Validasi tetap di server dan pratinjau tidak menulis apa pun |
| Kuota peringatan | Fleksibel untuk sekolah dengan rasio guru:murid tinggi, tapi bisa diabaikan. Badge di Per Guru membuatnya tetap terlihat |
| Auto-updater | Bisa menimpa patch. Didokumentasikan di `docs/patches/guru-wali.md` beserta SHA-256 |

## 12. Acceptance criteria

- **AC-1** ADMIN dan KS dapat menugaskan satu atau banyak murid aktif (lintas kelas) ke satu akun GURU dengan tanggal mulai ≤ hari ini. TU, GURU, dan anonim ditolak (403/401).
- **AC-2** Setiap murid paling banyak punya satu penugasan aktif, dijamin di DB dan teruji dengan permintaan paralel.
- **AC-3** Mengganti guru wali mewajibkan alasan dan konfirmasi eksplisit, menutup penugasan lama pada `T−1`, membuka yang baru pada `T`, dan keduanya tampil di riwayat.
- **AC-4** Penugasan tetap berlaku setelah murid pindah/naik kelas atau tahun ajaran aktif berganti, tanpa tindakan apa pun.
- **AC-5** Membatalkan penugasan aktif menandainya `DIBATALKAN` (tidak dihapus) dan memulihkan penugasan yang digantikannya bila memungkinkan. Riwayat resmi menyembunyikan yang dibatalkan.
- **AC-6** Murid nonaktif tidak dihitung kuota dan tidak tampil di Murid Dampingan. Penugasan menggantung bisa ditutup massal dengan alasan `MURID_NONAKTIF`.
- **AC-7** Impor Excel menampilkan pratinjau status per baris tanpa menulis data. Simpan hanya membuat `BARU` (dan `GANTI` bila dicentang + alasan) dan melaporkan sisanya.
- **AC-8** Melebihi `guru_wali_kuota_peringatan` menghasilkan peringatan, bukan penolakan. Hanya ADMIN yang bisa mengubah ambang lewat `save_system_config`, dan `save_config` menolaknya.
- **AC-9** GURU melihat menu Murid Dampingan berisi murid dampingan efektifnya saja, dan dapat membuka serta mencetak Profil Murid 360° mereka (baca saja, NIK/KK tersamarkan). Murid lain tetap 403. Akses hilang segera setelah penugasan berakhir.
- **AC-10** Akses wali kelas, BK, KS, ADMIN ke Profil Murid 360° dan Pantau Nilai tidak berubah (uji regresi T12).
- **AC-11** Profil Murid 360° menampilkan guru wali aktif dan riwayatnya. Dashboard Kelas Saya menampilkan kolom Guru Wali untuk pengguna yang login.
- **AC-12** `delete_user`, `gtk_delete`, dan perubahan role dari GURU ditolak 409 selama guru masih punya dampingan efektif. Riwayat tetap menampilkan nama guru setelah akunnya dihapus.
- **AC-13** Semua mutasi memakai POST, aktor dari session, dan transaksi. Galat DB menghasilkan 500 berpesan umum tanpa stack trace.
- **AC-14** `php -l`, `node --check`, uji unit, dan uji API T1–T12 lulus. Checklist E2E 10.5 terpenuhi di browser.
