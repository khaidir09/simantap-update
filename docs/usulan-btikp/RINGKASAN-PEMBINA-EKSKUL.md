# Usulan Fitur SIMANTAP: Pengelolaan Ekstrakurikuler oleh Admin dan Guru Pembina

| | |
|---|---|
| Sekolah | SMK Negeri 1 Amuntai (HSU) |
| ID Distribusi | 9E8DC8FBF4 |
| Versi | 1.0.2 |
| Tanggal | 2026-09-14 |
| Lampiran | `backend-pembina-ekskul.diff` |
| Terkait | Lanjutan dari usulan "Menu Kesiswaan untuk Guru Wali Kelas" (`RINGKASAN-PENGEMBANG.md`); memakai helper `api/kesiswaan_access.php` dari usulan tersebut |

## 1. Kebutuhan

Data ekstrakurikuler saat ini hanya bisa dikelola lewat akun Kepala Sekolah, dan pembina ditulis sebagai teks bebas. Akibatnya:

1. guru pembina tidak bisa login untuk mencatat anggota, jurnal kegiatan, dan presensi ekskul binaannya;
2. Kepala Sekolah menjadi operator data, padahal perannya memantau;
3. wali kelas tidak bisa melihat keikutsertaan ekskul siswanya dari menu ekskul;
4. endpoint baca ekskul, termasuk nomor HP orang tua anggota, terbuka tanpa login, dan beberapa mutasi tidak memiliki auth.

**Usulan:**
- **Admin** mengelola penuh data ekskul dan menunjuk pembina dari **daftar akun guru**.
- **Guru pembina** mengelola ekskul binaannya dari akunnya sendiri.
- **Wali kelas** bisa melihat (baca saja) ekskul yang diikuti siswanya.
- **Kepala Sekolah** menjadi pemantau.

## 2. Kondisi saat ini (1.0.2)

- **Menu:** item "Ekstrakurikuler" hanya untuk `KEPALA_SEKOLAH`/`GURU`, tetapi GURU non-BK tidak pernah melihat grup "Umum & Ekskul". ADMIN tidak punya menu ekskul.
- **Form ekskul:** pembina diisi lewat input teks `coachName`. `coachId` tidak punya input, dan saat simpan dikirim `coachId || uid pembuat`, sehingga data `coachId` berisi uid KS atau guru BK pembuat.
- **Endpoint:**
  - `get_extracurriculars`, `get_ekskul_journals`, `get_extracurricular_members` (berisi `parent_phone`), dan `get_extracurricular_activities`: **tanpa auth**;
  - `save_extracurricular`, `delete_extracurricular`, dan `save_ekskul_journal`: **tanpa auth**;
  - anggota dan aktivitas: `requireAuth()` saja, tanpa cek pembina.
- **`save_extracurricular` memakai `REPLACE INTO`:** setiap edit mereset `createdAt` dan mengosongkan kolom `students`/`activities`.
- **`save_extracurricular_activity` memakai `REPLACE INTO`** dengan `id` dari body, sehingga bisa menimpa aktivitas ekskul lain.

## 3. Fitur yang diusulkan

### 3.1 Model akses

Tanpa perubahan skema. Relasi pembina memakai `extracurriculars.coachId → users.uid` (1 pembina per ekskul), dan status dibaca ulang dari DB setiap request.

| Level | Siapa | Hak |
|---|---|---|
| `ADMIN` | role ADMIN | Kelola penuh semua ekskul, termasuk menunjuk pembina dan menghapus |
| `MONITOR` | KEPALA_SEKOLAH | Baca semua |
| `COACH` | GURU dengan `coachId = uid` (termasuk BK/wali) | Kelola anggota, jurnal/presensi, jadwal/ruang/deskripsi. Tidak bisa mengubah nama, pembina, atau menghapus ekskul |
| `HOMEROOM` | GURU wali kelas yang siswanya anggota aktif | Baca saja; anggota dan presensi difilter ke siswa perwaliannya |
| `NONE` | lainnya (termasuk TATA_USAHA, BK non-pembina) | 403 / daftar kosong |

### 3.2 Backend

