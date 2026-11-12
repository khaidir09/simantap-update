# Usulan Fitur SIMANTAP: Penugasan Murid Dampingan Guru Wali

| | |
|---|---|
| Sekolah | SMK Negeri 1 Amuntai (HSU) |
| ID Distribusi | 9E8DC8FBF4 |
| Versi | 1.0.2 |
| Tanggal | 2026-11-12 |
| Lampiran | `backend-guru-wali.diff`, skrip frontend `docs/patches/apply-guru-wali-bundle.js` |
| Spesifikasi | `docs/specs/penugasan-guru-wali.md` |
| Dokumentasi patch lokal | `docs/patches/guru-wali.md` |
| Prasyarat | Usulan Profil Murid 360° (`api/profil_murid.php`, bundle `-profilmurid2.js`) |

## 1. Kebutuhan

Setiap guru ditugaskan sebagai **guru wali** bagi sekelompok murid. Berbeda dari wali kelas, pendampingan melekat
pada murid sejak terdaftar hingga lulus. Murid dampingan satu guru bisa berasal dari beberapa kelas dan angkatan,
dan penugasan harus bertahan lintas tahun ajaran beserta riwayat bila guru walinya diganti. SIMANTAP belum punya
konsep ini. Penugasan ini juga menjadi fondasi tahap berikutnya: impor instrumen digital (Google Form) yang diisi
murid dan jurnal pendampingan guru wali.

## 2. Usulan

- Menu **Guru Wali** (ADMIN/Kepala Sekolah):
  - ringkasan murid tanpa guru wali;
  - tab Per Guru (jumlah dampingan, pindahkan, akhiri);
  - tab Murid (saring kelas/status/guru, centang, tugaskan);
  - tab Impor Excel (NISN + email/NIP guru, pratinjau sebelum simpan);
  - tab Riwayat (termasuk pembatalan).
- **Tepat satu** guru wali aktif per murid, ditautkan ke murid (bukan kelas), sehingga kenaikan kelas tidak mengubahnya.
- **Riwayat**:
  - Penggantian wajib dikonfirmasi dan diberi alasan.
  - Penugasan lama ditutup sehari sebelum tanggal efektif.
  - Salah input dibatalkan, bukan dihapus. Jejak audit tetap ada, dan guru wali sebelumnya dipulihkan.
- **Ambang** murid dampingan per guru diatur Admin (bawaan 20). Ambang ini hanya peringatan, tidak memblokir penugasan.
- **Murid lulus/keluar/pindah** otomatis tidak dihitung. Penugasannya bisa ditutup massal dengan satu tombol.
- **Murid Dampingan** (GURU): daftar murid dampingan lintas kelas. Klik nama membuka Profil Murid 360° dalam mode
  baca saja, dengan NIK/KK tersamar dan cetak tetap tersedia.
- Profil Murid 360° menampilkan guru wali aktif + riwayatnya. Dashboard Kelas Saya menampilkan kolom Guru Wali.
- Akun guru yang masih punya murid dampingan tidak bisa dihapus atau diubah perannya sebelum muridnya dipindahkan.

## 3. Dampak teknis

- Satu tabel baru `guru_wali_penugasan`. Di patch lokal tabel dibuat saat pertama dipakai. Untuk rilis resmi
  sebaiknya dijadikan migrasi di `schema_migrations`. Satu penugasan aktif per murid dijamin unique key atas kolom generated.
- Satu file backend baru (`api/guru_wali.php`, 10 action berprefiks `guru_wali_`) + 10 `case` di `api/index.php`.
- Perubahan perilaku action lama:
  - `delete_user`/`gtk_delete` dan perubahan peran lewat `save_user`/`save_user_v2` membalas 409 untuk guru yang masih punya dampingan.
  - Scope Profil Murid mendapat mode `DAMPINGAN`.
  - `get_homeroom_dashboard` menambah nama guru wali hanya untuk pengguna yang login.
- Satu key `system_config` baru: `guru_wali_kuota_peringatan`.
- Keamanan:
  - role dibaca ulang dari DB;
  - mutasi wajib POST dengan lock + transaksi;
  - aktor diambil dari session;
  - prepared statement;
  - galat DB tidak membocorkan detail;
  - endpoint dashboard yang sudah terbuka tanpa login tidak diberi data baru.

## 4. Pengujian

- 142 uji API otomatis (`docs/patches/uji-guru-wali-api.mjs`) lulus, mencakup:
  - otorisasi per peran;
  - permintaan paralel (tepat satu guru wali aktif);
  - penggantian dan pembatalan dengan pemulihan;
  - aturan tanggal;
  - murid nonaktif;
  - ambang;
  - impor (NISN ganda/berulang, NIP, notasi ilmiah, bukan guru);
  - akses Profil Murid guru wali;
  - penolakan hapus/ubah peran;
  - regresi Pantau Nilai.
- 55 uji unit fungsi murni lulus.
- Regresi Profil Murid 360° lulus.
- Diuji end-to-end di Chrome dengan akun ADMIN, GURU guru wali, dan wali kelas.
