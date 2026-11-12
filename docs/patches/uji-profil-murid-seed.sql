-- Seed data uji Profil Murid 360° (docs/specs/profil-murid-360.md §10.1).
-- HANYA untuk dev/staging. Semua data memakai prefiks uji_pm/UJI_PM/s_pm dan dihapus oleh uji-profil-murid-cleanup.sql.
-- Tanggal jurnal/presensi mengasumsikan tahun ajaran aktif 2026/2027 semester GANJIL (Juli–Desember 2026).
-- Password semua akun: uji12345 (bcrypt). Jalankan: mysql -uroot simantap < docs/patches/uji-profil-murid-seed.sql

REPLACE INTO users (uid, email, password, displayName, role, nip, class_homeroom, is_bk) VALUES
('uji_pm_admin',  'uji_pm_admin@uji.test',  '$2y$10$ZWC39OjJKETfq9megV/tdexPnuCbvOi4NVlazfLgOr/UVEkYE89i6', 'Uji PM Admin',       'ADMIN',          NULL,         NULL,       0),
('uji_pm_ks',     'uji_pm_ks@uji.test',     '$2y$10$ZWC39OjJKETfq9megV/tdexPnuCbvOi4NVlazfLgOr/UVEkYE89i6', 'Uji PM Kepala',      'KEPALA_SEKOLAH', NULL,         NULL,       0),
('uji_pm_tu',     'uji_pm_tu@uji.test',     '$2y$10$ZWC39OjJKETfq9megV/tdexPnuCbvOi4NVlazfLgOr/UVEkYE89i6', 'Uji PM TU',          'TATA_USAHA',     NULL,         NULL,       0),
('uji_pm_wali',   'uji_pm_wali@uji.test',   '$2y$10$ZWC39OjJKETfq9megV/tdexPnuCbvOi4NVlazfLgOr/UVEkYE89i6', 'Uji PM Wali A',      'GURU',           '1990PM0001', 'UJI_PM_A', 0),
('uji_pm_wali_b', 'uji_pm_wali_b@uji.test', '$2y$10$ZWC39OjJKETfq9megV/tdexPnuCbvOi4NVlazfLgOr/UVEkYE89i6', 'Uji PM Wali B',      'GURU',           NULL,         'UJI_PM_B', 0),
('uji_pm_bk',     'uji_pm_bk@uji.test',     '$2y$10$ZWC39OjJKETfq9megV/tdexPnuCbvOi4NVlazfLgOr/UVEkYE89i6', 'Uji PM Guru BK',     'GURU',           NULL,         NULL,       1),
('uji_pm_guru',   'uji_pm_guru@uji.test',   '$2y$10$ZWC39OjJKETfq9megV/tdexPnuCbvOi4NVlazfLgOr/UVEkYE89i6', 'Uji PM Guru Mapel',  'GURU',           NULL,         NULL,       0);

REPLACE INTO classes (id, name, level) VALUES
('UJI_PM_A', 'Kelas Uji PM A', 'UJI_PM'),
('UJI_PM_B', 'Kelas Uji PM B', 'UJI_PM');

REPLACE INTO subjects (id, name, is_active, sort_order, category) VALUES
('UJI_PM_M1', 'Mapel Uji PM Satu', 1, 951, 'Umum'),
('UJI_PM_M2', 'Mapel Uji PM Dua',  1, 952, 'Umum');

DELETE FROM teacher_subjects WHERE teacher_id = 'uji_pm_guru';
INSERT INTO teacher_subjects (teacher_id, subject_code, subject_name, class_level, class_ids) VALUES
('uji_pm_guru', 'UJI_PM_M1', 'Mapel Uji PM Satu', NULL, '["UJI_PM_A","UJI_PM_B"]'),
('uji_pm_guru', 'UJI_PM_M2', 'Mapel Uji PM Dua',  NULL, '["UJI_PM_A"]');

