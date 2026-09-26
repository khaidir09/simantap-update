# Patch lokal: Dispensasi Siswa

- Versi bundle acuan: `assets/index-CGWL69P7-portalnilai1.js` (patch Portal Nilai)
- Tanggal patch: 2026-09-26
- Prasyarat: patch presensi harian (`docs/patches/presensi-harian-jurnal.md`)
- Status upstream: **belum** ada fitur resmi. Patch ini sekaligus usulan ke BTIKP.

## 1. Ringkasan

Murid yang meninggalkan kelas untuk kegiatan yang diakui sekolah (lomba, tugas sekolah, dsb.)
dengan surat rekomendasi/izin dispensasi dicatat sebagai **dispensasi**, bukan alfa.

| Keputusan | Isi |
|---|---|
| Penginput | ADMIN, KEPALA_SEKOLAH, TATA_USAHA, Guru BK (semua kelas); wali kelas (kelas perwaliannya). Langsung berlaku tanpa persetujuan. |
| Cakupan | Rentang tanggal (maks 31 hari), per hari sehari penuh atau jam ke-X s.d. Y |
| Form jurnal | Siswa yang dicakup otomatis terisi **D** + keterangan kegiatan; guru tetap bisa mengubah |
| Dispensasi susulan | Jam yang terlanjur ditandai ALFA di jurnal dihitung DISPENSASI; insiden ALFA/TINGGAL_JP dicabut. Isi jurnal guru tidak diubah. |
| Presensi harian | Jam DISPENSASI dihitung hadir: status harian HADIR, kolom `notes` mencatat kegiatannya |

Pencocokan jam memakai angka di kolom "Jam Ke-" jurnal (teks bebas): `1-2`, `3`, `1, 3`, `3 s.d. 5`,
`Jam ke 7 - 8`. Jurnal yang kolom jamnya tidak berisi angka hanya dicakup dispensasi sehari penuh.

## 2. Daftar file

| File | Jenis | SHA-256 setelah patch |
|---|---|---|
| `api/dispensasi.php` | baru: tabel, akses, validasi, enam handler | `cf78b0587bb673a50277045783cdbc4f3a8b429a286729d50db3be3a80b49b8c` |
| `api/presensi_harian.php` | ubah: status per jam `DISPENSASI`, parser sendiri (`presensiParseJurnal`), `presensiAngkaJam`, `presensiDispensasiMencakup`, `presensiDispensasiTanggal`; jam ALFA yang dicakup dispensasi menjadi DISPENSASI | `a7c7ea12316184baae8cce629370740f38ab57d0ff45351c6e59ddb87939dd78` |
| `api/index.php` | ubah: `require_once dispensasi.php`, enam `case` setelah `portal_nilai_set_aktif`, `save_journal`/`delete_journal` memakai `presensiParseJurnal` | `50e1536c678fa180b3122cd5ac0590f99129e0b565318832a2b94cad8c3af829` |
| `assets/index-CGWL69P7-portalnilai1.js` | **tidak diubah** (sumber patch) | `964781e9bbba2d446f2f861560d780dc234bec48754d80817c9fc8c42bf57377` |
| `assets/index-CGWL69P7-dispensasi1.js` | baru: salinan dengan patch P0–P9 | `3aaf8c2081d2646b692fe03abad126b67a969be48bc6f672e0e417dca5d3cd71` |
| `app.html` | ubah: `<script src>` ke `index-CGWL69P7-dispensasi1.js` | `a709ab2c09340106a5b5797debba9450bc7e4aa2db89ad2b10e0e45c8db02372` |
| `docs/patches/apply-dispensasi-bundle.js` | baru: skrip penerap patch bundle | – |
| `docs/patches/uji-dispensasi-api.mjs` | baru: uji API (33 uji) | – |
| `docs/patches/uji-presensi-harian-*` | ubah: uji unit/DB dispensasi, akun & siswa uji tambahan | – |

Hash `api/index.php` hanya berlaku bila file itu tidak berubah karena patch lain.

**Skema:** tabel baru `student_dispensations`, dibuat otomatis (`CREATE TABLE IF NOT EXISTS`) oleh
handler dispensasi di luar transaksi. `presensi_harian.php` tidak menjalankan DDL; bila tabel belum
ada, dispensasi dianggap kosong.

**Unggahan:** `uploads/tu/dispensasi/<yyyymm>/<32 hex>.<pdf|jpg|png>`. Ekstensi ditentukan dari isi
berkas (`finfo`), bukan dari nama berkas klien; maks 5 MB. Berkas dihapus saat dispensasi terakhir
yang memakainya dihapus.

