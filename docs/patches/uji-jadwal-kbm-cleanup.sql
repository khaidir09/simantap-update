-- Pembersih data uji jadwal KBM.
DELETE FROM teacher_subjects WHERE teacher_id LIKE 'u\_uji\_jk\_%';
DELETE FROM gtk_pegawai WHERE id LIKE 'u\_uji\_jk\_%';
DELETE FROM users WHERE uid LIKE 'u\_uji\_jk\_%';
DELETE FROM classes WHERE id LIKE 'k\_uji\_jk\_%';
DELETE FROM subjects WHERE id LIKE 'm\_uji\_jk\_%';
