-- Pembersih data uji presensi harian & dispensasi.
DELETE FROM journal_entries WHERE id LIKE 'j\_uji\_ph%';
DELETE FROM journal_entries WHERE classId = 'k_uji_ph';
DELETE FROM attendance_records WHERE student_id LIKE 's\_uji\_ph\_%';
DELETE FROM incidents WHERE studentId LIKE 's\_uji\_ph\_%';
DELETE FROM parent_calls WHERE student_id LIKE 's\_uji\_ph\_%';
DELETE FROM student_dispensations WHERE student_id LIKE 's\_uji\_ph\_%';
DELETE FROM students WHERE id LIKE 's\_uji\_ph\_%';
DELETE FROM users WHERE uid LIKE 'u\_uji\_ph\_%';
DELETE FROM classes WHERE id = 'k_uji_ph';
