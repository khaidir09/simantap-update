-- Seed data uji Impor Instrumen Guru Wali (docs/specs/impor-instrumen-guru-wali.md §10.1).
-- HANYA untuk dev/staging. Semua data memakai prefiks uji_igw/UJI_IGW/s_igw dan dihapus oleh uji-instrumen-guru-wali-cleanup.sql.
-- Password semua akun: uji12345 (bcrypt). Jalankan cleanup lebih dulu, lalu:
--   mysql -uroot simantap < docs/patches/uji-instrumen-guru-wali-seed.sql
-- Penugasan guru wali, asesmen manual, biodata, dan periode aktif disiapkan oleh skrip uji API (lewat API/SQL),
-- karena tabelnya dibuat saat pertama dipakai.

REPLACE INTO users (uid, email, password, displayName, role, nip, class_homeroom, is_bk) VALUES
('uji_igw_ks',  'uji_igw_ks@uji.test',  '$2y$10$ZWC39OjJKETfq9megV/tdexPnuCbvOi4NVlazfLgOr/UVEkYE89i6', 'Uji IGW Kepala',     'KEPALA_SEKOLAH', NULL, NULL,        0),
('uji_igw_adm', 'uji_igw_adm@uji.test', '$2y$10$ZWC39OjJKETfq9megV/tdexPnuCbvOi4NVlazfLgOr/UVEkYE89i6', 'Uji IGW Admin',      'ADMIN',          NULL, NULL,        0),
('uji_igw_tu',  'uji_igw_tu@uji.test',  '$2y$10$ZWC39OjJKETfq9megV/tdexPnuCbvOi4NVlazfLgOr/UVEkYE89i6', 'Uji IGW TU',         'TATA_USAHA',     NULL, NULL,        0),
('uji_igw_gw1', 'uji_igw_gw1@uji.test', '$2y$10$ZWC39OjJKETfq9megV/tdexPnuCbvOi4NVlazfLgOr/UVEkYE89i6', 'Uji IGW Guru Wali 1', 'GURU',           NULL, NULL,        0),
('uji_igw_gw2', 'uji_igw_gw2@uji.test', '$2y$10$ZWC39OjJKETfq9megV/tdexPnuCbvOi4NVlazfLgOr/UVEkYE89i6', 'Uji IGW Guru Wali 2', 'GURU',           NULL, NULL,        0),
('uji_igw_gw0', 'uji_igw_gw0@uji.test', '$2y$10$ZWC39OjJKETfq9megV/tdexPnuCbvOi4NVlazfLgOr/UVEkYE89i6', 'Uji IGW Tanpa Murid', 'GURU',           NULL, NULL,        0),
('uji_igw_wk',  'uji_igw_wk@uji.test',  '$2y$10$ZWC39OjJKETfq9megV/tdexPnuCbvOi4NVlazfLgOr/UVEkYE89i6', 'Uji IGW Wali Kelas',  'GURU',           NULL, 'UJI_IGW_A', 0);

REPLACE INTO classes (id, name, level) VALUES
('UJI_IGW_A', 'Kelas Uji IGW A', 'UJI_IGW'),
('UJI_IGW_B', 'Kelas Uji IGW B', 'UJI_IGW');

-- s_igw_1..3: NISN tiga respons contoh (data buatan) di docs/asesmen-murid-dampingan.xlsx (nama s_igw_1 sengaja beda ejaan -> peringatan nama)
-- s_igw_4: dampingan gw1 untuk baris sintetis; s_igw_5: dampingan gw2; s_igw_6: dampingan gw1 lalu Lulus (nonaktif)
REPLACE INTO students (id, nisn, name, class_id, class_name, status, total_violation_points) VALUES
('s_igw_1', '0099200001', 'Ahmad Rizki Fiktif',     'UJI_IGW_A', 'Kelas Uji IGW A', 'Aktif', 0),
('s_igw_2', '0099200002', 'Budi Contoh',            'UJI_IGW_A', 'Kelas Uji IGW A', 'Aktif', 0),
('s_igw_3', '0099200003', 'Candra Sampel',          'UJI_IGW_B', 'Kelas Uji IGW B', 'Aktif', 0),
('s_igw_4', '0099100004', 'Uji IGW Murid Empat',    'UJI_IGW_B', 'Kelas Uji IGW B', 'Aktif', 0),
('s_igw_5', '0099100005', 'Uji IGW Murid Lima',     'UJI_IGW_B', 'Kelas Uji IGW B', 'Aktif', 0),
('s_igw_6', '0099100006', 'Uji IGW Murid Enam',     'UJI_IGW_B', 'Kelas Uji IGW B', 'Aktif', 0);
