# Patch lokal: Cetak Rekap Bulanan Wali Kelas

- Spesifikasi: `docs/specs/rekap-bulanan-wali-kelas.md`
- Versi bundle acuan: SIMANTAP **1.0.2** + patch wali kelas (`docs/patches/wali-kelas-kesiswaan.md`)
- Tanggal patch: 2026-09-14
- Status upstream: **belum** ada fitur resmi. Dokumen ini sekaligus menjadi lampiran usulan ke BTIKP.

## 1. Daftar file

| File | Jenis | SHA-256 setelah patch |
|---|---|---|
| `api/rekap_bulanan.php` | baru: handler `cetak_rekap_bulanan`, pengumpul data, dan renderer HTML A4 | `e31a9de18aba0e9958c86641bfdbb61de5ab39136cbfa2ccf5c9dd92bb07f723` |
| `api/index.php` | ubah: `require_once __DIR__ . '/rekap_bulanan.php';` setelah `kesiswaan_access.php`, dan `case 'cetak_rekap_bulanan'` sebelum `default:` | `b44e255ede66a20a91a013bcd45418d9f5636ad10d4974d101d2dba2f92bbf5d` |
| `assets/index-CGWL69P7-wali1.js` | **tidak diubah** (sumber patch, hasil patch wali kelas) | `8e153c7fd69ee8cf789e0f7c8be89f2b06d564f8b4e3b8b799e71eecb01f0d97` |
| `assets/index-CGWL69P7-wali2.js` | baru: salinan `-wali1.js` dengan patch R1 | `b4b4a68973c30eb13d1b7d6ea6d69141dbd5b3418caacce596cee7810f30c1f3` |
| `app.html` | ubah: `<script src>` ke `index-CGWL69P7-wali2.js` | `03994e50ac08324939095d2e02beb93e264b2fd2cc7fc906cdd8003f300adb9d` |
| `docs/patches/apply-rekap-bulanan-bundle.js` | baru: skrip penerap patch bundle | – |

Hash `api/index.php` hanya berlaku bila file itu tidak berubah karena patch lain. Jika berbeda, bandingkan isinya dengan dua baris perubahan di atas, bukan dengan hash.

Tidak ada perubahan skema. Config opsional `kop_kota` (kota di atas tanda tangan) bisa diisi admin lewat SQL:

```sql
INSERT INTO system_config (config_key, config_value, description)
VALUES ('kop_kota', 'Amuntai', 'Kota pada tanda tangan dokumen')
ON DUPLICATE KEY UPDATE config_value = VALUES(config_value);
```

## 2. Patch frontend (R1)

Terapkan dari root deploy, **setelah** patch wali kelas:

```
node docs/patches/apply-rekap-bulanan-bundle.js
```

Skrip ini:
1. memverifikasi hash `-wali1.js` dan berhenti jika berbeda;
2. memastikan anchor muncul **tepat 1 kali**;
3. menulis `assets/index-CGWL69P7-wali2.js`;
4. mengarahkan `app.html` dari `-wali1.js` ke `-wali2.js`.

Skrip aman dijalankan ulang.

| # | Anchor | Perubahan |
|---|---|---|
| R1 | Tombol "Perbarui Data" utuh di komponen `dot`: `a.jsxs("button",{onClick:L,className:"flex items-center gap-2 px-4 py-2 bg-white border border-slate-200 rounded-xl text-xs font-black text-slate-500 hover:bg-slate-50 transition-all",children:[a.jsx(po,{size:13})," Perbarui Data"]})` | Dibungkus `div.flex.flex-wrap.items-center.gap-2` bersama tombol baru **"Cetak Rekap Bulanan"** (emerald, ikon printer `Ss`). |

Tombol baru membuka `${K$}?action=cetak_rekap_bulanan&class_id=<class_homeroom>&month=<YYYY-MM>&auto=1` di tab baru.
- **Bulan default:** tanggal 1–10 bulan lalu, selain itu bulan berjalan.
- **`auto=1`:** backend menggeser bulan default yang jatuh di luar tahun ajaran aktif ke bulan valid terdekat, lalu menampilkan info di panel.

Hal yang sudah diverifikasi:
- `Ss` adalah ikon `printer` lucide yang dideklarasikan di top-level bundle dan dipakai sebelum maupun sesudah `dot`;
- `Ss` tidak dideklarasikan ulang di dalam `dot`;
- kelas Tailwind yang dipakai (`bg-emerald-600`, `border-emerald-600`, `hover:bg-emerald-700`, `text-white`, `flex-wrap`) ada di `assets/index-CC1PBzaO.css`.

Setelah deploy, minta pengguna melakukan *hard refresh*. Nama file bundle yang baru sudah memaksa cache lama tidak dipakai.

## 3. Re-apply setelah auto-update

