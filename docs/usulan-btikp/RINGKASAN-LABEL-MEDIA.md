# Usulan Fitur SIMANTAP: Label Media / Alat Pembelajaran di Form Jurnal

| | |
|---|---|
| Sekolah | SMK Negeri 1 Amuntai (HSU) |
| ID Distribusi | 9E8DC8FBF4 |
| Versi | 1.0.2 |
| Tanggal | 2026-09-27 |
| Lampiran | `backend-label-media.diff` |
| Dokumentasi patch lokal | `docs/patches/label-media-jurnal.md` |
| Prasyarat | Tidak ada. Diff dibuat di atas usulan **Presensi Jam Sebelumnya**; bila usulan itu belum diterapkan, hanya konteks baris `require_once` dan `case` di `api/index.php` yang bergeser |

## 1. Kebutuhan

Isian **Media / Alat Pembelajaran** di form jurnal adalah teks bebas yang harus diketik setiap kali. Padahal
media yang dipakai seorang guru cenderung sama dari jurnal ke jurnal (Papan Tulis, LKPD, Proyektor, dan
seterusnya). Mengetik ulang memperlambat pengisian jurnal, terutama dari HP, dan menghasilkan penulisan yang
beragam untuk benda yang sama ("LCD", "proyektor", "Proyektor LCD").

Kebutuhan sekolah:

1. Guru cukup **mengklik** label media yang sesuai, tidak perlu mengetik.
2. Tersedia **label bawaan** berisi media yang paling umum.
3. Guru tetap **boleh mengetik manual** bila label yang ada tidak sesuai.
4. Media yang diketik manual **otomatis tersimpan menjadi label** agar dapat dipakai lagi di jurnal berikutnya.

## 2. Kondisi saat ini (1.0.2)

- Form jurnal hanya menyediakan satu `input` teks dengan placeholder `cth. Proyektor, Buku Cetak`.
- Nilai disimpan apa adanya ke `journal_entries.alat_media`. Tidak ada daftar pilihan dan tidak ada riwayat
  isian guru.

## 3. Perbaikan yang diusulkan

### 3.1 Aturan

| Topik | Keputusan |
|---|---|
| Label bawaan | Papan Tulis, Spidol, Buku Paket, LKPD, Modul Ajar, LCD Proyektor, Laptop, PowerPoint, Video Pembelajaran, Internet, HP (Smartphone), Alat Peraga, Gambar & Poster, Speaker, Lab Komputer, Lingkungan Sekitar |
| Label milik guru | Label yang pernah diketik manual oleh guru itu, ditambah isian media dari 300 jurnal terakhirnya (dipisah koma), diurutkan dari yang paling sering dipakai |
| Cakupan label | Per guru. Label guru lain tidak tampil |
| Label baru | Label yang diketik langsung terpilih dan tersimpan untuk guru itu |
| Membersihkan daftar | Guru bisa menyembunyikan label dari daftarnya, termasuk label bawaan. Label muncul lagi bila diketik ulang. Jurnal lama tidak berubah |
| Perbandingan | Tidak peka huruf besar/kecil; spasi dirapatkan; maksimal 60 karakter per label |
| Format penyimpanan jurnal | **Tidak berubah**: teks dipisah koma di `journal_entries.alat_media`, misalnya `Papan Tulis, LKPD` |

### 3.2 Backend (lihat `backend-label-media.diff`)

**File baru `api/label_media.php`**, tiga action (`case` eksplisit di `api/index.php`):

| Action | Metode | Akses | Hasil |
|---|---|---|---|
| `media_label_list` | GET | semua user login | `{bawaan: [label], milik: [label]}` untuk user yang login |
| `media_label_simpan` | POST `{label}` | semua user login | simpan label, atau tampilkan lagi label yang disembunyikan; `{success, label}` |
| `media_label_hapus` | POST `{label}` | semua user login | sembunyikan label dari daftar user yang login |

- Pemilik label selalu diambil dari sesi, bukan dari body.
- Metode HTTP diperiksa (405 bila simpan/hapus bukan POST).
- Penyusunan daftar ada di fungsi murni `labelMediaSusun`, pemecahan isian lama di `labelMediaPecah`.
- **Tabel baru** `journal_media_labels`:

