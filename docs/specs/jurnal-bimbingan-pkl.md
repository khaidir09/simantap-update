# Spesifikasi: Jurnal Bimbingan Praktik Kerja Lapangan (PKL)

| | |
|---|---|
| Status | Diimplementasikan (patch lokal) — lihat `docs/patches/bimbingan-pkl.md` (termasuk perbedaan kecil dari spesifikasi ini) |
| Versi bundle acuan | SIMANTAP 1.0.2 (`api/version.php`) + patch lokal s.d. Kelola Guru |
| Bundle frontend acuan | `assets/index-CGWL69P7-kelolaguru.js` (direferensikan `app.html` saat ini) |
| Tanggal | 2026-10-07 |
| Jalur rilis | Patch lokal pada bundle ini **dan** paket usulan perubahan ke upstream BTIKP (pola sama dengan patch lain) |
| Peran terdampak | `GURU` (pembimbing, wali kelas XII), `KEPALA_SEKOLAH`, `ADMIN` |
| Spesifikasi terkait | `docs/specs/rekap-bulanan-wali-kelas.md` (pola halaman cetak ber-kop), `docs/specs/menu-kesiswaan-wali-kelas.md` (definisi wali kelas), `docs/patches/dispensasi-siswa.md` (pola tabel dibuat saat pertama dipakai dan unggah aman) |

## 1. Latar belakang dan kebutuhan bisnis

Siswa kelas XII sedang menjalani Praktik Kerja Lapangan (PKL) di dunia usaha/dunia industri (DU/DI). Selama periode ini guru yang biasa mengajar kelas XII tidak lagi mengajar di kelas. Kegiatan mereka beralih menjadi **membimbing siswa PKL** yang menjadi bimbingannya.

SIMANTAP hanya punya **Jurnal Mengajar** (`journal_entries`). Jurnal ini wajib berisi kelas, mapel, jam ke-, dan presensi siswa, sehingga tidak cocok untuk kegiatan bimbingan. Saat ini guru mencatat bimbingan **di luar sistem** (kertas, Word/Excel, grup WA).

Tujuan fitur:

1. **Bukti kinerja guru.** Jurnal bimbingan menggantikan jurnal mengajar sebagai bukti kerja guru selama PKL, dan bisa dicetak sebagai lampiran e-Kinerja bulanan.
2. **Monitoring siswa.** Perkembangan dan permasalahan tiap siswa di DU/DI tercatat. Kehadiran harian siswa **bukan** bagian fitur ini: itu ranah tempat PKL, yang sudah menyediakan lembar presensi harian sendiri.
3. **Laporan ke sekolah.** Kepala sekolah memantau seluruh kegiatan pembimbingan.
4. **Dokumen cetak.** Rekap bulanan per guru dan kartu bimbingan per siswa (A4, ber-kop).

### 1.1 Keputusan dari wawancara

| Topik | Keputusan |
|---|---|
| Penugasan pembimbing | **Guru memilih sendiri** siswa bimbingannya (tanpa persetujuan) |
| Siapa yang bisa jadi pembimbing | Hanya `GURU` yang punya **penugasan mapel untuk kelas XII** di `teacher_subjects` (lihat §4.1) |
| Pembimbing per siswa | **Satu pembimbing** untuk satu siswa pada rentang tanggal yang sama. Guru lain ditolak (409) dan diberi tahu nama pembimbingnya |
| Alih pembimbing | **Hanya ADMIN** yang bisa memindahkan siswa ke guru pembimbing lain |
| Siswa yang boleh dipilih | Hanya siswa **aktif di kelas tingkat XII** |
| DU/DI | Ada **master DU/DI**. Guru menambah DU/DI baru (bila belum ada) saat memilih siswa. ADMIN merapikan master |
| Isian DU/DI | Nama (wajib), bidang usaha, alamat, nama dan telepon pembimbing industri, catatan. Nama di-dedup setelah dinormalisasi |
| Periode PKL | **Per penempatan siswa** (tanggal mulai–selesai) |
| Pindah DU/DI / lepas siswa | **Riwayat penempatan.** Penempatan lama ditutup (tanggal selesai dimajukan) lalu dibuat penempatan baru. Penempatan yang sudah punya jurnal tidak bisa dihapus |
| Granularitas jurnal | **Satu entri per siswa** per kegiatan |
| Jenis kegiatan | Kunjungan/monitoring ke DU/DI, Bimbingan daring, Bimbingan di sekolah |
| Isian jurnal | Tanggal, jam mulai–selesai, jenis, siswa, DU/DI (otomatis dari penempatan), uraian kegiatan/materi bimbingan, kondisi/perkembangan siswa, permasalahan, tindak lanjut, foto bukti. **Tanpa isian kehadiran siswa** (presensi harian PKL dikelola tempat PKL) |
| Foto bukti | **Selalu opsional**, maksimal 3 |
| Validasi tanggal | Tanggal jurnal harus di dalam rentang penempatan dan **tidak di masa depan**. Satu siswa boleh punya beberapa jurnal per hari |
| Presensi | Jurnal bimbingan **tidak menyentuh** presensi harian, ALFA, poin pelanggaran, atau panggilan orang tua |
| Verifikasi | **Tanpa verifikasi** kepala sekolah |
| Ubah/hapus | Guru bebas mengubah/menghapus jurnal miliknya **kapan saja** |
| Akses baca | Guru: miliknya sendiri. KEPALA_SEKOLAH dan ADMIN: semua. Wali kelas XII: siswa kelas perwaliannya (hanya baca) |
| Cetak | Rekap bulanan per guru dan kartu bimbingan per siswa |
| Lokasi UI | Menu sidebar baru **"Bimbingan PKL"** di bawah "Jurnal Mengajar" |
| Integrasi statistik | **Terpisah.** Statistik/dashboard jurnal mengajar tidak berubah |
| Pengujian | Seperti patch lain: `php -l`, uji API Node dengan seed/cleanup SQL, uji manual di browser |

## 2. Perilaku saat ini (hasil analisis kode)

### 2.1 Backend

- Tidak ada tabel, action, atau teks apa pun terkait PKL, prakerin, magang, atau DU/DI (`grep -i "pkl\|prakerin\|magang"` pada `api/*.php` dan `install/schema.sql` kosong). Prefiks action `pkl_` aman dari bentrok di rantai fallback `index.php` → `endpoints_v2.php` → `endpoints_v3.php` → `endpoints_tu.php`.
- `journal_entries` mewajibkan `classId`, `subjectId`, `period`, `studentAttendance`, dan lain-lain. `save_journal` menghitung ulang presensi harian, insiden ALFA, poin, dan panggilan orang tua lewat `api/presensi_harian.php`. Memaksakan bimbingan PKL ke tabel ini akan memicu efek samping tersebut. Karena itu fitur ini memakai **tabel sendiri**.
- Tingkat kelas: `classes.level` diisi `SUBSTRING_INDEX(class_id, ' ', 1)` saat seed (`get_classes`, `api/endpoints_v3.php` ±baris 573), misalnya `XII` dari `XII TKJ 1`. Nilai bisa diubah ADMIN lewat Kelola Kelas, jadi formatnya bisa beragam (`XII`, `12`, ...).
- Siswa aktif: `getActiveClassStudents()` (`api/db.php`) memakai `status IS NULL OR '' OR UPPER(status)='AKTIF'`. Status siswa bercampur enum dan VARCHAR (lihat CLAUDE.md).
- Wali kelas: `users.class_homeroom`. Helper `loadFreshUser()` dan `homeroomClassScope()` di `api/kesiswaan_access.php` membaca role/perwalian terbaru dari DB, bukan dari sesi.
- Tahun ajaran aktif: `getActiveAcademicPeriod()` (`api/db.php`).
- Halaman cetak ber-kop: `api/rekap_bulanan.php` menyediakan `rekapEsc`, `rekapSendHeaders`, `rekapHtmlError`, `rekapLoadKop`, `rekapBulanIndo`, `rekapTanggalIndo`, `rekapAssetUrl`. Kop dari `system_config` (`kop_*`, `kop_kepsek_nama`, `kop_kepsek_nip`, `kop_kota`). NIP guru dari `users.nip`.
- Pola unggah aman: `handleDispensasiUnggahSurat()` (`api/dispensasi.php`) memakai cek `is_uploaded_file`, batas ukuran, MIME via `finfo`, ekstensi ditentukan server, nama acak `random_bytes`, dan `index.html` kosong di folder.
- Pola modul terpisah: file PHP sendiri di-`require_once` di `api/index.php` (baris 36–50), lalu `case` eksplisit di `switch` router, tabel dibuat saat pertama dipakai (`dispensasiPastikanTabel`).

