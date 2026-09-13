-- ============================================================
-- SIMANTAP - Skema Instalasi (DIGENERATE, jangan edit manual)
-- Sumber: simantap.sql via scripts/make-install-schema.mjs
-- Berisi struktur tabel + seed generik. TANPA data sekolah/PII.
-- ============================================================

CREATE TABLE `academic_years` (
  `id` int(11) NOT NULL,
  `year_label` varchar(20) NOT NULL,
  `semester` enum('GANJIL','GENAP') NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `academic_years` (`id`, `year_label`, `semester`, `is_active`, `start_date`, `end_date`, `created_at`) VALUES
(1, '2025/2026', 'GANJIL', 1, '2025-07-14', '2025-12-20', '2026-05-25 22:16:27'),
(2, '2025/2026', 'GENAP', 0, '2026-01-05', '2026-06-20', '2026-05-25 22:16:27'),
(3, '2024/2025', 'GANJIL', 0, '2024-07-15', '2024-12-21', '2026-05-25 22:16:27'),
(4, '2024/2025', 'GENAP', 0, '2025-01-06', '2025-06-21', '2026-05-25 22:16:27');

CREATE TABLE `app_changelog` (
  `id` int(11) NOT NULL,
  `version` varchar(20) NOT NULL,
  `note` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `arsip_digital` (
  `id` varchar(128) NOT NULL,
  `kategori` enum('Kepegawaian','Siswa','Surat','Sekolah') NOT NULL DEFAULT 'Sekolah',
  `tipe` varchar(100) NOT NULL COMMENT 'Misal: SK Kenaikan Pangkat, Buku Induk, Rapor Siswa',
  `nama_arsip` varchar(255) NOT NULL,
  `tanggal_arsip` date DEFAULT NULL,
  `file_path` varchar(500) DEFAULT NULL COMMENT 'uploads/tu/arsip/{kategori}/...',
  `file_size` varchar(20) DEFAULT NULL,
  `uploaded_by` varchar(128) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `attendance_records` (
  `id` varchar(128) NOT NULL,
  `student_id` varchar(128) NOT NULL,
  `student_name` varchar(255) NOT NULL,
  `class_id` varchar(50) NOT NULL,
  `class_name` varchar(100) NOT NULL,
  `date` date NOT NULL,
  `semester` enum('GANJIL','GENAP') NOT NULL DEFAULT 'GANJIL',
  `academic_year` varchar(20) NOT NULL DEFAULT '2025/2026',
  `status` enum('HADIR','SAKIT','IZIN','ALFA') NOT NULL DEFAULT 'HADIR',
  `recorded_by` varchar(128) NOT NULL DEFAULT '',
  `recorder_name` varchar(255) NOT NULL DEFAULT '',
  `source` enum('MANUAL','JURNAL','QR') NOT NULL DEFAULT 'MANUAL',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `booking_ruangan` (
  `id` int(11) NOT NULL,
  `ruangan_id` varchar(128) NOT NULL,
  `acara` varchar(255) NOT NULL,
  `tanggal` date NOT NULL,
  `jam_mulai` time NOT NULL,
  `jam_selesai` time NOT NULL,
  `pic` varchar(100) NOT NULL COMMENT 'Penanggung jawab acara',
  `status` enum('Pending','Disetujui','Ditolak') NOT NULL DEFAULT 'Pending',
  `created_by` varchar(128) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `classes` (
  `id` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `level` varchar(50) DEFAULT NULL,
  `homeroom_teacher` varchar(128) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `disposisi` (
  `id` int(11) NOT NULL,
  `surat_id` varchar(128) NOT NULL COMMENT 'FK ke surat_masuk.id',
  `from_role` varchar(50) NOT NULL,
  `to_unit` varchar(100) NOT NULL,
  `tanggal` date NOT NULL,
  `catatan` text DEFAULT NULL,
  `created_by` varchar(128) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `extracurriculars` (
  `id` varchar(128) NOT NULL,
  `name` varchar(255) NOT NULL,
  `coachId` varchar(128) NOT NULL,
  `coachName` varchar(255) NOT NULL,
  `schedule` varchar(255) NOT NULL,
  `students` longtext NOT NULL,
  `activities` text DEFAULT NULL,
  `createdAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `description` text DEFAULT NULL,
  `room` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `extracurricular_activities` (
  `id` varchar(128) NOT NULL,
  `extracurricular_id` varchar(128) NOT NULL COMMENT 'FK ke extracurriculars.id',
  `date` date NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `attendance` longtext DEFAULT NULL COMMENT 'JSON: [{student_id, student_name, status: HADIR|ALFA|IZIN}]',
  `reporter_id` varchar(128) NOT NULL,
  `reporter_name` varchar(255) NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `extracurricular_members` (
  `id` int(11) NOT NULL,
  `extracurricular_id` varchar(128) NOT NULL COMMENT 'FK ke extracurriculars.id',
  `student_id` varchar(128) NOT NULL,
  `student_name` varchar(255) NOT NULL,
  `class_id` varchar(50) NOT NULL,
  `class_name` varchar(100) NOT NULL DEFAULT '',
  `joined_at` date DEFAULT NULL,
  `status` enum('AKTIF','TIDAK_AKTIF') NOT NULL DEFAULT 'AKTIF',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `forum_posts` (
  `id` varchar(128) NOT NULL,
  `authorId` varchar(128) NOT NULL,
  `authorName` varchar(255) NOT NULL,
  `authorRole` varchar(50) NOT NULL,
  `authorAvatar` varchar(512) DEFAULT NULL,
  `content` text NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
  `likes` text DEFAULT NULL,
  `repliesCount` int(11) NOT NULL DEFAULT 0,
  `attachments` text DEFAULT NULL,
  `tags` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `forum_replies` (
  `id` varchar(128) NOT NULL,
  `postId` varchar(128) NOT NULL,
  `authorId` varchar(128) NOT NULL,
  `authorName` varchar(255) NOT NULL,
  `authorAvatar` varchar(512) DEFAULT NULL,
  `content` text NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
  `likes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `general_activities` (
  `id` varchar(128) NOT NULL,
  `type` enum('CEREMONY','CLEANING','LITERACY','RELIGIOUS') NOT NULL,
  `date` date NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `participantsCount` int(11) DEFAULT 0,
  `reporterId` varchar(128) NOT NULL,
  `reporterName` varchar(255) NOT NULL,
  `createdAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `photos` text DEFAULT NULL COMMENT 'JSON array URL foto dokumentasi',
  `class_id` varchar(50) DEFAULT NULL COMMENT 'Kelas terkait (opsional)',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `gtk_files` (
  `id` int(11) NOT NULL,
  `gtk_id` varchar(128) NOT NULL,
  `kategori` varchar(50) NOT NULL COMMENT 'ktp, kk, skCpns, ijazah, sertifikat, dll',
  `nama_file` varchar(255) NOT NULL,
  `path_file` varchar(500) NOT NULL COMMENT 'Path relatif dari root: uploads/tu/gtk/{id}/...',
  `ukuran` varchar(20) DEFAULT NULL,
  `tanggal_upload` date DEFAULT NULL,
  `uploaded_by` varchar(128) DEFAULT NULL COMMENT 'FK ke users.uid',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `gtk_pegawai` (
  `id` varchar(128) NOT NULL,
  `nik` varchar(50) DEFAULT NULL,
  `nip` varchar(50) DEFAULT NULL,
  `nuptk` varchar(50) DEFAULT NULL,
  `nama` varchar(255) NOT NULL,
  `tempat_lahir` varchar(100) DEFAULT NULL,
  `tanggal_lahir` date DEFAULT NULL,
  `jenis_kelamin` enum('Laki-laki','Perempuan') DEFAULT 'Laki-laki',
  `agama` varchar(30) DEFAULT 'Islam',
  `pangkat` varchar(100) DEFAULT NULL,
  `golongan` varchar(20) DEFAULT NULL,
  `jabatan` varchar(100) DEFAULT NULL,
  `mapel` varchar(100) DEFAULT NULL,
  `beban_mengajar` varchar(50) DEFAULT NULL,
  `kelas_ajar` varchar(255) DEFAULT NULL,
  `jadwal_mengajar_json` text DEFAULT NULL COMMENT 'JSON: [{id,hari,kelas,jamKe,ruang}]',
  `status_kepegawaian` enum('ASN','PPPK','Honorer') NOT NULL DEFAULT 'ASN',
  `pendidikan_terakhir` varchar(100) DEFAULT NULL,
  `sertifikasi` varchar(100) DEFAULT 'Belum Sertifikasi',
  `email` varchar(255) DEFAULT NULL,
  `nomor_hp` varchar(30) DEFAULT NULL,
  `alamat` text DEFAULT NULL,
  `pekerjaan_kategori` enum('Guru','Kepala Sekolah','Tenaga Kependidikan','Kepala TU','Bendahara','Operator','Pustakawan','Laboran','Teknisi') NOT NULL DEFAULT 'Guru',
  `docs_json` text DEFAULT NULL COMMENT 'JSON: {ktp,kk,skCpns,skPpk,skPangkat,skBerkala,ijazah,sertifikat}',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `class_homeroom` varchar(50) DEFAULT NULL,
  `is_bk` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `gtk_riwayat` (
  `id` int(11) NOT NULL,
  `gtk_id` varchar(128) NOT NULL COMMENT 'FK ke gtk_pegawai.id',
  `kategori` enum('pangkat','jabatan','diklat','pendidikan','mutasi') NOT NULL,
  `tanggal` date NOT NULL,
  `judul` varchar(255) NOT NULL,
  `keterangan` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `incidents` (
  `id` varchar(128) NOT NULL,
  `type` enum('VIOLATION','LATE','OUT','PARENT_CALL') NOT NULL,
  `date` date NOT NULL,
  `studentId` varchar(128) NOT NULL,
  `student_nisn` varchar(20) DEFAULT NULL,
  `studentName` varchar(255) NOT NULL,
  `className` varchar(255) NOT NULL,
  `academic_year` varchar(20) DEFAULT NULL,
  `semester` enum('GANJIL','GENAP') DEFAULT NULL,
  `reporterId` varchar(128) NOT NULL,
  `reporterName` varchar(255) NOT NULL,
  `details` text NOT NULL,
  `violation_code` varchar(50) DEFAULT NULL,
  `points` int(11) DEFAULT 0,
  `status` varchar(50) DEFAULT 'PENDING',
  `actionTaken` text DEFAULT NULL,
  `createdAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `parent_call_triggered` tinyint(1) NOT NULL DEFAULT 0,
  `parent_call_id` varchar(128) DEFAULT NULL,
  `homeroom_teacher_id` varchar(128) DEFAULT NULL COMMENT 'UID wali kelas saat insiden dicatat',
  `homeroom_teacher_name` varchar(255) DEFAULT NULL COMMENT 'Nama wali kelas',
  `resolved_level` enum('WALI_KELAS','GURU_BK','KEPALA_SEKOLAH') DEFAULT NULL COMMENT 'Level di mana insiden diselesaikan',
  `logs` longtext DEFAULT NULL COMMENT 'JSON array riwayat penanganan: [{status, actorName, timestamp, comment}]'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `inventaris` (
  `id` varchar(128) NOT NULL,
  `kode_barang` varchar(50) NOT NULL,
  `nama_barang` varchar(255) NOT NULL,
  `merk` varchar(100) DEFAULT NULL,
  `tahun_pengadaan` varchar(10) DEFAULT NULL,
  `kondisi` enum('Baik','Rusak Ringan','Rusak Sedang','Rusak Berat') NOT NULL DEFAULT 'Baik',
  `lokasi` varchar(100) DEFAULT NULL,
  `kategori` enum('Meja','Kursi','Komputer','Laptop','Printer','Proyektor','Kendaraan','Laboratorium','Lainnya') NOT NULL DEFAULT 'Lainnya',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `journal_entries` (
  `id` varchar(128) NOT NULL,
  `teacherId` varchar(128) NOT NULL,
  `teacherName` varchar(255) NOT NULL,
  `date` date NOT NULL,
  `classId` varchar(128) NOT NULL,
  `className` varchar(255) NOT NULL,
  `subjectId` varchar(128) NOT NULL,
  `subjectName` varchar(255) NOT NULL,
  `period` varchar(50) NOT NULL,
  `topic` varchar(255) NOT NULL,
  `learningObjectives` text NOT NULL,
  `method` varchar(100) NOT NULL,
  `media` text NOT NULL,
  `activities` text NOT NULL,
  `studentAttendance` longtext NOT NULL,
  `notes` text DEFAULT NULL,
  `obstacles` text DEFAULT NULL,
  `followUp` text DEFAULT NULL,
  `photos` text DEFAULT NULL,
  `materials` text DEFAULT NULL,
  `isDraft` tinyint(1) NOT NULL DEFAULT 1,
  `isVerified` tinyint(1) NOT NULL DEFAULT 0,
  `verifiedBy` varchar(128) DEFAULT NULL,
  `verifiedAt` timestamp NULL DEFAULT NULL,
  `createdAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `assessment_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`assessment_data`)),
  `has_assessment` tinyint(1) DEFAULT 0,
  `jam_ke` varchar(20) DEFAULT NULL,
  `jam_mulai` varchar(10) DEFAULT NULL,
  `jam_selesai` varchar(10) DEFAULT NULL,
  `alat_media` text DEFAULT NULL,
  `kategori` varchar(100) DEFAULT 'Rencana Pembelajaran',
  `attendanceRate` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `legalisir` (
  `id` varchar(128) NOT NULL,
  `nik` varchar(20) NOT NULL,
  `nama` varchar(255) NOT NULL,
  `tahun_lulus` varchar(10) DEFAULT NULL,
  `nomor_ijazah` varchar(100) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `nomor_wa` varchar(30) DEFAULT NULL,
  `ijazah_path` varchar(500) DEFAULT NULL COMMENT 'uploads/tu/legalisir/{id}/ijazah.*',
  `ktp_path` varchar(500) DEFAULT NULL,
  `status` enum('Pengajuan','Verifikasi TU','Validasi Arsip','Persetujuan Kepsek','Selesai') NOT NULL DEFAULT 'Pengajuan',
  `qr_code_text` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `legalisir_history` (
  `id` int(11) NOT NULL,
  `legalisir_id` varchar(128) NOT NULL,
  `status` varchar(50) NOT NULL,
  `tanggal` timestamp NOT NULL DEFAULT current_timestamp(),
  `catatan` text DEFAULT NULL,
  `processed_by` varchar(128) DEFAULT NULL COMMENT 'FK ke users.uid',
  `processed_by_name` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `lesson_rpps` (
  `id` varchar(128) NOT NULL,
  `title` varchar(255) NOT NULL,
  `subject` varchar(100) NOT NULL,
  `classLevel` varchar(50) NOT NULL,
  `topic` varchar(255) NOT NULL,
  `objectives` text NOT NULL,
  `activities` text NOT NULL,
  `authorId` varchar(128) DEFAULT NULL,
  `authorName` varchar(255) DEFAULT NULL,
  `isCustom` tinyint(1) DEFAULT 1,
  `createdAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `type` varchar(50) DEFAULT 'RPP',
  `payload` longtext DEFAULT NULL,
  `file_url` varchar(500) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'PENDING',
  `verified_by` varchar(100) DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `verified_by_name` varchar(200) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` varchar(255) NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `type` varchar(50) DEFAULT 'SYSTEM',
  `is_read` tinyint(1) DEFAULT 0,
  `link` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `parent_calls` (
  `id` varchar(128) NOT NULL,
  `student_id` varchar(128) NOT NULL,
  `student_name` varchar(255) NOT NULL,
  `student_nisn` varchar(20) DEFAULT NULL,
  `class_name` varchar(100) DEFAULT NULL,
  `academic_year` varchar(20) DEFAULT NULL,
  `semester` enum('GANJIL','GENAP') DEFAULT NULL,
  `total_points` int(11) NOT NULL DEFAULT 0,
  `trigger_type` enum('AUTO','MANUAL') NOT NULL DEFAULT 'MANUAL',
  `trigger_incident_id` varchar(128) DEFAULT NULL,
  `scheduled_date` date DEFAULT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'PENDING',
  `notes` text DEFAULT NULL,
  `logs` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`logs`)),
  `handled_by` varchar(128) DEFAULT NULL,
  `handler_name` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ruangan` (
  `id` varchar(128) NOT NULL,
  `nama_ruang` varchar(100) NOT NULL,
  `tipe` enum('Ruang Kelas','Laboratorium','Perpustakaan','UKS','Aula','Mushola','Kantor','Lainnya') NOT NULL DEFAULT 'Ruang Kelas',
  `kapasitas` int(11) NOT NULL DEFAULT 30,
  `kondisi` enum('Baik','Perlu Perbaikan') NOT NULL DEFAULT 'Baik',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `siswa_files` (
  `id` int(11) NOT NULL,
  `siswa_id` varchar(128) NOT NULL,
  `kategori` varchar(50) NOT NULL COMMENT 'kk, aktaLahir, kip, foto, dll',
  `nama_file` varchar(255) NOT NULL,
  `path_file` varchar(500) NOT NULL,
  `ukuran` varchar(20) DEFAULT NULL,
  `tanggal_upload` date DEFAULT NULL,
  `uploaded_by` varchar(128) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `siswa_riwayat` (
  `id` int(11) NOT NULL,
  `siswa_id` varchar(128) NOT NULL,
  `kategori` enum('prestasi','pelanggaran','beasiswa') NOT NULL,
  `tanggal` date NOT NULL,
  `judul` varchar(255) NOT NULL,
  `keterangan` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `staff_attendance` (
  `id` varchar(128) NOT NULL,
  `gtk_id` varchar(128) NOT NULL,
  `gtk_nama` varchar(255) NOT NULL,
  `nip` varchar(50) DEFAULT NULL,
  `jabatan` varchar(100) DEFAULT NULL,
  `pekerjaan_kategori` varchar(100) DEFAULT NULL,
  `tanggal` date NOT NULL,
  `jam_masuk` time DEFAULT NULL,
  `jam_keluar` time DEFAULT NULL,
  `status` enum('HADIR','TERLAMBAT','IZIN','SAKIT','ALPHA') NOT NULL DEFAULT 'HADIR',
  `keterangan` text DEFAULT NULL,
  `lokasi` varchar(100) DEFAULT 'Sekolah',
  `recorded_by` varchar(128) DEFAULT NULL,
  `recorded_by_name` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `students` (
  `id` varchar(128) NOT NULL,
  `nisn` varchar(20) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `class_id` varchar(50) NOT NULL,
  `class_name` varchar(100) NOT NULL,
  `enrollment_year` varchar(9) DEFAULT NULL COMMENT 'Tahun ajaran masuk siswa',
  `school_id` varchar(128) NOT NULL DEFAULT 'DEFAULT_SCHOOL',
  `academic_year` varchar(20) NOT NULL DEFAULT '2025/2026',
  `semester` enum('GANJIL','GENAP') NOT NULL DEFAULT 'GANJIL',
  `status` enum('AKTIF','TIDAK_AKTIF','LULUS','KELUAR') NOT NULL DEFAULT 'AKTIF',
  `total_violation_points` int(11) NOT NULL DEFAULT 0,
  `parent_name` varchar(255) DEFAULT NULL,
  `parent_phone` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `nik` varchar(20) DEFAULT NULL,
  `tempat_lahir` varchar(100) DEFAULT NULL,
  `tanggal_lahir` date DEFAULT NULL,
  `jenis_kelamin` varchar(20) DEFAULT 'Laki-laki',
  `agama` varchar(30) DEFAULT 'Islam',
  `alamat` text DEFAULT NULL,
  `docs_json` text DEFAULT NULL,
  `pekerjaan_ortu` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `student_registry` (
  `classId` varchar(128) NOT NULL,
  `students` longtext NOT NULL,
  `updatedAt` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `subjects` (
  `id` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `sort_order` int(11) DEFAULT 0,
  `category` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `subjects` (`id`, `name`, `is_active`, `sort_order`, `category`, `description`) VALUES
('BAS', 'Bahasa Arab', 0, 18, 'Umum', ''),
('BIN', 'Bahasa Indonesia', 1, 3, 'Umum', NULL),
('BING', 'Bahasa Inggris', 1, 4, 'Umum', NULL),
('BIO', 'Biologi', 1, 7, 'MIPA', NULL),
('BJR', 'Bahasa Jawa', 0, 19, 'Umum', ''),
('BKS', 'Bimbingan Konseling (BK)', 0, 20, 'Umum', NULL),
('EKO', 'Ekonomi', 1, 10, 'IPS', NULL),
('FIS', 'Fisika', 1, 5, 'MIPA', NULL),
('GEO', 'Geografi', 1, 11, 'IPS', NULL),
('KIM', 'Kimia', 1, 6, 'MIPA', NULL),
('K_1', 'Keterampilan Menjahit', 1, 21, 'Keterampilan', ''),
('MTK_P', 'Matematika Peminatan', 1, 2, 'MIPA', NULL),
('MTK_W', 'Matematika Wajib', 1, 1, 'Umum', ''),
('PAI', 'PAI & Budi Pekerti', 1, 15, 'Umum', NULL),
('PJOK', 'PJOK', 1, 13, 'Umum', NULL),
('PKN', 'PPKn', 1, 14, 'Umum', NULL),
('SBK', 'Seni Budaya', 1, 16, 'Umum', NULL),
('SEJ_P', 'Sejarah Peminatan', 1, 9, 'IPS', NULL),
('SEJ_W', 'Sejarah Indonesia', 1, 8, 'Umum', NULL),
('SOS', 'Sosiologi', 1, 12, 'IPS', NULL),
('TIK', 'Informatika', 1, 17, 'Umum', NULL);

CREATE TABLE `surat_keluar` (
  `id` varchar(128) NOT NULL,
  `nomor_surat` varchar(100) NOT NULL,
  `jenis` enum('Surat Tugas','Surat Undangan','Surat Keterangan','Surat Keputusan','Surat Rekomendasi','Surat Aktif Sekolah') NOT NULL DEFAULT 'Surat Keterangan',
  `tanggal` date NOT NULL,
  `perihal` varchar(500) NOT NULL,
  `penerima` varchar(255) NOT NULL,
  `penandatangan` varchar(255) DEFAULT NULL,
  `template_data_json` text DEFAULT NULL COMMENT 'JSON data khusus sesuai jenis surat',
  `qr_code_text` varchar(500) DEFAULT NULL,
  `created_by` varchar(128) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `surat_masuk` (
  `id` varchar(128) NOT NULL,
  `nomor_surat` varchar(100) NOT NULL,
  `tanggal_surat` date NOT NULL,
  `pengirim` varchar(255) NOT NULL,
  `perihal` varchar(500) NOT NULL,
  `lampiran` varchar(255) DEFAULT NULL,
  `file_path` varchar(500) DEFAULT NULL COMMENT 'Path scan surat: uploads/tu/surat/masuk/...',
  `is_disposed` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` varchar(128) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `system_config` (
  `config_key` varchar(100) NOT NULL,
  `config_value` text NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `teacher_registry` (
  `email` varchar(255) NOT NULL,
  `fullName` varchar(255) NOT NULL,
  `nip` varchar(50) DEFAULT NULL,
  `schoolName` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `teacher_subjects` (
  `id` int(11) NOT NULL,
  `teacher_id` varchar(128) NOT NULL,
  `subject_code` varchar(100) NOT NULL,
  `subject_name` varchar(255) NOT NULL,
  `class_level` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `class_ids` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ujian_scores` (
  `id` int(11) NOT NULL,
  `student_id` varchar(128) NOT NULL,
  `student_name` varchar(255) NOT NULL,
  `class_id` varchar(128) NOT NULL,
  `subject_id` varchar(128) NOT NULL,
  `academic_year` varchar(20) NOT NULL,
  `semester` varchar(20) NOT NULL,
  `ujian_type` varchar(50) NOT NULL,
  `score` decimal(5,2) DEFAULT NULL,
  `recorded_by` varchar(128) DEFAULT NULL,
  `recorder_name` varchar(255) DEFAULT NULL,
  `recorded_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `users` (
  `uid` varchar(128) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) DEFAULT NULL,
  `displayName` varchar(255) NOT NULL,
  `photoURL` varchar(512) DEFAULT NULL,
  `role` enum('GURU','KEPALA_SEKOLAH','ADMIN','OPERATOR','PENGAWAS','SISWA','TATA_USAHA') NOT NULL DEFAULT 'GURU',
  `nip` varchar(50) DEFAULT NULL,
  `schoolId` varchar(128) NOT NULL DEFAULT 'DEFAULT_SCHOOL',
  `schoolName` varchar(255) NOT NULL DEFAULT 'Sekolah Demo',
  `lastLogin` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `createdAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `subjects` text DEFAULT NULL COMMENT 'JSON array of subject codes',
  `class_homeroom` varchar(50) DEFAULT NULL COMMENT 'Kelas wali misal: XI-IPA-2',
  `is_bk` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = Guru BK/Konselor'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `violation_types` (
  `id` int(11) NOT NULL,
  `violation_code` varchar(50) NOT NULL,
  `violation_name` varchar(255) NOT NULL,
  `points` int(11) NOT NULL DEFAULT 10,
  `category` enum('RINGAN','SEDANG','BERAT') NOT NULL DEFAULT 'RINGAN',
  `description` text DEFAULT NULL,
  `auto_parent_call` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `violation_types` (`id`, `violation_code`, `violation_name`, `points`, `category`, `description`, `auto_parent_call`, `created_at`) VALUES
(1, 'TERLAMBAT', 'Terlambat Masuk Sekolah', 5, 'RINGAN', 'Siswa tiba setelah bel masuk berbunyi', 0, '2026-05-25 22:16:27'),
(2, 'SERAGAM', 'Tidak Memakai Seragam Lengkap', 10, 'RINGAN', 'Seragam tidak sesuai aturan sekolah', 0, '2026-05-25 22:16:27'),
(3, 'HP', 'Penggunaan HP Saat KBM', 10, 'RINGAN', 'Menggunakan HP tanpa izin guru saat belajar', 0, '2026-05-25 22:16:27'),
(4, 'TUGAS', 'Tidak Mengerjakan Tugas', 5, 'RINGAN', 'Siswa tidak mengumpulkan tugas tepat waktu', 0, '2026-05-25 22:16:27'),
(5, 'BOLOS', 'Membolos / Tidak Hadir Tanpa Izin', 10, 'RINGAN', '', 0, '2026-05-25 22:16:27'),
(6, 'MEROKOK', 'Merokok di Lingkungan Sekolah', 30, 'SEDANG', 'Kedapatan merokok di area sekolah', 0, '2026-05-25 22:16:27'),
(7, 'VANDAL', 'Merusak Fasilitas Sekolah', 40, 'SEDANG', 'Dengan sengaja merusak properti sekolah', 0, '2026-05-25 22:16:27'),
(8, 'BULLYING', 'Tindakan Perundungan (Bullying)', 40, 'SEDANG', 'Perundungan fisik maupun verbal terhadap sesama', 0, '2026-05-25 22:16:27'),
(9, 'PERKELAHIAN', 'Terlibat Perkelahian', 50, 'BERAT', 'Terlibat perkelahian di lingkungan sekolah', 0, '2026-05-25 22:16:27'),
(10, 'NARKOBA', 'Terlibat Narkoba/Obat Terlarang', 100, 'BERAT', 'Terbukti menggunakan atau mengedarkan narkoba', 0, '2026-05-25 22:16:27'),
(11, 'ALFA', 'Tidak Hadir Tanpa Keterangan (Jurnal)', 5, 'RINGAN', '', 0, '2026-05-25 22:16:27');

ALTER TABLE `academic_years`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_year_sem` (`year_label`,`semester`);

ALTER TABLE `app_changelog`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `arsip_digital`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_arsip_kategori` (`kategori`),
  ADD KEY `idx_arsip_tgl` (`tanggal_arsip`);

ALTER TABLE `attendance_records`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_daily` (`student_id`,`date`),
  ADD KEY `idx_class_date` (`class_id`,`date`),
  ADD KEY `idx_student` (`student_id`),
  ADD KEY `idx_date` (`date`);

ALTER TABLE `booking_ruangan`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_booking_ruangan` (`ruangan_id`),
  ADD KEY `idx_booking_tanggal` (`tanggal`),
  ADD KEY `idx_booking_status` (`status`);

ALTER TABLE `classes`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `disposisi`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_disposisi_surat` (`surat_id`);

ALTER TABLE `extracurriculars`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `extracurricular_activities`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_extracurricular` (`extracurricular_id`),
  ADD KEY `idx_date` (`date`);

ALTER TABLE `extracurricular_members`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_member` (`extracurricular_id`,`student_id`),
  ADD KEY `idx_extracurricular` (`extracurricular_id`),
  ADD KEY `idx_student` (`student_id`),
  ADD KEY `idx_class` (`class_id`);

ALTER TABLE `forum_posts`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `forum_replies`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_postId` (`postId`);

ALTER TABLE `general_activities`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_type` (`type`);

ALTER TABLE `gtk_files`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_gtk_files_gtk` (`gtk_id`);

ALTER TABLE `gtk_pegawai`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_gtk_nip` (`nip`),
  ADD KEY `idx_gtk_status` (`status_kepegawaian`);

ALTER TABLE `gtk_riwayat`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_riwayat_gtk` (`gtk_id`),
  ADD KEY `idx_riwayat_kat` (`kategori`);

ALTER TABLE `incidents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_studentId` (`studentId`),
  ADD KEY `idx_type` (`type`);

ALTER TABLE `inventaris`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_inventaris_kondisi` (`kondisi`),
  ADD KEY `idx_inventaris_kategori` (`kategori`);

ALTER TABLE `journal_entries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_teacherId` (`teacherId`),
  ADD KEY `idx_date` (`date`);

ALTER TABLE `legalisir`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_nomor_ijazah` (`nomor_ijazah`),
  ADD KEY `idx_legalisir_status` (`status`),
  ADD KEY `idx_legalisir_nik` (`nik`);

ALTER TABLE `legalisir_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_leg_hist_lid` (`legalisir_id`);

ALTER TABLE `lesson_rpps`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `parent_calls`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_student` (`student_id`),
  ADD KEY `idx_status` (`status`);

ALTER TABLE `ruangan`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `siswa_files`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_siswa_files_siswa` (`siswa_id`);

ALTER TABLE `siswa_riwayat`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_siswa_riwayat_siswa` (`siswa_id`),
  ADD KEY `idx_siswa_riwayat_kat` (`kategori`);

ALTER TABLE `staff_attendance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_gtk_tanggal` (`gtk_id`,`tanggal`),
  ADD KEY `idx_sa_tanggal` (`tanggal`),
  ADD KEY `idx_sa_gtk_id` (`gtk_id`),
  ADD KEY `idx_sa_status` (`status`);

ALTER TABLE `students`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_nisn` (`nisn`),
  ADD KEY `idx_class` (`class_id`),
  ADD KEY `idx_academic` (`academic_year`,`semester`),
  ADD KEY `idx_school` (`school_id`);

ALTER TABLE `student_registry`
  ADD PRIMARY KEY (`classId`);

ALTER TABLE `subjects`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `surat_keluar`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_surat_keluar_tgl` (`tanggal`);

ALTER TABLE `surat_masuk`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_surat_masuk_tgl` (`tanggal_surat`);

ALTER TABLE `system_config`
  ADD PRIMARY KEY (`config_key`);

ALTER TABLE `teacher_registry`
  ADD PRIMARY KEY (`email`);

ALTER TABLE `teacher_subjects`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_teacher_subject` (`teacher_id`,`subject_code`,`class_level`),
  ADD KEY `idx_teacher` (`teacher_id`);

ALTER TABLE `ujian_scores`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_ujian` (`student_id`,`class_id`,`subject_id`,`academic_year`,`semester`,`ujian_type`);

ALTER TABLE `users`
  ADD PRIMARY KEY (`uid`),
  ADD UNIQUE KEY `idx_email` (`email`);

ALTER TABLE `violation_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `violation_code` (`violation_code`);

ALTER TABLE `academic_years`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `app_changelog`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `booking_ruangan`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `disposisi`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `extracurricular_members`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `gtk_files`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `gtk_riwayat`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `legalisir_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `siswa_files`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `siswa_riwayat`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `teacher_subjects`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `ujian_scores`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `violation_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- Seed netral system_config untuk instalasi baru.
-- Identitas sekolah (school_name, kop_*) diisi oleh wizard installer, bukan di sini.
INSERT INTO `system_config` (`config_key`, `config_value`, `description`) VALUES
('kkm_default', '75', 'KKM default penilaian'),
('bobot_tugas', '30', 'Bobot nilai tugas (%)'),
('bobot_kuis', '30', 'Bobot nilai kuis (%)'),
('bobot_ujian', '40', 'Bobot nilai ujian (%)'),
('subject_categories', 'Umum,MIPA,IPS,Keterampilan,Muatan Lokal', 'Kategori mata pelajaran'),
('violation_threshold_notify', '25', 'Ambang poin: notifikasi wali kelas'),
('violation_threshold_parentcall', '50', 'Ambang poin: panggilan orang tua'),
('violation_threshold_urgent', '75', 'Ambang poin: penanganan mendesak'),
('violation_threshold_flag', '100', 'Ambang poin: siswa ditandai khusus');
