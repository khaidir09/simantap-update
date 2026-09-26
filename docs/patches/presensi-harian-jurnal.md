# Patch lokal: Presensi harian dari jurnal, insiden ALFA, dan pelanggaran meninggalkan jam pelajaran

- Tanggal patch: 2026-09-26
- Status upstream: **belum** ada perbaikan resmi. Patch ini sekaligus usulan ke BTIKP.
- Tahap berikutnya (belum dikerjakan): status per jam `DISPENSASI` dan tabel dispensasi. Jam
  DISPENSASI nantinya tidak dihitung sebagai meninggalkan jam pelajaran.

## 1. Masalah yang diperbaiki

1. **Jurnal terakhir menimpa presensi harian.** `attendance_records` hanya punya satu baris per
   siswa per tanggal, dan setiap jurnal (jam berapa pun) menimpa statusnya. Murid yang HADIR di
   jam 1 lalu ditandai ALFA oleh guru jam 3 langsung menjadi ALFA harian, lengkap dengan insiden,
   poin, dan kemungkinan panggilan orang tua.
2. **Insiden ALFA tidak mengikuti prioritas MANUAL.** Status MANUAL wali kelas dipertahankan, tetapi
   `save_journal` tetap membuat/menghapus insiden ALFA menurut status jurnal. Contoh: wali kelas
   ALFA (MANUAL) + jurnal HADIR → status tetap ALFA, tetapi insiden dan poinnya terhapus.
3. Penghapusan insiden mengurangi poin ALFA yang berlaku **sekarang**, bukan poin yang dulu
   ditambahkan.
4. Menghapus jurnal atau mengubah tanggal/siswa jurnal tidak memperbarui presensi harian.

## 2. Aturan baru

Status harian dihitung ulang dari **semua jurnal non-draf** pada tanggal itu:

| Kondisi | Status harian |
|---|---|
| Baris sudah `MANUAL` (disimpan wali kelas) | tidak diubah |
| Ada minimal satu jam HADIR | HADIR |
| Tidak ada HADIR, ada SAKIT | SAKIT |
| Tidak ada HADIR/SAKIT, ada IZIN | IZIN |
| Seluruh jam ALFA | ALFA |
| Tidak ada lagi jurnal non-draf yang mencatat siswa | baris `JURNAL` dihapus |

Bila status per jam tidak seragam, kolom `notes` diisi, misalnya
`Dari jurnal, tidak mengikuti jam 3 (Matematika): ALFA; jam 4 (Fisika): IZIN`.

Insiden ALFA (`inc_att_<siswa>_<tanggal>`) selalu mengikuti status harian **final**, termasuk
status MANUAL. Saat dihapus, poin siswa dikurangi sebesar `incidents.points` insiden itu.

### Pelanggaran meninggalkan jam pelajaran (`TINGGAL_JP`)

Kasus "hadir jam 1 lalu meninggalkan jam pelajaran" tercatat HADIR harian plus catatan, dan
otomatis mendapat insiden pelanggaran `TINGGAL_JP` (id `inc_tjp_<siswa>_<tanggal>`):

- Syarat: status harian final **HADIR** (dari jurnal maupun MANUAL) dan minimal satu jam pelajaran
  ditandai ALFA di jurnal non-draf. Jam SAKIT/IZIN tidak dihitung.
- **Sekali per siswa per hari.** Semua jam yang ditinggalkan dicatat di rincian insiden, misalnya
  `Meninggalkan jam pelajaran tanpa izin (dari Jurnal 2026-09-26): jam 3 (Matematika), jam 4 (Fisika)`.
  Rincian diperbarui bila jam berubah; poin tidak ditambah lagi.
- Insiden dicabut (poin dikembalikan sebesar `incidents.points`) bila syarat tidak lagi terpenuhi:
  guru mengoreksi jurnal, jurnal dihapus, status harian menjadi ALFA (diganti insiden ALFA), atau
  wali kelas menyimpan status MANUAL SAKIT/IZIN/ALFA.