-- s_pm_1: semester lalu (2025/2026 GENAP) di UJI_PM_B, kini di UJI_PM_A
-- s_pm_4: nonaktif (Pindah); s_pm_6 & s_pm_7: NIK sama (uji KONFLIK)
REPLACE INTO students (id, nisn, name, class_id, class_name, status, total_violation_points, nik, parent_name, parent_phone) VALUES
('s_pm_1', '0099000001', 'Uji PM Satu',  'UJI_PM_A', 'Kelas Uji PM A', 'Aktif',  5, NULL,               'Ortu Lama', '081200000001'),
('s_pm_2', '0099000002', 'Uji PM Dua',   'UJI_PM_A', 'Kelas Uji PM A', 'Aktif',  0, '6372015503090002', NULL,        NULL),
('s_pm_3', '0099000003', 'Uji PM Tiga',  'UJI_PM_A', 'Kelas Uji PM A', 'Aktif',  0, '6372015503090003', NULL,        NULL),
('s_pm_4', '0099000004', 'Uji PM Empat', 'UJI_PM_A', 'Kelas Uji PM A', 'Pindah', 0, NULL,               NULL,        NULL),
('s_pm_5', '0099000005', 'Uji PM Lima',  'UJI_PM_B', 'Kelas Uji PM B', 'Aktif',  0, NULL,               NULL,        NULL),
('s_pm_6', '0099000006', 'Uji PM Enam',  'UJI_PM_B', 'Kelas Uji PM B', 'Aktif',  0, '6372015503090077', NULL,        NULL),
('s_pm_7', '0099000007', 'Uji PM Tujuh', 'UJI_PM_B', 'Kelas Uji PM B', 'Aktif',  0, '6372015503090077', NULL,        NULL);

-- Jurnal ber-penilaian semester aktif (UJI_PM_A) + semester lalu (UJI_PM_B)
DELETE FROM journal_entries WHERE id LIKE 'j_uji_pm_%';
INSERT INTO journal_entries (id, teacherId, teacherName, date, classId, className, subjectId, subjectName, period, topic,
    learningObjectives, method, media, activities, studentAttendance, isDraft, isVerified, assessment_data, has_assessment) VALUES
('j_uji_pm_1', 'uji_pm_guru', 'Uji PM Guru Mapel', '2026-08-03', 'UJI_PM_A', 'Kelas Uji PM A', 'UJI_PM_M1', 'Mapel Uji PM Satu', '1-2', 'Topik PM 1',
    '-', 'Ceramah', '-', '-', '{"s_pm_1":"HADIR","s_pm_2":"HADIR","s_pm_3":"SAKIT"}', 0, 1,
    '[{"student_id":"s_pm_1","student_name":"Uji PM Satu","tugas_score":60,"kuis_score":"","notes":"Perlu bimbingan <b>pecahan</b>"},{"student_id":"s_pm_2","student_name":"Uji PM Dua","tugas_score":90,"kuis_score":"","notes":""}]', 1),
('j_uji_pm_2', 'uji_pm_guru', 'Uji PM Guru Mapel', '2026-08-10', 'UJI_PM_A', 'Kelas Uji PM A', 'UJI_PM_M1', 'Mapel Uji PM Satu', '1-2', 'Topik PM 2',
    '-', 'Diskusi', '-', '-', '[{"studentId":"s_pm_1","status":"ALPHA"},{"studentId":"s_pm_2","status":"HADIR"}]', 0, 1,
    '[{"student_id":"s_pm_2","student_name":"Uji PM Dua","tugas_score":"","kuis_score":85,"notes":""}]', 1),
('j_uji_pm_3', 'uji_pm_guru', 'Uji PM Guru Mapel', '2026-08-11', 'UJI_PM_A', 'Kelas Uji PM A', 'UJI_PM_M2', 'Mapel Uji PM Dua', '3-4', 'Topik PM 3',
    '-', 'Praktik', '-', '-', '{"s_pm_1":"IZIN","s_pm_2":"HADIR"}', 0, 1,
    '[{"student_id":"s_pm_1","student_name":"Uji PM Satu","tugas_score":88,"kuis_score":92,"notes":"Aktif bertanya"}]', 1),
('j_uji_pm_4', 'uji_pm_guru', 'Uji PM Guru Mapel', '2026-08-12', 'UJI_PM_A', 'Kelas Uji PM A', 'UJI_PM_M2', 'Mapel Uji PM Dua', '3-4', 'Draf PM',
    '-', 'Praktik', '-', '-', '{"s_pm_1":"ALFA"}', 1, 0,
    '[{"student_id":"s_pm_1","student_name":"Uji PM Satu","tugas_score":10,"kuis_score":10,"notes":"Catatan draf tidak boleh tampil"}]', 1),
