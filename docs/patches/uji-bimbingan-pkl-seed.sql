-- Data uji API Jurnal Bimbingan PKL (uji-bimbingan-pkl-api.mjs). HANYA untuk dev/staging.
-- Password semua akun: uji12345. Jalankan uji-bimbingan-pkl-cleanup.sql dulu bila pernah dijalankan.
-- guru_a : penugasan mapel class_ids berisi kelas XII            -> memenuhi syarat pembimbing
-- guru_b : penugasan class_level = XII tanpa class_ids           -> memenuhi syarat
-- guru_c : class_level = XII tetapi class_ids hanya kelas XI     -> TIDAK memenuhi syarat
-- wali   : wali kelas XII, tanpa penugasan mapel XII             -> hanya tab baca
INSERT INTO users (uid, email, password, displayName, role, class_homeroom, is_bk, nip) VALUES
  ('u_uji_pkl_admin', 'uji_pkl_admin@uji.test', '$2y$10$FSYMXEhmKWn2tWK6ovVov.3GrNcHYHGhSIrycWwT5afsELTZNBSA2', 'Admin Uji PKL', 'ADMIN', NULL, 0, NULL),
  ('u_uji_pkl_ks', 'uji_pkl_ks@uji.test', '$2y$10$FSYMXEhmKWn2tWK6ovVov.3GrNcHYHGhSIrycWwT5afsELTZNBSA2', 'Kepsek Uji PKL', 'KEPALA_SEKOLAH', NULL, 0, NULL),
  ('u_uji_pkl_tu', 'uji_pkl_tu@uji.test', '$2y$10$FSYMXEhmKWn2tWK6ovVov.3GrNcHYHGhSIrycWwT5afsELTZNBSA2', 'TU Uji PKL', 'TATA_USAHA', NULL, 0, NULL),
  ('u_uji_pkl_guru_a', 'uji_pkl_guru_a@uji.test', '$2y$10$FSYMXEhmKWn2tWK6ovVov.3GrNcHYHGhSIrycWwT5afsELTZNBSA2', 'Guru A Uji PKL', 'GURU', NULL, 0, '198001012005011001'),
  ('u_uji_pkl_guru_b', 'uji_pkl_guru_b@uji.test', '$2y$10$FSYMXEhmKWn2tWK6ovVov.3GrNcHYHGhSIrycWwT5afsELTZNBSA2', 'Guru B Uji PKL', 'GURU', NULL, 0, NULL),
  ('u_uji_pkl_guru_c', 'uji_pkl_guru_c@uji.test', '$2y$10$FSYMXEhmKWn2tWK6ovVov.3GrNcHYHGhSIrycWwT5afsELTZNBSA2', 'Guru C Uji PKL', 'GURU', NULL, 0, NULL),
  ('u_uji_pkl_wali', 'uji_pkl_wali@uji.test', '$2y$10$FSYMXEhmKWn2tWK6ovVov.3GrNcHYHGhSIrycWwT5afsELTZNBSA2', 'Wali XII Uji PKL', 'GURU', 'k_uji_pkl_xii', 0, NULL);

INSERT INTO teacher_subjects (teacher_id, subject_code, subject_name, class_level, class_ids) VALUES
  ('u_uji_pkl_guru_a', 'PKL_UJI_PRODUKTIF', 'Produktif Uji', 'XII', '["k_uji_pkl_xii"]'),
  ('u_uji_pkl_guru_b', 'PKL_UJI_PRODUKTIF', 'Produktif Uji', 'XII', '[]'),
  ('u_uji_pkl_guru_c', 'PKL_UJI_PRODUKTIF', 'Produktif Uji', 'XII', '["k_uji_pkl_xi"]');

INSERT INTO classes (id, name, level) VALUES
  ('k_uji_pkl_xii', 'XII UJI PKL', 'XII'),
  ('k_uji_pkl_xi', 'XI UJI PKL', 'XI');

INSERT INTO students (id, nisn, name, class_id, class_name, status) VALUES
  ('s_uji_pkl_1', '9900000101', 'Siswa PKL Satu', 'k_uji_pkl_xii', 'XII UJI PKL', 'AKTIF'),
  ('s_uji_pkl_2', '9900000102', 'Siswa PKL Dua', 'k_uji_pkl_xii', 'XII UJI PKL', 'AKTIF'),
  ('s_uji_pkl_3', '9900000103', 'Siswa PKL Tiga', 'k_uji_pkl_xii', 'XII UJI PKL', 'AKTIF'),
  ('s_uji_pkl_4', '9900000104', 'Siswa PKL Empat', 'k_uji_pkl_xii', 'XII UJI PKL', 'AKTIF'),
  ('s_uji_pkl_5', '9900000105', 'Siswa PKL Keluar', 'k_uji_pkl_xii', 'XII UJI PKL', 'KELUAR'),
  ('s_uji_pkl_9', '9900000109', 'Siswa Kelas XI', 'k_uji_pkl_xi', 'XI UJI PKL', 'AKTIF');