### 2.2 Frontend (`assets/index-CGWL69P7-kelolaguru.js`)

- Sidebar berisi array item `{label, icon, ...}`. Item "Jurnal Mengajar" adalah titik sisip menu baru.
- Belum ada halaman PKL. Fitur cetak lain membuka halaman HTML backend di tab baru (`cetak_rekap_bulanan`) atau memakai `window.print()`.
- Tailwind sudah dikompilasi. Patch hanya boleh memakai kelas yang ada di `assets/index-CC1PBzaO.css`.

## 3. Perilaku yang diharapkan

### 3.1 Konsep

- **DU/DI** (`pkl_dudi`): tempat PKL.
- **Penempatan** (`pkl_penempatan`): satu siswa, di satu DU/DI, dibimbing satu guru, pada rentang `tanggal_mulai`–`tanggal_selesai`. Ini adalah "siswa bimbingan" seorang guru. Satu siswa bisa punya beberapa penempatan berurutan (riwayat), tetapi **rentangnya tidak boleh tumpang tindih**. Aturan inilah yang menegakkan "satu pembimbing per siswa".
- **Jurnal bimbingan** (`pkl_jurnal`): satu kegiatan bimbingan untuk satu penempatan pada satu tanggal.
- Penempatan **aktif** = `tanggal_mulai ≤ hari ini ≤ tanggal_selesai`. **Akan datang** = mulai > hari ini. **Selesai** = selesai < hari ini. Status dihitung dari tanggal, tidak disimpan.
- "Hari ini" mengikuti `APP_TIMEZONE` (`date('Y-m-d')`).

### 3.2 Alur guru pembimbing

1. Buka **Bimbingan PKL → Siswa Bimbingan**, lalu klik **Tambah Siswa Bimbingan**.
2. Pilih kelas XII, lalu pilih satu atau beberapa siswa. Siswa yang sudah punya penempatan aktif atau akan datang ditampilkan nonaktif dengan keterangan "Dibimbing: <nama guru>".
3. Pilih DU/DI dari daftar (bisa dicari). Bila belum ada, klik **Tambah DU/DI baru** untuk mengisi form DU/DI. Sistem menolak nama yang sama setelah normalisasi dan menawarkan DU/DI yang sudah ada.
4. Isi tanggal mulai dan selesai (default dari penempatan terakhir yang dibuat guru ini di sesi itu). Klik **Simpan**. Penempatan tersimpan per siswa. Bila sebagian siswa gagal (bentrok), yang lain tetap tersimpan dan UI menampilkan daftar yang gagal beserta alasannya.
5. Di tab **Jurnal Bimbingan**, klik **Tambah Jurnal**, lalu pilih siswa (dari penempatan miliknya yang rentangnya mencakup tanggal yang dipilih). DU/DI tampil otomatis (hanya baca). Isi form lalu simpan.
6. Guru bisa mengubah atau menghapus jurnal miliknya kapan saja.
7. Untuk **memindahkan siswa ke DU/DI lain** atau **melepas siswa**, guru klik **Tutup Penempatan** dan isi tanggal selesai baru (≥ tanggal jurnal terakhir penempatan itu, dan ≥ tanggal mulai). Setelah itu guru bisa membuat penempatan baru mulai tanggal berikutnya.
8. Penempatan **tanpa jurnal** bisa dihapus. Penempatan **dengan jurnal** hanya bisa ditutup.

### 3.3 Isian jurnal bimbingan

| Field | Wajib | Aturan |
|---|---|---|
| `penempatan_id` | Ya | Milik guru (kecuali ADMIN, lihat §4); siswa dan DU/DI diambil dari penempatan |
| `tanggal` | Ya | `Y-m-d` valid, di dalam rentang penempatan, ≤ hari ini |
| `jam_mulai`, `jam_selesai` | Ya | `HH:MM`, mulai < selesai |
| `jenis` | Ya | `KUNJUNGAN` (Kunjungan/Monitoring DU/DI), `DARING` (Bimbingan Daring), `SEKOLAH` (Bimbingan di Sekolah) |
| `uraian` | Ya | Uraian kegiatan/materi bimbingan, 1–5000 karakter |
| `kondisi_siswa` | Tidak | Catatan kondisi/perkembangan siswa, ≤ 2000 karakter |
| `permasalahan` | Tidak | ≤ 2000 karakter |
| `tindak_lanjut` | Tidak | ≤ 2000 karakter |
| `foto` | Tidak | Array path hasil `pkl_unggah_foto`, maks 3. Server hanya menerima path berprefiks `uploads/journals/pkl/` yang filenya ada |

### 3.4 Wali kelas XII

Guru dengan `class_homeroom` yang tingkatnya XII melihat tab **Siswa PKL Kelas Saya** (hanya baca). Isinya daftar siswa kelasnya beserta penempatan (DU/DI, pembimbing, periode, jumlah jurnal, tanggal bimbingan terakhir), siswa yang **belum punya pembimbing**, dan detail jurnal per siswa. Wali juga bisa mencetak kartu bimbingan siswanya. Seorang wali kelas yang juga pembimbing tetap melihat tab pembimbing seperti biasa.

### 3.5 Kepala sekolah dan ADMIN

- Tab **Pantau**: tabel semua penempatan dengan filter guru, kelas, DU/DI, dan status (aktif/akan datang/selesai). Ada ringkasan per guru: jumlah siswa bimbingan aktif, jumlah jurnal bulan dipilih, dan tanggal bimbingan terakhir. Ada juga daftar siswa kelas XII aktif yang **belum punya penempatan**. Klik baris untuk melihat jurnal.
- Cetak rekap bulanan guru mana pun dan kartu siswa mana pun.
- KEPALA_SEKOLAH **hanya baca**.
- ADMIN tambahan:
  - tab **DU/DI**: ubah dan hapus master DU/DI (hapus ditolak bila dipakai penempatan);
  - **Alih Pembimbing** pada penempatan aktif: tutup penempatan lama per tanggal X dan buat penempatan baru (siswa dan DU/DI sama, guru baru) mulai X+1. Jurnal lama tetap milik guru lama;
  - hapus jurnal siapa pun (pembersihan data). ADMIN **tidak** mengubah isi jurnal guru.

