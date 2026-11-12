# Patch lokal: Profil Murid 360°

- Spesifikasi: `docs/specs/profil-murid-360.md`
- Versi bundle acuan: SIMANTAP **1.0.2** + patch Hari Sekolah (`assets/index-CGWL69P7-harisekolah1.js`)
- Tanggal patch: 2026-11-12
- Status upstream: **belum** ada fitur resmi. Patch ini sekaligus usulan ke BTIKP.

## 1. Daftar file

| File | Jenis | SHA-256 setelah patch |
|---|---|---|
| `api/profil_murid.php` | baru: tabel (dibuat saat pertama dipakai), scope, parser Dapodik & template asesmen, 8 handler action, renderer cetak | `6173ac64c028880d92965430824af2888b487ff67ce3629ae290f3534d286c9d` |
| `api/index.php` | ubah: `require_once __DIR__ . '/profil_murid.php';` setelah `bimbingan_pkl.php`, dan 8 `case` sebelum blok Portal Nilai | `e0f97c52cc7197c3ea4f34325467bf1283ddc8b998bff593c94a1a763c9d6e20` |
| `api/pantau_nilai.php` | ubah: parameter opsional `$studentsOverride` di `pantauNilaiCollect` (tanpa argumen itu perilaku tidak berubah) | `6aea755464830a276deef6fe5a9541fc9d4d361bdd47d960640043520ca3b0b1` |
| `assets/index-CGWL69P7-harisekolah1.js` | **tidak diubah** (sumber patch) | `7a2edf32de2296dbcefbe2dae41986dc38bf763cf83b90225510a5c0808b4263` |
| `assets/index-CGWL69P7-profilmurid2.js` | baru: salinan `-harisekolah1.js` dengan patch M0–M8 | `abe9baa433a1f197ef3ccb0a72ac61410ef137b71d004d9388dd21c87be79492` |
| `app.html` | ubah: `<script src>` ke `index-CGWL69P7-profilmurid2.js` | `dae1675c57deac6e68bb44292c10efeccac10af4134bd88b4e7d106952120a4c` |
| `docs/patches/apply-profil-murid-bundle.js` | baru: skrip penerap patch bundle | – |
| `docs/patches/uji-profil-murid-api.mjs` | baru: skrip uji API T1–T8 (92 pemeriksaan) | – |
| `docs/patches/uji-profil-murid-unit.php` | baru: uji unit fungsi murni (56 pemeriksaan) | – |
| `docs/patches/uji-profil-murid-dapodik.json` | baru: contoh ekspor Dapodik (header dua tingkat, kolom bank/koordinat, NISN tanpa nol depan, NIK notasi ilmiah) | – |
| `docs/patches/uji-profil-murid-seed.sql`, `uji-profil-murid-cleanup.sql` | baru: data uji dan pembersihnya | – |

Hash `api/index.php` dan `api/pantau_nilai.php` hanya berlaku bila file itu tidak berubah karena patch lain.

**Skema:** tiga tabel baru dibuat saat action `profil_murid_*`/`cetak_profil_murid` pertama dipanggil
(`profilMuridPastikanTabel`): `student_biodata`, `student_asesmen`, `student_import_log` (DDL di spesifikasi §4.1).
Tidak ada key `system_config` baru. Impor Dapodik **menulis** kolom `students` berikut bila kolomnya ada di file:
`nik`, `tempat_lahir`, `tanggal_lahir`, `jenis_kelamin`, `agama`, `alamat`/`address`, `parent_name`, `pekerjaan_ortu`.
Nama, NISN, kelas, status, dan `parent_phone` tidak pernah diubah.

## 2. Perubahan backend

