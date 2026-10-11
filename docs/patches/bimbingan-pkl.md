# Patch lokal: Jurnal Bimbingan PKL

- Spesifikasi: `docs/specs/jurnal-bimbingan-pkl.md`
- Versi bundle acuan: `assets/index-CGWL69P7-kelolaguru.js` (patch Kelola Guru)
- Tanggal patch: 2026-10-08; iterasi 2 (salin jurnal ke siswa lain di DU/DI yang sama): 2026-10-11
- Status upstream: **belum** ada fitur resmi. Patch ini sekaligus usulan ke BTIKP (`docs/usulan-btikp/RINGKASAN-BIMBINGAN-PKL.md`).

## 1. Ringkasan

Selama siswa kelas XII menjalani Praktik Kerja Lapangan, guru yang biasa mengajar kelas XII mencatat
**jurnal bimbingan** di menu baru **Bimbingan PKL**, terpisah dari Jurnal Mengajar.

| Keputusan | Isi |
|---|---|
| Pembimbing | Hanya `GURU` dengan penugasan mapel kelas XII (`teacher_subjects`). Guru memilih sendiri siswa bimbingannya |
| Satu pembimbing | Periode penempatan satu siswa tidak boleh tumpang tindih (409 + nama pembimbing) |
| DU/DI | Master `pkl_dudi`, ditambah guru saat memilih siswa; nama di-dedup setelah dinormalisasi (PT/CV/tanda baca diabaikan) |
| Riwayat | Pindah DU/DI / lepas siswa = tutup penempatan lalu buat baru. Alih pembimbing hanya ADMIN |
| Jurnal | Satu entri per siswa per kegiatan: tanggal, jam, jenis (Kunjungan/Daring/Di Sekolah), uraian, kondisi, permasalahan, tindak lanjut, foto opsional (maks 3). **Tanpa kehadiran siswa** |
| Efek samping | Tidak menyentuh presensi harian, insiden, poin, panggilan orang tua, atau `journal_entries` |
| Salin jurnal (iterasi 2) | Satu isian → jurnal terpisah untuk beberapa siswa bimbingan sendiri di DU/DI yang sama (form Tambah Jurnal atau tombol "Salin ke siswa lain"). Semua atau tidak sama sekali. Jurnal ganda (penempatan + tanggal + jam mulai) ditolak 409 |
| Verifikasi | Tidak ada; guru bebas mengubah/menghapus jurnalnya |
| Cetak | Rekap bulanan per guru dan kartu bimbingan per siswa (HTML A4 ber-kop) |

## 2. Daftar file

| File | Jenis | SHA-256 setelah patch |
|---|---|---|
| `api/bimbingan_pkl.php` | baru: tabel, otorisasi, 16 handler, 2 halaman cetak, salin jurnal | `3a562c8f2e86d92ee5be2008c7ed0215a002aa9d358de15ec3ebbeac23d558c2` |
| `api/index.php` | ubah: `require_once bimbingan_pkl.php` + 16 `case` setelah `jadwal_kbm_save` | `67097aa070c7f7b3dc3c3e0375254d34b2524c80a109a152c09f8423c5395e3b` |
| `assets/index-CGWL69P7-kelolaguru.js` | **tidak diubah** (sumber patch) | `1c0d511b30cc56b5bedac6cba674d9a2838c2592889de8bd93b6610da6a65c45` |
| `assets/index-CGWL69P7-pkl2.js` | baru: salinan dengan patch P0–P3 (menggantikan `pkl1.js` dari iterasi 1, nama baru agar cache browser tidak memakai bundle lama) | `65f184be84a2086aed68d06f227657e6996cf0e586a28153280f89d7c770ddb8` |
| `app.html` | ubah: `<script src>` ke `index-CGWL69P7-pkl2.js` | `b2b61b7c9f738615d9135753fb019fc81ea064eaebd064c04c37e15042e4d4a6` |
| `docs/patches/apply-bimbingan-pkl-bundle.js` | baru: skrip penerap patch bundle | – |
| `docs/patches/uji-bimbingan-pkl-api.mjs` | baru: uji API (90 uji, termasuk grup S untuk salin jurnal) | – |
| `docs/patches/uji-bimbingan-pkl-seed.sql`, `-cleanup.sql`, `-hitung.php` | baru: data uji, pembersih, penghitung baris tabel yang tidak boleh berubah | – |

