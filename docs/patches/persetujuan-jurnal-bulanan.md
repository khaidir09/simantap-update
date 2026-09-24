# Patch lokal: Persetujuan Massal Jurnal Bulanan

- Spesifikasi: `docs/specs/persetujuan-jurnal-bulanan.md`
- Versi bundle acuan: SIMANTAP **1.0.2** + patch detail jurnal/cetak (`assets/index-CGWL69P7-jurnal1.js`)
- Tanggal patch: 2026-09-24
- Status upstream: **belum** ada fitur resmi. Usulan: `docs/usulan-btikp/RINGKASAN-PERSETUJUAN-JURNAL.md`.

## 1. Daftar file

| File | Jenis | SHA-256 setelah patch |
|---|---|---|
| `api/persetujuan_jurnal.php` | baru: validasi bulan, preview, approve, DDL `journal_approval_batches` | `62cd487989a42626baff24069d5c5d6f3dcb9c4f5605b61ed992d13bf41eca81` |
| `api/index.php` | ubah: `require_once` file di atas; `save_journal`, `delete_journal`, `verify_journal`; action `preview_journal_month_approval` dan `approve_journals_month` setelah `verify_journal` | `0a0b593d51be71e3385834ef00c5fec96f09d2a98c1041dee3d24459c0c1f418` |
| `assets/index-CGWL69P7-jurnal1.js` | **tidak diubah** (sumber patch) | `7b3471f6af1850a80fdaadbadcbca3b45db7fe9cd1e351e41ef4033b2eff63aa` |
| `assets/index-CGWL69P7-setuju1.js` | baru: salinan `-jurnal1.js` dengan patch P0–P2d | `171849275eb1afeeb217cbfb09d44578b9217951253aa9abfd9adf684b108ad5` |
| `app.html` | ubah: `<script src>` ke `index-CGWL69P7-setuju1.js` | `daa2c98bcf0fefa1f0539e6057537b2420d8741d4403a6c5026cf88b7ad28b3c` |
| `docs/patches/apply-persetujuan-jurnal-bundle.js` | baru: skrip penerap patch bundle | – |
| `docs/patches/uji-persetujuan-jurnal-api.mjs` | baru: skrip uji API (spesifikasi §12.4) | – |

Hash `api/index.php` hanya berlaku bila file itu tidak berubah karena patch lain. Jika berbeda,
bandingkan isinya dengan `docs/usulan-btikp/backend-persetujuan-jurnal.diff`.

**Skema:** tabel baru `journal_approval_batches` dibuat otomatis (`CREATE TABLE IF NOT EXISTS`)
saat `approve_journals_month` pertama kali dipanggil. DDL lengkap ada di spesifikasi §4.2.
Tidak ada perubahan pada `journal_entries`.

## 2. Perubahan backend

| # | Lokasi | Perubahan |
|---|---|---|
| S1 | `save_journal` | `isVerified`/`verifiedBy`/`verifiedAt` tidak lagi diambil dari body. Jurnal baru selalu belum terverifikasi; edit mempertahankan status lama; jurnal terverifikasi dipaksa `isDraft = 0`. |
| S2 | `save_journal` | Selain ADMIN/KEPALA_SEKOLAH: edit jurnal terverifikasi → 403. |
| S3 | `delete_journal` | Selain ADMIN/KEPALA_SEKOLAH: hapus jurnal terverifikasi → 403. |
| S4 | `verify_journal` | 404 bila jurnal tidak ada, 400 untuk draf, idempoten untuk jurnal yang sudah terverifikasi. |
| S5/S6 | `save_journal`/`delete_journal` | Selain ADMIN/KEPALA_SEKOLAH: hanya jurnal milik sendiri (commit `46ed134`). |
| baru | `preview_journal_month_approval` | GET `month=YYYY-MM`, KEPALA_SEKOLAH/ADMIN, read-only. |
| baru | `approve_journals_month` | POST `{month, ids}`, KEPALA_SEKOLAH/ADMIN, transaksi + `FOR UPDATE`, tulis satu baris log per batch. |

Semua pengecekan S2/S3/S5/S6 terjadi **sebelum** transaksi `save_journal`, sehingga permintaan
yang ditolak tidak menulis presensi, insiden ALFA, poin, atau panggilan orang tua.

## 3. Patch frontend

Terapkan dari root deploy, **setelah** patch detail jurnal/cetak:

```
node docs/patches/apply-persetujuan-jurnal-bundle.js
```

Skrip ini:
1. memverifikasi hash `-jurnal1.js` dan berhenti jika berbeda;
2. memastikan nama baru (`ModalPersetujuanJurnal`, `setujuOpen`, `previewMonth`, `approveMonth`) belum ada di bundle;
3. memastikan **semua class Tailwind** yang disisipkan sudah ada di `assets/index-CC1PBzaO.css`
   (CSS bundle sudah terkompilasi, class baru tidak akan punya gaya);
