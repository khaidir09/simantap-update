-- Data uji API presensi harian (uji-presensi-harian-api.mjs). Password akun: uji12345.
INSERT INTO users (uid, email, password, displayName, role) VALUES
  ('u_uji_ph_admin', 'uji_ph_admin@uji.test', '$2y$10$FSYMXEhmKWn2tWK6ovVov.3GrNcHYHGhSIrycWwT5afsELTZNBSA2', 'Admin Uji PH', 'ADMIN');
INSERT INTO students (id, nisn, name, class_id, class_name) VALUES
  ('s_uji_ph_1', '9900000001', 'Siswa Uji PH', 'k_uji_ph', 'X UJI PH');