Hash `api/index.php` hanya berlaku bila file itu tidak berubah karena patch lain.

**Skema:** tabel baru `pkl_dudi`, `pkl_penempatan`, `pkl_jurnal`, dibuat otomatis (`CREATE TABLE IF NOT EXISTS`)
oleh handler `pkl_*` pertama yang dipanggil. Tidak ada perubahan pada tabel lama.

**Unggahan:** `uploads/journals/pkl/<yyyymm>/<32 hex>.<jpg|png|webp>`. Ekstensi dari isi berkas (`finfo`), maks 2 MB.
Foto dihapus saat jurnal dihapus atau foto dilepas dari jurnal (bila tidak dipakai jurnal lain).

## 3. Backend (`api/bimbingan_pkl.php`)

| Action | Metode | Akses |
|---|---|---|
| `pkl_konteks` | GET | GURU, KEPALA_SEKOLAH, ADMIN (peran + daftar guru untuk Kepsek/Admin) |
| `pkl_dudi_list`, `pkl_kandidat_siswa` | GET | pembimbing, wali kelas XII, Kepsek, Admin |
| `pkl_dudi_simpan` | POST | pembimbing (ubah: hanya DU/DI buatannya yang belum dipakai guru lain), Admin |
| `pkl_dudi_hapus` | POST | Admin, bila tidak dipakai |
| `pkl_penempatan_list` | GET | GURU (miliknya + kelas perwalian XII), Kepsek/Admin (semua) |
| `pkl_penempatan_simpan` | POST | pembimbing untuk dirinya; Admin untuk guru yang memenuhi syarat. Ubah: pemilik/Admin |
| `pkl_penempatan_tutup`, `pkl_penempatan_hapus` | POST | pemilik, Admin |
| `pkl_penempatan_alih` | POST | Admin |
| `pkl_jurnal_list` | GET | seperti `pkl_penempatan_list` + jurnal yang ditulis sendiri |
| `pkl_jurnal_simpan` | POST | GURU pemilik penempatan (ubah: penulis jurnal). `salin_ke[]` (hanya jurnal baru): penempatan lain milik guru itu di DU/DI yang sama, periodenya mencakup tanggal |
| `pkl_jurnal_hapus` | POST | penulis jurnal, Admin |
| `pkl_unggah_foto` | POST multipart | GURU pembimbing, atau GURU yang masih punya penempatan |
| `pkl_cetak_rekap` | GET (HTML) | GURU (dirinya), Kepsek/Admin (`guru=` wajib) |
| `pkl_cetak_kartu` | GET (HTML) | pembimbing/penulis jurnal siswa itu, wali kelasnya, Kepsek, Admin |

Role dan perwalian dibaca ulang dari DB setiap request (`loadFreshUser`). Mutasi wajib POST (405).

**Perbedaan dari spesifikasi (disengaja):**
- Balapan dua guru memilih siswa yang sama dicegah dengan kunci bernama MySQL per siswa (`GET_LOCK`), bukan
  `SELECT ... FOR UPDATE`, karena `FOR UPDATE` tidak mengunci siswa yang belum punya baris penempatan.
- `pkl_unggah_foto` juga mengizinkan guru yang penugasan XII-nya sudah dicabut tetapi masih punya penempatan,
  agar ia bisa melanjutkan jurnal lengkap dengan foto (§4.1 spesifikasi: jurnal lama tetap bisa diteruskan).
- `pkl_cetak_rekap` hanya menolak bulan yang belum berjalan. Rentang tahun ajaran aktif hanya menjadi batas
  bawah pemilih bulan, supaya rekap tahun ajaran lalu tetap bisa dicetak ulang.

## 4. Frontend (`apply-bimbingan-pkl-bundle.js`)