1. Catat `api/version.php` dan file `assets/index-*.js` yang direferensikan `app.html`.
2. Jika versi atau bundle berubah, cek dulu apakah upstream sudah menyediakan rekap bulanan resmi. Jika sudah, patch ini tidak diperlukan lagi.
3. Terapkan ulang **patch wali kelas lebih dulu** (lihat `docs/patches/wali-kelas-kesiswaan.md` §3), karena fitur ini memakai `api/kesiswaan_access.php` dan bundle `-wali1.js`.
4. **Backend:** salin `api/rekap_bulanan.php` bila hilang, lalu tambahkan kembali `require_once` dan `case` di `api/index.php` (§1). Pastikan `grep -n "case 'cetak_rekap_bulanan'" api/*.php` menghasilkan tepat 1 baris.
5. **Frontend:** bila `-wali1.js` yang baru punya hash berbeda, verifikasi manual bahwa anchor R1 masih muncul tepat 1 kali dan `K$`, `r`, `Ss` masih bermakna sama di `dot`. Setelah itu perbarui `SRC_SHA256` di skrip dan jalankan skrip.
6. Jalankan `php -l api/rekap_bulanan.php api/index.php` dan `node --check assets/index-CGWL69P7-wali2.js`.
7. Jalankan subset regresi spesifikasi §12.4: E1, E4, E5, E14, E16, E19, E20.

## 4. Rollback

1. Di `app.html`, kembalikan `./assets/index-CGWL69P7-wali2.js` menjadi `./assets/index-CGWL69P7-wali1.js`.
2. Di `api/index.php`, hapus `case 'cetak_rekap_bulanan'` beserta komentarnya, lalu hapus `require_once __DIR__ . '/rekap_bulanan.php';`.
3. Hapus `api/rekap_bulanan.php` (**setelah** langkah 2) dan, bila perlu, `assets/index-CGWL69P7-wali2.js`.
4. Tidak ada perubahan skema atau data. Key `kop_kota` boleh dibiarkan.

Untuk rollback penuh sampai bundle asli, lakukan rollback ini dulu, lalu rollback patch wali kelas.

## 5. Hasil verifikasi (lingkungan dev lokal, 2026-09-14)

**Lingkungan:**
- Laragon, PHP 8.2 built-in server (`SIMANTAP_DEV_MODE`), MySQL 5.7.33 dengan `STRICT_TRANS_TABLES` dan `ONLY_FULL_GROUP_BY`;
- data sekolah lokal: `active_academic_year` = 2026/2027, kop lengkap;
- Chrome headless untuk UI dan PDF.

**Data uji** (bulan Agustus 2026, kelas `X_AKT_1` dengan nama "X AKT 1" dan `class_homeroom` wali "X-AKT-1"), dibuat lewat API asli:
- presensi 3 hari berisi 1 Sakit, 1 Izin, 1 Alpha (memicu insiden `inc_att_*`);
- insiden: 2 terlambat, 1 izin keluar, 3 pelanggaran (1 ditolak, 1 berisi `<script>`);
- 1 panggilan ortu manual;
- ditambah via SQL: 1 insiden tanpa `studentId`, 1 insiden kelas lain, dan 1 siswa yang dipindah ke `X_AKT_2`.

Semua data uji dibersihkan setelah pengujian, dan poin serta kelas siswa dikembalikan.

**Pemeriksaan statis:**
- `php -l` lolos untuk kedua file PHP;
- `node --check` lolos untuk `-wali2.js`;
- hash bundle asli dan `-wali1.js` tidak berubah;
- `case 'cetak_rekap_bulanan'` hanya ada 1.

**Backend (HTTP + parsing HTML): 76/76 lulus.** Cakupannya:
- E2, E4–E13, E16–E24;
- angka per siswa dan baris Jumlah cocok dengan query referensi §12.3;
- Alpha tidak dihitung ganda, insiden `REJECTED` dikecualikan tetapi tampil "Ditolak";
- label "Pindah ke X AKT 2" dan "Data siswa tidak ditemukan";
- XSS ter-escape (di `details` dan `kop_kota`);
- validasi 400 untuk semua parameter, dan `auto=1` menggeser bulan;
- otorisasi: anonim 401 HTML, GURU/BK/TU 403, wali ke kelas lain 403, KS/Admin semua kelas, perubahan `class_homeroom` langsung berlaku, user terhapus 401 HTML, POST 405;
- regresi `get_incidents`, `get_homeroom_dashboard`, dan action tak dikenal.

**UI (Chrome headless): 20/20 lulus, tanpa JS exception.**
- Tombol tampil di samping "Perbarui Data" dengan ikon dan warna emerald.
- `window.open` memanggil URL yang benar dengan `_blank`.
- Tab dashboard masih berfungsi.
- GURU non-wali dan KS tidak melihat tombol.
- Judul dokumen `Rekap_Bulanan_X_AKT_1_2026-08`, dan panel kontrol tersembunyi di media print.
- `printToPDF` menghasilkan PDF A4 (594,96 × 841,92 pt) dua halaman. Diperiksa visual: kop, tabel A dengan header dua baris, B.1–B.4, dan blok tanda tangan utuh.
- Lebar 400px tanpa scroll horizontal.
- Temuan visual yang sudah diperbaiki: judul "B. Rincian Kejadian" sempat tertinggal sendirian di akhir halaman 1 (`h3` kini `break-after: avoid`).

**Belum diuji:**
- E15 (Firefox) dan cetak manual lewat dialog cetak Edge/Chrome oleh pengguna;
- logo kop: file `uploads/logos/*.png` tidak ada di checkout lokal, sehingga yang teruji hanya path `../uploads/...` dan penyembunyian gambar rusak (`onerror`);
- pengujian di **staging** (spesifikasi §12) tetap wajib sebelum produksi.
