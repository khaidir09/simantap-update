-- Pembersih data uji Jurnal Bimbingan PKL (uji-bimbingan-pkl-seed.sql / uji-bimbingan-pkl-api.mjs).
-- Tabel pkl_* tidak di-DROP (bisa sudah berisi data sungguhan); hanya baris uji yang dihapus.
-- File foto uji di uploads/journals/pkl/ sudah dihapus oleh skrip uji (pkl_jurnal_hapus);
-- bila skrip berhenti di tengah, hapus manual file yang namanya tercatat di pkl_jurnal uji sebelum menjalankan ini.
DELETE FROM pkl_jurnal WHERE siswa_id LIKE 's\_uji\_pkl\_%';
DELETE FROM pkl_penempatan WHERE siswa_id LIKE 's\_uji\_pkl\_%';
DELETE FROM pkl_dudi WHERE nama LIKE '%Uji PKL%';
DELETE FROM teacher_subjects WHERE teacher_id LIKE 'u\_uji\_pkl\_%';
DELETE FROM students WHERE id LIKE 's\_uji\_pkl\_%';
DELETE FROM classes WHERE id LIKE 'k\_uji\_pkl\_%';
DELETE FROM users WHERE uid LIKE 'u\_uji\_pkl\_%';
