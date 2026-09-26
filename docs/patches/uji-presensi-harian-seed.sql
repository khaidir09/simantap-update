-- Data uji API presensi harian & dispensasi (uji-presensi-harian-api.mjs, uji-dispensasi-api.mjs).
-- Password semua akun: uji12345.
INSERT INTO users (uid, email, password, displayName, role, class_homeroom, is_bk) VALUES
  ('u_uji_ph_admin', 'uji_ph_admin@uji.test', '$2y$10$FSYMXEhmKWn2tWK6ovVov.3GrNcHYHGhSIrycWwT5afsELTZNBSA2', 'Admin Uji PH', 'ADMIN', NULL, 0),
  ('u_uji_ph_wali', 'uji_ph_wali@uji.test', '$2y$10$FSYMXEhmKWn2tWK6ovVov.3GrNcHYHGhSIrycWwT5afsELTZNBSA2', 'Wali Uji PH', 'GURU', 'k_uji_ph', 0),
  ('u_uji_ph_guru', 'uji_ph_guru@uji.test', '$2y$10$FSYMXEhmKWn2tWK6ovVov.3GrNcHYHGhSIrycWwT5afsELTZNBSA2', 'Guru Uji PH', 'GURU', NULL, 0),
  ('u_uji_ph_bk', 'uji_ph_bk@uji.test', '$2y$10$FSYMXEhmKWn2tWK6ovVov.3GrNcHYHGhSIrycWwT5afsELTZNBSA2', 'BK Uji PH', 'GURU', NULL, 1),
  ('u_uji_ph_tu', 'uji_ph_tu@uji.test', '$2y$10$FSYMXEhmKWn2tWK6ovVov.3GrNcHYHGhSIrycWwT5afsELTZNBSA2', 'TU Uji PH', 'TATA_USAHA', NULL, 0);
INSERT INTO students (id, nisn, name, class_id, class_name) VALUES
  ('s_uji_ph_1', '9900000001', 'Siswa Uji PH', 'k_uji_ph', 'X UJI PH'),
  ('s_uji_ph_2', '9900000002', 'Siswa Uji PH Dua', 'k_uji_ph', 'X UJI PH'),
  ('s_uji_ph_9', '9900000009', 'Siswa Kelas Lain', 'k_uji_lain', 'X UJI LAIN');
INSERT INTO classes (id, name, level) VALUES ('k_uji_ph', 'X UJI PH', 'X');
