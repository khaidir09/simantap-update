-- Seed data uji Portal Nilai Orang Tua (docs/specs/portal-nilai-orang-tua.md §10.2).
-- HANYA untuk dev/staging. Semua data memakai prefiks uji/UJI, NISN 99000000xx,
-- dan dihapus oleh uji-portal-nilai-cleanup.sql.
-- Password semua akun: uji12345. Jalankan: mysql -uroot simantap < docs/patches/uji-portal-nilai-seed.sql

REPLACE INTO users (uid, email, password, displayName, role, nip, class_homeroom, is_bk) VALUES
('uji_admin', 'uji_admin@uji.test', '$2y$10$ZWC39OjJKETfq9megV/tdexPnuCbvOi4NVlazfLgOr/UVEkYE89i6', 'Uji Admin',        'ADMIN',          NULL, NULL,   0),
('uji_ks',    'uji_ks@uji.test',    '$2y$10$ZWC39OjJKETfq9megV/tdexPnuCbvOi4NVlazfLgOr/UVEkYE89i6', 'Uji Kepala',       'KEPALA_SEKOLAH', NULL, NULL,   0),
('uji_tu',    'uji_tu@uji.test',    '$2y$10$ZWC39OjJKETfq9megV/tdexPnuCbvOi4NVlazfLgOr/UVEkYE89i6', 'Uji TU',           'TATA_USAHA',     NULL, NULL,   0),
('uji_guru',  'uji_guru@uji.test',  '$2y$10$ZWC39OjJKETfq9megV/tdexPnuCbvOi4NVlazfLgOr/UVEkYE89i6', 'Uji Guru Mapel A', 'GURU',           NULL, NULL,   0),
('uji_wali',  'uji_wali@uji.test',  '$2y$10$ZWC39OjJKETfq9megV/tdexPnuCbvOi4NVlazfLgOr/UVEkYE89i6', 'Uji Wali Kelas',   'GURU',           NULL, 'UJI',  0),
('uji_wali2', 'uji_wali2@uji.test', '$2y$10$ZWC39OjJKETfq9megV/tdexPnuCbvOi4NVlazfLgOr/UVEkYE89i6', 'Uji Wali Kelas 2', 'GURU',           NULL, 'UJI2', 0),
('uji_bk',    'uji_bk@uji.test',    '$2y$10$ZWC39OjJKETfq9megV/tdexPnuCbvOi4NVlazfLgOr/UVEkYE89i6', 'Uji Guru BK',      'GURU',           NULL, NULL,   1);

REPLACE INTO classes (id, name, level) VALUES
('UJI',  'Kelas Uji',   'UJI'),
('UJI2', 'Kelas Uji 2', 'UJI2');

REPLACE INTO subjects (id, name, is_active, sort_order, category) VALUES
('UJI_A', 'Mapel Uji A', 1, 901, 'Umum');

DELETE FROM teacher_subjects WHERE teacher_id = 'uji_guru';
INSERT INTO teacher_subjects (teacher_id, subject_code, subject_name, class_level, class_ids) VALUES
('uji_guru', 'UJI_A', 'Mapel Uji A', NULL, '["UJI"]');

-- s_uji_1..3 aktif; s_uji_4 pindah; s_uji_5 tanpa NISN; s_uji_6 aktif (tanpa nilai); s_uji2_1 di UJI2.
-- NISN ganda tidak bisa dibuat: install/schema.sql memasang UNIQUE KEY idx_nisn.
REPLACE INTO students (id, nisn, name, class_id, class_name, status) VALUES
('s_uji_1',  '9900000001', 'Uji Siswa Satu',  'UJI',  'Kelas Uji',   'Aktif'),
('s_uji_2',  '9900000002', 'Uji Siswa Dua',   'UJI',  'Kelas Uji',   'Aktif'),
('s_uji_3',  '9900000003', 'Uji Siswa Tiga',  'UJI',  'Kelas Uji',   'Aktif'),
('s_uji_4',  '9900000004', 'Uji Siswa Empat', 'UJI',  'Kelas Uji',   'Pindah'),
('s_uji_5',  NULL,         'Uji Siswa Lima',  'UJI',  'Kelas Uji',   'Aktif'),
('s_uji_6',  '9900000006', 'Uji Siswa Enam',  'UJI',  'Kelas Uji',   'Aktif'),
('s_uji2_1', '9900000021', 'Uji Siswa Lain',  'UJI2', 'Kelas Uji 2', 'Aktif');

-- Tabel portal (sama dengan portalNilaiEnsureTables) + PIN ABCD2345 untuk siswa pindah (uji P20)
CREATE TABLE IF NOT EXISTS portal_nilai_akses (
  student_id        VARCHAR(128) NOT NULL PRIMARY KEY,
  pin_hash          VARCHAR(255) NOT NULL,
  dibuat_oleh       VARCHAR(128) DEFAULT NULL,
  dibuat_oleh_nama  VARCHAR(255) DEFAULT NULL,
  dibuat_pada       DATETIME     NOT NULL,
  login_terakhir    DATETIME     DEFAULT NULL,
  jumlah_login      INT          NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS portal_nilai_percobaan (
  id          BIGINT      NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ip          VARCHAR(45) NOT NULL,
  nisn        VARCHAR(32) NOT NULL,
  berhasil    TINYINT(1)  NOT NULL,
  dibuat_pada DATETIME    NOT NULL,
  KEY idx_ip_waktu (ip, dibuat_pada),
  KEY idx_nisn_waktu (nisn, dibuat_pada)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
REPLACE INTO portal_nilai_akses (student_id, pin_hash, dibuat_oleh, dibuat_oleh_nama, dibuat_pada) VALUES
('s_uji_4', '$2y$10$Rn8cIEb6Rlxm0JadhjwhD.2s5VYnc974wAyPwHfGDwb8pH37KpICq', 'uji_admin', 'Uji Admin', NOW());

-- Percobaan login dari mesin uji (lokal) dikosongkan agar batas per IP dihitung dari nol
DELETE FROM portal_nilai_percobaan WHERE ip IN ('127.0.0.1', '::1') OR nisn LIKE '990000%';
