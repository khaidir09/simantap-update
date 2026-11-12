-- Hapus seluruh data uji Profil Murid 360° (pasangan uji-profil-murid-seed.sql).
-- Jalankan: mysql -uroot simantap < docs/patches/uji-profil-murid-cleanup.sql
-- Tabel student_biodata/student_asesmen/student_import_log dibuat saat action profil_murid_* pertama dipanggil.
-- Bila belum pernah dipanggil, tiga baris pertama gagal "table doesn't exist": jalankan dengan mysql --force.
DELETE FROM student_biodata    WHERE student_id LIKE 's\_pm\_%';
DELETE FROM student_asesmen    WHERE student_id LIKE 's\_pm\_%';
DELETE FROM student_import_log WHERE oleh LIKE 'uji\_pm\_%';
DELETE FROM extracurricular_members WHERE extracurricular_id = 'eks_uji_pm';
DELETE FROM extracurriculars   WHERE id = 'eks_uji_pm';
DELETE FROM parent_calls       WHERE id LIKE 'pc_uji_pm_%';
DELETE FROM incidents          WHERE id LIKE 'inc_uji_pm_%';
DELETE FROM attendance_records WHERE student_id LIKE 's\_pm\_%';
DELETE FROM ujian_scores       WHERE student_id LIKE 's\_pm\_%';
DELETE FROM journal_entries    WHERE id LIKE 'j_uji_pm_%';
DELETE FROM students           WHERE id LIKE 's\_pm\_%';
DELETE FROM teacher_subjects   WHERE teacher_id = 'uji_pm_guru';
DELETE FROM subjects           WHERE id IN ('UJI_PM_M1', 'UJI_PM_M2');
DELETE FROM classes            WHERE id IN ('UJI_PM_A', 'UJI_PM_B');
DELETE FROM users              WHERE uid LIKE 'uji\_pm\_%';
