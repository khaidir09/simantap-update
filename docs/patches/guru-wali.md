# Patch lokal: Penugasan Murid Dampingan Guru Wali

- Spesifikasi: `docs/specs/penugasan-guru-wali.md`
- Versi bundle acuan: SIMANTAP **1.0.2** + patch Profil Murid 360° (`assets/index-CGWL69P7-profilmurid2.js`)
- Tanggal patch: 2026-11-12
- Status upstream: **belum** ada fitur resmi. Patch ini sekaligus usulan ke BTIKP (`docs/usulan-btikp/RINGKASAN-GURU-WALI.md`).
- Tahap 1 dari rangkaian Bimbingan Guru Wali (berikutnya: impor instrumen Google Form, lalu jurnal pendampingan).

## 1. Daftar file

| File | Jenis | SHA-256 setelah patch |
|---|---|---|
| `api/guru_wali.php` | baru: tabel (dibuat saat pertama dipakai), fungsi murni, guard, 10 handler action, helper untuk fitur lain | `94fe3c98c808333842e5cf72bd2837cb43142029ec47a7b50b9bc1cebfb5cc60` |
| `api/index.php` | ubah: `require_once guru_wali.php`, 10 `case` sebelum blok Profil Murid, guard di `delete_user`/`save_user`, validasi + nilai efektif `guru_wali_kuota_peringatan` di `save_system_config`/`get_system_config` | `260b6ee21678b2dc1b1e784b6a1e965bcc1e0104643273389e7ec19a7fa78511` |
| `api/endpoints_v2.php` | ubah: `save_config` menolak key kuota; `get_config` mengirim nilai efektif | `83be03ef0f59abfbb0cbadcfcb91ce70413e4fbdeda488909dec6bcc14af80e1` |
| `api/endpoints_v3.php` | ubah: `get_homeroom_dashboard` menambah `guru_wali_uid`/`guru_wali_nama` (hanya bila login); guard peran di `save_user_v2` | `7e151bf9df651da5396083766c97f508a1a96b2920000ade2aaa4c0b433e5a6a` |
| `api/endpoints_tu.php` | ubah: guard di `gtk_delete` | `9369c5feab93ae0ce6e4f55b8e8230fe4dc85379ca1c5ee967bcf5c013058c96` |
| `api/profil_murid.php` | ubah: scope `DAMPINGAN`, hak edit asesmen, penyamaran `!== 'ALL'`, field `guru_wali`, baris "Guru Wali" di cetak | `feaf3ac343f73cf590a7347f4d149cd3418af901bf5ed8ca092d20f8d94286c8` |
| `assets/index-CGWL69P7-profilmurid2.js` | **tidak diubah** (sumber patch) | `abe9baa433a1f197ef3ccb0a72ac61410ef137b71d004d9388dd21c87be79492` |
| `assets/index-CGWL69P7-guruwali1.js` | baru: salinan `-profilmurid2.js` dengan patch G0–G7 | `2fd0fd13c3c1a6be1a7fcc9c8158b0627782dd1dccaf7d66a913b905cada381c` |
| `app.html` | ubah: `<script src>` ke `index-CGWL69P7-guruwali1.js` | `384840afe9065f1056895775e20e1b7dd2e7c2d46f3f8cc18f1de96a2f9a89ac` |
| `docs/patches/apply-guru-wali-bundle.js` | baru: skrip penerap patch bundle | – |
| `docs/patches/uji-guru-wali-api.mjs` | baru: skrip uji API T1–T12 (142 pemeriksaan) | – |
| `docs/patches/uji-guru-wali-unit.php` | baru: uji unit fungsi murni (55 pemeriksaan) | – |
| `docs/patches/uji-guru-wali-seed.sql`, `uji-guru-wali-cleanup.sql` | baru: data uji dan pembersihnya | – |

Hash file `api/*.php` yang diubah hanya berlaku bila file itu tidak berubah karena patch lain.

**Skema:** tabel `guru_wali_penugasan` dibuat saat action `guru_wali_*` pertama dipanggil (`guruWaliPastikanTabel`,
DDL di spesifikasi §4.1). Satu penugasan aktif per murid dijamin unique key `uq_gw_aktif` atas kolom generated
`aktif_student_id` (MySQL ≥ 5.7 / MariaDB ≥ 10.2; jika gagal dibuat, tabel dibuat tanpa kolom itu dan
`error_log` mencatatnya, lalu jaminan hanya dari `GET_LOCK` + `FOR UPDATE`).
Fitur lain (Profil Murid, Dashboard Kelas, `delete_user`, dst.) **tidak** membuat tabel; tanpa tabel mereka berperilaku seperti sebelum patch.
Key `system_config` baru: `guru_wali_kuota_peringatan` (1–100, bawaan 20, ADMIN-only lewat `save_system_config`).