### 3.6 Dokumen cetak (halaman HTML A4 siap cetak, pola `cetak_rekap_bulanan`)

**Rekap Bulanan Bimbingan PKL** (`pkl_cetak_rekap&guru=<uid>&bulan=YYYY-MM`)
- Kop sekolah, judul "REKAP JURNAL BIMBINGAN PRAKTIK KERJA LAPANGAN", identitas guru (nama, NIP), bulan.
- Ringkasan: jumlah siswa dibimbing pada bulan itu, jumlah kegiatan per jenis, dan total jam (dari jam mulai–selesai).
- Tabel kronologis: No, Hari/Tanggal, Jam, Siswa (kelas), DU/DI, Jenis, Uraian, Permasalahan/Tindak lanjut.
- Bila kosong, tampil satu baris "Nihil".
- Tanda tangan: kiri "Mengetahui, Kepala Sekolah" (`kop_kepsek_nama`/`kop_kepsek_nip`), kanan "Guru Pembimbing" (nama dan NIP guru).
- Tempat dan tanggal TTD: kota dari `kop_kota`, tanggal = hari terakhir bulan (atau hari ini untuk bulan berjalan). Keduanya bisa diedit di halaman cetak, sama seperti rekap bulanan wali kelas.
- Foto **tidak** dicetak di rekap. Opsi `foto=1` menambahkan lampiran foto thumbnail.
- Bulan yang bisa dipilih: bulan dalam tahun ajaran aktif s.d. bulan berjalan (`rekapAcademicMonthRange`).

**Kartu Bimbingan Siswa** (`pkl_cetak_kartu&siswa=<student_id>`)
- Kop, identitas siswa (nama, NISN, kelas), lalu daftar **semua penempatan** siswa (DU/DI, alamat, pembimbing industri, guru pembimbing, periode).
- Tabel semua jurnal bimbingan siswa itu secara kronologis (lintas penempatan).
- TTD: "Mengetahui, Kepala Sekolah" dan "Guru Pembimbing" (pembimbing penempatan terakhir).

### 3.7 Salin jurnal ke siswa lain di DU/DI yang sama (iterasi 2, 2026-10-11)

Satu kunjungan ke DU/DI biasanya mencakup beberapa siswa. Agar guru tidak mengetik ulang isian yang sama:
- **Saat menambah jurnal:** setelah memilih siswa, form menampilkan daftar centang "Salin juga ke siswa lain di DU/DI yang sama".
  Daftar berisi penempatan **milik guru itu** di **DU/DI yang sama** yang periodenya mencakup tanggal jurnal. Tombol "Pilih semua" tersedia.
- **Dari jurnal yang sudah ada:** tombol "Salin ke siswa lain" pada kartu jurnal (hanya jurnal milik sendiri) membuka form berisi isian jurnal itu
  (tanggal, jam, jenis, uraian, kondisi, permasalahan, tindak lanjut, foto), dengan daftar centang siswa lain di DU/DI yang sama. Isian masih bisa diubah sebelum disimpan.
- Hasilnya **jurnal terpisah per siswa** (satu baris `pkl_jurnal` per penempatan), sehingga kartu siswa dan rekap tetap per siswa.
  Setelah disimpan, tiap jurnal diubah/dihapus sendiri-sendiri; tidak ada kaitan antar-salinan.
- Foto dipakai bersama (path yang sama). File baru dihapus bila tidak dipakai jurnal mana pun (`pklHapusFotoYatim` sudah menghitung pemakaian).
- Disimpan **semua atau tidak sama sekali** (transaksi). Bila satu tujuan tidak valid, tidak ada jurnal yang tersimpan.
- **Jurnal ganda** ditolak 409: satu penempatan tidak boleh punya dua jurnal dengan tanggal dan jam mulai yang sama. Aturan ini berlaku untuk semua
  penyimpanan jurnal (baru maupun ubah), bukan hanya salin, supaya salin dua kali tidak menghasilkan duplikat.
- Salin hanya saat menambah jurnal. `salin_ke` pada permintaan ubah ditolak 400. Maksimal 50 siswa sekali salin.

## 4. Role dan otorisasi

Semua pemeriksaan dilakukan di backend. Role dan perwalian dibaca ulang dari DB lewat `loadFreshUser()`, bukan hanya dari sesi. Aktor selalu `$_SESSION['user']['uid']`, tidak pernah dari body. Action mutasi wajib `POST` (405 bila bukan).

| Aksi | GURU (pembimbing) | GURU wali kelas XII | KEPALA_SEKOLAH | ADMIN | TATA_USAHA / lain |
|---|---|---|---|---|---|
| Lihat daftar DU/DI | ✓ | ✓ | ✓ | ✓ | ✗ 403 |
| Tambah DU/DI | ✓ | ✓ | ✗ | ✓ | ✗ |
| Ubah DU/DI | hanya yang ia buat dan belum dipakai penempatan guru lain | sama | ✗ | ✓ semua | ✗ |
| Hapus DU/DI | ✗ | ✗ | ✗ | ✓ bila tidak dipakai | ✗ |
| Kandidat siswa XII | ✓ | ✓ | ✓ | ✓ | ✗ |
| Buat penempatan | untuk dirinya sendiri | untuk dirinya sendiri | ✗ | ✓ untuk guru mana pun | ✗ |
| Ubah/tutup/hapus penempatan | miliknya | miliknya | ✗ | ✓ semua | ✗ |
| Lihat penempatan & jurnal | miliknya | + siswa kelas perwalian (baca) | semua (baca) | semua | ✗ |
| Buat/ubah jurnal | pada penempatan miliknya | sama | ✗ | ✗ | ✗ |
| Hapus jurnal | miliknya | miliknya | ✗ | ✓ semua | ✗ |
| Unggah foto | ✓ | ✓ | ✗ | ✗ | ✗ |
| Cetak rekap bulanan | dirinya sendiri | dirinya sendiri | guru mana pun | guru mana pun | ✗ |
| Cetak kartu siswa | siswa yang pernah ia bimbing | + siswa kelas perwalian | semua | semua | ✗ |

Catatan:
- "Miliknya" untuk penempatan = `pkl_penempatan.guru_id = uid`. Untuk jurnal = `pkl_jurnal.guru_id = uid`. Setelah alih pembimbing, guru lama tetap bisa melihat, mengubah, dan menghapus jurnal lamanya, tetapi tidak bisa menambah jurnal baru di penempatan yang sudah ditutup di luar rentangnya.
- Kolom "GURU (pembimbing)" hanya berlaku untuk guru yang memenuhi syarat pembimbing (§4.1). GURU lain (termasuk wali kelas XII tanpa penugasan mapel XII) tidak bisa membuat penempatan, menambah DU/DI, mengunggah foto, atau menulis jurnal (403 "Hanya guru dengan penugasan mapel kelas XII yang dapat menjadi pembimbing PKL").
- Penolakan memakai pesan Indonesia dan kode: 401 (belum login), 403 (bukan haknya), 404 (data tidak ada), 409 (bentrok), 400 (validasi), 405 (metode salah).

### 4.1 Syarat guru pembimbing

