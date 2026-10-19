-- Data uji API Jenjang Sekolah (uji-jenjang-sekolah-api.mjs). HANYA untuk dev/staging.
-- Password semua akun: uji12345. Jalankan uji-jenjang-sekolah-cleanup.sql dulu bila pernah dijalankan.
-- Nilai school_level / kop_sekolah / kop_telp saat ini dicadangkan ke key uji_jj_backup lalu
-- dipulihkan oleh cleanup (uji mengubah ketiganya).
INSERT INTO system_config (config_key, config_value, description)
SELECT 'uji_jj_backup', JSON_OBJECT(
    'school_level', (SELECT config_value FROM system_config WHERE config_key = 'school_level'),
    'kop_sekolah',  (SELECT config_value FROM system_config WHERE config_key = 'kop_sekolah'),
    'kop_telp',     (SELECT config_value FROM system_config WHERE config_key = 'kop_telp')
), 'Cadangan uji jenjang sekolah (hapus lewat uji-jenjang-sekolah-cleanup.sql)';

-- Titik awal deterministik: jenjang belum diatur, nama sekolah SMK → terdeteksi SMK
DELETE FROM system_config WHERE config_key = 'school_level';
INSERT INTO system_config (config_key, config_value) VALUES ('kop_sekolah', 'SMKN 9 UJI JENJANG')
  ON DUPLICATE KEY UPDATE config_value = VALUES(config_value);

INSERT INTO users (uid, email, password, displayName, role) VALUES
  ('u_jj_uji_admin', 'jj_uji_admin@uji.test', '$2y$10$FSYMXEhmKWn2tWK6ovVov.3GrNcHYHGhSIrycWwT5afsELTZNBSA2', 'Admin Uji JJ', 'ADMIN'),
  ('u_jj_uji_tu', 'jj_uji_tu@uji.test', '$2y$10$FSYMXEhmKWn2tWK6ovVov.3GrNcHYHGhSIrycWwT5afsELTZNBSA2', 'TU Uji JJ', 'TATA_USAHA'),
  ('u_jj_uji_guru', 'jj_uji_guru@uji.test', '$2y$10$FSYMXEhmKWn2tWK6ovVov.3GrNcHYHGhSIrycWwT5afsELTZNBSA2', 'Guru Uji JJ', 'GURU');

INSERT INTO gtk_pegawai (id, nama, email, pekerjaan_kategori) VALUES
  ('u_jj_uji_guru', 'Guru Uji JJ', 'jj_uji_guru@uji.test', 'Guru');

-- Kelas, penugasan, dan siswa aktif ber-level X: dijamin "di luar jenjang" saat pindah ke SD/SMP
INSERT INTO classes (id, name, level) VALUES ('UJI_JJ_X_1', 'X UJI JJ 1', 'X');
INSERT INTO teacher_subjects (teacher_id, subject_code, subject_name, class_level, class_ids) VALUES
  ('u_jj_uji_guru', 'jj_uji_mtk', 'Matematika Uji JJ', 'X', '[]');
INSERT INTO students (id, nisn, name, class_id, class_name, status) VALUES
  ('UJI_JJ_SISWA_1', '9990000001', 'Siswa Uji JJ', 'X UJI JJ 1', 'X UJI JJ 1', 'Aktif');