- Helper baru `api/ekskul_access.php`: level akses, filter siswa perwalian, validasi pembina, dan hitungan menu.
- Semua endpoint ekskul wajib login dan menegakkan tabel di atas. Mutasi menolak `GET`.
- `get_extracurriculars` menambah:
  - `access` (flag `canEditInfo`/`canAssignCoach`/`canDelete`/`canManageMembers`/`canManageActivities`);
  - `coachValid`, `coachNameLegacy`, `memberCount`;
  - `coachName` dari `users.displayName` (atau "Belum ada pembina").
- `get_ekskul_journals` menambah `canManage`.
- `save_extracurricular`:
  - `coachId` wajib akun GURU (400);
  - `INSERT`/`UPDATE` menggantikan `REPLACE`;
  - pembina hanya boleh mengubah jadwal, ruang, dan deskripsi.
- `save_extracurricular_member` mengambil nama dan kelas dari tabel `students`. `save_extracurricular_activity` menolak `id` milik ekskul lain.
- `login`/`session` menambah `ekskul_coach_count` dan `ekskul_homeroom_count` agar sidebar mengikuti penugasan terbaru saat halaman dimuat.
- `delete_user`, `gtk_delete`, dan `save_user`/`save_user_v2` (role bukan GURU) mengosongkan pembina.

### 3.3 Frontend

Untuk sumber React:
- Menu "Ekstrakurikuler" ditambah role ADMIN. Untuk GURU (BK maupun non-BK), menu tampil bila `ekskul_coach_count > 0 || ekskul_homeroom_count > 0`. Item Literasi, Keagamaan, dan Kebersihan tidak berubah.
- Halaman ekskul memakai `access` per ekskul, bukan cek role:
  - Tambah Ekskul dan Hapus hanya ADMIN;
  - Edit bila `canEditInfo`;
  - Input Kegiatan dan picker hanya ekskul `canManageActivities`;
  - aksi kartu jurnal bila `canManage`;
  - Tambah/Keluarkan Anggota bila `canManageMembers`;
  - Catat/Edit/Hapus Aktivitas bila `canManageActivities`.
- Form ekskul:
  - input teks pembina menjadi `select` akun GURU (dari `get_users`) dengan opsi "Belum ada pembina";
  - nama dan pembina read-only untuk pembina;
  - fallback `coachId || user.uid` dihapus;
  - pesan error backend ditampilkan.

Di sekolah kami, perubahan ini diterapkan sebagai patch pada bundle hasil build (lihat `docs/patches/pembina-ekstrakurikuler.md`). Pada sumber React cukup perubahan kondisi di atas.

## 4. Kompatibilitas dan catatan migrasi

- Tidak ada perubahan skema. Field respons baru bersifat aditif.
- **KS dan guru BK kehilangan akses tulis ekskul.** Perubahan ini disengaja; sekolah perlu diberi tahu.
- **Data lama:** ekskul yang `coachId`-nya bukan akun GURU tampil "Belum ada pembina" sampai Admin memilih pembina. Ekskul yang `coachId`-nya kebetulan akun GURU (misalnya dibuat guru BK) langsung dianggap sah, jadi perlu ditinjau Admin. Kueri audit tersedia di dokumen patch §4.
- Bagian `ekskul` di `get_homeroom_dashboard` tidak diubah dan masih membaca kolom `coachName`.

## 5. Hasil uji di sekolah (dev lokal)

- **API:** 64/64 skenario lulus. Cakupannya semua level akses, 401/403/405, pencabutan pembina langsung berlaku, filter data wali, validasi pembina/siswa, dan proteksi `id` aktivitas lintas ekskul. Regresi fitur wali kelas juga lulus.
- **UI (Chrome):** tampilan menu dan tombol sesuai untuk Admin, pembina, wali kelas, BK, dan Kepala Sekolah, tanpa error console.
- Rincian ada di `docs/patches/pembina-ekstrakurikuler.md` §8. Staging tetap diperlukan sebelum produksi.

## 6. Permintaan ke pengembang

1. Menerapkan model akses §3.1 di sumber resmi (backend dan React), atau desain setara yang tetap ditegakkan di backend.
2. Menutup endpoint ekskul yang saat ini tanpa auth.
3. Mengganti `REPLACE INTO` pada `save_extracurricular` agar edit tidak mengosongkan data.
4. Bila memungkinkan, mempertimbangkan indeks `extracurriculars.coachId` dan dukungan lebih dari satu pembina di rilis berikutnya.