| Action | Metode | Akses | Isi |
|---|---|---|---|
| `profil_murid_daftar` | GET `class_id` atau `q` | wali (kelasnya), BK/KS/ADMIN | Daftar murid + penanda biodata/asesmen, poin, % hadir. `q` (≥ 3 huruf, lintas kelas, termasuk nonaktif) hanya untuk BK/KS/ADMIN |
| `profil_murid_get` | GET `student_id` | sama | Seluruh isi profil (spesifikasi §4.4) |
| `profil_murid_asesmen_simpan` | POST | sama | Upsert asesmen + karir satu murid, periode TA aktif |
| `profil_murid_asesmen_hapus` | POST | sama | Hapus satu periode TA aktif (dicatat di `error_log`) |
| `profil_murid_asesmen_template` | GET `class_id`, `semester` | sama | Header + baris template (berisi data periode itu bila ada) |
| `profil_murid_asesmen_impor` | POST `mode` pratinjau/simpan | sama | Impor template asesmen satu kelas |
| `profil_murid_dapodik_impor` | POST `mode` pratinjau/simpan | TATA_USAHA, ADMIN | Impor ekspor Peserta Didik Dapodik |
| `cetak_profil_murid` | GET `student_id` | sama dengan baca | HTML A4 ber-Kop |

Akses baca/tulis memakai `pantauNilaiScope` (user dibaca ulang dari DB). Wali kelas hanya untuk murid **aktif**
di kelas perwaliannya. NIK/No. KK (murid dan orang tua) disamarkan di backend untuk wali kelas, termasuk di cetak.
Kolom Dapodik `Bank`, `Nomor Rekening Bank`, `Rekening Atas Nama`, `Lintang`, `Bujur` (dan label apa pun yang
memuat "rekening") dibuang sebelum disimpan dan tidak dikirim balik di pratinjau.

