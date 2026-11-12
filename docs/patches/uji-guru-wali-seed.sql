-- Seed data uji Penugasan Guru Wali (docs/specs/penugasan-guru-wali.md §10.1).
-- HANYA untuk dev/staging. Semua data memakai prefiks uji_gw/UJI_GW/s_gw dan dihapus oleh uji-guru-wali-cleanup.sql.
-- Password semua akun: uji12345 (bcrypt). Jalankan: mysql -uroot simantap < docs/patches/uji-guru-wali-seed.sql
-- Jalankan cleanup lebih dulu bila seed pernah dipakai (skrip uji menghapus/mengubah sebagian data).

REPLACE INTO users (uid, email, password, displayName, role, nip, class_homeroom, is_bk) VALUES
('uji_gw_admin', 'uji_gw_admin@uji.test', '$2y$10$ZWC39OjJKETfq9megV/tdexPnuCbvOi4NVlazfLgOr/UVEkYE89i6', 'Uji GW Admin',  'ADMIN',          NULL,                 NULL,       0),
('uji_gw_ks',    'uji_gw_ks@uji.test',    '$2y$10$ZWC39OjJKETfq9megV/tdexPnuCbvOi4NVlazfLgOr/UVEkYE89i6', 'Uji GW Kepala', 'KEPALA_SEKOLAH', NULL,                 NULL,       0),
('uji_gw_tu',    'uji_gw_tu@uji.test',    '$2y$10$ZWC39OjJKETfq9megV/tdexPnuCbvOi4NVlazfLgOr/UVEkYE89i6', 'Uji GW TU',     'TATA_USAHA',     NULL,                 NULL,       0),
('uji_gw_1',     'uji_gw_1@uji.test',     '$2y$10$ZWC39OjJKETfq9megV/tdexPnuCbvOi4NVlazfLgOr/UVEkYE89i6', 'Uji GW Satu',   'GURU',           '199001012020121001', NULL,       0),
('uji_gw_2',     'uji_gw_2@uji.test',     '$2y$10$ZWC39OjJKETfq9megV/tdexPnuCbvOi4NVlazfLgOr/UVEkYE89i6', 'Uji GW Dua',    'GURU',           '199002022020122002', 'UJI_GW_A', 0),
('uji_gw_3',     'uji_gw_3@uji.test',     '$2y$10$ZWC39OjJKETfq9megV/tdexPnuCbvOi4NVlazfLgOr/UVEkYE89i6', 'Uji GW BK',     'GURU',           NULL,                 NULL,       1),
('uji_gw_4',     'uji_gw_4@uji.test',     '$2y$10$ZWC39OjJKETfq9megV/tdexPnuCbvOi4NVlazfLgOr/UVEkYE89i6', 'Uji GW Empat',  'GURU',           NULL,                 NULL,       0);

REPLACE INTO classes (id, name, level) VALUES
('UJI_GW_A', 'Kelas Uji GW A', 'UJI_GW'),
('UJI_GW_B', 'Kelas Uji GW B', 'UJI_GW');

-- s_gw_5: Lulus; s_gw_6 & s_gw_7: NISN sama setelah normalisasi nol depan (uji NISN ganda; idx_nisn unik di sebagian DB); s_gw_7 punya NIK (uji penyamaran)
REPLACE INTO students (id, nisn, name, class_id, class_name, status, total_violation_points, nik) VALUES
('s_gw_1',  '0098000001', 'Uji GW Murid Satu',     'UJI_GW_A', 'Kelas Uji GW A', 'Aktif', 0,  NULL),
('s_gw_2',  '0098000002', 'Uji GW Murid Dua',      'UJI_GW_A', 'Kelas Uji GW A', 'Aktif', 10, NULL),
('s_gw_3',  '0098000003', 'Uji GW Murid Tiga',     'UJI_GW_B', 'Kelas Uji GW B', 'Aktif', 0,  NULL),
('s_gw_4',  '0098000004', 'Uji GW Murid Empat',    'UJI_GW_B', 'Kelas Uji GW B', 'Aktif', 0,  NULL),
('s_gw_5',  '0098000005', 'Uji GW Murid Lima',     'UJI_GW_A', 'Kelas Uji GW A', 'Lulus', 0,  NULL),
('s_gw_6',  '98000066',   'Uji GW Murid Enam',     'UJI_GW_B', 'Kelas Uji GW B', 'Aktif', 0,  NULL),
('s_gw_7',  '0098000066', 'Uji GW Murid Tujuh',    'UJI_GW_B', 'Kelas Uji GW B', 'Aktif', 0,  '6372015503090777'),
('s_gw_8',  '0098000008', 'Uji GW Murid Delapan',  'UJI_GW_B', 'Kelas Uji GW B', 'Aktif', 0,  NULL),
('s_gw_9',  '0098000009', 'Uji GW Murid Sembilan', 'UJI_GW_B', 'Kelas Uji GW B', 'Aktif', 0,  NULL),
('s_gw_10', '0098000010', 'Uji GW Murid Sepuluh',  'UJI_GW_B', 'Kelas Uji GW B', 'Aktif', 0,  NULL);