Guru **memenuhi syarat pembimbing** bila role terbarunya `GURU` dan ia punya minimal satu baris `teacher_subjects` (`teacher_id = uid`) yang menyasar kelas XII. Aturannya mengikuti cara `teacherTeachesClassSubject()` (`api/db.php`) membaca penugasan:
- bila `class_ids` (JSON array) tidak kosong: minimal satu id di dalamnya adalah kelas bertingkat XII (`pklKelasXII`, §5);
- bila `class_ids` kosong: `class_level` setelah dinormalisasi bernilai `XII` atau `12`.

Ketentuan:
- Syarat ini **hanya menentukan siapa yang boleh menjadi pembimbing**. Guru yang memenuhi syarat boleh memilih siswa aktif dari **kelas XII mana pun**, tidak dibatasi kelas XII yang ia ajar.
- Syarat diperiksa saat **membuat penempatan** (termasuk guru tujuan pada alih pembimbing oleh ADMIN), saat **menambah/mengubah DU/DI**, dan saat **mengunggah foto**.
- Menulis, mengubah, dan menghapus jurnal **tidak** memeriksa ulang syarat ini; cukup kepemilikan penempatan/jurnal. Jadi bila penugasan mapel XII guru dihapus di tengah PKL, penempatan dan jurnal yang sudah ada tetap bisa dilanjutkan sampai penempatan selesai, tetapi guru itu tidak bisa membuat penempatan baru.
- ADMIN membuat penempatan atas nama guru lain hanya untuk guru yang memenuhi syarat (400 bila tidak).
- Guru yang tidak memenuhi syarat dan bukan wali kelas XII tetap melihat menu, tetapi halamannya hanya menampilkan pesan: "Menu ini untuk guru dengan penugasan mapel kelas XII. Lengkapi penugasan mapel di Profil bila Anda mengajar kelas XII."

## 5. Data model

Tabel dibuat saat pertama dipakai (`pklPastikanTabel()`, `CREATE TABLE IF NOT EXISTS`, InnoDB utf8mb4_unicode_ci), seperti `student_dispensations`. Usulan upstream juga menambahkannya sebagai migrasi bertanda di `schema_migrations`. **Tidak ada** perubahan pada tabel yang sudah ada.

```sql
CREATE TABLE IF NOT EXISTS pkl_dudi (
  id               VARCHAR(64)  NOT NULL PRIMARY KEY,      -- uniqid('dudi_')
  nama             VARCHAR(255) NOT NULL,
  nama_norm        VARCHAR(255) NOT NULL,                  -- lowercase, spasi tunggal, tanpa tanda baca, tanpa awalan PT/CV/UD
  bidang_usaha     VARCHAR(150) NULL,
  alamat           TEXT NULL,
  pembimbing_nama  VARCHAR(150) NULL,                      -- pembimbing industri
  pembimbing_telp  VARCHAR(30)  NULL,
  catatan          TEXT NULL,
  dibuat_oleh      VARCHAR(128) NOT NULL,                  -- users.uid
  dibuat_pada      DATETIME NOT NULL,
  diubah_pada      DATETIME NULL,
  UNIQUE KEY uq_pkl_dudi_norm (nama_norm)
);

CREATE TABLE IF NOT EXISTS pkl_penempatan (
  id               VARCHAR(64)  NOT NULL PRIMARY KEY,      -- uniqid('pklp_')
  siswa_id         VARCHAR(128) NOT NULL,                  -- students.id
  siswa_nama       VARCHAR(255) NOT NULL,                  -- snapshot
  kelas_id         VARCHAR(50)  NOT NULL,                  -- snapshot students.class_id saat dibuat
  kelas_nama       VARCHAR(100) NOT NULL,                  -- snapshot
  dudi_id          VARCHAR(64)  NOT NULL,                  -- pkl_dudi.id
  guru_id          VARCHAR(128) NOT NULL,                  -- users.uid pembimbing
  guru_nama        VARCHAR(255) NOT NULL,                  -- snapshot
  tanggal_mulai    DATE NOT NULL,
  tanggal_selesai  DATE NOT NULL,
  tahun_ajaran     VARCHAR(20)  NOT NULL,                  -- getActiveAcademicPeriod() saat dibuat
  dibuat_oleh      VARCHAR(128) NOT NULL,
  dibuat_pada      DATETIME NOT NULL,
  diubah_oleh      VARCHAR(128) NULL,
  diubah_pada      DATETIME NULL,
  KEY idx_pklp_siswa (siswa_id, tanggal_mulai),
  KEY idx_pklp_guru (guru_id, tanggal_mulai),
  KEY idx_pklp_kelas (kelas_id),
  KEY idx_pklp_dudi (dudi_id)
);

CREATE TABLE IF NOT EXISTS pkl_jurnal (
  id               VARCHAR(64)  NOT NULL PRIMARY KEY,      -- uniqid('pklj_')
  penempatan_id    VARCHAR(64)  NOT NULL,
  guru_id          VARCHAR(128) NOT NULL,                  -- penulis jurnal
  guru_nama        VARCHAR(255) NOT NULL,
  siswa_id         VARCHAR(128) NOT NULL,                  -- denormalisasi dari penempatan untuk query
  tanggal          DATE NOT NULL,
  jam_mulai        CHAR(5) NOT NULL,
  jam_selesai      CHAR(5) NOT NULL,
  jenis            VARCHAR(20) NOT NULL,                   -- KUNJUNGAN | DARING | SEKOLAH
  uraian           TEXT NOT NULL,
  kondisi_siswa    TEXT NULL,
  permasalahan     TEXT NULL,
  tindak_lanjut    TEXT NULL,
  foto_json        TEXT NULL,                              -- json_encode(array path), maks 3
  dibuat_pada      DATETIME NOT NULL,
  diubah_pada      DATETIME NULL,
  KEY idx_pklj_guru_tgl (guru_id, tanggal),
  KEY idx_pklj_penempatan (penempatan_id, tanggal),
  KEY idx_pklj_siswa (siswa_id, tanggal)
);
```

Aturan integritas (di aplikasi, karena tabel lama tidak memakai FK):
- **Tidak tumpang tindih:** saat membuat atau mengubah penempatan, tolak (409) bila ada penempatan lain untuk `siswa_id` yang sama dengan `tanggal_mulai ≤ selesai_baru AND tanggal_selesai ≥ mulai_baru`. Pesan menyebut nama guru pembimbing dan periodenya. Cek dan insert dijalankan di bawah kunci bernama MySQL per siswa (`GET_LOCK`) untuk mencegah balapan dua guru; `SELECT ... FOR UPDATE` tidak cukup karena siswa yang belum punya penempatan tidak punya baris untuk dikunci.
- `tanggal_mulai ≤ tanggal_selesai`, durasi ≤ 366 hari.
- Penempatan yang **sudah punya jurnal**: `siswa_id`, `dudi_id`, dan `guru_id` tidak bisa diubah (gunakan tutup + penempatan baru). Rentang baru harus tetap mencakup semua tanggal jurnalnya.
- Kelas XII: siswa valid bila aktif (aturan status seperti `getActiveClassStudents`) dan kelasnya bertingkat XII. Tingkat ditentukan oleh `classes.level` yang dinormalisasi (`XII` atau `12`). Bila `level` kosong, dipakai token pertama `class_id`/`class_name`. Aturan ini hanya berlaku saat **membuat** penempatan. Penempatan yang sudah ada tetap sah walau status siswa berubah.
- Hapus DU/DI ditolak (409) bila dipakai penempatan mana pun.
- Hapus jurnal juga menghapus file fotonya, bila path masih di dalam `uploads/journals/pkl/`.

