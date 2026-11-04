# Patch lokal: Mode Verifikasi Jurnal — Otomatis SAH & Tetap Bisa Diedit

- Spesifikasi: `docs/specs/verifikasi-jurnal-otomatis.md`
- Bundle sumber: `assets/index-CGWL69P7-jenjang1.js` (patch Jenjang Sekolah)
- Tanggal patch: 2026-11-04

## 1. Daftar file

| File | Jenis | SHA-256 setelah patch |
|---|---|---|
| `api/verifikasi_jurnal.php` | baru: helper mode verifikasi, migrasi lazy, nama kepala sekolah | `ebd4b0db10f06ae3aaf050c27f3d6ad9ebe51a7cee8f695010b9309f048e1a55` |
| `api/index.php` | ubah: `require_once`; `get_journals`, `save_journal`, `delete_journal`, `get_system_config`, `save_system_config` | `4576e6be151e06764acb02c2d9106cf0f060a9e66701219fa2141bdbe8762d81` |
| `api/endpoints_v2.php` | ubah: `get_config` (key mode + migrasi lazy), `save_config` (tolak key mode/penanda) | `e515eca1c84851084b0b0a772794d6fdddf6c7370cffe6570bd6407b8d8171b6` |
| `api/endpoints_v3.php` | ubah: `get_dashboard_analytics` (`mode_verifikasi_jurnal`, 0 Pending di mode OTOMATIS) | `200f7c8a667a9a55a1c909c193c6a578a53f7b42555e296ee0dd1f8ee2c3689b` |
| `assets/index-CGWL69P7-jenjang1.js` | **tidak diubah** (sumber patch) | `154b1e2ca04be709387a43464f46f912648a884df2e9b1f577abb9927d80f123` |
| `assets/index-CGWL69P7-verifotomatis1.js` | baru: salinan `-jenjang1.js` dengan patch V0–V6 | `c3deb03c1a102e87ee6019d01c3b175c315a4f682e727de6cb9b7e11e80e51b4` |
| `app.html` | ubah: `<script src>` ke `index-CGWL69P7-verifotomatis1.js` | `e8e7e631eae960fc5c015b8037b3c0aa103fb021926d98595ad311369c87328f` |
| `docs/patches/apply-verifikasi-otomatis-bundle.js` | baru: skrip penerap patch bundle | – |
| `docs/patches/uji-verifikasi-otomatis-api.mjs` | baru: skrip uji API (spesifikasi §9.3) | – |

Hash file PHP hanya berlaku bila file itu tidak berubah karena patch lain.

**Skema:** tidak ada perubahan tabel. `system_config` mendapat dua key yang dibuat otomatis:
`journal_verification_mode` (`OTOMATIS`/`MANUAL`) dan `journal_verification_migrated`.

## 2. Perubahan backend

| # | Lokasi | Perubahan |
|---|---|---|
| B1 | `save_journal` | Mode OTOMATIS: jurnal non-draf yang belum SAH → `isVerified=1, verifiedBy='SISTEM', verifiedAt=sekarang`; pemilik boleh mengedit jurnal SAH. Mode MANUAL: perilaku lama. |
| B2 | `delete_journal` | Mode OTOMATIS: pemilik boleh menghapus jurnal SAH. |
| B3 | `get_journals` | Tambah `verifiedByName` (SISTEM / verifikator terhapus → nama kepala sekolah; selain itu `users.displayName`). |
| B4 | `get_config`, `get_system_config` | Tambah mode efektif `journal_verification_mode`. |
| B5 | `save_system_config` | Validasi mode; MANUAL → OTOMATIS dengan jurnal Pending → 409 `peringatan_verifikasi` sampai `konfirmasi_verifikasi: true`; penanda migrasi diabaikan dari body. |
| B6 | `save_config` | Tolak key `journal_verification_mode`/`journal_verification_migrated` (400). |
| B7 | `get_dashboard_analytics` | Tambah `mode_verifikasi_jurnal`; mode OTOMATIS → `jurnal_belum_verifikasi = 0`. |
| B8 | migrasi lazy | `vjPastikanMigrasi()` di B1–B5/B7: sekali jalan, `GET_LOCK` + transaksi, mengesahkan jurnal Pending lama. |

`verify_journal` dan `approve_journals_month` tidak diubah (tetap berfungsi, disembunyikan di UI mode OTOMATIS).

## 3. Perubahan frontend (V0–V6)

Lihat komentar kepala `apply-verifikasi-otomatis-bundle.js`. Mode dibaca dari `get_config` yang
sudah dimuat `__jjMuat()` (tidak ada request tambahan); gagal dimuat → OTOMATIS.

## 4. Cara menerapkan ulang (mis. setelah auto-update)

1. Pastikan `api/verifikasi_jurnal.php` ada dan perubahan B1–B7 masih ada:
   `grep -n "vjPastikanMigrasi\|vjOtomatis" api/index.php api/endpoints_v2.php api/endpoints_v3.php`.
2. Bila `app.html` tidak lagi merujuk `verifotomatis1`: `node docs/patches/apply-verifikasi-otomatis-bundle.js`
   (gagal bila hash bundle sumber berubah — cari ulang anchor).
3. `php -l` keempat file PHP; `node --check assets/index-CGWL69P7-verifotomatis1.js`.

## 5. Rollback

Arahkan `app.html` kembali ke `index-CGWL69P7-jenjang1.js` dan kembalikan file PHP dari backup.
Jurnal yang sudah SAH otomatis tetap SAH (lihat spesifikasi §9.7).

## 6. Cara menjalankan uji API

Hanya di dev/staging. Uji mengubah mode verifikasi lalu mengembalikannya, dan mengesahkan semua
jurnal Pending di database saat kembali ke OTOMATIS.

1. Buat akun uji sementara `uji_admin` (ADMIN), `uji_ks` (KEPALA_SEKOLAH, displayName `Uji KS`),
   `uji_guru` dan `uji_wali` (GURU) — SQL sama dengan `docs/patches/persetujuan-jurnal-bulanan.md` §6.
   `kop_kepsek_nama` harus terisi.
2. `BASE=http://localhost/simantap/api/index.php UJI_PASSWORD=<pw> node docs/patches/uji-verifikasi-otomatis-api.mjs`
3. Bersihkan:
   ```sql
   DELETE FROM journal_entries WHERE id LIKE 'j_uji_verif_%';
   DELETE FROM users WHERE email LIKE '%@uji.test';
   ```

## 7. Hasil verifikasi (dev lokal, 2026-11-04)

- `php -l` bersih untuk keempat file PHP; patch bundle V0–V6 berhasil (setiap anchor cocok), `node --check` lulus.
- Migrasi lazy pada salinan DB sekolah: 21 jurnal Pending → SAH `SISTEM`; 1 jurnal yang sudah
  diverifikasi Kepsek tidak berubah.
- `uji-verifikasi-otomatis-api.mjs`: **28 lulus, 0 gagal** (T1–T13).
- T15 migrasi bersamaan (5 request paralel, penanda dihapus): semua 200, migrasi sekali, jurnal Pending jadi SAH.
- E13 nilai mode tidak valid → efektif OTOMATIS; key `MANUAL` tanpa penanda → hanya penanda ditulis, tidak memigrasi.
- **Belum**: skenario browser §9.4 (ekstensi Chrome tidak terhubung saat patch dibuat).
