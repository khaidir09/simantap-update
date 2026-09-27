# Patch lokal: Label Media / Alat Pembelajaran di form jurnal

- Tanggal patch: 2026-09-27
- Status upstream: **belum** ada perbaikan resmi. Patch ini sekaligus usulan ke BTIKP.

## 1. Masalah

Isian "Media / Alat Pembelajaran" di form jurnal harus diketik manual setiap kali, padahal isinya
sering sama dari jurnal ke jurnal (Papan Tulis, LKPD, Proyektor, dan seterusnya).

## 2. Aturan baru

Input teks diganti daftar label yang bisa diklik:

- **Label bawaan**: Papan Tulis, Spidol, Buku Paket, LKPD, Modul Ajar, LCD Proyektor, Laptop,
  PowerPoint, Video Pembelajaran, Internet, HP (Smartphone), Alat Peraga, Gambar & Poster, Speaker,
  Lab Komputer, Lingkungan Sekitar (`LABEL_MEDIA_BAWAAN` di `api/label_media.php`; cadangan yang sama
  ada di `BAWAAN` skrip patch bundle).
- **Label milik guru**: label yang pernah diketik manual oleh guru itu, ditambah isian media dari
  300 jurnal terakhirnya (dipisah koma), diurutkan dari yang paling sering dipakai. Label guru lain
  tidak ikut tampil.
- Klik label untuk memilih/membatalkan (✓ = terpilih). Media lain diketik di kolom bawah lalu
  **Tambah** atau Enter (boleh beberapa sekaligus, pisahkan koma); isian juga ditambahkan saat
  kolom ditinggalkan. Label yang diketik langsung terpilih dan tersimpan ke daftar guru.
- **Kelola label**: klik label untuk menyembunyikannya dari daftar guru (termasuk label bawaan).
  Label muncul lagi bila diketik ulang. Jurnal lama tidak berubah.
- Perbandingan label tidak peka huruf besar/kecil; label maksimal 60 karakter.

Nilai yang disimpan ke jurnal tetap teks dipisah koma di `journal_entries.alat_media`
(`"Papan Tulis, LKPD"`), jadi detail, cetak, dan rekap jurnal tidak berubah. Isian jurnal lama yang
tidak ada di daftar tetap tampil sebagai label terpilih saat jurnal diedit.

## 3. API

| Action | Parameter | Akses | Hasil |
|---|---|---|---|
| `media_label_list` | GET | semua user login | `{bawaan: [label], milik: [label]}` untuk user yang login |
| `media_label_simpan` | POST `{label}` | semua user login | simpan label (atau tampilkan lagi label tersembunyi); `{success, label}` |
| `media_label_hapus` | POST `{label}` | semua user login | sembunyikan label dari daftar user yang login |

Pemilik label selalu diambil dari sesi, bukan dari body.

## 4. Daftar file

| File | Jenis | SHA-256 setelah patch |
|---|---|---|
| `api/label_media.php` | baru: `labelMediaSusun`, `labelMediaPecah`, handler 3 action | `fea0a8d47f87d5f2a6d885cd118d0eb45da45840200346cc94bccd4978beedcb` |
| `api/index.php` | ubah: `require_once` setelah `presensi_jam_sebelumnya.php`; `case 'media_label_*'` | `280716150392ba9fc5bcaf9e063a91266ccbc06d51721eeb579a10d29e56cbb0` |
| `docs/patches/apply-label-media-bundle.js` | baru: patch bundle `index-CGWL69P7-presensijam1.js` → `index-CGWL69P7-labelmedia1.js` | – |
| `assets/index-CGWL69P7-labelmedia1.js` | hasil patch bundle (antara) | `eb3b9df3e945f02f481dcac438898514ba1bcc7533cb96920e22ac0dc4ffb125` |
| `docs/patches/apply-media-detail-bundle.js` | baru: patch bundle `index-CGWL69P7-labelmedia1.js` → `index-CGWL69P7-mediadetail1.js` (bagian 7) | – |
| `assets/index-CGWL69P7-mediadetail1.js` | hasil patch bundle; `app.html` diarahkan ke file ini | `6ee0cbcfeaf17c3db63ace38d92cc7b686e5e520f3393f63683c28c7b2f5f780` |
| `docs/patches/uji-label-media-api.mjs` | baru: uji HTTP (memakai seed/cleanup presensi harian) | – |
| `docs/patches/uji-label-media-cleanup.sql` | baru: hapus label milik akun uji | – |

`api/label_media.php` bergantung pada `teacher_subject_access.php` (`tsRequireMethod`).
Hash `api/index.php` hanya berlaku bila file itu tidak berubah karena patch lain.

**Skema:** tabel baru `journal_media_labels (teacher_id, label_key, label, hidden, updated_at)`,
PK `(teacher_id, label_key)`, dibuat otomatis saat action pertama dipanggil.

## 5. Pengujian

```
node docs/patches/apply-label-media-bundle.js
node docs/patches/apply-media-detail-bundle.js
mysql -uroot simantap < docs/patches/uji-presensi-harian-seed.sql
node docs/patches/uji-label-media-api.mjs              # 14 uji lewat HTTP
mysql -uroot simantap < docs/patches/uji-presensi-harian-cleanup.sql
mysql -uroot simantap < docs/patches/uji-label-media-cleanup.sql
```

Hasil 2026-09-27 di Laragon (Apache PHP 8.3, MySQL 5.7.33): 14 lulus. Uji manual di browser: klik
Papan Tulis dan LKPD → terpilih (✓); ketik "Kit Arduino Uji" + Enter → label baru terpilih, form
tidak terkirim, dan baris tersimpan di `journal_media_labels`.

## 6. Catatan

- Auto-updater portal dapat menimpa `api/index.php`, `app.html`, dan bundle; periksa hash di atas setelah update.
- Label bersifat per guru; belum ada daftar label bersama tingkat sekolah.

## 7. Tampilan media di detail dan cetak jurnal

Sebelumnya isian media hanya dipakai saat mengisi jurnal; normalisasi daftar jurnal di bundle bahkan
membuang field `alatMedia`, sehingga nilainya tidak pernah tampil lagi. Patch
`apply-media-detail-bundle.js`:

- Normalisasi daftar jurnal membawa `alatMedia` (dari `alatMedia`/`alat_media` API).
- **Modal Detail Jurnal Mengajar**, bagian *Topik & Aktivitas Pembelajaran*: kartu baru
  **Media / Alat Pembelajaran** di samping kartu Metode Pembelajaran (`-` bila kosong); kartu Kendala /
  Masalah dilebarkan penuh lewat inline style `gridColumn: 1 / -1` (class `sm:col-span-2` tidak ada di CSS bundle).
- **Cetak detail jurnal**: butir *3. Metode Pembelajaran* menjadi **3. Metode / Media Ajar**, isinya
  `Project Based Learning / LCD Proyektor, Laptop, Modul Ajar`; bila media kosong hanya metode yang tampil.
- Label bawaan dibuat tanpa garis miring (`LCD Proyektor`, `HP (Smartphone)`, `Gambar & Poster`,
  `Lab Komputer`) agar tidak rancu dengan pemisah ` / ` di cetakan.

Uji browser 2026-09-27: jurnal dengan metode Project Based Learning dan media `Proyektor/LCD, Laptop, Modul Ajar`
→ modal detail menampilkan kartu media; halaman cetak berisi
`3. Metode / Media Ajar: Project Based Learning / Proyektor/LCD, Laptop, Modul Ajar`.
