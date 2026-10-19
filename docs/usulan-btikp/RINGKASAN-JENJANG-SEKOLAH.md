# Usulan Perbaikan SIMANTAP: Jenjang Sekolah Dinamis (SD/SMP/SMA/SMK)

| | |
|---|---|
| Sekolah | SMK Negeri 1 Amuntai (HSU) |
| ID Distribusi | 9E8DC8FBF4 |
| Versi | 1.0.2 |
| Tanggal | 2026-10-19 |
| Lampiran | `backend-jenjang-sekolah.diff` |
| Patch bundle lokal | `docs/patches/apply-jenjang-sekolah-bundle.js` |
| Prasyarat | Diff dibuat di atas usulan-usulan sekolah sebelumnya, terutama usulan Bimbingan PKL (`api/bimbingan_pkl.php`, 16 `case 'pkl_*'`, `pklHtmlError()`). Tanpa usulan itu, bagian guard PKL di `api/index.php` dapat dilewati. |

## 1. Masalah

SIMANTAP mengasumsikan sekolah SMA/SMK:

1. **Level kelas hardcode X/XI/XII** di Kelola Kelas, Tambah Penugasan Mapel, Kelola Guru, dan Profil guru. SD dan SMP tidak bisa memilih tingkat kelasnya.
2. **Form Jurnal Mengajar membuang kelas SD/SMP.** Level kelas guru diturunkan dari prefiks ID (`startsWith("XII"/"XI"/"X")`), sehingga kelas `VII A` atau `4B` menjadi level kosong dan tersaring keluar.
3. **Parser level backend** (`gtk_update_atribut`) dan seeding `get_classes` (`SUBSTRING_INDEX(class_id,' ',1)`) tidak mengenali I–VI, angka (`7A`), atau pemisah `_`.
4. **Menu Bimbingan PKL** (usulan sebelumnya) tampil di semua sekolah, padahal hanya relevan untuk SMK.

## 2. Usulan

| Bagian | Perubahan |
|---|---|
| Pengaturan | Key baru `system_config.school_level` (`SD`/`SMP`/`SMA`/`SMK`), diatur ADMIN lewat `save_system_config` yang sudah ada. Tidak ada DDL |
| Default | Bila belum diatur, dideteksi dari `kop_sekolah` (SMK→SMK, SMP/MTs→SMP, SD/MI→SD, selain itu SMA); tidak ditulis ke DB |
| Level | SD I–VI, SMP VII–IX, SMA/SMK X–XII (Romawi). Parser yang sama di PHP dan bundle juga mengenali angka 1–12 |
| Ganti jenjang | 409 + `peringatan_jenjang {kelas, penugasan, siswa, dari, ke}` bila ada data di luar jenjang baru; simpan ulang dengan `konfirmasi_jenjang: true`. Data lama tidak diubah |
| PKL | Hanya SMK: menu disembunyikan dan ke-16 aksi `pkl_*` (termasuk cetak) menjawab 403 "Fitur Bimbingan PKL hanya tersedia untuk jenjang SMK." Data PKL tetap utuh |
| API baca | `get_config` menambah `school_level` + `school_level_sumber` (`pengaturan`/`deteksi`); `get_system_config` menambah `school_level` hanya bila hasil deteksi |

## 3. Kompatibilitas

- SMK yang `kop_sekolah`-nya memuat "SMK" tidak mengalami perubahan perilaku.
- **SMK yang nama sekolahnya tidak memuat "SMK" akan terdeteksi SMA dan kehilangan menu PKL** sampai ADMIN memilih SMK. Perlu dicantumkan di catatan rilis.
- Respons API hanya bertambah key. `save_system_config` tanpa `school_level` berperilaku sama seperti sebelumnya.
- Validasi level tidak ditambahkan di `save_class`/penugasan (data lama & import tetap diterima); pembatasan di UI.

## 4. Di luar cakupan

Guru kelas SD, Fase Kurikulum Merdeka, SMK 4 tahun (XIII), perubahan installer, migrasi otomatis data level lama.

## 5. Pengujian di sekolah

Unit PHP 51 kasus dan paritas parser JS 34 kasus (tabel bersama `uji-jenjang-sekolah-kasus.json`), uji API 27 kasus, serta uji browser alur ganti jenjang SMK→SMP. Semua lulus. Rincian di `docs/patches/jenjang-sekolah.md` dan spesifikasi `docs/specs/jenjang-sekolah-dinamis.md`.
