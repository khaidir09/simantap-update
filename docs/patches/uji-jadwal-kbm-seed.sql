-- Data uji API jadwal KBM (uji-jadwal-kbm-api.mjs). HANYA untuk dev/staging.
-- Password semua akun: uji12345. Hapus lagi dengan uji-jadwal-kbm-cleanup.sql.
INSERT INTO users (uid, email, password, displayName, role, class_homeroom, is_bk) VALUES
  ('u_uji_jk_a', 'uji_jk_a@uji.test', '$2y$10$FSYMXEhmKWn2tWK6ovVov.3GrNcHYHGhSIrycWwT5afsELTZNBSA2', 'Guru Uji JK A', 'GURU', NULL, 0),
  ('u_uji_jk_b', 'uji_jk_b@uji.test', '$2y$10$FSYMXEhmKWn2tWK6ovVov.3GrNcHYHGhSIrycWwT5afsELTZNBSA2', 'Guru Uji JK B', 'GURU', NULL, 0),
  ('u_uji_jk_c', 'uji_jk_c@uji.test', '$2y$10$FSYMXEhmKWn2tWK6ovVov.3GrNcHYHGhSIrycWwT5afsELTZNBSA2', 'Guru Uji JK C', 'GURU', NULL, 0),
  ('u_uji_jk_tu', 'uji_jk_tu@uji.test', '$2y$10$FSYMXEhmKWn2tWK6ovVov.3GrNcHYHGhSIrycWwT5afsELTZNBSA2', 'TU Uji JK', 'TATA_USAHA', NULL, 0),
  ('u_uji_jk_admin', 'uji_jk_admin@uji.test', '$2y$10$FSYMXEhmKWn2tWK6ovVov.3GrNcHYHGhSIrycWwT5afsELTZNBSA2', 'Admin Uji JK', 'ADMIN', NULL, 0),
  ('u_uji_jk_ks', 'uji_jk_ks@uji.test', '$2y$10$FSYMXEhmKWn2tWK6ovVov.3GrNcHYHGhSIrycWwT5afsELTZNBSA2', 'KS Uji JK', 'KEPALA_SEKOLAH', NULL, 0);
-- GURU C sengaja tanpa baris gtk_pegawai.
INSERT INTO gtk_pegawai (id, nama, email, pekerjaan_kategori, jadwal_mengajar_json) VALUES
  ('u_uji_jk_a', 'Guru Uji JK A', 'uji_jk_a@uji.test', 'Guru',
   '[{"id":"JM-lama1","hari":"Senin","kelas":"X UJI JK 1","jamKe":"01 - 02 (07.30 - 08.50)","ruang":"R1"},{"id":"JM-lama2","hari":"Selasa","kelas":"X UJI JK 2","jamKe":"pagi","ruang":"R2"}]'),
  ('u_uji_jk_b', 'Guru Uji JK B', 'uji_jk_b@uji.test', 'Guru', '[]');
INSERT INTO classes (id, name, level) VALUES
  ('k_uji_jk_1', 'X UJI JK 1', 'X'),
  ('k_uji_jk_2', 'X UJI JK 2', 'X'),
  ('k_uji_jk_3', 'XI UJI JK 3', 'XI'),
  ('k_uji_jk_4', 'XI UJI JK 4', 'XI');
INSERT INTO subjects (id, name, is_active) VALUES
  ('m_uji_jk_1', 'Mapel Uji JK Satu', 1),
  ('m_uji_jk_2', 'Mapel Uji JK Dua', 1),
  ('m_uji_jk_0', 'Mapel Uji JK Nonaktif', 0);
-- Penugasan GURU A: M1 untuk K1, K2 (level X); M2 untuk K3 (level XI).
INSERT INTO teacher_subjects (teacher_id, subject_code, subject_name, class_level, class_ids) VALUES
  ('u_uji_jk_a', 'm_uji_jk_1', 'Mapel Uji JK Satu', 'X', '["k_uji_jk_1","k_uji_jk_2"]'),
  ('u_uji_jk_a', 'm_uji_jk_2', 'Mapel Uji JK Dua', 'XI', '["k_uji_jk_3"]');