Format keluaran mengikuti konvensi bundle: field snake_case plus alias camelCase untuk field yang dipakai UI (`studentName`, `teacherName`, `startDate`, `endDate`), angka di-cast, `foto` di-decode menjadi array, dan field turunan `status` (`AKTIF`/`AKAN_DATANG`/`SELESAI`) serta `jumlah_jurnal`/`jurnal_terakhir` pada penempatan.

## 6. API

File baru **`api/bimbingan_pkl.php`** (guard `if (!defined('SIMANTAP_API')) exit;`), di-`require_once` di `api/index.php` setelah `kelola_guru.php`, dengan `case` eksplisit di `switch` router sebelum `default:`. Semua respons JSON memakai `jsonResponse`/`errorResponse`. Prepared statements wajib.

| Action | Metode | Masukan | Keluaran |
|---|---|---|---|
| `pkl_konteks` | GET | – | `{peran, is_pembimbing, is_wali_xii, kelas_wali?, is_admin, is_kepsek}`. Dipakai UI untuk menentukan tab; GURU yang tidak memenuhi syarat tetap 200 (bukan 403) |
| `pkl_dudi_list` | GET | `q?` | `{items:[dudi + jumlah_penempatan_aktif]}` |
| `pkl_dudi_simpan` | POST | `{id?, nama, bidang_usaha?, alamat?, pembimbing_nama?, pembimbing_telp?, catatan?}` | `{success, item}`. 409 + `{existing:{id,nama}}` bila nama_norm bentrok |
| `pkl_dudi_hapus` | POST | `{id}` | `{success}`. Hanya ADMIN; 409 bila dipakai |
| `pkl_kandidat_siswa` | GET | `kelas_id?` | `{kelas:[{id,nama}], siswa:[{id,nama,nisn,kelas_id,kelas_nama,penempatan_aktif:{guru_nama,dudi_nama,tanggal_mulai,tanggal_selesai}\|null}]}`, hanya siswa aktif kelas XII |
| `pkl_penempatan_list` | GET | `guru?`, `kelas_id?`, `dudi_id?`, `status?`, `siswa?` | `{items:[...], belum_ditempatkan?:[...]}`. Cakupan otomatis per role; `belum_ditempatkan` hanya untuk Kepsek/ADMIN/wali |
| `pkl_penempatan_simpan` | POST | `{id?, siswa_ids[] (buat) \| siswa_id, dudi_id, tanggal_mulai, tanggal_selesai, guru_id? (ADMIN)}` | `{success, tersimpan:[...], gagal:[{siswa_id, nama, alasan}]}`. Bila tidak ada yang tersimpan: 409/400 |
| `pkl_penempatan_tutup` | POST | `{id, tanggal_selesai}` | `{success, item}` |
| `pkl_penempatan_alih` | POST | `{id, guru_id_baru, tanggal_alih}` | `{success, lama, baru}`. Hanya ADMIN; satu transaksi |
| `pkl_penempatan_hapus` | POST | `{id}` | `{success}`; 409 bila punya jurnal |
| `pkl_jurnal_list` | GET | `bulan?` (YYYY-MM), `penempatan_id?`, `siswa?`, `guru?` | `{items:[...]}`, urut tanggal desc lalu jam_mulai desc |
| `pkl_jurnal_simpan` | POST | `{id?, penempatan_id, tanggal, jam_mulai, jam_selesai, jenis, uraian, kondisi_siswa?, permasalahan?, tindak_lanjut?, foto?[], salin_ke?[]}` | `{success, item, jumlah, ids?}` (§3.7) |
| `pkl_jurnal_hapus` | POST | `{id}` | `{success}` |
| `pkl_unggah_foto` | POST multipart | `file` | `{success, path}`. JPG/PNG/WEBP via `finfo`, ≤ 2 MB, ekstensi dari server, nama `random_bytes(16)`, folder `uploads/journals/pkl/<Ym>/` + `index.html` |
| `pkl_cetak_rekap` | GET | `guru?` (default diri sendiri), `bulan`, `kota?`, `tgl_ttd?`, `foto?` | Halaman HTML A4 (bukan JSON). Error berupa halaman HTML (`rekapHtmlError`) |
| `pkl_cetak_kartu` | GET | `siswa` | Halaman HTML A4 |

Helper internal yang diusulkan: `pklPastikanTabel`, `pklKonteks` (fresh user + flag `isAdmin`/`isKepsek`/`isPembimbing`/kelas perwalian XII via `homeroomClassScope`), `pklNormNama`, `pklKelasXII`, `pklSyaratPembimbing` (§4.1), `pklCekTumpangTindih`, `pklBarisPenempatan`, `pklBarisJurnal`, `pklValidasiJurnal`, `pklValidasiFoto`.

## 7. UI (patch bundle)

Patch `docs/patches/apply-bimbingan-pkl-bundle.js` membaca `assets/index-CGWL69P7-kelolaguru.js`, menulis `assets/index-CGWL69P7-pkl1.js`, dan mengarahkan `app.html` ke file itu. Patch gagal keras bila salah satu anchor string tidak ditemukan tepat satu kali. Hanya kelas Tailwind yang sudah ada di `assets/index-CC1PBzaO.css` yang dipakai.

- **Sidebar:** item "Bimbingan PKL" disisipkan setelah "Jurnal Mengajar" untuk role `GURU`, `KEPALA_SEKOLAH`, `ADMIN`. Ikon memakai komponen ikon yang sudah ada di bundle.
- **Halaman Bimbingan PKL**, tab tergantung peran:
  - GURU: **Siswa Bimbingan** (kartu/tabel penempatan: siswa, kelas, DU/DI, periode, badge status, jumlah jurnal, aksi Tambah Jurnal/Tutup/Hapus), **Jurnal Bimbingan** (filter bulan dan siswa, daftar jurnal, form tambah/ubah dalam modal, unggah foto ≤3 dengan pratinjau), **Cetak** (pilih bulan → buka `pkl_cetak_rekap` di tab baru; pilih siswa → `pkl_cetak_kartu`).
  - Tab pembimbing hanya muncul bila `pkl_konteks.is_pembimbing`. Tombol **Tambah Siswa Bimbingan** juga disembunyikan bila tidak memenuhi syarat, tetapi penempatan lama tetap tampil.
  - Wali kelas XII: tambahan tab **Siswa PKL Kelas Saya** (hanya baca, termasuk daftar "Belum punya pembimbing").
  - KEPALA_SEKOLAH: **Pantau** dan **Cetak** (pilih guru).
  - ADMIN: **Pantau**, **DU/DI**, **Cetak**, plus aksi Alih Pembimbing dan Hapus.
- Form DU/DI baru tampil sebagai modal dari pemilih DU/DI. Respons 409 menampilkan "DU/DI sudah ada: <nama>" dengan tombol "Gunakan yang ada".
- Pesan error backend ditampilkan apa adanya (toast/alert gaya bundle).
- Tampilan mobile: tabel diganti kartu bertumpuk seperti halaman lain.

## 8. Edge cases

