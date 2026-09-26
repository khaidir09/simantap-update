-- Seed data uji Pantau Nilai Kelas (docs/specs/pantau-nilai-wali-kelas.md §10.1).
-- HANYA untuk dev/staging. Semua data memakai prefiks uji/UJI dan dihapus oleh uji-pantau-nilai-cleanup.sql.
-- Password semua akun: uji12345 (bcrypt). Jalankan: mysql -uroot simantap < docs/patches/uji-pantau-nilai-seed.sql

-- Akun uji (REPLACE: akun uji_* lama dari skrip uji lain ditimpa dengan konfigurasi di bawah)
REPLACE INTO users (uid, email, password, displayName, role, nip, class_homeroom, is_bk) VALUES
('uji_admin', 'uji_admin@uji.test', '$2y$10$ZWC39OjJKETfq9megV/tdexPnuCbvOi4NVlazfLgOr/UVEkYE89i6', 'Uji Admin',        'ADMIN',          NULL,        NULL,  0),
('uji_ks',    'uji_ks@uji.test',    '$2y$10$ZWC39OjJKETfq9megV/tdexPnuCbvOi4NVlazfLgOr/UVEkYE89i6', 'Uji Kepala',       'KEPALA_SEKOLAH', NULL,        NULL,  0),
('uji_tu',    'uji_tu@uji.test',    '$2y$10$ZWC39OjJKETfq9megV/tdexPnuCbvOi4NVlazfLgOr/UVEkYE89i6', 'Uji TU',           'TATA_USAHA',     NULL,        NULL,  0),
('uji_guru',  'uji_guru@uji.test',  '$2y$10$ZWC39OjJKETfq9megV/tdexPnuCbvOi4NVlazfLgOr/UVEkYE89i6', 'Uji Guru Mapel A', 'GURU',           NULL,        NULL,  0),
('uji_guru2', 'uji_guru2@uji.test', '$2y$10$ZWC39OjJKETfq9megV/tdexPnuCbvOi4NVlazfLgOr/UVEkYE89i6', 'Uji Guru Mapel B', 'GURU',           NULL,        NULL,  0),
('uji_wali',  'uji_wali@uji.test',  '$2y$10$ZWC39OjJKETfq9megV/tdexPnuCbvOi4NVlazfLgOr/UVEkYE89i6', 'Uji Wali Kelas',   'GURU',           '1990UJI01', 'UJI', 0),
('uji_bk',    'uji_bk@uji.test',    '$2y$10$ZWC39OjJKETfq9megV/tdexPnuCbvOi4NVlazfLgOr/UVEkYE89i6', 'Uji Guru BK',      'GURU',           NULL,        NULL,  1),
('uji_lain',  'uji_lain@uji.test',  '$2y$10$ZWC39OjJKETfq9megV/tdexPnuCbvOi4NVlazfLgOr/UVEkYE89i6', 'Uji Guru Lain',    'GURU',           NULL,        NULL,  0);

REPLACE INTO classes (id, name, level) VALUES
('UJI',  'Kelas Uji',   'UJI'),
('UJI2', 'Kelas Uji 2', 'UJI2');

REPLACE INTO subjects (id, name, is_active, sort_order, category) VALUES
('UJI_A', 'Mapel Uji A', 1, 901, 'Umum'),
('UJI_B', 'Mapel Uji B', 1, 902, 'Umum'),
('UJI_C', 'Mapel Uji C', 1, 903, 'Umum');

-- Penugasan: UJI_C hanya ditugaskan (tanpa data penilaian)
DELETE FROM teacher_subjects WHERE teacher_id IN ('uji_guru', 'uji_guru2', 'uji_lain');
INSERT INTO teacher_subjects (teacher_id, subject_code, subject_name, class_level, class_ids) VALUES
('uji_guru',  'UJI_A', 'Mapel Uji A', NULL, '["UJI"]'),
('uji_guru2', 'UJI_B', 'Mapel Uji B', NULL, '["UJI"]'),
('uji_lain',  'UJI_C', 'Mapel Uji C', NULL, '["UJI"]');

-- Siswa: 3 aktif + 1 pindah di UJI, 1 aktif di UJI2
REPLACE INTO students (id, nisn, name, class_id, class_name, status) VALUES
('s_uji_1',  'UJI0001', 'Uji Siswa Satu',  'UJI',  'Kelas Uji',   'Aktif'),
('s_uji_2',  'UJI0002', 'Uji Siswa Dua',   'UJI',  'Kelas Uji',   'Aktif'),
('s_uji_3',  'UJI0003', 'Uji Siswa Tiga',  'UJI',  'Kelas Uji',   'Aktif'),
('s_uji_4',  'UJI0004', 'Uji Siswa Empat', 'UJI',  'Kelas Uji',   'Pindah'),
('s_uji2_1', 'UJI0201', 'Uji Siswa Lain',  'UJI2', 'Kelas Uji 2', 'Aktif');
