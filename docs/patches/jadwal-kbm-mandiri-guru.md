# Patch lokal: Jadwal KBM Mandiri oleh Guru dan Isi Otomatis Jam Jurnal

- Spesifikasi: `docs/specs/jadwal-kbm-mandiri-guru.md`
- Versi bundle acuan: SIMANTAP **1.0.2** + patch lokal hingga Tampilan Jurnal (`docs/patches/apply-tampilan-jurnal-bundle.js`)
- Tanggal patch: 2026-09-27
- Status upstream: **belum** ada perbaikan resmi. Dokumen ini sekaligus menjadi lampiran usulan ke BTIKP (`docs/usulan-btikp/RINGKASAN-JADWAL-KBM.md`).

## 1. Daftar file

| File | Jenis | SHA-256 setelah patch |
|---|---|---|
| `api/jadwal_kbm.php` | baru: helper, validasi, dan handler `jadwal_kbm_get`/`jadwal_kbm_save` | `d31a5b355f3c0951b14457757f5150b90e22e010b51a4a3891795569339dcd06` |
| `api/index.php` | ubah: `require_once` `jadwal_kbm.php`; `case 'jadwal_kbm_get'` dan `case 'jadwal_kbm_save'` | `9250bd4c060e5d1804b9433c894830d052eb8267b664b523a72ad8fde7c321e3` |
| `api/endpoints_tu.php` | ubah: `gtk_update_atribut` (TU/ADMIN, POST, prepared statement); `gtk_create`/`gtk_update` tidak lagi menimpa jadwal saat update | `f4e85a780d3768706351f155cd93d50eab2a56d949908042bf88770e243221c9` |
| `assets/index-CGWL69P7-tampiljurnal2.js` | **tidak diubah** (sumber patch) | `7d5fa4f15a349b9a1d52bf27eea56a11c1fb3af1c51ee973ef0a0658d99dea2c` |
| `assets/index-CGWL69P7-jadwal1.js` | baru: salinan `-tampiljurnal2.js` dengan patch J0–J13 | `1ff756464bd98e51d9cb7acdbc9db3136871792cf2886e6ae7cc05b21cf17f86` |
| `app.html` | ubah: `<script src>` ke `index-CGWL69P7-jadwal1.js` | `2bf26291a8c425509f2a96e6730644eb8980a5833aef0423fad146eca0aad5cb` |
| `docs/patches/apply-jadwal-kbm-bundle.js` | baru: skrip penerap patch bundle | – |
| `docs/patches/uji-jadwal-kbm-unit.php` | baru: unit test helper murni (parser jam lama, irisan, efektif) | – |
| `docs/patches/uji-jadwal-kbm-api.mjs`, `uji-jadwal-kbm-seed.sql`, `uji-jadwal-kbm-cleanup.sql` | baru: uji API dan data ujinya | – |

Hash file PHP hanya berlaku bila file itu tidak berubah karena patch lain. Jika berbeda, bandingkan isinya dengan `docs/usulan-btikp/backend-jadwal-kbm.diff`.

**Skema:** tidak ada tabel, kolom, index, atau migrasi baru. Format JSON `gtk_pegawai.jadwal_mengajar_json` diperluas (spesifikasi §3.1).

## 2. Ringkasan perilaku backend

Detail lengkap ada di spesifikasi §3.3–§3.4.

- Role dibaca ulang dari tabel `users` setiap request (`loadFreshUser`).
- `jadwal_kbm_get` (GET): GURU (milik sendiri; `gtk_id` kosong = dirinya), TATA_USAHA, ADMIN, KEPALA_SEKOLAH. Mengembalikan item tersimpan apa adanya plus `efektif`, `legacy`, `perluDilengkapi`, `alasan`, serta `versi` (sha1 isi kolom). Sesi lama (teks bebas) di-parse saat dibaca; data tersimpan tidak diubah.
- `jadwal_kbm_save` (POST saja): GURU (milik sendiri), TATA_USAHA, ADMIN.
  - `versi` wajib dan harus sama dengan data saat ini, bila tidak → 409 (dicek ulang dengan `SELECT … FOR UPDATE` dalam transaksi).
  - Sesi yang `id`-nya tersimpan dan nilai efektifnya tidak berubah disimpan kembali apa adanya (termasuk `ruang` dan `jamKe` lama) tanpa validasi baru.
  - Sesi baru divalidasi: hari Senin–Jumat, kelas ada di `classes`, jam ke 1–11 (mulai ≤ selesai), jam `HH:MM` (mulai < selesai), mapel opsional harus ada di master. Untuk GURU, kelas dan mapel dibatasi penugasan mapel (tanpa penugasan = semua kelas, mapel harus aktif). `kelas`, `mapel`, dan `jamKe` dibentuk server dari master.
  - Sesi baru yang jam ke-nya beririsan dengan sesi lengkap lain di hari yang sama → 400 bentrok. Irisan dengan GTK lain pada kelas yang sama → `peringatan` (tidak memblokir).
  - Maksimal 100 sesi. Error DB → 500 dengan pesan umum, detail ke `error_log`.