| Kasus | Perilaku |
|---|---|
| Dua guru memilih siswa yang sama hampir bersamaan | Transaksi + `FOR UPDATE`: yang kedua mendapat 409 "Siswa sudah dibimbing oleh <guru> (periode ...)" |
| Siswa dipilih guru B untuk periode **setelah** penempatan guru A berakhir | Diizinkan (tidak tumpang tindih). Siswa kini punya riwayat dua pembimbing |
| Guru menutup penempatan dengan tanggal sebelum jurnal terakhir | 400 "Tanggal selesai tidak boleh sebelum jurnal terakhir (<tgl>)" |
| Jurnal tanggal besok / di luar rentang penempatan | 400 dengan pesan rentang yang valid |
| Jam selesai ≤ jam mulai | 400 |
| Beberapa jurnal satu siswa di hari yang sama | Diizinkan (mis. daring pagi, kunjungan siang). Tidak ada cek bentrok jam |
| Siswa pindah sekolah/keluar setelah ditempatkan | Penempatan dan jurnal tetap ada dan tetap bisa diisi dalam rentangnya. UI memberi badge status siswa terbaru bila bukan aktif |
| Siswa dihapus (`student_delete`) | Penempatan/jurnal memakai snapshot `siswa_nama`/`kelas_nama`. Ditampilkan "(data siswa terhapus)" |
| Guru dihapus (`delete_user`) | Penempatan/jurnal tetap dengan snapshot `guru_nama`. ADMIN bisa mengalihkan penempatan aktifnya |
| Siswa naik kelas/tahun ajaran berganti | Penempatan memakai snapshot `kelas_id` saat dibuat. Wali kelas lama tetap melihat lewat snapshot; wali kelas baru tidak |
| Wali kelas XII dengan `class_homeroom` yang tidak cocok persis | Dicocokkan dengan `normalizeClassKey` lewat `homeroomClassScope` |
| `classes.level` kosong atau format lain (`12`, `XII `) | Dinormalisasi. Fallback ke token pertama `class_id`/`class_name` |
| Sekolah tanpa tabel `classes` | `pkl_kandidat_siswa` memakai fallback token kelas dari `students` |
| Nama DU/DI mirip ("PT. Telkom" vs "Telkom") | `nama_norm` membuang awalan badan usaha dan tanda baca, jadi dianggap sama (409 + tawaran pakai yang ada) |
| Guru mengubah DU/DI yang sudah dipakai penempatan guru lain | 403. Hanya ADMIN |
| Foto diunggah tetapi jurnal batal disimpan | File yatim dibiarkan (sama seperti modul lain). Pembersihan di luar scope |
| `foto` berisi path asing (`../api/...`) atau > 3 | 400 |
| Bulan cetak tanpa data | Halaman tetap tercetak dengan baris "Nihil" |
| Guru tanpa penugasan mapel XII mencoba membuat penempatan lewat API | 403 dengan pesan syarat pembimbing (§4.1) |
| Penugasan mapel guru hanya memakai `class_level` (mis. `XII`) tanpa `class_ids` | Memenuhi syarat |
| Penugasan mapel guru memakai `class_ids` yang hanya berisi kelas XI walau `class_level` = `XII` | Tidak memenuhi syarat (`class_ids` diutamakan, sama seperti `teacherTeachesClassSubject`) |
| Penugasan mapel XII dihapus saat penempatan masih aktif | Penempatan dan jurnal lama tetap bisa diteruskan; penempatan baru ditolak |
| Wali kelas XII yang tidak mengajar mapel di kelas XII | Hanya mendapat tab baca **Siswa PKL Kelas Saya**, tidak bisa membimbing |
| ADMIN mengalihkan ke guru yang tidak memenuhi syarat | 400 |
| Role dicabut (mis. guru jadi TU) saat sesi aktif | `loadFreshUser` membaca role terbaru, jadi akses langsung mengikuti DB |
| GET ke action mutasi | 405 |

## 9. Compatibility

- **Tidak mengubah** `journal_entries`, `save_journal`, `attendance_records`, insiden, poin, panggilan orang tua, verifikasi jurnal, persetujuan massal, Pusat Analisis, statistik jurnal, atau Laporan Bulanan jurnal mengajar.
- Hanya tabel baru. Instalasi lama mendapat tabel saat action `pkl_*` pertama dipanggil. Instalasi baru dari `install/schema.sql` tidak perlu diubah (file itu DIGENERATE, jangan diedit manual; usulan upstream menambahkannya lewat migrasi).
- Action baru berprefiks `pkl_` dan tidak bentrok dengan action mana pun di keempat file endpoint (diverifikasi dengan grep sebelum implementasi).
- Bundle baru dibangun dari bundle saat ini, sehingga semua patch sebelumnya tetap ada. Rollback cukup dengan mengembalikan `app.html` ke `index-CGWL69P7-kelolaguru.js`. Tabel PKL boleh dibiarkan.
- **Auto-updater portal** bisa menimpa `api/index.php` dan `app.html`. Karena itu fitur ini harus ikut paket usulan upstream BTIKP. Dokumen patch mencatat langkah pemasangan ulang.
- Fitur cetak bergantung pada helper `api/rekap_bulanan.php`. Bila helper itu berubah, cetak PKL ikut diuji ulang.

## 10. Di luar scope

- Penilaian/nilai akhir PKL, sertifikat, dan rapor PKL.
- Jurnal harian **siswa** di DU/DI, akun atau akses untuk pembimbing industri, dan portal orang tua.
- Presensi PKL yang masuk `attendance_records` atau memengaruhi ALFA/poin/panggilan orang tua.
- Verifikasi/persetujuan kepala sekolah untuk jurnal bimbingan, serta penguncian jurnal.
- Integrasi angka bimbingan ke dashboard Kepsek, Pusat Analisis, atau statistik jurnal mengajar yang ada.
- Pembagian pembimbing massal oleh TU/ADMIN dari SK (impor Excel), dan akses TATA_USAHA.
- Penggabungan (merge) DU/DI duplikat oleh ADMIN. ADMIN hanya bisa ubah/hapus.
- Peta lokasi/GPS, verifikasi foto kunjungan, dan surat tugas kunjungan.
- PKL untuk tingkat selain XII.
- Kolom/isian **kehadiran siswa** di jurnal bimbingan. Presensi harian siswa PKL adalah ranah tempat PKL, yang sudah menyediakan lembar presensi harian.
- ~~Salin jurnal ke siswa lain di DU/DI yang sama~~: sudah diimplementasikan pada iterasi 2 (§3.7).
- Salin jurnal ke siswa di DU/DI lain atau ke siswa bimbingan guru lain, dan pengubahan massal jurnal hasil salin.
- Pembersihan otomatis file foto yatim.
- Perbaikan celah keamanan lama di modul lain (tercatat di CLAUDE.md).

## 11. File atau interface terkait

