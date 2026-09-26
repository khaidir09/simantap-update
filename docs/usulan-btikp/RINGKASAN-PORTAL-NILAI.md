# Usulan Perbaikan SIMANTAP: Portal Nilai untuk Siswa dan Orang Tua

| | |
|---|---|
| Sekolah | SMK Negeri 1 Amuntai (HSU) |
| ID Distribusi | 9E8DC8FBF4 |
| Versi | 1.0.2 |
| Tanggal | 2026-09-26 |
| Lampiran | `backend-portal-nilai.diff` |
| Spesifikasi lengkap | `docs/specs/portal-nilai-orang-tua.md` |
| Prasyarat | Usulan **Pantau Nilai Kelas** (`RINGKASAN-PANTAU-NILAI.md`, `backend-pantau-nilai.diff`). Diff ini diterapkan **setelah** diff pantau nilai. |

## 1. Kebutuhan

Siswa dan orang tua tidak punya jalur apa pun untuk memantau nilai. Informasi tugas yang tertinggal
baru sampai ke rumah saat rapor atau panggilan orang tua.

Kebutuhan sekolah: siswa/orang tua membuka **halaman web dari HP**, memasukkan **NISN + PIN** dari
sekolah, lalu melihat nilai, tugas/kuis yang belum ada nilainya, dan presensi **hanya untuk siswa itu**.
Tanpa membuat ratusan akun login baru.

## 2. Kondisi saat ini (1.0.2)

- Semua endpoint nilai memanggil `requireAuth()`; tidak ada halaman publik selain installer.
- Role `SISWA` ada di enum `users.role` tetapi tidak dipakai; tabel `students` tidak punya kredensial.
- Di data sekolah kami, `students.tanggal_lahir`, `nik`, dan `parent_phone` kosong untuk semua siswa,
  sehingga login "NISN + tanggal lahir" tidak bisa dipakai. NISN terisi dan unik (`idx_nisn`), tetapi
  bukan rahasia (tercetak di rapor, bisa dicek di situs NISN), jadi tidak aman sebagai kunci tunggal.
- **Tidak ada pembatasan percobaan login** di mana pun.

## 3. Perbaikan yang diusulkan

### 3.1 Desain akses

| Topik | Keputusan |
|---|---|
| Verifikasi | NISN + **PIN acak 8 karakter** (alfabet tanpa `0/O/1/I/L`, ±8,5 × 10¹¹ kombinasi), format `XXXX-XXXX` |
| Distribusi | Wali kelas/Admin mencetak **kartu akses** A4 (10 kartu/halaman) dan membagikannya |
| Penyimpanan | Hanya **hash** (`password_hash`). PIN tampil sekali di halaman kartu; cetak ulang = reset |
| Pengelola PIN | Wali kelas (kelas perwaliannya) dan Admin (semua kelas) |
| Saklar | `system_config.portal_nilai_aktif`, **default nonaktif** sampai Admin menyalakan |
| Anti tebak | 5 gagal per NISN atau 20 gagal per IP dalam 15 menit → 429 selama 15 menit |
| Pesan gagal | Selalu "NISN atau PIN salah." (tidak membedakan NISN tak dikenal, belum ada PIN, siswa tidak aktif, PIN salah) |
| Sesi | Cookie bertanda tangan 7 hari (bukan sesi PHP); dibatalkan oleh reset PIN atau portal dinonaktifkan |
| Data tampil | Nilai per mapel, tugas/kuis tertinggal, nilai per pertemuan, ringkasan presensi |
| Data **tidak** tampil | Catatan guru, foto, data siswa lain, insiden/pelanggaran |

### 3.2 Backend (lihat `backend-portal-nilai.diff`)

**Halaman publik baru `nilai/index.php`** (server-rendered, tanpa JavaScript, mobile-first):

- gerbang install → maintenance → lisensi, sama dengan `index.php` root;
- form masuk (POST, Post/Redirect/Get), halaman nilai (toggle semester Ganjil/Genap TA aktif), keluar (POST);
- data diambil dengan `pantauNilaiCollect` (usulan pantau nilai), lalu **disaring ke satu siswa**
  sebelum dirender; catatan guru dibuang;
- header: `Cache-Control: no-store`, `X-Frame-Options: DENY`, CSP `default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'`, `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex, nofollow`.

**File baru `api/portal_nilai.php`** dan tiga action staf:

| Action | Metode | Akses | Isi |
|---|---|---|---|
| `portal_nilai_status` | GET `class_id` | Admin semua kelas; GURU wali kelas perwaliannya | Status PIN per siswa (`BELUM`, `AKTIF`, `TERKUNCI`, `NISN_KOSONG`, `NISN_GANDA`), login terakhir, jumlah login, saklar, alamat portal. Tidak pernah mengembalikan hash |
| `cetak_kartu_akses` | POST form `class_id`, `mode` (`baru`/`reset`), `student_ids[]` | sama | Membuat/mereset PIN lalu merender halaman kartu. Dikirim lewat `<form target="_blank">` agar **PIN tidak pernah muncul di URL** atau JSON. Menolak GET (405) dan permintaan lintas asal (cek `Origin`/`Referer`, 403) |
| `portal_nilai_set_aktif` | POST JSON `{aktif: bool}` | Admin | Saklar portal |

Kepala Sekolah, TU, dan guru BK non-wali mendapat 403 (keputusan sekolah: PIN dikelola wali kelas dan Admin).

