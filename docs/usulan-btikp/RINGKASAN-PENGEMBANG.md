# Usulan Perubahan SIMANTAP: Menu Kesiswaan untuk Guru Wali Kelas

| | |
|---|---|
| Sekolah | SMK Negeri 1 Amuntai (HSU) |
| ID Distribusi | 9E8DC8FBF4 |
| Versi | 1.0.2 |
| Tanggal | 2026-09-13 |
| Lampiran | `backend-kesiswaan-wali-kelas.diff` |

## 1. Masalah

Menu **Presensi Kelas**, beserta seluruh grup menu **Kesiswaan**, hanya muncul untuk Kepala Sekolah. Guru yang menjadi **wali kelas** tidak bisa mencatat presensi harian kelasnya dan tidak bisa menangani siswa terlambat, izin keluar, pelanggaran, atau panggilan orang tua di kelasnya.

**Penyebab.** Item menu sebenarnya sudah memiliki `roles: ["KEPALA_SEKOLAH", "GURU"]`, tetapi filter sidebar menyembunyikan grup Kesiswaan untuk **semua** GURU tanpa melihat `class_homeroom`:

```js
if (role === "GURU") {
  if (isBk) { /* sembunyikan Pusat Dashboard, Akademik, Kesiswaan */ }
  else if (group.title === "Kesiswaan" || group.title === "Umum & Ekskul") return false;
}
```

## 2. Bug terkait yang ditemukan

1. **ALPHA vs ALFA: presensi dengan status Alpha gagal disimpan.**
   - Halaman Presensi Kelas mengirim status `"ALPHA"`, sedangkan `attendance_records.status` bertipe `enum('HADIR','SAKIT','IZIN','ALFA')` dan `save_attendance_records` hanya mengenali `'ALFA'`.
   - Pada MySQL strict mode (default MySQL 5.7+/8), satu siswa Alpha membuat seluruh penyimpanan gagal: *"Gagal simpan presensi: Data truncated for column 'status'"*.
   - Pada mode non-strict, status tersimpan kosong dan poin ALFA serta panggilan ortu otomatis tidak pernah terpicu.
2. **Tahun ajaran hardcoded.**
   - Halaman Presensi mengirim `academic_year: "2025/2026"` (tanpa semester, sehingga backend memakai default `GANJIL`) dan juga memfilter pembacaan dengan `year=2025/2026`.
   - Akibatnya, data presensi tahun ajaran 2026/2027 tercatat sebagai tahun lalu. `save_journal` sudah benar, karena memakai `system_config.active_academic_year`/`active_semester`.
3. **Endpoint Kesiswaan tanpa autentikasi.** `get_attendance_records`, `get_attendance_summary`, `get_incidents`, `get_parent_calls`, dan `delete_incident` bisa dipanggil tanpa login. Endpoint tulis lainnya hanya memanggil `requireAuth()`, tanpa cek peran atau kelas.

## 3. Perilaku yang diusulkan

**Definisi wali kelas:** `role = 'GURU'`, `is_bk ≠ 1`, dan `users.class_homeroom` terisi.

**Pencocokan kelas** memakai kunci ternormalisasi (lowercase, hapus spasi, `_`, `-`, dan `.`), sama dengan fungsi normalisasi yang sudah ada di frontend dan `get_classes`. Alasannya, di data nyata ketiga nilai ini berbeda:
- `users.class_homeroom` = `X-AKT-1`
- `classes.id` = `X_AKT_1`
- `classes.name` / `incidents.className` = `X AKT 1`

### Matriks akses

Keterangan: ✅ semua kelas · 🏫 hanya kelas perwalian · ⛔ 403 · tanpa login 401.

| Action | ADMIN | KEPALA_SEKOLAH | GURU wali | GURU BK | GURU lain | TATA_USAHA |
|---|---|---|---|---|---|---|
| Menu grup Kesiswaan | – | ✅ | **✅ (tanpa Absensi Upacara)** | tetap tersembunyi | tersembunyi | – |
| `get_attendance_records`, `get_attendance_summary` | ✅ | ✅ | 🏫 | ⛔ | ⛔ | ⛔ |
| `save_attendance_records` | ✅ | ⛔ (baca saja) | 🏫 | ⛔ | ⛔ | ⛔ |
| `get_incidents`, `save_incident`, `update_incident_status` | ✅ | ✅ | 🏫 | ✅ | ⛔ | ⛔ |
| `delete_incident` | ✅ | ✅ | ⛔ | ✅ | ⛔ | ⛔ |
| `get_parent_calls`, `save_parent_call_manual`, `update_parent_call` | ✅ | ✅ | 🏫 | ✅ | ⛔ | ⛔ |

Catatan:
- Status wali kelas **dibaca ulang dari DB** di endpoint-endpoint ini, sehingga perubahan penugasan langsung berlaku di API tanpa login ulang. Sidebar tetap mengikuti session.
- Hak `delete_incident` untuk ADMIN/KS/BK masih menunggu konfirmasi kebijakan sekolah. UI hapus insiden memang belum ada.

## 4. Perubahan backend (lihat file diff)

File `backend-kesiswaan-wali-kelas.diff` dibuat terhadap **bundle 1.0.2 asli** dan sudah diverifikasi bisa diterapkan bersih dengan `git apply` (jika line ending berbeda: `git apply --ignore-whitespace`). Diff ini tidak mengubah skema database.