## 2. Perubahan backend

| Action | Metode | Akses | Isi |
|---|---|---|---|
| `guru_wali_ringkasan` | GET | ADMIN, KS | Kuota, total murid aktif, murid tanpa guru wali, penugasan menggantung/yatim, daftar GURU + jumlah dampingan |
| `guru_wali_murid` | GET `class_id?`, `status_penugasan?` (`SEMUA`/`BELUM`/`SUDAH`/`YATIM`), `guru_uid?`, `q?` | ADMIN, KS | Murid aktif + penugasan aktifnya |
| `guru_wali_tugaskan` | POST | ADMIN, KS | Tugaskan/ganti (409 `perlu_konfirmasi` sampai `ganti: true` + alasan) |
| `guru_wali_akhiri` | POST | ADMIN, KS | Akhiri tanpa pengganti |
| `guru_wali_tutup_nonaktif` | POST | ADMIN, KS | Tutup semua penugasan murid nonaktif (`MURID_NONAKTIF`) |
| `guru_wali_batalkan` | POST | ADMIN, KS | Tandai `DIBATALKAN` + pulihkan penugasan yang digantikan bila memungkinkan |
| `guru_wali_riwayat` | GET | ADMIN, KS | Riwayat (50/halaman), `termasuk_batal=1` untuk yang dibatalkan |
| `guru_wali_template` | GET `class_id?` | ADMIN, KS | Header + baris template impor (berisi guru wali aktif) |
| `guru_wali_impor` | POST `pratinjau` | ADMIN, KS | Pratinjau (tanpa menulis) atau simpan; status BARU/GANTI/SAMA/KOSONG/GALAT per baris |
| `guru_wali_dampingan_saya` | GET | GURU (sendiri); ADMIN/KS dengan `guru_uid` | Murid dampingan efektif + % hadir, poin, penanda biodata/asesmen |

Role selalu dibaca ulang dari DB (`loadFreshUser`). Mutasi wajib POST, dibungkus `GET_LOCK('simantap_guru_wali')`
+ transaksi; duplicate key dari balapan diterjemahkan menjadi 409, galat lain 500 tanpa detail (dicatat di `error_log`).

Perubahan perilaku action lama:
- `delete_user`, `gtk_delete`: 409 `{jumlah_dampingan}` bila guru masih punya dampingan efektif; penugasan menggantung miliknya ditutup otomatis sebelum hapus.
- `save_user`, `save_user_v2`: 409 bila peran user GURU yang sudah ada diubah ke peran lain selagi punya dampingan efektif.
- `profil_murid_*`: GURU tanpa kelas perwalian/BK tetapi punya dampingan mendapat mode `DAMPINGAN` (baca + cetak murid dampingan, NIK/KK tersamar, `bisa_edit_asesmen=false`; daftar/template/impor per kelas 403). Wali kelas yang juga guru wali: murid dampingan dari kelas lain hanya bisa dibaca. Respons `profil_murid_get` mendapat `guru_wali: {aktif, riwayat}`.
- `get_homeroom_dashboard`: `per_siswa[].guru_wali_nama`/`guru_wali_uid` hanya bila ada session (action ini tidak mewajibkan login).

Penyesuaian terhadap spesifikasi:
- Filter tambahan `status_penugasan=YATIM` (penugasan aktif dengan akun guru terhapus/bukan GURU) dan `penugasan_yatim` di ringkasan, untuk menindaklanjuti edge case "akun guru dihapus langsung di DB".
- Riwayat guru wali di Profil Murid tampil sebagai **kartu "Guru Wali"** tepat di bawah "1. Identifikasi Profil Murid" (halaman profil tidak memakai tab Identitas).
- Impor membaca file sebagai teks (`__gwBacaFile`: CSV tidak diubah jadi angka, sel Excel sesuai tampilannya). Pembaca bersama `__pmBacaFile` membulatkan NIP 18 digit (mis. `…122002` → `…122016`) — temuan saat E2E. Sel Excel bertipe angka untuk NIP tetap rusak karena Excel hanya menyimpan 15 digit; template SIMANTAP menulis semua sel sebagai teks.