4. memastikan setiap anchor muncul **tepat 1 kali**;
5. menulis `assets/index-CGWL69P7-setuju1.js` dan mengarahkan `app.html` ke file itu.

Skrip aman dijalankan ulang.

| # | Anchor | Perubahan |
|---|---|---|
| P0 | `function XTe({onAddJournal:e,onEditJournal:t,user:n}){` | Sisipkan komponen `ModalPersetujuanJurnal` sebelum `XTe`. Komponen ditulis sebagai fungsi biasa di skrip patch lalu disisipkan lewat `toString()`. |
| P1 | `verify:(e,t)=>yt("verify_journal","POST",{id:e,verifiedBy:t})}` | Tambah `previewMonth(month)` dan `approveMonth(month, ids)` di `et.journals`. |
| P2a | `[Y,ne]=ue.useState("grid"),` | State `setujuOpen`. |
| P2b | tombol " Laporan Bulanan" | Tombol "Setujui Jurnal Bulanan" (emerald, ikon `Cue`) hanya untuk KEPALA_SEKOLAH/ADMIN. |
| P2c | `I&&a.jsx(ZTe,{onClose:()=>M(!1)}),` | Render modal. Bulan default dari filter halaman (`N` + `E`). Setelah sukses, jurnal di `approvedIds` ditandai terverifikasi di state `r` tanpa reload. |
| P2d | pembungkus header halaman Jurnal | Tambah `flex-wrap`: dengan tiga tombol, grup tombol turun ke baris kedua alih-alih memotong tab bulan. Tampilan dua tombol (GURU) tidak berubah. |

Identifier bundle yang dipakai komponen: `a` (jsx runtime), `ue` (React), `et` (API client), dan
ikon lucide `pi` (x), `Cue` (shield-check), `Z1` (triangle-alert), `cr` (circle-check), `po`
(refresh-cw). Semuanya dideklarasikan di top-level modul.

`yt` melempar `Error` berisi teks body mentah. Modal mem-parse `{"error": ...}` dari pesan itu,
dan menampilkan "Terjadi kesalahan server" bila body kosong (HTTP 500).

Setelah deploy, minta pengguna melakukan *hard refresh*. Nama file bundle yang baru sudah
memaksa cache lama tidak dipakai.

## 4. Re-apply setelah auto-update

1. Catat `api/version.php` dan file `assets/index-*.js` yang direferensikan `app.html`.
2. Jika versi berubah, cek dulu apakah upstream sudah menyediakan persetujuan massal resmi.
   Jika sudah, patch ini tidak diperlukan lagi.
3. **Backend:**
   - salin `api/persetujuan_jurnal.php` bila hilang;
   - terapkan ulang perubahan `api/index.php` dari `docs/usulan-btikp/backend-persetujuan-jurnal.diff`;
   - pastikan `grep -n "case 'approve_journals_month'\|case 'preview_journal_month_approval'" api/*.php`
     menghasilkan tepat 2 baris.
4. **Frontend:** terapkan dulu patch-patch sebelumnya secara berurutan (lihat masing-masing dokumen
   patch), lalu jalankan skrip patch ini. Bila hash `-jurnal1.js` berbeda, verifikasi manual bahwa
   anchor P0–P2d masih muncul tepat 1 kali dan identifier di atas masih bermakna sama di `XTe`.
   Setelah itu perbarui `SRC_SHA256` di skrip.
5. Jalankan `php -l api/index.php api/persetujuan_jurnal.php`. Untuk bundle, parse sebagai ES module
   (salin ke `.mjs` lalu `node --check`), karena `node --check` pada `.js` memperlakukannya sebagai
   CommonJS.
6. Jalankan skrip uji API §6.

## 5. Rollback

1. Di `app.html`, kembalikan `./assets/index-CGWL69P7-setuju1.js` menjadi `./assets/index-CGWL69P7-jurnal1.js`.
2. Di `api/index.php`, hapus kedua `case` baru dan `require_once __DIR__ . '/persetujuan_jurnal.php';`,
   lalu hapus `api/persetujuan_jurnal.php` (**setelah** `require_once` dihapus).
3. Perubahan S1–S6 boleh dibiarkan, karena menutup celah keamanan dan tidak bergantung pada fitur ini.
4. Tabel `journal_approval_batches` dan status verifikasi yang sudah ditulis boleh dibiarkan.

## 6. Cara menjalankan uji API

Hanya di dev/staging. Uji membuat, memverifikasi, dan menghapus jurnal di bulan `TEST_MONTH`.