- `gtk_update_atribut`: hanya TATA_USAHA/ADMIN (403 lainnya), hanya POST (405). Field jadwal tetap diterima sebagai jalur legacy bundle lama. Sinkronisasi `users.class_homeroom`/`is_bk` memakai prepared statement.
- `gtk_create`/`gtk_update`: `ON DUPLICATE KEY UPDATE` tidak lagi memuat `jadwal_mengajar_json`, sehingga simpan biodata tidak menimpa jadwal.

## 3. Ringkasan perilaku frontend

| # | Lokasi | Perubahan |
|---|---|---|
| J0 | Modul bundle | Komponen `__jkKelola`, `__jkKartuProfil`, `__jkPanelGtk`, hook `__jkPakaiJurnal`, `__jkChipJurnal`, helper `__jkTeksSesi`/`__jkAmbil`, peta class `__jkK`. |
| J1 | Profil (GURU) | Kartu **"Jadwal Mengajar (KBM)"** di bawah kartu Penugasan Mata Pelajaran: daftar per hari, badge "Perlu dilengkapi", hapus berkonfirmasi inline, form tambah (hari, kelas, mapel opsional, jam ke, jam mulai–selesai), simpan langsung per aksi, pesan sukses/error/peringatan, muat ulang otomatis pada 409. |
| J6/J7 | Tab Kepegawaian (GTK), detail pegawai | Bagian jadwal lama (grid + input teks bebas + ruang) diganti panel baru dengan form dan endpoint yang sama seperti Profil (hanya TU/ADMIN yang bisa mengubah). Grid Senin–Jumat dipertahankan, badge ruang dihapus. |
| J7b | Tab GTK | Master ruangan tidak lagi dimuat. |
| J9 | Widget Jadwal KBM di dashboard | Baris ruang dihapus; sesi format baru tampil sebagai `Jam ke-a–b (HH:MM–HH:MM) · mapel`, sesi lama tetap memakai teks `jamKe`. |
| J10–J13 | Form Jurnal Mengajar (jurnal baru) | Hari dari tanggal + kelas cocok dengan tepat satu sesi → Jam Ke-, Jam Mulai, Jam Selesai (dan mapel, bila guru belum memilih mapel sendiri) terisi otomatis dengan info "Diisi dari jadwal …". Lebih dari satu sesi, atau jam sudah diubah manual → chip "Pilih dari jadwal". Edit jurnal lama: tidak ada isi otomatis. |

Menu sidebar dan payload `save_journal` tidak diubah.

## 4. Cara menerapkan ulang

```
# 1. Backend
git apply docs/usulan-btikp/backend-jadwal-kbm.diff   # atau salin manual
php -l api/jadwal_kbm.php
php -l api/index.php
php -l api/endpoints_tu.php
php docs/patches/uji-jadwal-kbm-unit.php

# 2. Bundle frontend (sumber harus -tampiljurnal2.js dan app.html masih menunjuk ke sana)
node docs/patches/apply-jadwal-kbm-bundle.js

# 3. Uji API (dev/staging saja)
mysql simantap < docs/patches/uji-jadwal-kbm-seed.sql
BASE=http://localhost/simantap/api/index.php node docs/patches/uji-jadwal-kbm-api.mjs
mysql simantap < docs/patches/uji-jadwal-kbm-cleanup.sql
```

Skrip bundle memverifikasi SHA-256 sumber, menolak jika prefiks `__jk` sudah ada di sumber, memvalidasi semua class Tailwind terhadap `index-CC1PBzaO.css`, menulis `assets/index-CGWL69P7-jadwal1.js`, lalu mengarahkan `app.html`. Menjalankannya ulang pada sumber yang sama aman (hasil ditulis ulang, `app.html` sudah menunjuk ke hasil).