| File | Perubahan |
|---|---|
| `api/bimbingan_pkl.php` | **Baru**: helper, pembuatan tabel, semua handler `pkl_*`, dan halaman cetak |
| `api/index.php` | `require_once __DIR__ . '/bimbingan_pkl.php';` dan `case` eksplisit untuk 16 action `pkl_*` |
| `api/kesiswaan_access.php` | Dipakai (tanpa perubahan): `loadFreshUser`, `homeroomClassScope`, `normalizeClassKey` |
| `api/db.php` | Dipakai (tanpa perubahan): `requireAuth`, `jsonResponse`, `errorResponse`, `getRequestBody`, `getActiveAcademicPeriod`; `teacherTeachesClassSubject` sebagai acuan cara membaca `teacher_subjects` (§4.1) |
| Tabel `teacher_subjects` | Dibaca (tanpa perubahan) untuk syarat pembimbing. Diisi lewat penugasan mapel mandiri di Profil atau modal edit user ADMIN |
| `api/rekap_bulanan.php` | Dipakai (tanpa perubahan): `rekapEsc`, `rekapLoadKop`, `rekapSendHeaders`, `rekapHtmlError`, `rekapBulanIndo`, `rekapTanggalIndo`, `rekapAcademicMonthRange`, `rekapAssetUrl` |
| `docs/patches/apply-bimbingan-pkl-bundle.js` | **Baru**: patch bundle → `assets/index-CGWL69P7-pkl1.js` |
| `assets/index-CGWL69P7-pkl1.js`, `app.html` | Hasil patch dan referensi baru |
| `docs/patches/bimbingan-pkl.md` | **Baru**: catatan patch (anchor, pemasangan ulang, rollback) |
| `docs/patches/uji-bimbingan-pkl-api.mjs`, `uji-bimbingan-pkl-seed.sql`, `uji-bimbingan-pkl-cleanup.sql` | **Baru**: uji API |
| `docs/usulan-btikp/` | Paket usulan upstream (diff backend + patch frontend + ringkasan) |
| `CLAUDE.md` | Tambah baris `api/bimbingan_pkl.php` pada tabel domain |
| `uploads/journals/pkl/` | Folder foto (dibuat otomatis + `index.html`) |

## 12. Acceptance criteria

1. GURU melihat menu "Bimbingan PKL". TATA_USAHA tidak melihatnya, dan action `pkl_*` mengembalikan 403 untuknya.
1a. Hanya GURU dengan penugasan mapel kelas XII (§4.1) yang bisa membuat penempatan, menambah DU/DI, dan mengunggah foto. GURU tanpa penugasan itu mendapat 403 dari API dan halaman berisi pesan syarat. Wali kelas XII tanpa penugasan mapel XII hanya mendapat tab baca.
2. GURU dapat memilih ≥1 siswa aktif kelas XII, memilih/menambah DU/DI, mengisi periode, dan menyimpan. Penempatan tampil di tab Siswa Bimbingan dengan status yang benar.
3. Siswa kelas X/XI dan siswa non-aktif tidak muncul sebagai kandidat, dan ditolak (400) bila dikirim langsung ke API.
4. Guru B yang memilih siswa yang punya penempatan tumpang tindih milik guru A mendapat 409 dengan nama guru A. Penempatan guru A tidak berubah. Dua permintaan bersamaan tidak menghasilkan dua penempatan tumpang tindih.
5. Menambah DU/DI dengan nama yang sama setelah normalisasi menghasilkan 409 beserta DU/DI yang sudah ada.
6. Guru dapat menambah jurnal (tiga jenis kegiatan) untuk penempatan miliknya. Tanggal di luar rentang, tanggal di masa depan, jam tidak valid, atau `jenis` tidak dikenal ditolak 400. Form dan API tidak punya field kehadiran siswa.
7. Guru dapat mengubah dan menghapus jurnalnya kapan saja. Guru lain mendapat 403 untuk jurnal yang bukan miliknya, dan ADMIN dapat menghapus jurnal siapa pun.
8. Foto opsional: jurnal tanpa foto tersimpan. Unggahan > 2 MB, bukan JPG/PNG/WEBP, atau file `.php` yang diganti nama ditolak. File yang tersimpan berekstensi dari server dan bernama acak. Lebih dari 3 foto ditolak.
9. Menyimpan, mengubah, atau menghapus jurnal bimbingan **tidak** mengubah satu baris pun di `attendance_records`, `incidents`, `students.total_violation_points`, `journal_entries`, atau panggilan orang tua.
10. Menutup penempatan dengan tanggal ≥ jurnal terakhir berhasil, setelah itu penempatan baru (DU/DI lain) mulai hari berikutnya bisa dibuat. Penempatan berjurnal tidak bisa dihapus (409). Penempatan tanpa jurnal bisa dihapus.
11. ADMIN dapat mengalihkan pembimbing ke guru yang memenuhi syarat: penempatan lama berakhir di `tanggal_alih`, penempatan baru milik guru baru mulai `tanggal_alih + 1`, dan jurnal lama tetap milik guru lama. Pengalihan ke guru yang tidak memenuhi syarat ditolak 400.
12. Wali kelas XII melihat penempatan dan jurnal siswa kelas perwaliannya (hanya baca), termasuk daftar siswa yang belum punya pembimbing. Mutasi oleh wali atas penempatan yang bukan miliknya mendapat 403.
13. KEPALA_SEKOLAH melihat semua penempatan/jurnal di tab Pantau, termasuk ringkasan per guru, dan mendapat 403 untuk semua mutasi.
14. `pkl_cetak_rekap` menghasilkan halaman A4 ber-kop berisi jurnal guru pada bulan itu, ringkasan per jenis, dan TTD Kepala Sekolah + Guru Pembimbing. Bulan kosong menampilkan "Nihil". Guru tidak bisa mencetak rekap guru lain (403).
15. `pkl_cetak_kartu` menampilkan seluruh riwayat penempatan dan jurnal siswa lintas pembimbing, dengan akses sesuai §4.
16. Action mutasi yang dipanggil dengan GET mengembalikan 405.
17. Halaman Jurnal Mengajar, statistik jurnal, persetujuan jurnal, dan Pusat Analisis berperilaku sama seperti sebelum patch.
18. `php -l` lolos untuk semua file PHP yang berubah. Patch bundle bisa dijalankan ulang dari bundle acuan dan gagal keras bila anchor tidak cocok.
19. (Iterasi 2) Jurnal baru dengan `salin_ke` membuat satu jurnal per siswa terpilih dengan isian sama. Tujuan harus milik guru yang sama, di DU/DI
    yang sama, dan periodenya mencakup tanggal; bila tidak, tidak ada jurnal yang tersimpan (403/400). Jurnal ganda (penempatan, tanggal, jam mulai) → 409.
    Jurnal hasil salin bisa diubah sendiri-sendiri.

## 13. Verifikasi end-to-end

### 13.1 Statis

```
php -l api/bimbingan_pkl.php
php -l api/index.php
node docs/patches/apply-bimbingan-pkl-bundle.js     # menulis index-CGWL69P7-pkl1.js dan app.html
grep -n "pkl_" api/endpoints_v2.php api/endpoints_v3.php api/endpoints_tu.php   # harus kosong
```

### 13.2 Uji API otomatis (`docs/patches/uji-bimbingan-pkl-api.mjs`)

Prasyarat: Laragon aktif, `api/config.local.php` dengan `SIMANTAP_DEV_MODE`, `api/install.lock` ada, lalu jalankan seed `uji-bimbingan-pkl-seed.sql`. Seed membuat:
- 4 guru: A (penugasan `class_ids` berisi `XII TKJ 1`), B (penugasan `class_level = XII` tanpa `class_ids`), C (penugasan hanya kelas XI), dan wali XII (wali `XII TKJ 1`, tanpa penugasan XII); plus 1 kepsek, 1 admin, 1 TU, semuanya dengan password uji;
- kelas `XII TKJ 1` dan `XI TKJ 1`;
- 4 siswa aktif XII, 1 siswa XII berstatus Pindah, 1 siswa XI.

