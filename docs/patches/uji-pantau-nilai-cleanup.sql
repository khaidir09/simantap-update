-- Hapus seluruh data uji Pantau Nilai Kelas (pasangan uji-pantau-nilai-seed.sql).
-- Jalankan: mysql -uroot simantap < docs/patches/uji-pantau-nilai-cleanup.sql
DELETE FROM journal_entries   WHERE classId IN ('UJI', 'UJI2');
DELETE FROM ujian_scores      WHERE class_id IN ('UJI', 'UJI2');
DELETE FROM attendance_records WHERE student_id IN ('s_uji_1', 's_uji_2', 's_uji_3', 's_uji_4', 's_uji2_1');
DELETE FROM incidents         WHERE studentId IN ('s_uji_1', 's_uji_2', 's_uji_3', 's_uji_4', 's_uji2_1');
DELETE FROM parent_calls      WHERE student_id IN ('s_uji_1', 's_uji_2', 's_uji_3', 's_uji_4', 's_uji2_1');
DELETE FROM students          WHERE id IN ('s_uji_1', 's_uji_2', 's_uji_3', 's_uji_4', 's_uji2_1');
DELETE FROM teacher_subjects  WHERE teacher_id IN ('uji_guru', 'uji_guru2', 'uji_lain');
DELETE FROM subjects          WHERE id IN ('UJI_A', 'UJI_B', 'UJI_C');
DELETE FROM classes           WHERE id IN ('UJI', 'UJI2');
DELETE FROM users             WHERE uid IN ('uji_admin', 'uji_ks', 'uji_tu', 'uji_guru', 'uji_guru2', 'uji_wali', 'uji_bk', 'uji_lain');
