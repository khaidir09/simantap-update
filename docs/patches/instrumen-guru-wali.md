# Patch lokal: Impor Instrumen Digital Guru Wali (Google Form)

- Spesifikasi: `docs/specs/impor-instrumen-guru-wali.md`
- Versi bundle acuan: SIMANTAP **1.0.2** + patch Profil Murid 360° + patch Guru Wali (`assets/index-CGWL69P7-guruwali1.js`)
- Tanggal patch: 2026-11-14
- Status upstream: **belum** ada fitur resmi. Patch ini sekaligus usulan ke BTIKP.
- Tahap 2 dari rangkaian Bimbingan Guru Wali (sebelumnya: penugasan murid dampingan; berikutnya: jurnal pendampingan).
- Instrumen acuan: Google Form "Formulir Awal Murid Guru Wali 2026/2027" (SMK Negeri 1 Amuntai); contoh respons `docs/asesmen-murid-dampingan.xlsx`.

## 1. Daftar file

| File | Jenis | SHA-256 setelah patch |
|---|---|---|
| `api/instrumen_guru_wali.php` | baru: konstanta kunci penskoran, fungsi murni (peta kolom, Cap waktu, skor minat/kompetensi RIASEC, V/A/K, pemetaan butir), skema (dibuat saat pertama dipakai), pembaca untuk modul lain, handler `instrumen_gw_impor` | `72dfb733a06d64a37b9819b8acec6139bcf38774fc374115bc00a0c27d52ae1e` |
| `api/index.php` | ubah: `require_once instrumen_guru_wali.php`, `case 'instrumen_gw_impor'` setelah blok Guru Wali | `b3acc68eb52d0416b439f2b854dbaab0483cb99a8f4ea4d373c996c4fdc57f2c` |
| `api/profil_murid.php` | ubah: `profilMuridPastikanTabel` memanggil `instrumenGwPastikanSkema`; `profilMuridBarisAsesmen` cast/decode kolom baru; field `instrumen` di `profil_murid_get`; baris kompetensi, bakat, keterampilan, alasan di cetak | `63557881e2ea1deded84529d10a7d27518cd2d6323499d2098fd874dc03ee6ad` |
| `api/guru_wali.php` | ubah: `guru_wali_dampingan_saya` menambah `murid[].instrumen_cap_waktu` dan `ringkasan.instrumen_terisi` | `6b777aebbfca1fd17dd2d0fad79ad55f51f2d55c37929f9af70b2f15bdd69242` |
| `assets/index-CGWL69P7-guruwali1.js` | **tidak diubah** (sumber patch) | `2fd0fd13c3c1a6be1a7fcc9c8158b0627782dd1dccaf7d66a913b905cada381c` |
| `assets/index-CGWL69P7-instrumengw1.js` | baru: salinan `-guruwali1.js` dengan patch I0–I5 | `52dab71df2d05a8ac17c68788a36c5497c9a3c6cbcb1996ffbb99c6313c4654a` |
| `app.html` | ubah: `<script src>` ke `index-CGWL69P7-instrumengw1.js` | `0bcbde08f64f5d86212f5b54b0a554ac9929d348cbb737799ef942a4a90ce5e4` |
| `docs/patches/apply-instrumen-guru-wali-bundle.js` | baru: skrip penerap patch bundle | – |
| `docs/patches/uji-instrumen-guru-wali-api.mjs` | baru: uji API P0–P5, T1–T12 (66 pemeriksaan) | – |
| `docs/patches/uji-instrumen-guru-wali-unit.php` | baru: uji unit fungsi murni (88 pemeriksaan) | – |
| `docs/patches/uji-instrumen-guru-wali-kasus.json` | baru: header + 3 respons contoh (identitas buatan) + skor harapan yang dihitung independen | – |
| `docs/patches/uji-instrumen-guru-wali-seed.sql`, `-cleanup.sql` | baru: data uji dan pembersihnya | – |

Hash file `api/*.php` yang diubah hanya berlaku bila file itu tidak berubah karena patch lain.

