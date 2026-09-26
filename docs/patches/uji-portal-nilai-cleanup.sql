-- Hapus seluruh data uji Portal Nilai Orang Tua (pasangan uji-portal-nilai-seed.sql).
-- Tabel portal_nilai_* dan key portal_nilai_aktif TIDAK dihapus (skrip uji mengembalikan nilai saklar semula).
-- Jalankan: mysql -uroot simantap < docs/patches/uji-portal-nilai-cleanup.sql
DELETE FROM journal_entries    WHERE classId IN ('UJI', 'UJI2');
DELETE FROM ujian_scores       WHERE class_id IN ('UJI', 'UJI2');
DELETE FROM attendance_records WHERE student_id LIKE 's\_uji%';
DELETE FROM incidents          WHERE studentId LIKE 's\_uji%';
DELETE FROM parent_calls       WHERE student_id LIKE 's\_uji%';
DELETE FROM portal_nilai_akses WHERE student_id LIKE 's\_uji%';
DELETE FROM portal_nilai_percobaan WHERE ip IN ('127.0.0.1', '::1') OR nisn LIKE '990000%';
DELETE FROM students           WHERE id LIKE 's\_uji%';
DELETE FROM teacher_subjects   WHERE teacher_id IN ('uji_guru', 'uji_guru2', 'uji_lain');
DELETE FROM subjects           WHERE id IN ('UJI_A', 'UJI_B', 'UJI_C');
DELETE FROM classes            WHERE id IN ('UJI', 'UJI2');
DELETE FROM users              WHERE uid IN ('uji_admin', 'uji_ks', 'uji_tu', 'uji_guru', 'uji_guru2', 'uji_wali', 'uji_wali2', 'uji_bk', 'uji_lain');
