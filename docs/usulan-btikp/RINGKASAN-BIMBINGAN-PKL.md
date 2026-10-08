# Usulan Fitur SIMANTAP: Jurnal Bimbingan PKL

| | |
|---|---|
| Sekolah | SMK Negeri 1 Amuntai (HSU) |
| ID Distribusi | 9E8DC8FBF4 |
| Versi | 1.0.2 |
| Tanggal | 2026-10-08 |
| Lampiran | `backend-bimbingan-pkl.diff`, skrip frontend `docs/patches/apply-bimbingan-pkl-bundle.js` |
| Spesifikasi | `docs/specs/jurnal-bimbingan-pkl.md` |
| Dokumentasi patch lokal | `docs/patches/bimbingan-pkl.md` |
| Prasyarat | Tidak ada perubahan pada fitur lain. Halaman cetak memakai helper `api/rekap_bulanan.php` (usulan Rekap Bulanan Wali Kelas) |

## 1. Kebutuhan

Saat siswa kelas XII menjalani Praktik Kerja Lapangan (PKL), guru yang biasa mengajar kelas XII tidak lagi
mengajar di kelas. Kegiatannya beralih menjadi **membimbing siswa PKL**. SIMANTAP hanya punya Jurnal Mengajar,
yang wajib berisi kelas, mapel, jam ke-, dan presensi, serta memicu presensi harian, ALFA, poin, dan panggilan
orang tua. Akibatnya bimbingan PKL dicatat di luar sistem, dan guru kehilangan bukti kinerja selama PKL.

## 2. Usulan

- Menu baru **Bimbingan PKL** (Akademik, di bawah Jurnal Mengajar).
- **Pembimbing** = GURU dengan penugasan mapel kelas XII. Guru memilih sendiri siswa bimbingannya; satu siswa
  hanya punya satu pembimbing pada periode yang sama.
- **Master DU/DI** (tempat PKL) dengan pembimbing industri; nama di-dedup otomatis.
- **Penempatan** siswa ↔ DU/DI ↔ guru ↔ periode, dengan riwayat (tutup lalu buat baru) dan alih pembimbing oleh Admin.
- **Jurnal bimbingan** per siswa: Kunjungan/Monitoring, Daring, atau di Sekolah; uraian, kondisi, permasalahan,
  tindak lanjut, foto opsional. Kehadiran harian siswa **tidak** dicatat (ranah tempat PKL).
- **Pantau** untuk Kepala Sekolah/Admin: ringkasan per guru dan daftar siswa XII yang belum punya pembimbing.
  Wali kelas XII melihat siswa kelasnya (hanya baca).
- **Cetak** A4 ber-kop: rekap bulanan per guru (lampiran e-Kinerja) dan kartu bimbingan per siswa.
- Tanpa verifikasi Kepala Sekolah; tidak mengubah statistik/dashboard jurnal mengajar.

## 3. Dampak teknis

- Tiga tabel baru (`pkl_dudi`, `pkl_penempatan`, `pkl_jurnal`). Di patch lokal dibuat saat pertama dipakai;
  untuk rilis resmi sebaiknya dijadikan migrasi di `schema_migrations`.
- Satu file backend baru (`api/bimbingan_pkl.php`, 16 action berprefiks `pkl_`) + 16 `case` di `api/index.php`.
- Tidak ada perubahan perilaku pada action yang sudah ada.
- Keamanan: semua action memeriksa role dari DB, mutasi wajib POST, prepared statement, unggahan foto
  divalidasi MIME dengan nama acak dan ekstensi dari server.

## 4. Pengujian

81 uji API otomatis (`docs/patches/uji-bimbingan-pkl-api.mjs`) lulus, termasuk otorisasi per peran, balapan dua guru,
validasi tanggal/jam/foto, unggahan berbahaya, dan bukti bahwa presensi/insiden/poin/jurnal mengajar tidak berubah.
