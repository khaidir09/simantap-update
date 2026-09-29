-- Data uji API Kelola Guru (uji-kelola-guru-api.mjs). HANYA untuk dev/staging.
-- Password semua akun: uji12345. Jalankan uji-kelola-guru-cleanup.sql dulu bila pernah dijalankan.
-- guru1: 2 mapel (3 baris penugasan), 5 sesi jadwal, lastLogin lama 2020-01-01.
-- guru2: tanpa penugasan & tanpa baris gtk_pegawai. guru3: jadwal_mengajar_json rusak.
INSERT INTO users (uid, email, password, displayName, role, lastLogin) VALUES
  ('u_kg_uji_admin', 'kg_uji_admin@uji.test', '$2y$10$FSYMXEhmKWn2tWK6ovVov.3GrNcHYHGhSIrycWwT5afsELTZNBSA2', 'Admin Uji KG', 'ADMIN', '2020-01-01 00:00:00'),
  ('u_kg_uji_ks', 'kg_uji_ks@uji.test', '$2y$10$FSYMXEhmKWn2tWK6ovVov.3GrNcHYHGhSIrycWwT5afsELTZNBSA2', 'KS Uji KG', 'KEPALA_SEKOLAH', '2020-01-01 00:00:00'),
  ('u_kg_uji_tu', 'kg_uji_tu@uji.test', '$2y$10$FSYMXEhmKWn2tWK6ovVov.3GrNcHYHGhSIrycWwT5afsELTZNBSA2', 'TU Uji KG', 'TATA_USAHA', '2020-01-01 00:00:00'),
  ('u_kg_uji_guru1', 'kg_uji_guru1@uji.test', '$2y$10$FSYMXEhmKWn2tWK6ovVov.3GrNcHYHGhSIrycWwT5afsELTZNBSA2', 'Guru Satu Uji KG', 'GURU', '2020-01-01 00:00:00'),
  ('u_kg_uji_guru2', 'kg_uji_guru2@uji.test', '$2y$10$FSYMXEhmKWn2tWK6ovVov.3GrNcHYHGhSIrycWwT5afsELTZNBSA2', 'Guru Dua Uji KG', 'GURU', '2020-01-01 00:00:00'),
  ('u_kg_uji_guru3', 'kg_uji_guru3@uji.test', '$2y$10$FSYMXEhmKWn2tWK6ovVov.3GrNcHYHGhSIrycWwT5afsELTZNBSA2', 'Guru Tiga Uji KG', 'GURU', '2020-01-01 00:00:00');

INSERT INTO teacher_subjects (teacher_id, subject_code, subject_name, class_level, class_ids) VALUES
  ('u_kg_uji_guru1', 'KG_UJI_MTK', 'Matematika Uji', 'X', '[]'),
  ('u_kg_uji_guru1', 'KG_UJI_MTK', 'Matematika Uji', 'XI', '[]'),
  ('u_kg_uji_guru1', 'KG_UJI_FIS', 'Fisika Uji', 'X', '[]');

INSERT INTO gtk_pegawai (id, nama, email, pekerjaan_kategori, jadwal_mengajar_json) VALUES
  ('u_kg_uji_guru1', 'Guru Satu Uji KG', 'kg_uji_guru1@uji.test', 'Guru',
   '[{"id":"a","hari":"Senin"},{"id":"b","hari":"Selasa"},{"id":"c","hari":"Rabu"},{"id":"d","hari":"Kamis"},{"id":"e","hari":"Jumat"}]'),
  ('u_kg_uji_guru3', 'Guru Tiga Uji KG', 'kg_uji_guru3@uji.test', 'Guru', '{rusak');

INSERT INTO teacher_registry (email, fullName, nip, schoolName) VALUES
  ('kg_uji_guru1@uji.test', 'Guru Satu Uji KG', '', 'Sekolah Uji'),
  ('kg_uji_guru2@uji.test', 'Guru Dua Uji KG', '', 'Sekolah Uji');