## 3. Patch frontend

Terapkan dari root deploy, **setelah** patch Profil Murid 360°:

```
node docs/patches/apply-guru-wali-bundle.js
```

Skrip ini memverifikasi hash `-profilmurid2.js`, memastikan nama baru belum ada, identifier bundle yang dipakai ada
(ikon lucide `t0`/`E4`/`fE`/`wue`/`wa`/…, SheetJS `C_`/`Jl`, helper `__pm*`), semua kunci `__pmK`/`__gwK` yang dipakai ada,
semua class Tailwind ada di `assets/index-CC1PBzaO.css`, dan setiap anchor muncul tepat 1 kali; lalu menulis
`assets/index-CGWL69P7-guruwali1.js` dan mengarahkan `app.html`.

| Patch | Isi |
|---|---|
| G0 | Komponen `__gwKelola` (halaman Guru Wali: kartu ringkasan, banner menggantung/yatim, tab Per Guru/Murid/Impor Excel/Riwayat), modal Tugaskan (dengan tahap konfirmasi penggantian), Akhiri, Batalkan, `__gwDampingan`, `__gwKartuProfil`; disisipkan sebelum `function dot(` |
| G1+G2 | Sidebar: grup "Bimbingan Guru Wali" sebelum "Umum & Ekskul": "Guru Wali" (ADMIN/KS), "Murid Dampingan" (semua GURU, termasuk BK) |
| G3+G4 | Router `guru-wali`, `murid-dampingan` + daftar halaman dikenal |
| G5 | Profil Murid 360°: baris "Guru wali: … (sejak …)" di kepala + kartu Guru Wali. Tombol edit asesmen sudah mengikuti `akses.bisa_edit_asesmen` |
| G6 | Dashboard Kelas Saya: kolom "Guru Wali" di tab Per Siswa |
| G7 | Konfigurasi Sistem: input "Ambang Murid Dampingan per Guru Wali" di bawah "Hari Sekolah" (tersimpan lewat tombol simpan konfigurasi yang sama) |

Modal Tugaskan/Pindahkan/Akhiri menyimpan daftar murid terpilih saat dibuka, sehingga perubahan saringan di belakang modal tidak mengubah pilihan.

## 4. Pengujian

Prasyarat: `api/config.local.php` dengan `SIMANTAP_DEV_MODE`, `api/install.lock`.

```
mysql --force -uroot simantap < docs/patches/uji-guru-wali-cleanup.sql
mysql -uroot simantap < docs/patches/uji-guru-wali-seed.sql
node docs/patches/uji-guru-wali-api.mjs          # 142 lulus (UJI_MYSQL bawaan: Laragon MySQL 5.7)
php docs/patches/uji-guru-wali-unit.php           # 55 lulus
mysql --force -uroot simantap < docs/patches/uji-guru-wali-cleanup.sql
```

Catatan seed: DB lokal punya unique key `idx_nisn` pada `students.nisn` (tidak ada di `install/schema.sql`), sehingga
NISN ganda disimulasikan lewat NISN yang sama setelah normalisasi nol depan (`98000066` vs `0098000066`).

Regresi Profil Murid 360° (`uji-profil-murid-api.mjs` 92 lulus, `uji-profil-murid-unit.php` 56 lulus) dijalankan ulang setelah patch.

E2E di Chrome (2026-11-12, data seed): ADMIN menugaskan 4 murid dari dua kelas lewat centang; penggantian memunculkan
konfirmasi + alasan (tombol terkunci sampai alasan dipilih); Batalkan di tab Riwayat memulihkan guru wali lama;
impor CSV menghasilkan Galat (NISN ganda) / Baru / Ganti (dilewati tanpa centang) / Kosong / Baru via NIP;
tab Per Guru menampilkan jumlah dan panel dampingan; field ambang tampil di Konfigurasi Sistem (tombol simpan
konfigurasi tidak ditekan pada DB dev karena ikut menetapkan jenjang sekolah — jalur simpan teruji di T7);
GURU melihat menu Murid Dampingan saja, membuka profil murid kelas lain (baca saja, tanpa tombol edit, ada Cetak,
Kembali ke Murid Dampingan); wali kelas melihat kolom Guru Wali di Dashboard Kelas. Tanpa galat console.
Belum dicoba di browser: unduh template (memicu unduhan file; endpoint teruji di T8) dan halaman cetak (teruji di T9).