('j_uji_pm_5', 'uji_pm_guru', 'Uji PM Guru Mapel', '2026-03-10', 'UJI_PM_B', 'Kelas Uji PM B', 'UJI_PM_M1', 'Mapel Uji PM Satu', '1-2', 'Topik semester lalu',
    '-', 'Ceramah', '-', '-', '{"s_pm_1":"HADIR"}', 0, 1,
    '[{"student_id":"s_pm_1","student_name":"Uji PM Satu","tugas_score":50,"kuis_score":70,"notes":"Catatan semester lalu"}]', 1);

DELETE FROM ujian_scores WHERE student_id LIKE 's\_pm\_%';
INSERT INTO ujian_scores (student_id, student_name, class_id, subject_id, academic_year, semester, ujian_type, score, recorded_by, recorder_name) VALUES
('s_pm_1', 'Uji PM Satu', 'UJI_PM_B', 'UJI_PM_M1', '2025/2026', 'GENAP', 'UAS', 80.00, 'uji_pm_guru', 'Uji PM Guru Mapel');

DELETE FROM attendance_records WHERE student_id LIKE 's\_pm\_%';
INSERT INTO attendance_records (id, student_id, student_name, class_id, class_name, date, semester, academic_year, status, recorded_by, recorder_name, source) VALUES
('ar_uji_pm_1', 's_pm_1', 'Uji PM Satu', 'UJI_PM_A', 'Kelas Uji PM A', '2026-08-03', 'GANJIL', '2026/2027', 'HADIR', 'uji_pm_wali', 'Uji PM Wali A', 'MANUAL'),
('ar_uji_pm_2', 's_pm_1', 'Uji PM Satu', 'UJI_PM_A', 'Kelas Uji PM A', '2026-08-10', 'GANJIL', '2026/2027', 'ALFA',  'uji_pm_wali', 'Uji PM Wali A', 'JURNAL'),
('ar_uji_pm_3', 's_pm_1', 'Uji PM Satu', 'UJI_PM_A', 'Kelas Uji PM A', '2026-08-11', 'GANJIL', '2026/2027', 'IZIN',  'uji_pm_wali', 'Uji PM Wali A', 'MANUAL'),
('ar_uji_pm_4', 's_pm_1', 'Uji PM Satu', 'UJI_PM_A', 'Kelas Uji PM A', '2026-09-01', 'GANJIL', '2026/2027', 'HADIR', 'uji_pm_wali', 'Uji PM Wali A', 'MANUAL');

DELETE FROM incidents WHERE id LIKE 'inc_uji_pm_%';
INSERT INTO incidents (id, type, date, studentId, student_nisn, studentName, className, academic_year, semester, reporterId, reporterName, details, points, status) VALUES
('inc_uji_pm_1', 'LATE',      '2026-08-04', 's_pm_1', '0099000001', 'Uji PM Satu', 'Kelas Uji PM A', '2026/2027', 'GANJIL', 'uji_pm_wali', 'Uji PM Wali A', 'Terlambat 20 menit', 5, 'RESOLVED'),
('inc_uji_pm_2', 'VIOLATION', '2026-02-04', 's_pm_1', '0099000001', 'Uji PM Satu', 'Kelas Uji PM B', '2025/2026', 'GENAP',  'uji_pm_wali', 'Uji PM Wali A', 'Insiden tahun lalu', 10, 'RESOLVED');

DELETE FROM parent_calls WHERE id LIKE 'pc_uji_pm_%';
INSERT INTO parent_calls (id, student_id, student_name, student_nisn, class_name, academic_year, semester, total_points, trigger_type, scheduled_date, status, notes, logs) VALUES
('pc_uji_pm_1', 's_pm_1', 'Uji PM Satu', '0099000001', 'Kelas Uji PM A', '2026/2027', 'GANJIL', 5, 'MANUAL', '2026-08-20', 'PENDING', 'Bahas keterlambatan', '[]');

REPLACE INTO extracurriculars (id, name, coachId, coachName, schedule, students) VALUES
('eks_uji_pm', 'Ekskul Uji PM', 'uji_pm_guru', 'Uji PM Guru Mapel', 'Sabtu', '[]');
DELETE FROM extracurricular_members WHERE extracurricular_id = 'eks_uji_pm';
INSERT INTO extracurricular_members (extracurricular_id, student_id, student_name, class_id, class_name, joined_at, status) VALUES
('eks_uji_pm', 's_pm_1', 'Uji PM Satu', 'UJI_PM_A', 'Kelas Uji PM A', '2026-07-20', 'AKTIF');