- Poin mengikuti jenis pelanggaran `TINGGAL_JP` "Meninggalkan Jam Pelajaran Tanpa Izin"
  (default 10 poin, RINGAN). Jenis ini dibuat otomatis saat pertama dibutuhkan dan poinnya dapat
  diubah dari menu jenis pelanggaran. Bila dihapus, jenis ini dibuat ulang dengan nilai default.
- Ambang panggilan orang tua (`violation_threshold_parentcall`) berlaku sama seperti insiden ALFA.
- Rekap bulanan menampilkan insiden ini sebagai pelanggaran biasa (bukan ALFA), dengan nama dari
  `violation_types`.

## 3. Daftar file

| File | Jenis | SHA-256 setelah patch |
|---|---|---|
| `api/presensi_harian.php` | baru: `presensiTurunkanHarian`, `presensiJamAlfa`, `presensiPoinTinggalJp`, `presensiKonfigInsiden`, `presensiSinkronInsiden(Alfa)`, `presensiSinkronHarian` | `ed2fcb7ff22f0edc2d2a7606aa7a446bf6c8adf6402381b977c77a87dee82383` |
| `api/index.php` | ubah: `require_once` setelah `pantau_nilai.php`; `save_journal` memakai `presensiSinkronHarian` untuk tanggal baru dan lama; `delete_journal` dalam transaksi + sinkron | `66447719f5429c93fa181a789f85b301539dd636cda85326b4e8f69a72815427` |
| `api/endpoints_v2.php` | ubah: `save_attendance_records` memakai `presensiSinkronInsidenAlfa` (status panggilan orang tua tetap `PARENT_INVITED`), lalu `presensiSinkronHarian` per tanggal untuk insiden `TINGGAL_JP` | `928e90d4c6ffbfefd51fc50b568c78a691532de4a40d86fd143aa9c43d27a52b` |
| `docs/patches/uji-presensi-harian-unit.php` | baru: uji aturan status harian (tanpa DB) | – |
| `docs/patches/uji-presensi-harian-db.php` | baru: uji integrasi DB, semua data di-rollback | – |
| `docs/patches/uji-presensi-harian-api.mjs`, `-seed.sql`, `-cleanup.sql` | baru: uji HTTP dan data ujinya | – |

Hash `api/index.php` dan `api/endpoints_v2.php` hanya berlaku bila file itu tidak berubah karena patch lain.
`api/presensi_harian.php` bergantung pada `pantauNilaiParseAttendance`/`pantauNilaiAttendanceStatus`
dari `api/pantau_nilai.php` dan `activeAcademicPeriod` dari `api/kesiswaan_access.php`.

**Skema:** tidak ada perubahan skema. Kolom `attendance_records.notes` sudah ada di skema dasar.
**Data:** baris `violation_types` `TINGGAL_JP` ditambahkan otomatis saat pertama dibutuhkan.
**Frontend:** tidak ada perubahan bundle.

## 4. Pengujian

```
php docs/patches/uji-presensi-harian-unit.php          # 14 uji
php docs/patches/uji-presensi-harian-db.php            # 31 uji, data di-rollback
mysql -uroot simantap < docs/patches/uji-presensi-harian-seed.sql
node docs/patches/uji-presensi-harian-api.mjs          # 12 uji lewat HTTP
mysql -uroot simantap < docs/patches/uji-presensi-harian-cleanup.sql
```

Hasil 2026-09-26 di Laragon (PHP 8.2, MySQL 5.7.33): semua lulus.

## 5. Catatan

- Data lama tidak dihitung ulang otomatis. Presensi dan insiden yang sudah salah karena bug di atas,
  dan insiden `TINGGAL_JP` untuk tanggal lampau, baru terbentuk/terkoreksi saat jurnal tanggal itu disimpan atau dihapus lagi.
- `recorded_by`/`recorder_name` baris `JURNAL` berisi user yang terakhir memicu perhitungan ulang.
- Auto-updater portal dapat menimpa `api/index.php` dan `api/endpoints_v2.php`; periksa hash di atas
  setelah update.