**Skema (aditif, dibuat saat pertama dipakai oleh `instrumenGwPastikanSkema`, dijaga `GET_LOCK` + pengecekan `information_schema`):**
- `student_asesmen`: 15 kolom baru (`kompetensi_r..c`, `kompetensi_kode`, `kegiatan_dikuasai`, `kesulitan_cerita`, `mapel_pilihan`,
  `mapel_pendukung`, `bakat`, `keterampilan_target`, `alasan_rencana`, `instrumen_respons_id`); `sumber` menjadi `enum('IMPOR','MANUAL','INSTRUMEN')`.
- `student_import_log.jenis` menjadi `enum('DAPODIK','ASESMEN','INSTRUMEN')`.
- Tabel baru `student_instrumen_respons` (satu respons per murid + instrumen + periode, `jawaban_json` = `{"jawaban": {...}, "judul": {...}}`).

## 2. Patch bundle (I0–I5)

| ID | Lokasi | Isi |
|---|---|---|
| I0 | sebelum `__gwKartuProfil` | `__igwWaktu`, `__igwBacaFile` (SheetJS `raw: true` agar tanggal CSV tidak ditebak bulan/hari), modal `__igwImpor`, kartu `__igwJawaban` |
| I1 | `__gwDampingan` | diganti utuh: kolom "Formulir", "X dari Y murid sudah mengisi", tombol "Impor Hasil Formulir (Google Form)" |
| I2 | Profil Murid, Identitas | "Jarak / transportasi menurut murid" bila biodata Dapodik kosong |
| I3 | Profil Murid, Asesmen | Kompetensi RIASEC + kegiatan, cerita kesulitan, mapel pilihan, bakat, keterampilan target, label sumber "formulir murid" |
| I4 | Profil Murid, Rencana Karir | mapel pendukung, alasan memilih rencana |
| I5 | Profil Murid, tata letak | kartu "Jawaban Murid — Formulir Awal" setelah Rencana Karir |

## 3. Menerapkan ulang setelah pembaruan portal

Updater portal bisa menimpa `api/index.php`, `api/profil_murid.php`, `api/guru_wali.php`, dan `app.html`.
1. Terapkan ulang patch Profil Murid dan Guru Wali bila ikut tertimpa.
2. Salin `api/instrumen_guru_wali.php`, lalu ulangi perubahan pada §1 (cari komentar "instrumen" di file tersebut).
3. `node docs/patches/apply-instrumen-guru-wali-bundle.js` (skrip menolak bila hash `-guruwali1.js` berbeda; cari ulang anchor bila bundle berubah).

## 4. Verifikasi (2026-11-14)

- `php -l` empat file PHP: lulus. `node --check` skrip patch, bundle hasil, skrip uji API: lulus.
- `php docs/patches/uji-instrumen-guru-wali-unit.php`: **88 lulus, 0 gagal**.
- `node docs/patches/uji-instrumen-guru-wali-api.mjs` (Laragon Apache + MySQL 5.7): **66 lulus, 0 gagal**.
- Regresi: `uji-profil-murid-unit.php` 56 lulus; `uji-guru-wali-unit.php` 55 lulus; `uji-profil-murid-api.mjs` 89 lulus (3 dilewati karena `UJI_MYSQL` tidak diset); `uji-guru-wali-api.mjs` 142 lulus.
- E2E (Chrome headless lewat DevTools Protocol, akun `uji_igw_gw1`): Murid Dampingan menampilkan kolom Formulir + ringkasan; unggah `docs/asesmen-murid-dampingan.xlsx` → pratinjau (Timpa/Dilewati, peringatan nama, skor) → simpan; Profil Murid menampilkan Kompetensi RIASEC, butir baru, label sumber, dan kartu Jawaban Murid (42 pernyataan). Tidak ada error/peringatan konsol.
- Catatan: file contoh `docs/asesmen-murid-dampingan.xlsx` dan data uji memakai nama/NISN buatan (`0099200001`–`0099200003`); jawaban pilihan tetap dari respons asli sehingga skor harapan tidak berubah. Pencocokan NISN hanya terhadap murid dampingan pengimpor, sehingga murid lain dengan NISN sama tidak tersentuh.