1. Buat akun uji sementara (email `<uid>@uji.test`, satu password yang sama):
   `uji_admin` (ADMIN), `uji_ks` (KEPALA_SEKOLAH), `uji_tu` (TATA_USAHA), `uji_guru` dan `uji_wali` (GURU).
   Contoh via SQL, dengan hash dari `php -r "echo password_hash('<pw>', PASSWORD_DEFAULT);"`:

   ```sql
   INSERT INTO users (uid, email, password, displayName, role) VALUES
   ('uji_admin','uji_admin@uji.test','<hash>','Uji Admin','ADMIN'),
   ('uji_ks','uji_ks@uji.test','<hash>','Uji KS','KEPALA_SEKOLAH'),
   ('uji_tu','uji_tu@uji.test','<hash>','Uji TU','TATA_USAHA'),
   ('uji_guru','uji_guru@uji.test','<hash>','Uji Guru','GURU'),
   ('uji_wali','uji_wali@uji.test','<hash>','Uji Wali','GURU');
   ```

2. Jalankan:
   ```
   BASE=http://localhost/simantap/api/index.php TEST_MONTH=2020-01 UJI_PASSWORD=<pw> node docs/patches/uji-persetujuan-jurnal-api.mjs
   ```
   Skrip berhenti (exit 2) bila `TEST_MONTH` sudah berisi jurnal, dan menghapus jurnal ujinya sendiri di akhir.
3. Bersihkan:
   ```sql
   DELETE FROM journal_approval_batches WHERE month = '2020-01';
   DELETE FROM journal_entries WHERE teacherId LIKE 'uji\_%';
   DELETE FROM users WHERE email LIKE '%@uji.test';
   ```

## 7. Hasil verifikasi (lingkungan dev lokal, 2026-09-24)

**Lingkungan:** Laragon (Apache + PHP 8.2, `SIMANTAP_DEV_MODE`), MySQL lokal dengan data sekolah, Chrome.

**Pemeriksaan statis:**
- `php -l` lolos untuk `api/index.php` dan `api/persetujuan_jurnal.php`;
- bundle `-setuju1.js` lolos parse sebagai ES module;
- 89 class Tailwind yang disisipkan tersedia di CSS bundle (validator skrip sempat menolak
  `max-h-64`, `my-auto`, dan `md:flex-wrap`, lalu diganti class yang tersedia).

**API: 40/40 lulus** (`uji-persetujuan-jurnal-api.mjs`, `TEST_MONTH=2020-01`), mencakup T0–T21:
- preview: jumlah layak, draf dikecualikan, rincian per guru, rentang bulan lampau;
- otorisasi 401/403, validasi bulan (termasuk bulan depan);
- approve memakai id preview: jurnal baru setelah preview tetap pending, verifikasi satuan di sela
  waktu tidak ditimpa, id asing dilewati (5 disetujui, 2 dilewati);
- approve ulang idempoten (0, tanpa batch);
- kunci edit/hapus jurnal terverifikasi untuk GURU; ADMIN mengedit tanpa me-reset verifikasi;
- `verify_journal`: 400 draf, 404 id tidak ada, idempoten;
- jurnal bertanggal besok di bulan berjalan tidak ikut (read-only terhadap data nyata);
- approve paralel KS + ADMIN: setiap jurnal disetujui tepat sekali;
- kepemilikan: GURU tidak bisa mengedit/menghapus jurnal guru lain.
- Tabel log berisi tepat 2 batch (T8: 5 jurnal, T17: 4 jurnal), sesuai harapan.

**UI (Chrome, akun KEPALA_SEKOLAH lokal dan akun GURU):**
- GURU: tombol tidak tampil.
- KEPALA_SEKOLAH: tombol tampil. Modal default ke bulan filter halaman. Ringkasan September 2026
  (7 akan disetujui, 5 sudah terverifikasi) cocok dengan "5/12" di halaman. Bulan berjalan
  **tidak** disetujui.
- Jurnal uji Januari 2022 (5) dan Februari 2022 (2) disetujui lewat modal. Pesan hasil tampil,
  dan rekap FEB berubah 0/2 → 2/2 tanpa reload.
- Tanpa error console.
- Temuan visual yang sudah diperbaiki: tab bulan terpotong karena tombol ketiga (P2d), dan bagian
  atas modal terpotong pada layar pendek (overlay kini menggulir dengan wrapper `min-h-screen`).

Semua akun, jurnal, dan log batch uji dihapus setelah pengujian. Tabel `journal_approval_batches`
tetap ada dalam keadaan kosong.

**Belum diuji:**
- Firefox dan layar ponsel;
- volume besar (ribuan jurnal dalam satu batch);
- pengujian di **staging** tetap wajib sebelum produksi.