Penyesuaian terhadap spesifikasi:
- Kolom `students` yang tidak ada di file Dapodik tidak disentuh (spesifikasi hanya menyebut "sel kosong
  mengosongkan"); ini mencegah NIK terhapus bila file ekspor tidak memuat kolom NIK.
- Grafik (RIASEC, kehadiran per bulan, tren nilai) digambar dengan batang CSS dan SVG, bukan recharts:
  identifier recharts di bundle hasil minify dan rawan berubah (fallback yang diizinkan spesifikasi §5).
- Murid yang dipilih disimpan di `sessionStorage`, tetapi aplikasi selalu kembali ke Dashboard saat halaman
  di-refresh (perilaku router aplikasi secara umum). Setelah refresh, buka ulang profil dari daftar murid.

## 3. Patch frontend

Terapkan dari root deploy, **setelah** patch Hari Sekolah:

```
node docs/patches/apply-profil-murid-bundle.js
```

Skrip ini:
1. memverifikasi hash `-harisekolah1.js` dan berhenti jika berbeda;
2. memastikan nama baru (`__pmK`, `__pmProfil`, `__pmDaftar`, `"bk-profil-murid"`, `"profil-murid"`) belum ada;
3. memastikan identifier bundle yang dipakai ada (ikon lucide, `C_`/`Jl`/`QC` SheetJS);
4. memastikan **semua class Tailwind** (peta `KELAS` di skrip) ada di `assets/index-CC1PBzaO.css`;
5. memastikan setiap anchor muncul **tepat 1 kali**;
6. menulis `assets/index-CGWL69P7-profilmurid2.js` dan mengarahkan `app.html` ke file itu.

| Patch | Isi |
|---|---|
| M0 | Komponen `__pmProfil` (halaman profil), `__pmDaftar` (daftar + impor asesmen), `__pmImpor` (modal impor generik), `__pmImporDapodik`, `__pmFormAsesmen`, `__pmTren` (grafik SVG), disisipkan sebelum `function dot(` |
| M1–M2 | Dashboard Kelas Saya: `per_siswa` menyimpan `student_id`; nama murid di tab "Per Siswa" membuka profil |
| M3 | Dashboard Kelas Saya: tab "👤 Profil Murid" (daftar kelas perwalian, unduh template, impor asesmen) |
| M4 | Sidebar blok BK: "Profil Murid 360°" setelah "Pantau Nilai Kelas" |
| M5 | Sidebar grup "Manajemen Data": "Profil Murid 360°" untuk ADMIN/KEPALA_SEKOLAH |
| M6–M7 | Router `bk-profil-murid` dan `profil-murid`, plus daftar halaman yang dikenal |
| M8 | Data Siswa (TU/ADMIN): tombol "Impor Biodata Dapodik" sebelum "Tambah Siswa" |

File Excel dibaca di browser dengan SheetJS bawaan bundle (`C_` = `XLSX.read`, sheet pertama, `raw: true` agar
NIK/tanggal tidak diformat ulang); pemetaan kolom dan validasi tetap di PHP.

## 4. Rollback

1. Kembalikan `app.html` ke `./assets/index-CGWL69P7-harisekolah1.js` (hapus `index-CGWL69P7-profilmurid2.js` bila perlu).
2. Hapus `require_once __DIR__ . '/profil_murid.php';` dan 8 `case` Profil Murid dari `api/index.php`.
3. Parameter `$studentsOverride` di `pantau_nilai.php` boleh dibiarkan (opsional, tidak mengubah perilaku).
4. Tabel `student_biodata`, `student_asesmen`, `student_import_log` boleh dibiarkan. Perubahan kolom `students`
   oleh impor Dapodik **tidak** dikembalikan oleh rollback.

## 5. Pengujian

Prasyarat dev: `api/config.local.php` dengan `SIMANTAP_DEV_MODE`, `api/install.lock`, TA aktif 2026/2027 GANJIL.

```
mysql -uroot simantap < docs/patches/uji-profil-murid-seed.sql
UJI_MYSQL="E:/laragon/bin/mysql/mysql-5.7.33-winx64/bin/mysql.exe -uroot simantap" node docs/patches/uji-profil-murid-api.mjs
mysql -uroot simantap < docs/patches/uji-profil-murid-cleanup.sql
php docs/patches/uji-profil-murid-unit.php
php -l api/profil_murid.php && php -l api/pantau_nilai.php && php -l api/index.php
node --check docs/patches/apply-profil-murid-bundle.js && node --check assets/index-CGWL69P7-profilmurid2.js
```

Hasil 2026-11-12: API 92/92, unit 56/56, regresi Pantau Nilai (API 44/44, parser 14/14), lint lulus.
Akun uji (`uji_pm_*@uji.test`, password `uji12345`) dibuat oleh seed dan dihapus oleh cleanup.

Verifikasi browser (2026-11-12, Chrome, 1536 px): wali kelas → tab Profil Murid → profil (NIK tersamar, 7 bagian,
grafik tren 2 periode, catatan guru ber-HTML tampil sebagai teks) → Edit asesmen → simpan → Kembali → impor
asesmen CSV (pratinjau 1 baru/1 galat → simpan); Guru BK → menu Profil Murid 360° → ganti kelas, cari lintas
kelas; TU → menu Profil Murid tidak tampil, tombol Impor Biodata Dapodik tampil, pratinjau Dapodik melaporkan
kolom dibuang; cetak A4 wali (NIK tersamar). Konsol tanpa error. Belum diuji: tampilan lebar ponsel (375 px).

## 6. Temuan

- `student_list` dan `get_student_registry` masih bisa dibaca tanpa login dan mengembalikan kolom `students`
  (termasuk `nik`). Impor Dapodik mengisi kolom itu untuk lebih banyak murid, sehingga kebocoran membesar.
  Perbaiki autentikasi kedua action itu sebelum impor Dapodik pertama di produksi.
- Auto-updater portal dapat menimpa `app.html`, `api/index.php`, dan `api/pantau_nilai.php`; jalankan ulang
  skrip patch dan periksa `require_once`/`case` setelah update.

## 7. Catatan deploy (Cloudflare)

Produksi (`sim.smkn1amuntai.sch.id`) berada di belakang Cloudflare, yang menyimpan file `assets/*.js` selama
7 hari (`cache-control: public, max-age=604800`). Pada deploy pertama (2026-11-12), file
`index-CGWL69P7-profilmurid1.js` sempat diminta saat upload masih berjalan. Cloudflare menyimpan versi **0 byte**
(`cf-cache-status: HIT`, `Last-Modified: 12:33:07 GMT`), sehingga aplikasi tampil putih tanpa pesan error.
Purge URL tidak menghilangkannya, jadi bundle diganti nama menjadi `index-CGWL69P7-profilmurid2.js` (isi identik).

Urutan deploy bundle berikutnya:
1. Upload file bundle JS baru **sampai selesai** (mode binary), dengan nama file yang belum pernah dipakai.
2. Periksa `https://<domain>/assets/<nama-bundle>.js` di browser: ukurannya harus sama dengan file lokal.
3. Baru upload `app.html`.
