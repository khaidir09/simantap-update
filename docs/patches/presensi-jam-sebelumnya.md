# Patch lokal: Isi otomatis Sakit/Izin dari jam pelajaran sebelumnya di form jurnal

- Tanggal patch: 2026-09-27
- Status upstream: **belum** ada perbaikan resmi. Patch ini sekaligus usulan ke BTIKP.
- Melengkapi: `docs/patches/presensi-harian-jurnal.md` dan `docs/patches/dispensasi-siswa.md`.

## 1. Masalah

Form jurnal baru selalu memulai semua siswa dengan status **HADIR**. Siswa yang sudah ditandai
IZIN/SAKIT oleh guru jam 1-3 harus ditandai ulang oleh setiap guru berikutnya. Bila guru jam
berikutnya lupa, siswa tercatat HADIR di jam itu, dan karena satu jam HADIR sudah cukup, status
harian siswa ikut menjadi **HADIR** (lihat aturan presensi harian).

## 2. Aturan baru

Saat guru mengisi jurnal **baru** (bukan edit), form mengambil saran dari action
`presensi_jam_sebelumnya` dan mengisi status otomatis:

| Sumber | Syarat | Diisi |
|---|---|---|
| Jurnal non-draf kelas yang sama pada tanggal itu | jam akhir jurnal < jam awal jurnal yang diisi; dipakai jurnal **terakhir** sebelum jam ini | SAKIT/IZIN bila jurnal terakhir mencatat SAKIT/IZIN |
| Presensi harian `MANUAL` wali kelas | status SAKIT/IZIN dan siswa belum tercatat di jurnal sebelumnya | SAKIT/IZIN |

- Jurnal terakhir mencatat HADIR/DISPENSASI → tidak diisi (siswa IZIN jam 1 lalu HADIR jam 3 tidak diisi IZIN untuk jam 5).
- **ALFA tidak diteruskan.** Siswa bisa kembali ke kelas; bolos di tengah hari ditangani insiden `TINGGAL_JP`.
- Beberapa jurnal pada jam akhir yang sama: HADIR/DISPENSASI menang, lalu SAKIT, lalu IZIN.
- Jurnal dengan "Jam Ke-" yang tidak terbaca tidak dipakai. Bila jam jurnal yang diisi masih kosong, hanya sumber MANUAL yang dipakai.
- Siswa yang dispensasinya mencakup jam ini dilewati (form mengisi D lewat `dispensasi_jurnal`).
- Hanya siswa yang masih HADIR bawaan dan belum diubah guru yang diisi. Isian otomatis yang tidak
  berlaku lagi (guru mengubah jam/tanggal) dikembalikan ke HADIR.
- Form menampilkan keterangan di bawah nama siswa, misalnya `Izin di jam 1-3 (Matematika)` atau
  `Sakit menurut presensi harian wali kelas (Nama)`, serta pemberitahuan jumlah siswa yang diisi otomatis.

Tidak ada perubahan pada cara status harian dihitung; patch ini hanya mengubah isian awal form.

## 3. API

| Action | Parameter | Akses | Hasil |
|---|---|---|---|
| `presensi_jam_sebelumnya` | GET `class_id` (id atau nama kelas), `date`, `jam` | semua user login | `{items: {student_id: {status: SAKIT\|IZIN, sumber: JURNAL\|MANUAL, keterangan}}}` |

## 4. Daftar file

| File | Jenis | SHA-256 setelah patch |
|---|---|---|
| `api/presensi_jam_sebelumnya.php` | baru: `presensiSaranDariJamTerakhir`, `handlePresensiJamSebelumnya` | `4a85711d74bb9ee33a1eeb4d13d949c395b01998befe9f670924c2d1860345f4` |
| `api/index.php` | ubah: `require_once` setelah `dispensasi.php`; `case 'presensi_jam_sebelumnya'` | `ff9f5f2d87df09d423feb3547ed7e4b85bf4b790eadc4c90b9ad9f03e89314ae` |
| `docs/patches/apply-presensi-jam-bundle.js` | baru: patch bundle `index-CGWL69P7-jadwal1.js` → `index-CGWL69P7-presensijam1.js` | – |
| `assets/index-CGWL69P7-presensijam1.js` | hasil patch bundle; `app.html` diarahkan ke file ini | `737e2e887007b4196cd633ecc4d08ec825ae81da149c2b728145a4b6d954255c` |
| `docs/patches/uji-presensi-jam-api.mjs` | baru: uji HTTP (memakai seed/cleanup presensi harian) | – |

`api/presensi_jam_sebelumnya.php` bergantung pada `presensi_harian.php` (`presensiAngkaJam`,
`presensiParseJurnal`, `presensiDispensasi*`), `dispensasi.php` (`dispensasiWajibMetode`,
`dispensasiTanggalValid`), dan `kesiswaan_access.php` (`normalizeClassKey`).
Hash `api/index.php` hanya berlaku bila file itu tidak berubah karena patch lain.

**Skema:** tidak ada perubahan.

## 5. Pengujian

```
node docs/patches/apply-presensi-jam-bundle.js
mysql -uroot simantap < docs/patches/uji-presensi-harian-seed.sql
node docs/patches/uji-presensi-jam-api.mjs             # 20 uji lewat HTTP
mysql -uroot simantap < docs/patches/uji-presensi-harian-cleanup.sql
```

Hasil 2026-09-27 di Laragon (Apache PHP 8.3, MySQL 5.7.33): semua lulus. Uji manual di browser: jurnal
jam 1-3 mencatat Siswa A IZIN → form jurnal baru jam 4-5 menampilkan Siswa A = I dengan keterangan
`Izin di jam 1-3 (Matematika)`; jam diubah ke 2 → kembali H.

## 6. Catatan

- Auto-updater portal dapat menimpa `api/index.php`, `app.html`, dan bundle; periksa hash di atas setelah update.
- Action ini membuka status presensi per jam kelas mana pun untuk semua user login (sama seperti `dispensasi_jurnal`).