### Anchor yang dipakai

| # | Anchor (string eksak, 1 kemunculan) |
|---|---|
| J0 | `function __pnkPantauNilaiKelas(` |
| J1 | `a.jsxs(H$,{title:"Ubah Password"` |
| J6/J7 | Elemen `a.jsxs("div",{className:"space-y-4",…"Jadwal KBM & Tatap Muka Terintegrasi"…` sampai `id:"schedule-form-submit",children:"Tambah"})]})]})]})]})]})` (batas dicari lewat pencocokan kurung, panjang 5889 karakter) |
| J7b | `he=await et.tu.sarpras.ruangan();` |
| J9 | `a.jsx("p",{className:"text-[10px] text-slate-500 font-semibold",children:I.jamKe}),I.ruang&&…` |
| J10/J11 | `const __dspJ=__dspPakaiJurnal(` |
| J13 | `Yt=Ge=>{N(at=>({...at,[Ge.target.name]:Ge.target.value}))}` |
| J13b | `a.jsxs("select",{value:y.mapelId,onChange:Ge=>{const Ke=(Ae.length>0?Ae:Te).find(` |
| J12 | `a.jsx("div",{className:"flex justify-end pt-2",children:a.jsxs("button",{onClick:()=>{!y.jam.trim()` |

## 5. Hasil uji (dev lokal, 2026-09-27)

- `php -l` lolos untuk ketiga file PHP; bundle hasil patch lolos `node --check` sebagai ES module.
- `uji-jadwal-kbm-unit.php`: **39 PASS, 0 FAIL**.
- `uji-jadwal-kbm-api.mjs`: **47 PASS, 0 FAIL** (PHP built-in server + MySQL 5.7 Laragon).
- Browser (Chrome headless lewat DevTools Protocol, akun seed): E1, E2, E3, E4, E5, E6, E8, E9, E12, E18, E19, E20, E23 sesuai spesifikasi, dan tidak ada error/warning console baru saat membuka Profil, Jurnal Baru, Dashboard, dan tab GTK.
- **Belum diuji di browser:** simpan jurnal sampai selesai (E7), E10/E11/E22 lewat UI (sudah tercakup uji API), dan regresi fitur lain (E24). Jalankan sebelum rilis ke produksi.

## 6. Checklist pasca auto-update

1. Cek `app.html` masih mereferensikan `index-CGWL69P7-jadwal1.js`.
2. Cek `api/index.php` masih memuat `require_once __DIR__ . '/jadwal_kbm.php';` dan `case 'jadwal_kbm_get'`/`case 'jadwal_kbm_save'`.
3. Cek `gtk_update_atribut` di `api/endpoints_tu.php` masih dibatasi TU/ADMIN, dan `ON DUPLICATE KEY UPDATE` pada `gtk_create`/`gtk_update` tidak memuat `jadwal_mengajar_json`.
4. Bila ada yang tertimpa: terapkan ulang diff backend, jalankan skrip bundle terhadap bundle baru (anchor mungkin perlu dicari ulang), lalu jalankan `uji-jadwal-kbm-api.mjs` serta E1, E4, E18, dan E23 pada spesifikasi §9.3.

## 7. Rollback

1. Kembalikan `app.html` ke `index-CGWL69P7-tampiljurnal2.js`.
2. `git checkout <commit sebelum patch> -- api/index.php api/endpoints_tu.php`.
3. `api/jadwal_kbm.php` dan `assets/index-CGWL69P7-jadwal1.js` boleh dibiarkan (tidak direferensikan).
4. Tidak ada perubahan skema. Sesi format baru tetap terbaca bundle lama lewat key `kelas`, `hari`, dan `jamKe`.

## 8. Catatan

- Sesi yang tidak berubah disimpan ulang lewat `json_encode` bawaan PHP (sama dengan penulis lama), jadi isinya identik secara nilai; susunan byte kolom bisa berbeda dari tulisan tangan di DB.
- Widget dashboard tetap membaca jadwal lewat `gtk_list` yang tanpa login (tercatat di `CLAUDE.md`, di luar scope).
- Bila TU masih memakai bundle lama, `gtk_update_atribut` menimpa jadwal tanpa cek versi; simpan guru berikutnya mendapat 409 lalu memuat ulang.