```sql
CREATE TABLE IF NOT EXISTS journal_media_labels (
    teacher_id VARCHAR(128) NOT NULL,
    label_key VARCHAR(191) NOT NULL,   -- label huruf kecil, untuk mencegah duplikat
    label VARCHAR(100) NOT NULL,
    hidden TINYINT(1) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (teacher_id, label_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

  Di patch lokal tabel dibuat saat action pertama dipanggil. Di sumber resmi sebaiknya menjadi migrasi di
  `schema.sql`/`schema_migrations`.

### 3.3 Frontend (patch bundle di sekolah kami)

Form Jurnal Mengajar, bagian Media / Alat Pembelajaran:

- Input teks diganti deretan label berbentuk tombol bulat. Label terpilih berwarna biru dengan tanda ✓; klik
  lagi untuk membatalkan.
- Kolom **Ketik media lain...** dengan tombol **+ Tambah**. Enter menambahkan label tanpa mengirim form.
  Beberapa media bisa diketik sekaligus (dipisah koma). Isian yang belum ditambahkan ikut masuk saat kolom
  ditinggalkan, agar tidak hilang.
- Tautan **Kelola label**: dalam mode ini klik label menyembunyikannya dari daftar guru.
- Isian jurnal lama yang tidak ada di daftar tetap tampil sebagai label terpilih saat jurnal diedit.
- Bila `media_label_list` gagal dimuat, form tetap menampilkan label bawaan.

### 3.4 Menampilkan media di detail dan cetak jurnal

Di versi 1.0.2 nilai media tidak pernah tampil lagi setelah jurnal disimpan: normalisasi daftar jurnal
membuang field `alatMedia`, dan detail maupun cetak jurnal tidak memuatnya. Usulan:

- Normalisasi daftar jurnal ikut membawa `alatMedia`.
- **Modal Detail Jurnal Mengajar**, bagian *Topik & Aktivitas Pembelajaran*: kartu **Media / Alat Pembelajaran**
  di samping kartu Metode Pembelajaran; kartu Kendala / Masalah selebar penuh.
- **Cetak detail jurnal**: butir *3. Metode Pembelajaran* menjadi **3. Metode / Media Ajar**, misalnya
  `Project Based Learning / LCD Proyektor, Laptop, Modul Ajar`. Bila media kosong, hanya metode yang tampil.
- Label bawaan sengaja tanpa garis miring agar tidak rancu dengan pemisah ` / ` di cetakan.

## 4. Kompatibilitas

- Format `alat_media` tidak berubah, jadi rekap dan ekspor jurnal tidak perlu disesuaikan; detail dan cetak
  hanya menambah tampilan (§3.4).
- Isian lama langsung menjadi label milik guru tanpa migrasi data.
- Satu tabel baru yang hanya berisi daftar label; tidak ada kolom tabel lama yang diubah.

## 5. Hasil uji di sekolah (dev lokal: PHP 8.3 Apache, MySQL 5.7)

- Uji API **14/14 lulus**: tanpa login 401; simpan lewat GET 405; label kosong ditolak; label manual tersimpan
  dan spasinya dirapikan; label tidak tampil untuk guru lain; label beda huruf besar tidak dobel; label milik
  guru dan label bawaan bisa disembunyikan tanpa memengaruhi guru lain; label yang diketik ulang muncul lagi;
  media dari jurnal yang disimpan menjadi label, tanpa menduplikasi label bawaan.
- Uji UI di Chrome (akun guru): klik Papan Tulis dan LKPD → terpilih (✓); ketik "Kit Arduino Uji" + Enter →
  label baru terpilih, form tidak terkirim, dan label tersimpan di `journal_media_labels`.
- Uji detail dan cetak di Chrome: jurnal Project Based Learning dengan media `Proyektor/LCD, Laptop, Modul Ajar`
  → kartu media tampil di modal detail; cetakan berisi `3. Metode / Media Ajar: Project Based Learning /
  Proyektor/LCD, Laptop, Modul Ajar`.

## 6. Permintaan ke pengembang

1. Menyediakan pemilihan label media di sumber resmi dengan aturan §3.1 atau setara, dan menampilkan media di
   detail serta cetak jurnal (§3.4).
2. Mempertimbangkan daftar label bawaan yang bisa diatur ADMIN per sekolah (misalnya lewat `system_config`),
   karena kebutuhan SMK, SMA, dan SLB berbeda.
3. Mempertimbangkan pola yang sama untuk isian lain yang berulang, misalnya **Catatan / Kendala KBM** dan
   **Rencana Tindak Lanjut**.

## 7. Catatan tambahan

- Label bersifat pribadi per guru. Belum ada daftar label bersama tingkat sekolah; label yang sering diketik
  banyak guru tidak otomatis naik menjadi label bawaan.
- Label yang diketik tersimpan meskipun jurnal akhirnya tidak dikirim. Guru dapat menyembunyikannya lewat
  **Kelola label**.