| File | Perubahan |
|---|---|
| `api/kesiswaan_access.php` (baru) | Helper: `requireKesiswaanScope()` (mode `ALL`/`CLASS`/`DENY` per fitur), `classInScope()`, `studentInScope()`, `parentCallInScope()`, `normalizeClassKey()`, `normalizeAttendanceStatus()` (`ALPHA` → `ALFA`, status tidak valid → 400), `activeAcademicPeriod()` |
| `api/index.php` | `require_once` helper; `get_incidents` (auth + filter kelas wali), `save_incident` (validasi kelas dan siswa, termasuk update record lama), `delete_incident` (auth + peran) |
| `api/endpoints_v2.php` | `get_attendance_records`: auth + scope, parameter `year`/`semester` diabaikan, `ALFA` dikirim sebagai `ALPHA`. `save_attendance_records`: auth + scope, **semua** record divalidasi (kelas dan `students.class_id`) sebelum transaksi, status dinormalisasi, tahun/semester dari `system_config`. `get_attendance_summary`, `get_parent_calls`, `update_parent_call`, `save_parent_call_manual`: auth + scope. |
| `api/endpoints_v3.php` | `update_incident_status`: auth + scope berdasarkan `incidents.className` |

Copy `get_parent_calls`/`update_parent_call`/`save_parent_call_manual` di `endpoints_v3.php` tidak diubah, karena tidak pernah dijalankan (tertimpa copy di `endpoints_v2.php`).

## 5. Perubahan frontend yang diusulkan (level source)

Di sekolah kami perubahan ini sementara dipasang sebagai patch pada file JS hasil build. Mohon diterapkan di source React:

1. **Filter sidebar.** Untuk GURU non-BK, grup "Kesiswaan" hanya disembunyikan jika user **tidak** punya `class_homeroom`:
   ```js
   else if ((group.title === "Kesiswaan" && !user?.class_homeroom) || group.title === "Umum & Ekskul") return false;
   ```
2. **Halaman Presensi Kelas:**
   - Untuk GURU, daftar kelas dari `classes.list()` difilter ke kelas yang `normalize(c.id)` atau `normalize(c.name)` sama dengan `normalize(user.class_homeroom)`, lalu kelas pertama hasil filter dipilih.
   - State awal kelas **jangan** `"X-IPA-1"` dan jangan `class_homeroom` mentah (formatnya bisa berbeda dari `classes.id`). Untuk GURU, mulai dari `""` agar tidak terjadi fetch ke kelas yang salah, yang bisa menimpa daftar siswa (race).
   - Hapus `"2025/2026"` hardcoded di `attendance.getRecords` dan `attendance.saveRecords`, atau ambil dari konfigurasi aktif.
   - Seragamkan status Alpha. Idealnya frontend langsung memakai `ALFA`; backend usulan menerima keduanya.
3. **Halaman Terlambat, Izin Keluar, Pelanggaran, dan Panggilan Ortu** (komponen yang sama, prop `type`): untuk GURU non-BK, dropdown kelas pada form tambah difilter ke kelas perwalian dengan cara yang sama.

## 6. Hasil uji di sekolah

Lingkungan: PHP 8.2, MySQL 5.7.33 strict mode, data sekolah (35 kelas, 968 siswa).

- **Backend: 60/60 skenario lulus.** Mencakup:
  - penolakan untuk anonim, user terhapus, GURU non-wali, dan TU;
  - wali ditolak untuk kelas lain, termasuk payload campuran yang dibatalkan seluruhnya;
  - alur Alpha → insiden ALFA → poin → panggilan ortu otomatis, dan koreksi Alpha → Hadir yang mengembalikan poin;
  - KS hanya baca untuk presensi;
  - pemindahan wali kelas langsung berlaku.
- **UI (Chrome headless): 14/14 lulus, tanpa JS exception.**
  - wali melihat 5 menu Kesiswaan, dan dropdown kelasnya hanya kelas perwaliannya;
  - menu KS, GURU biasa, dan GURU BK tidak berubah.
- **Belum diuji:** regresi presensi dari Jurnal (kodenya tidak diubah).

## 7. Catatan tambahan (di luar usulan ini)

- `save_journal` juga menyimpan `ALPHA` mentah ke kolom enum `ALFA` (baris `if ($status === 'ALFA' || $status === 'ALPHA')` tanpa normalisasi sebelum insert). Pada strict mode, jurnal dengan siswa Alpha kemungkinan gagal disimpan.
- Transisi status insiden tidak dibatasi per peran di backend. Wali secara teknis bisa mengirim status tingkat BK/KS lewat API untuk insiden kelasnya.
- Tombol proses insiden untuk wali membandingkan `class_homeroom` dengan **nama** kelas insiden. Pencocokan ini aman selama id dan nama kelas sama setelah dinormalisasi.
- `save_attendance_records` masih memiliki query lama dengan interpolasi string (`$incId` dari `student_id`). Sebaiknya dijadikan prepared statement.
- Kami juga mencatat beberapa temuan keamanan lain, seperti endpoint data pribadi tanpa login dan validasi upload. Karena berlaku untuk semua sekolah, temuan ini akan kami sampaikan secara terpisah dan tertutup.