| Patch | Anchor | Isi |
|---|---|---|
| P0 | `const __dspK=` | Sisip peta class `__pklK` dan komponen `__pklHalaman` + tab: Siswa Bimbingan, Jurnal Bimbingan (dengan salin ke siswa lain di DU/DI yang sama), Siswa PKL Kelas Saya (wali XII), Pantau (Kepsek/Admin), DU/DI (Admin), Cetak |
| P1 | `roles:["GURU","KEPALA_SEKOLAH"]},{id:"assessment"` | Item menu Akademik "Bimbingan PKL" (ikon `dW` briefcase) untuk GURU, KEPALA_SEKOLAH, ADMIN |
| P2 | `e==="dispensasi"&&a.jsx(__dspHalaman,{user:o}),` | Router `bimbingan-pkl` |
| P3 | `"portal-nilai","dispensasi","kepegawaian-gtk"` | Daftar halaman yang dikenal |

Catatan: guru BK (`is_bk=1`) tidak melihat seksi Akademik di sidebar bawaan bundle, sehingga juga tidak melihat
menu Bimbingan PKL. Guru tanpa penugasan XII yang bukan wali kelas XII melihat menu, tetapi halamannya
hanya berisi pesan syarat.

## 5. Pemasangan ulang (setelah auto-update portal menimpa file)

```
# 1. Backend: salin api/bimbingan_pkl.php, lalu tambahkan lagi require_once dan 16 case di api/index.php
#    (lihat docs/usulan-btikp/backend-bimbingan-pkl.diff)
php -l api/bimbingan_pkl.php && php -l api/index.php
# 2. Frontend: bila bundle sumber berbeda, perbarui SRC_NAME/SRC_SHA256 dan cek ulang anchor P0–P3
node docs/patches/apply-bimbingan-pkl-bundle.js
```

Skrip patch juga mengarahkan ulang `app.html` yang masih menunjuk ke keluaran lama (`index-CGWL69P7-pkl1.js`).
Rollback frontend: arahkan `app.html` kembali ke `./assets/index-CGWL69P7-kelolaguru.js`. Tabel `pkl_*` boleh dibiarkan.

## 6. Verifikasi

```
php -l api/bimbingan_pkl.php
php -l api/index.php
node docs/patches/apply-bimbingan-pkl-bundle.js
mysql simantap < docs/patches/uji-bimbingan-pkl-seed.sql
BASE=http://localhost/simantap/api/index.php node docs/patches/uji-bimbingan-pkl-api.mjs   # 90 uji
mysql simantap < docs/patches/uji-bimbingan-pkl-cleanup.sql
```

Hasil 2026-10-08 (PHP 8.2 built-in server + MySQL 5.7 Laragon): **81 lulus, 0 gagal**, dijalankan dua kali dari data
bersih. Termasuk uji J14: jumlah baris `attendance_records`, `incidents`, `parent_calls`, `journal_entries`, dan
total poin siswa sama sebelum dan sesudah jurnal bimbingan disimpan.

Uji manual di browser (Chrome): sebagai guru B menu muncul, kartu penempatan dan tombol aksi tampil, "Tulis Jurnal"
membuka form dengan siswa dan DU/DI terisi, simpan berhasil, tanpa error konsol. Sebagai Kepala Sekolah hanya tab
Pantau dan Cetak, ringkasan per guru benar. Halaman rekap bulanan dan kartu siswa tampil rapi di A4.
Belum diuji manual: unggah foto lewat UI, alur ADMIN (tab DU/DI, alih pembimbing) di browser, tampilan lebar ponsel.

**Iterasi 2 (2026-10-11), salin jurnal:** **90 lulus, 0 gagal**, dijalankan dua kali dari data bersih (81 uji lama + 9 uji grup S).
Di browser sebagai guru B dengan 3 siswa di satu DU/DI: "Salin ke siswa lain" pada jurnal siswa 1 membuka form berisi isian
jurnal itu dengan daftar centang siswa 2 dan 3. "Pilih semua" lalu "Simpan Jurnal (2 siswa)" menampilkan pesan "Jurnal bimbingan tersimpan untuk 2 siswa"
dan dua kartu jurnal baru. Di "Tambah Jurnal", daftar centang tidak memuat siswa yang sedang dipilih. Tidak ada error konsol.
