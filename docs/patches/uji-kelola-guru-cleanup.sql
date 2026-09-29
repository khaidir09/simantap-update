-- Pembersih data uji Kelola Guru (uji-kelola-guru-seed.sql / uji-kelola-guru-api.mjs).
DELETE FROM teacher_subjects WHERE teacher_id LIKE 'u\_kg\_uji\_%';
DELETE FROM gtk_pegawai WHERE id LIKE 'u\_kg\_uji\_%';
DELETE FROM teacher_registry WHERE email LIKE 'kg\_uji\_%';
DELETE FROM users WHERE uid LIKE 'u\_kg\_uji\_%';
