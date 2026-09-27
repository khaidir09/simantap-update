-- Pembersih data uji label media (jalankan bersama uji-presensi-harian-cleanup.sql).
DELETE FROM journal_media_labels WHERE teacher_id LIKE 'u\_uji\_ph\_%';
