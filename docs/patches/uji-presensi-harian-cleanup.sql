-- Pembersih data uji presensi harian.
DELETE FROM journal_entries WHERE id LIKE 'j\_uji\_ph%';
DELETE FROM attendance_records WHERE student_id = 's_uji_ph_1';
DELETE FROM incidents WHERE studentId = 's_uji_ph_1';
DELETE FROM parent_calls WHERE student_id = 's_uji_ph_1';
DELETE FROM students WHERE id = 's_uji_ph_1';
DELETE FROM users WHERE uid = 'u_uji_ph_admin';