Skrip login per role (cookie terpisah) dan memeriksa minimal:
1. TU → `pkl_dudi_list` 403. GET `pkl_jurnal_simpan` → 405. `pkl_konteks`: A dan B `is_pembimbing=true`; C dan wali `false`; wali `is_wali_xii=true`. C dan wali → `pkl_penempatan_simpan` dan `pkl_dudi_simpan` 403.
2. Guru A tambah DU/DI "PT. Maju Jaya" → 200; Guru B tambah "Maju Jaya" → 409 + `existing`.
3. `pkl_kandidat_siswa` hanya berisi 4 siswa aktif XII.
4. Guru A menempatkan siswa 1–2 (1–31 Okt). Guru B menempatkan siswa 2–3 (15 Okt–15 Nov) → siswa 3 tersimpan, siswa 2 ada di `gagal` dengan nama Guru A. Dua permintaan paralel untuk siswa 4 → tepat satu berhasil.
5. Penempatan siswa XI / siswa Pindah → 400.
6. Guru A menambah jurnal valid (KUNJUNGAN + 1 foto JPG). Lalu mencoba tanggal besok, tanggal 1 Nov, jam 10:00–09:00, dan `jenis=X` → masing-masing 400.
7. Hitung baris `attendance_records`, `incidents`, dan `journal_entries` sebelum dan sesudah langkah 6 → sama (diperiksa via endpoint baca yang ada atau query helper PHP CLI).
8. Unggah file PHP berganti nama `.jpg` → 400. Unggah 3 MB → 400. `foto` berisi `../api/db.php` → 400.
9. Guru B mengubah/menghapus jurnal Guru A → 403. Guru A mengubah → 200. ADMIN menghapus → 200 dan file foto terhapus.
10. Guru A menutup penempatan siswa 1 sebelum tanggal jurnal terakhir → 400, sesudahnya → 200. Hapus penempatan berjurnal → 409.
11. ADMIN `pkl_penempatan_alih` siswa 1 ke Guru B → dua penempatan dengan tanggal yang tepat. Alih ke Guru C → 400. Hapus penugasan XII Guru A lewat seed → A masih bisa menambah jurnal di penempatan lama (200), tetapi penempatan baru → 403.
12. Wali XII: `pkl_penempatan_list` berisi siswa kelasnya + `belum_ditempatkan`. `pkl_penempatan_tutup` milik Guru A → 403.
13. Kepsek: list semua → 200. Mutasi apa pun → 403.
14. `pkl_cetak_rekap` Guru A bulan ini → 200 `text/html` berisi nama siswa dan "Mengetahui". Guru B dengan `guru=<A>` → 403. Bulan tanpa data → berisi "Nihil".
15. `pkl_cetak_kartu` siswa 1 → memuat dua penempatan (Guru A dan Guru B).
16. (Iterasi 2, grup S) Guru A: jurnal siswa 2 dengan `salin_ke=[siswa 1, siswa 2, siswa 1]` → 200, `jumlah=2`. Ulangi tanpa salin → 409.
    Salah satu tujuan ganda → 409 dan jumlah jurnal tidak berubah. Tujuan milik Guru B → 403. Tujuan di DU/DI lain atau di luar periode → 400.
    `salin_ke` saat ubah → 400. Ubah satu jurnal hasil salin tidak mengubah salinan lain.

Setelah selesai, jalankan `uji-bimbingan-pkl-cleanup.sql`. Cleanup menghapus data uji, tabel `pkl_*` bila dibuat oleh uji, dan file `uploads/journals/pkl/` hasil uji.

### 13.3 Uji manual di browser

1. Login sebagai Guru A (desktop dan lebar ponsel). Jalankan alur §3.2 dari menu Bimbingan PKL: pilih siswa, tambah DU/DI baru, isi 3 jurnal berbeda jenis (satu dengan foto), ubah satu, hapus satu, tutup penempatan, lalu buat penempatan baru.
2. Cetak rekap bulanan dan kartu siswa. Pastikan kop, logo, TTD, kota/tanggal yang bisa diedit, dan "Simpan sebagai PDF" A4 rapi.
3. Login sebagai wali kelas XII: tab Siswa PKL Kelas Saya hanya baca.
4. Login sebagai Kepala Sekolah: tab Pantau, filter, cetak rekap guru lain. Tidak ada tombol mutasi.
5. Login sebagai ADMIN: kelola DU/DI, alih pembimbing, hapus jurnal.
5a. (Iterasi 2) Sebagai guru dengan 3 siswa di satu DU/DI: "Salin ke siswa lain" pada jurnal siswa 1 → centang "Pilih semua" → tombol
    "Simpan Jurnal (2 siswa)" → pesan "tersimpan untuk 2 siswa". "Tambah Jurnal" lalu pilih siswa → daftar centang tidak memuat siswa itu sendiri.
6. Regresi: buat dan simpan satu Jurnal Mengajar biasa, buka statistik jurnal, persetujuan jurnal bulanan, dan Dashboard Kelas Saya. Semua harus tetap berfungsi. Tidak ada error di konsol browser.

## 14. Risiko dan trade-off

| Risiko / trade-off | Mitigasi / alasan |
|---|---|
| Guru memilih sendiri tanpa persetujuan → bisa salah klaim siswa sehingga siswa lain tidak bisa ditempatkan oleh pembimbing sebenarnya | Pesan 409 menyebut nama pembimbing agar diselesaikan langsung. ADMIN bisa mengalihkan. Tab Pantau menampilkan siswa yang belum ditempatkan |
| Tanpa verifikasi dan bisa diubah kapan saja → rekap yang sudah dicetak untuk e-Kinerja bisa berbeda dari data terbaru | Keputusan bisnis yang disengaja. Halaman cetak mencantumkan "Dicetak pada <tanggal jam>". Penguncian bisa ditambah nanti |
| Guru tidak bisa mengubah DU/DI milik guru lain → data DU/DI salah ketik bergantung pada ADMIN | Dedup `nama_norm` mengurangi duplikat. Merge DU/DI di luar scope |
| Deteksi kelas XII dari `classes.level`/nama kelas bisa salah bila sekolah memakai penamaan tak lazim | Normalisasi + fallback. Kasus khusus dicatat di dokumen patch |
| Snapshot nama/kelas bisa usang | Disengaja agar riwayat tetap terbaca setelah siswa/guru dihapus atau naik kelas |
| Tabel dibuat saat runtime (DDL di request) → pola yang sama dengan modul lain, tetapi user DB butuh hak CREATE | Sama seperti `student_dispensations` dan `journal_media_labels`. Usulan upstream memindahkannya ke migrasi |
| `uploads/` tanpa `.htaccess` | Ekstensi ditentukan server dari MIME dan nama acak, sehingga file `.php` tidak bisa terbentuk |
| Auto-updater menimpa `api/index.php`/`app.html` | Ikut paket usulan upstream. Dokumen patch berisi langkah pasang ulang |
| Satu entri per siswa → guru yang mengunjungi DU/DI berisi 5 siswa harus mengisi 5 jurnal | Keputusan wawancara (data per siswa lebih rapi untuk kartu siswa). Diatasi pada iterasi 2 dengan "salin jurnal ke siswa lain di DU/DI yang sama" (§3.7) |
| Syarat pembimbing bergantung pada kelengkapan `teacher_subjects` → guru yang benar-benar membimbing tetapi penugasan mapelnya belum diisi akan ditolak | Pesan 403 mengarahkan guru mengisi penugasan mapel di Profil (fitur penugasan mapel mandiri), lalu mencoba lagi |
| Terpisah dari statistik jurnal mengajar → dashboard Kepsek tidak menunjukkan aktivitas guru selama PKL | Disengaja untuk menghindari regresi. Tab Pantau menjadi tempat pemantauan |