**Cookie sesi** `simantap_nilai` = `base64url(student_id).exp.HMAC-SHA256(student_id|exp, pin_hash)`,
dengan atribut `HttpOnly`, `SameSite=Lax`, `Secure` (HTTPS), dan `Path=<root>/nilai/`.
Hash PIN siswa dipakai sebagai kunci HMAC. Dengan begitu:
- reset PIN otomatis membatalkan semua perangkat yang sudah masuk, tanpa kolom versi;
- tidak perlu kunci rahasia global yang harus disimpan di folder `api/` (yang dapat diakses publik
  karena bundle tidak memakai `.htaccess`);
- sesi 7 hari tidak bergantung pada `session.gc_maxlifetime` PHP (default 24 menit).

**Tabel baru** (dibuat `CREATE TABLE IF NOT EXISTS` di handler; kami usulkan menjadi migrasi resmi, DDL
di `portalNilaiEnsureTables`):

- `portal_nilai_akses` (`student_id` PK, `pin_hash`, `dibuat_oleh`, `dibuat_oleh_nama`, `dibuat_pada`, `login_terakhir`, `jumlah_login`);
- `portal_nilai_percobaan` (`ip`, `nisn`, `berhasil`, `dibuat_pada`, indeks per IP dan per NISN).
  Kolom `berhasil`: 0 = gagal, 1 = berhasil, 2 = gagal yang kuncinya sudah dibuka (reset/login berhasil).
  Kunci per NISN menghitung 0; batas per IP menghitung 0 dan 2, sehingga login berhasil tidak
  menghapus jejak per IP. Baris lebih dari 7 hari dibersihkan oportunistik.

Tabel lama tidak diubah.

### 3.3 Frontend (patch bundle di sekolah kami)

- Tab "📝 Nilai" wali kelas: tombol **"🔑 Akses Orang Tua"** → panel status PIN (tabel siswa,
  ringkasan, alamat portal), tombol **"Buat PIN & Cetak Kartu"** (siswa yang belum punya) dan
  **"Reset PIN Terpilih & Cetak"** (dengan konfirmasi).
- Admin: menu **"Portal Nilai Ortu"** di grup Manajemen Data berisi saklar Aktif/Nonaktif,
  pemilih kelas, dan panel yang sama.

## 4. Kompatibilitas

- Folder baru `nilai/` di root deploy; action staf bersifat aditif.
- Dua tabel baru dibuat saat runtime; rollback kode meninggalkan tabel kosong yang tidak berbahaya.
- Tanpa ekstensi PHP tambahan (`random_int`, `password_hash`, `hash_hmac`); tidak memakai `sodium`.
- Portal nonaktif secara default, sehingga memasang kode tidak langsung membuka data ke publik.

## 5. Hasil uji di sekolah (dev lokal + HP)

- Uji API otomatis **33/33 lulus**: matriks akses staf, saklar, status PIN, kartu (GET 405, asal lain 403,
  PIN tidak dibuat ulang, siswa kelas lain dilewati), portal nonaktif 503, pesan generik, kunci per NISN,
  reset membuka kunci dan membatalkan PIN/sesi lama, halaman nilai tanpa siswa lain dan tanpa catatan guru,
  toggle semester, header keamanan dan atribut cookie, cookie dirusak, siswa pindah, keluar, dan batas per IP.
- Uji unit **19/19 lulus**: PIN, normalisasi, tanda tangan cookie (reset, kedaluwarsa, dirusak, id diganti),
  URL portal (subfolder, HTTPS, proxy), cek asal permintaan.
- Regresi usulan pantau nilai tetap **44/44** lulus.
- Uji UI di Chrome: saklar Admin, panel wali, halaman kartu, masuk portal (PIN huruf kecil/spasi diterima),
  status login terbarui di panel; tanpa error console. **Tampilan portal dicek langsung di HP: rapi dan data muncul.**
- Uji menemukan dan memperbaiki satu bug desain awal: membuka kunci dengan menghapus catatan gagal
  ikut mengurangi hitungan per IP (§3.2, kolom `berhasil = 2`).

## 6. Permintaan ke pengembang

1. Menyediakan akses nilai untuk siswa/orang tua di sumber resmi, dengan desain seperti §3.1–3.2 atau setara.
   Karena menyangkut data pribadi anak di internet publik, kami mohon ditinjau tim keamanan BTIKP.
2. Menjadikan `portal_nilai_akses` dan `portal_nilai_percobaan` migrasi resmi.
3. Mempertimbangkan pembatasan percobaan login yang sama untuk login staf (`login`), yang saat ini
   tanpa batas dan tanpa `session_regenerate_id`. Setelah ada portal publik, host yang sama lebih
   sering dikunjungi publik.

## 7. Catatan tambahan

- **Reverse proxy.** Batas per IP memakai `REMOTE_ADDR` (tidak memakai `X-Forwarded-For` yang bisa
  dipalsukan). Di belakang reverse proxy, semua pengunjung terlihat ber-IP sama, sehingga batas 20 gagal
  per 15 menit berlaku untuk semua orang tua sekaligus. Perlu konfigurasi IP proxy tepercaya bila BTIKP
  memakai proxy.
- **NAT/CGNAT.** Orang tua di jaringan yang sama (mis. wifi sekolah) berbagi batas per IP. Hanya percobaan
  gagal yang dihitung dan blok berlaku 15 menit.
- **Nilai langsung terlihat.** Nilai jurnal non-draf langsung tampil ke orang tua (keputusan sekolah).
  Guru perlu diberi tahu sebelum portal diaktifkan.
- **Kartu hanya sekali.** Bila halaman kartu tertutup sebelum dicetak, PIN harus direset.