## 3. Backend

| Action | Metode | Akses | Isi |
|---|---|---|---|
| `dispensasi_list` | GET `dari`, `sampai`, `class_id?` | cakupan dispensasi | Dispensasi yang beririsan dengan rentang tanggal + `scope` |
| `dispensasi_siswa` | GET `class_id` | cakupan dispensasi | Siswa aktif satu kelas |
| `dispensasi_simpan` | POST JSON | cakupan dispensasi | `student_ids[]`, `tanggal_mulai`, `tanggal_selesai`, `sehari_penuh`, `jam_ke_mulai`, `jam_ke_selesai`, `kegiatan`, `nomor_surat`, `surat_path`; satu baris per siswa, lalu presensi harian dihitung ulang untuk setiap tanggal |
| `dispensasi_hapus` | POST JSON `{id}` | cakupan dispensasi | Hapus + hitung ulang presensi harian |
| `dispensasi_unggah_surat` | POST multipart `file` | cakupan dispensasi | Simpan surat, balas `path` |
| `dispensasi_jurnal` | GET `class_id`, `date`, `jam` | semua user login | Dispensasi yang mencakup jam jurnal: `{items: {student_id: {kegiatan, cakupan}}}` |

Cakupan dispensasi (`dispensasiScope`, user dibaca ulang dari DB): ADMIN, KEPALA_SEKOLAH,
TATA_USAHA, dan GURU BK untuk semua kelas; GURU wali kelas untuk kelas perwaliannya; lainnya 403.
Metode HTTP diperiksa (405 bila salah).

## 4. Patch frontend

Terapkan dari root deploy, **setelah** patch Portal Nilai:

```
node docs/patches/apply-dispensasi-bundle.js
```

| Patch | Isi |
|---|---|
| P0 | Komponen `__dspHalaman`, hook `__dspPakaiJurnal`, peta class `__dspK` |
| P1 | Form jurnal: pasang hook; perubahan status oleh guru ditandai manual sehingga tidak ditimpa |
| P2–P3 | Tombol **D** di setiap siswa (gaya `bg-indigo-600`, karena CSS bundle tidak punya `peer-checked:bg-indigo-*`) |
| P4 | Keterangan "Dispensasi: kegiatan (cakupan)" di bawah nama siswa |
| P5 | Kotak ringkasan Dispensasi (`grid-cols-3 md:grid-cols-5`) |
| P6 | Menu Kesiswaan "Dispensasi Siswa" (wali kelas, Kepala Sekolah, Admin, TU) |
| P7 | Menu Bimbingan & Konseling "Dispensasi Siswa" (Guru BK; grup Kesiswaan disembunyikan untuk BK) |
| P8–P9 | Router dan daftar halaman dikenal: `dispensasi` |

Perilaku hook form jurnal:
- Dipanggil ulang 400 ms setelah kelas/tanggal/jam berubah.
- Hanya pada jurnal **baru**: siswa yang masih HADIR bawaan diubah menjadi D; bila jam diganti dan
  dispensasi tidak lagi mencakup, D otomatis dikembalikan ke HADIR. Siswa yang statusnya sudah
  diubah guru tidak disentuh.
- Pada edit jurnal, status tidak diubah; keterangan dispensasi tetap ditampilkan.

## 5. Pengujian

```
php docs/patches/uji-presensi-harian-unit.php          # 44 uji
php docs/patches/uji-presensi-harian-db.php            # 39 uji, data di-rollback
mysql -uroot simantap < docs/patches/uji-presensi-harian-seed.sql
node docs/patches/uji-dispensasi-api.mjs               # 33 uji
node docs/patches/uji-presensi-harian-api.mjs          # 12 uji
mysql -uroot simantap < docs/patches/uji-presensi-harian-cleanup.sql
```

Hasil 2026-09-26 di Laragon (PHP 8.3 Apache, MySQL 5.7.33): semua lulus. Uji di browser (akun wali
kelas): menu, halaman kelola, simpan dispensasi, D otomatis + keterangan di form jurnal, ubah
manual D↔H, dan kotak ringkasan berjalan.

## 6. Keterbatasan

- Tampilan lain yang menghitung H/S/I/A dari jurnal (detail jurnal, cetak laporan jurnal, pantau
  nilai per pertemuan) belum menampilkan status D secara khusus. Pantau nilai menampilkan "-".
- `attendanceRate` jurnal tetap dihitung frontend dari jumlah HADIR saja.
- Dispensasi tidak bisa diedit; hapus lalu input ulang.
- Dispensasi tanpa jurnal pada hari itu tidak membuat baris presensi harian.
