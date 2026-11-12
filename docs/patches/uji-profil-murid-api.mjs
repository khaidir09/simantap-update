// Uji API Profil Murid 360° (docs/specs/profil-murid-360.md §10.1).
// HANYA untuk dev/staging. Prasyarat: docs/patches/uji-profil-murid-seed.sql sudah dijalankan (akun uji_pm_*,
// kelas UJI_PM_A/UJI_PM_B, murid s_pm_*), tahun ajaran aktif 2026/2027 GANJIL. Bersihkan dengan uji-profil-murid-cleanup.sql
// lalu seed ulang sebelum menjalankan ulang (skrip berhenti bila murid uji sudah punya biodata/asesmen).
// UJI_MYSQL (opsional) mengaktifkan pemeriksaan langsung ke DB, mis. "E:/laragon/bin/mysql/mysql-5.7.33-winx64/bin/mysql.exe -uroot simantap".
// Jalankan: BASE=http://localhost/simantap/api/index.php UJI_PASSWORD=uji12345 node docs/patches/uji-profil-murid-api.mjs
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

const BASE = process.env.BASE || 'http://localhost/simantap/api/index.php';
const PASSWORD = process.env.UJI_PASSWORD || 'uji12345';
let pass = 0, failed = 0, skipped = 0;
const jars = {};

async function call(who, action, { method = 'GET', body = null, query = '' } = {}) {
  const headers = { 'Content-Type': 'application/json' };
  if (who && jars[who]) headers.Cookie = jars[who];
  const res = await fetch(`${BASE}?action=${action}${query}`, { method, headers, body: body ? JSON.stringify(body) : undefined });
  const setCookie = res.headers.get('set-cookie');
  if (who && setCookie) jars[who] = setCookie.split(';')[0];
  const text = await res.text();
  let json = null; try { json = JSON.parse(text); } catch {}
  return { status: res.status, json, text, type: res.headers.get('content-type') || '' };
}
function check(id, cond, info = '') {
  if (cond) { pass++; console.log(`PASS ${id}`); }
  else { failed++; console.log(`FAIL ${id} ${typeof info === 'string' ? info : JSON.stringify(info)}`.slice(0, 1500)); }
}
function skip(id, why) { skipped++; console.log(`SKIP ${id} (${why})`); }
async function login(who) {
  const r = await call(who, 'login', { method: 'POST', body: { email: `${who}@uji.test`, password: PASSWORD } });
  if (r.status !== 200) throw new Error(`login ${who} gagal: ${r.status} ${r.text.slice(0, 200)}`);
}
function sql(query) {
  const parts = process.env.UJI_MYSQL.split(' ').filter(Boolean);
  return execFileSync(parts[0], [...parts.slice(1), '-N', '-e', query], { encoding: 'utf8' }).trim();
}
const q = (params) => Object.entries(params).map(([k, v]) => `&${k}=${encodeURIComponent(v)}`).join('');
const profil = (who, sid) => call(who, 'profil_murid_get', { query: q({ student_id: sid }) });
const post = (who, action, body) => call(who, action, { method: 'POST', body });

const HEADER = ['NISN', 'Nama', 'R', 'I', 'A', 'S', 'E', 'C', 'Kode RIASEC', 'Gaya Belajar', 'Skor Visual', 'Skor Auditori',
  'Skor Kinestetik', 'Kesulitan Belajar', 'Mapel Termudah', 'Mapel Tersulit', 'Cita-cita', 'Rencana Setelah Lulus',
  'Jurusan Tujuan', 'Institusi Tujuan', 'Catatan Karir'];
const barisAsesmen = (o) => HEADER.map((h) => o[h] ?? '');

async function main() {
  const semua = ['uji_pm_admin', 'uji_pm_ks', 'uji_pm_tu', 'uji_pm_wali', 'uji_pm_wali_b', 'uji_pm_bk', 'uji_pm_guru'];
  for (const who of semua) await login(who);

  // Pengaman: murid uji belum punya biodata/asesmen
  const awal = await profil('uji_pm_bk', 's_pm_1');
  if (awal.status !== 200) throw new Error(`profil awal gagal: ${awal.status} ${awal.text.slice(0, 300)}`);
  if (awal.json.biodata !== null || awal.json.asesmen.length > 0) {
    throw new Error('Murid uji sudah punya biodata/asesmen. Jalankan uji-profil-murid-cleanup.sql lalu seed ulang.');
  }
  if (awal.json.periode_aktif.academic_year !== '2026/2027' || awal.json.periode_aktif.semester !== 'GANJIL') {
    throw new Error(`Seed mengasumsikan TA aktif 2026/2027 GANJIL, aktif sekarang ${JSON.stringify(awal.json.periode_aktif)}`);
  }

  // ---- T1 Otorisasi baca ----
  const harap = { uji_pm_wali: 200, uji_pm_bk: 200, uji_pm_ks: 200, uji_pm_admin: 200, uji_pm_tu: 403, uji_pm_guru: 403, uji_pm_wali_b: 403 };
  for (const [who, st] of Object.entries(harap)) {
    const r = await profil(who, 's_pm_1');
    check(`T1 baca ${who} -> ${st}`, r.status === st, `${r.status} ${r.text.slice(0, 200)}`);
  }
  let r = await profil('uji_pm_wali', 's_pm_4');
  check('T1 wali murid nonaktif -> 403', r.status === 403, r.status);
  r = await profil('uji_pm_wali', 's_pm_5');
  check('T1 wali murid kelas lain -> 403', r.status === 403 && /kelas perwalian/.test(r.json?.error || ''), r.text);
  r = await profil('uji_pm_bk', 's_pm_4');
  check('T1 BK murid nonaktif -> 200', r.status === 200 && r.json.murid.aktif === false && r.json.akademik.semester_aktif.length === 0, r.status);
  r = await profil('uji_pm_bk', 'tidak_ada');
  check('T1 murid tidak ada -> 404', r.status === 404, r.status);
  r = await profil(null, 's_pm_1');
  check('T1 tanpa login -> 401', r.status === 401, r.status);

  // ---- T3 Metode ----
  r = await call('uji_pm_bk', 'profil_murid_get', { method: 'POST', body: { student_id: 's_pm_1' } });
  check('T3 POST ke get -> 405', r.status === 405, r.status);
  r = await call('uji_pm_bk', 'profil_murid_asesmen_simpan', { query: q({ student_id: 's_pm_1' }) });
  check('T3 GET ke simpan -> 405', r.status === 405, r.status);

  // ---- T4 Impor Dapodik ----
  const rows = JSON.parse(readFileSync(new URL('./uji-profil-murid-dapodik.json', import.meta.url), 'utf8'));
  for (const who of ['uji_pm_wali', 'uji_pm_bk', 'uji_pm_ks']) {
    r = await post(who, 'profil_murid_dapodik_impor', { rows, mode: 'pratinjau' });
    check(`T4 dapodik ${who} -> 403`, r.status === 403, r.status);
  }
  r = await post('uji_pm_tu', 'profil_murid_dapodik_impor', { rows: [['Nama', 'Kelas'], ['A', 'X']], mode: 'pratinjau' });
  check('T4 format tidak dikenali -> 400', r.status === 400, r.text);
  r = await post('uji_pm_tu', 'profil_murid_dapodik_impor', { rows: [['NISN', 'Nama', 'NIK'], ['', 'Konflik', '6372015503090077']], mode: 'pratinjau' });
  check('T4 NIK ganda -> KONFLIK', r.status === 200 && r.json.baris[0].status === 'KONFLIK', r.json);

  const pra = await post('uji_pm_tu', 'profil_murid_dapodik_impor', { rows, nama_file: 'dapodik-uji.xlsx', mode: 'pratinjau' });
  check('T4 pratinjau 200', pra.status === 200, pra.text.slice(0, 300));
  const byRow = Object.fromEntries((pra.json?.baris || []).map((b) => [b.baris, b]));
  check('T4 ringkasan', JSON.stringify(pra.json?.ringkasan) === JSON.stringify({ perbarui: 3, tidak_ditemukan: 1, konflik: 0, galat: 2, total: 6 }), pra.json?.ringkasan);
  check('T4 NISN 8 digit dipad & cocok', byRow[7]?.status === 'PERBARUI' && byRow[7]?.student_id === 's_pm_1' && byRow[7]?.nisn === '0099000001', byRow[7]);
  check('T4 cocok via NIK + peringatan nama', byRow[8]?.student_id === 's_pm_2' && byRow[8]?.peringatan.some((p) => /Nama berbeda/.test(p)), byRow[8]);
  check('T4 tidak ditemukan', byRow[9]?.status === 'TIDAK_DITEMUKAN', byRow[9]);
  check('T4 NIK notasi ilmiah galat', byRow[10]?.status === 'GALAT' && /format angka Excel/.test(byRow[10]?.pesan.join(' ')), byRow[10]);
  check('T4 duplikat dalam file', byRow[11]?.status === 'GALAT' && /Duplikat/.test(byRow[11]?.pesan.join(' ')), byRow[11]);
  check('T4 nonaktif + rombel beda diperingatkan', byRow[12]?.status === 'PERBARUI' && byRow[12]?.peringatan.some((p) => /Rombel/.test(p)) && byRow[12]?.peringatan.some((p) => /Status/.test(p)), byRow[12]);
  check('T4 kolom dibuang dilaporkan', ['Bank', 'Nomor Rekening Bank', 'Rekening Atas Nama', 'Lintang', 'Bujur'].every((k) => pra.json?.kolom.dibuang.includes(k)), pra.json?.kolom);
  check('T4 pratinjau tanpa nilai rekening/koordinat', !pra.text.includes('0123456789') && !pra.text.includes('114.83'));
  r = await profil('uji_pm_bk', 's_pm_1');
  check('T4 pratinjau tidak menulis', r.json.biodata === null && r.json.murid.nik === null, r.json.biodata);

  const simpan = await post('uji_pm_tu', 'profil_murid_dapodik_impor', { rows, nama_file: 'dapodik-uji.xlsx', mode: 'simpan' });
  check('T4 simpan 200', simpan.status === 200 && simpan.json.impor_id, simpan.text.slice(0, 300));
  r = await profil('uji_pm_bk', 's_pm_1');
  const b = r.json.biodata;
  check('T4 biodata tersimpan', b && b.nipd === '24251' && b.jarak_rumah_km === 3.5 && b.ayah.nik === '6372010101800001' && b.ibu.pekerjaan === 'Guru' && b.no_kk === '6372010101090009', b);
  check('T4 students diperbarui', r.json.murid.nik === '6372015503090001' && r.json.murid.tanggal_lahir === '2009-03-15' && r.json.murid.jenis_kelamin === 'Perempuan' && r.json.murid.parent_name === 'Ayah Uji PM Satu', r.json.murid);
  check('T4 nama & kelas tidak berubah', r.json.murid.name === 'Uji PM Satu' && r.json.murid.class_id === 'UJI_PM_A', r.json.murid);
  check('T4 parent_phone tidak ditimpa', r.json.murid.parent_phone === '081200000001', r.json.murid.parent_phone);
  check('T4 kolom lain di JSON', b?.dapodik_lain?.['No Registrasi Akta Lahir'] === 'AKTA-1' && !('Bank' in (b?.dapodik_lain || {})), b?.dapodik_lain);
  r = await profil('uji_pm_bk', 's_pm_2');
  check('T4 murid 2 via NIK, nama tetap', r.json.biodata !== null && r.json.murid.name === 'Uji PM Dua', r.json.murid);
  r = await profil('uji_pm_bk', 's_pm_3');
  check('T4 baris galat tidak ditulis', r.json.biodata === null && r.json.murid.nik === '6372015503090003', r.json.murid);
  if (process.env.UJI_MYSQL) {
    const bocor = sql("SELECT COUNT(*) FROM student_biodata WHERE CONCAT_WS('|', dapodik_lain_json, ayah_json, ibu_json, nama_di_kip) REGEXP '0123456789|114\\\\.83|-3\\\\.44|BRI'");
    check('T4 DB tanpa rekening/koordinat', bocor === '0', bocor);
    const log = sql("SELECT CONCAT(jenis,'/',jml_baris,'/',jml_tulis,'/',jml_galat) FROM student_import_log WHERE oleh='uji_pm_tu'");
    check('T4 log impor', log === 'DAPODIK/6/3/2', log);
  } else { skip('T4 DB tanpa rekening/koordinat', 'UJI_MYSQL kosong'); skip('T4 log impor', 'UJI_MYSQL kosong'); }

  // ---- T2 Penyamaran ----
  r = await profil('uji_pm_wali', 's_pm_1');
  const w = r.json;
  check('T2 wali NIK/KK tersamar', w.murid.nik === '6372********0001' && w.biodata.no_kk === '6372********0009' && w.biodata.ayah.nik === '6372********0001' && w.akses.disamarkan === true, [w.murid.nik, w.biodata.no_kk, w.biodata.ayah.nik]);
  r = await profil('uji_pm_ks', 's_pm_1');
  check('T2 KS NIK penuh', r.json.murid.nik === '6372015503090001' && r.json.akses.disamarkan === false);
  r = await call('uji_pm_wali', 'cetak_profil_murid', { query: q({ student_id: 's_pm_1' }) });
  check('T2 cetak wali tersamar', r.status === 200 && r.type.includes('text/html') && r.text.includes('6372********0001') && !r.text.includes('6372015503090001'), r.status);
  r = await call('uji_pm_bk', 'cetak_profil_murid', { query: q({ student_id: 's_pm_1' }) });
  check('T2 cetak BK penuh + escape', r.status === 200 && r.text.includes('6372015503090001') && r.text.includes('&lt;b&gt;pecahan') && !r.text.includes('<b>pecahan'), r.status);
  r = await call('uji_pm_tu', 'cetak_profil_murid', { query: q({ student_id: 's_pm_1' }) });
  check('T2 cetak TU -> 403 HTML', r.status === 403 && r.type.includes('text/html'), r.status);
  r = await call(null, 'cetak_profil_murid', { query: q({ student_id: 's_pm_1' }) });
  check('T2 cetak tanpa login -> 401 HTML', r.status === 401 && r.type.includes('text/html'), r.status);

  // ---- T5 Impor asesmen ----
  r = await call('uji_pm_wali', 'profil_murid_asesmen_template', { query: '' });
  check('T5 template wali default kelasnya', r.status === 200 && r.json.kelas.id === 'UJI_PM_A' && r.json.rows.length === 3 && r.json.header.join('|') === HEADER.join('|'), r.json);
  r = await call('uji_pm_wali', 'profil_murid_asesmen_template', { query: q({ class_id: 'UJI_PM_B' }) });
  check('T5 template kelas lain -> 403', r.status === 403, r.status);
  const fileA = [HEADER,
    barisAsesmen({ NISN: '99000001', Nama: 'x', R: 10, I: 80, A: 70, S: '90,5', E: 5, C: 1, 'Kode RIASEC': 'RIA', 'Gaya Belajar': 'V', 'Kesulitan Belajar': 'Sulit fokus',
      'Mapel Termudah': 'Mapel Uji PM Dua', 'Mapel Tersulit': 'Mapel Uji PM Satu; Fisika', 'Cita-cita': 'Dokter', 'Rencana Setelah Lulus': 'Kuliah', 'Jurusan Tujuan': 'Kedokteran', 'Institusi Tujuan': 'ULM' }),
    barisAsesmen({ NISN: '0099000002', 'Kode RIASEC': 'SEC', 'Rencana Setelah Lulus': 'nikah' }),
    barisAsesmen({ NISN: '0099000003' }),
    barisAsesmen({ NISN: '0099000005', 'Cita-cita': 'Pilot' }),
    HEADER.map(() => ''),
  ];
  r = await post('uji_pm_wali', 'profil_murid_asesmen_impor', { class_id: 'UJI_PM_A', rows: fileA, mode: 'pratinjau' });
  const st = Object.fromEntries((r.json?.baris || []).map((x) => [x.nisn, x.status]));
  check('T5 pratinjau status', r.status === 200 && st['0099000001'] === 'BARU' && st['0099000002'] === 'GALAT' && st['0099000003'] === 'LEWATI' && st['0099000005'] === 'GALAT', r.json?.baris);
  check('T5 pratinjau ringkasan', JSON.stringify(r.json?.ringkasan) === JSON.stringify({ baru: 1, timpa: 0, lewati: 1, galat: 2, total: 4 }), r.json?.ringkasan);
  r = await profil('uji_pm_wali', 's_pm_1');
  check('T5 pratinjau tidak menulis', r.json.asesmen.length === 0);
  r = await post('uji_pm_wali', 'profil_murid_asesmen_impor', { class_id: 'UJI_PM_A', rows: fileA, mode: 'simpan', nama_file: 'asesmen-a.xlsx' });
  check('T5 simpan 200', r.status === 200 && r.json.impor_id, r.text.slice(0, 300));
  r = await profil('uji_pm_wali', 's_pm_1');
  const a1 = r.json.asesmen[0];
  check('T5 asesmen tersimpan', a1 && a1.riasec_kode === 'SIA' && a1.riasec_s === 90.5 && a1.gaya_belajar === 'VISUAL' && a1.rencana_lulus === 'KULIAH'
    && a1.mapel_tersulit.join('|') === 'Mapel Uji PM Satu|Fisika' && a1.sumber === 'IMPOR' && a1.class_id === 'UJI_PM_A' && a1.academic_year === '2026/2027' && a1.semester === 'GANJIL', a1);
  r = await post('uji_pm_wali', 'profil_murid_asesmen_impor', { class_id: 'UJI_PM_B', rows: fileA, mode: 'pratinjau' });
  check('T5 wali impor kelas lain -> 403', r.status === 403, r.status);
  r = await post('uji_pm_wali', 'profil_murid_asesmen_impor', { class_id: 'UJI_PM_A', semester: '3', rows: fileA, mode: 'pratinjau' });
  check('T5 semester tidak valid -> 400', r.status === 400, r.status);
  r = await post('uji_pm_wali', 'profil_murid_asesmen_impor', { class_id: 'UJI_PM_A', rows: [['Nama', 'R'], ['a', 1]], mode: 'pratinjau' });
  check('T5 tanpa kolom NISN -> 400', r.status === 400, r.status);
  r = await post('uji_pm_tu', 'profil_murid_asesmen_impor', { class_id: 'UJI_PM_A', rows: fileA, mode: 'pratinjau' });
  check('T5 TU impor asesmen -> 403', r.status === 403, r.status);
  // Impor ulang: menimpa seluruh baris; baris kosong tidak menghapus
  const fileA2 = [HEADER, barisAsesmen({ NISN: '0099000001', 'Gaya Belajar': 'Kinestetik' }), barisAsesmen({ NISN: '0099000003' })];
  r = await post('uji_pm_bk', 'profil_murid_asesmen_impor', { class_id: 'UJI_PM_A', rows: fileA2, mode: 'pratinjau' });
  check('T5 impor ulang -> TIMPA', r.status === 200 && r.json.baris[0].status === 'TIMPA', r.json?.baris);
  await post('uji_pm_bk', 'profil_murid_asesmen_impor', { class_id: 'UJI_PM_A', rows: fileA2, mode: 'simpan' });
  r = await profil('uji_pm_bk', 's_pm_1');
  const a2 = r.json.asesmen[0];
  check('T5 timpa seluruh baris', r.json.asesmen.length === 1 && a2.gaya_belajar === 'KINESTETIK' && a2.cita_cita === null && a2.riasec_kode === null && a2.diubah_oleh_nama === 'Uji PM Guru BK', a2);
  r = await post('uji_pm_bk', 'profil_murid_asesmen_impor', { class_id: 'UJI_PM_B', rows: [HEADER, barisAsesmen({ NISN: '0099000005', 'Cita-cita': 'Pilot' })], mode: 'simpan' });
  check('T5 BK impor kelas lain', r.status === 200 && r.json.ringkasan.baru === 1, r.text.slice(0, 200));

  // ---- T6 Simpan/hapus manual ----
  r = await post('uji_pm_wali', 'profil_murid_asesmen_simpan', { student_id: 's_pm_1', riasec_r: 101 });
  check('T6 skor 101 -> 400', r.status === 400, r.status);
  r = await post('uji_pm_wali', 'profil_murid_asesmen_simpan', { student_id: 's_pm_1', rencana_lulus: 'menikah' });
  check('T6 rencana tidak dikenal -> 400', r.status === 400, r.status);
  r = await post('uji_pm_wali', 'profil_murid_asesmen_simpan', { student_id: 's_pm_1' });
  check('T6 kosong -> 400', r.status === 400, r.status);
  r = await post('uji_pm_wali', 'profil_murid_asesmen_simpan', { student_id: 's_pm_1', riasec_i: 70, riasec_e: 90, cita_cita: 'Pengusaha', rencana_lulus: 'WIRAUSAHA', mapel_termudah: ['Mapel Uji PM Dua'] });
  check('T6 simpan manual', r.status === 200 && r.json.aksi === 'UBAH' && r.json.asesmen.riasec_kode === 'EI' && r.json.asesmen.sumber === 'MANUAL' && r.json.asesmen.diubah_oleh_nama === 'Uji PM Wali A', r.json);
  r = await post('uji_pm_wali', 'profil_murid_asesmen_simpan', { student_id: 's_pm_1', semester: 2, cita_cita: 'Guru' });
  check('T6 simpan semester genap (TA aktif)', r.status === 200 && r.json.aksi === 'BUAT' && r.json.asesmen.semester === 'GENAP', r.json);
  r = await profil('uji_pm_wali', 's_pm_1');
  check('T6 riwayat 2 periode, terbaru dulu', r.json.asesmen.length === 2 && r.json.asesmen[0].semester === 'GENAP', r.json.asesmen.map((x) => x.semester));
  r = await post('uji_pm_wali', 'profil_murid_asesmen_simpan', { student_id: 's_pm_4', cita_cita: 'X' });
  check('T6 wali murid nonaktif -> 403', r.status === 403, r.status);
  r = await post('uji_pm_bk', 'profil_murid_asesmen_simpan', { student_id: 's_pm_4', cita_cita: 'Arsitek', actor: 'palsu' });
  check('T6 BK murid nonaktif -> 200', r.status === 200 && r.json.asesmen.dibuat_oleh_nama === 'Uji PM Guru BK', r.json);
  if (process.env.UJI_MYSQL) {
    const logs = JSON.parse(sql("SELECT logs FROM student_asesmen WHERE student_id='s_pm_1' AND semester='GANJIL'"));
    check('T6 log aksi', logs.map((l) => l.aksi).join(',') === 'IMPOR,IMPOR_TIMPA,UBAH' && logs[2].oleh === 'uji_pm_wali', logs);
  } else skip('T6 log aksi', 'UJI_MYSQL kosong');
  r = await post('uji_pm_wali', 'profil_murid_asesmen_hapus', { student_id: 's_pm_1', academic_year: '2025/2026', semester: 'GENAP' });
  check('T6 hapus TA lain -> 400', r.status === 400, r.status);
  r = await post('uji_pm_wali', 'profil_murid_asesmen_hapus', { student_id: 's_pm_1', academic_year: '2026/2027', semester: 'GENAP' });
  check('T6 hapus 200', r.status === 200, r.text);
  r = await post('uji_pm_wali', 'profil_murid_asesmen_hapus', { student_id: 's_pm_1', academic_year: '2026/2027', semester: 'GENAP' });
  check('T6 hapus ulang -> 404', r.status === 404, r.status);

  // ---- T7 Isi ringkasan ----
  r = await profil('uji_pm_bk', 's_pm_1');
  const p = r.json;
  const ov = await call('uji_pm_wali', 'get_class_grade_overview', { query: q({ class_id: 'UJI_PM_A', semester: '1' }) });
  const ovRow = ov.json?.students.find((s) => s.student_id === 's_pm_1');
  const sama = ovRow && p.akademik.semester_aktif.every((s) => {
    const g = ovRow.grades[s.subject_id];
    return g && g.ref_score === s.ref_score && g.below_kkm === s.below_kkm && g.missing_tugas === s.missing_tugas && g.missing_kuis === s.missing_kuis;
  });
  check('T7 akademik identik dengan Pantau Nilai', ov.status === 200 && sama && p.akademik.semester_aktif.length === ov.json.subjects.length, [p.akademik.semester_aktif, ovRow?.grades]);
  const m1 = p.akademik.semester_aktif.find((s) => s.subject_id === 'UJI_PM_M1');
  check('T7 M1 di bawah KKTP + kuis tertinggal', m1?.ref_score === 60 && m1.below_kkm && m1.missing_kuis === 1 && m1.ref_is_provisional, m1);
  const tren = p.akademik.tren;
  const tM1 = tren.mapel.find((s) => s.subject_id === 'UJI_PM_M1');
  check('T7 tren dua periode termasuk kelas lama', tren.periode.join('|') === '2025/2026 GENAP|2026/2027 GANJIL' && tM1?.nilai.join('|') === '68|60', tren);
  check('T7 tren rata-rata', tren.rata_rata.join('|') === '68|75', tren.rata_rata);
  check('T7 target & kompetensi belum tersedia', p.akademik.target.tersedia === false && p.kompetensi.tersedia === false);
  const h = p.kehadiran.harian;
  check('T7 kehadiran harian', h.hadir === 2 && h.alfa === 1 && h.izin === 1 && h.sakit === 0 && h.persen_hadir === 50 && h.per_bulan.length === 2, h);
  const pm = Object.fromEntries(p.kehadiran.per_mapel.map((x) => [x.subject_id, x]));
  check('T7 kehadiran per mapel (tanpa draf)', pm.UJI_PM_M1?.hadir === 1 && pm.UJI_PM_M1?.alfa === 1 && pm.UJI_PM_M1?.pertemuan === 2 && pm.UJI_PM_M2?.izin === 1 && pm.UJI_PM_M2?.pertemuan === 1, p.kehadiran.per_mapel);
  check('T7 tidak hadir terakhir', p.kehadiran.tidak_hadir_terakhir.map((x) => x.tanggal).join('|') === '2026-08-11|2026-08-10', p.kehadiran.tidak_hadir_terakhir);
  check('T7 catatan guru TA aktif tanpa draf', p.riwayat.catatan_guru.map((c) => c.catatan).join('|') === 'Aktif bertanya|Perlu bimbingan <b>pecahan</b>', p.riwayat.catatan_guru);
  check('T7 pelanggaran TA aktif', p.riwayat.pelanggaran.daftar.length === 1 && p.riwayat.pelanggaran.rekap[0]?.type === 'LATE' && p.riwayat.pelanggaran.rekap[0]?.poin === 5 && p.murid.total_poin === 5, p.riwayat.pelanggaran);
  check('T7 panggilan ortu & ekskul', p.riwayat.panggilan_ortu.length === 1 && p.riwayat.ekskul[0]?.name === 'Ekskul Uji PM', p.riwayat);
  check('T7 dispensasi berupa array', Array.isArray(p.riwayat.dispensasi));

  // ---- T8 Daftar ----
  r = await call('uji_pm_wali', 'profil_murid_daftar');
  check('T8 wali default kelasnya', r.status === 200 && r.json.kelas.id === 'UJI_PM_A' && r.json.murid.map((x) => x.student_id).join(',') === 's_pm_2,s_pm_1,s_pm_3', r.json);
  const d1 = r.json?.murid.find((x) => x.student_id === 's_pm_1');
  check('T8 flag daftar', d1?.ada_biodata === true && d1?.ada_asesmen_aktif === true && d1?.poin === 5 && d1?.persen_hadir === 50, d1);
  r = await call('uji_pm_wali', 'profil_murid_daftar', { query: q({ q: 'Uji PM' }) });
  check('T8 wali cari lintas kelas -> 403', r.status === 403, r.status);
  r = await call('uji_pm_bk', 'profil_murid_daftar', { query: q({ q: 'ab' }) });
  check('T8 q < 3 -> 400', r.status === 400, r.status);
  r = await call('uji_pm_bk', 'profil_murid_daftar');
  check('T8 BK tanpa class_id -> 400', r.status === 400, r.status);
  r = await call('uji_pm_bk', 'profil_murid_daftar', { query: q({ q: 'Uji PM' }) });
  check('T8 BK cari termasuk nonaktif', r.status === 200 && r.json.murid.some((x) => x.student_id === 's_pm_4' && x.aktif === false) && r.json.murid.some((x) => x.student_id === 's_pm_5'), r.json?.murid?.length);
  r = await call('uji_pm_bk', 'profil_murid_daftar', { query: q({ class_id: 'kelas uji pm b' }) });
  check('T8 BK class_id berupa nama', r.status === 200 && r.json.kelas.id === 'UJI_PM_B', r.json?.kelas);
  r = await call('uji_pm_guru', 'profil_murid_daftar', { query: q({ class_id: 'UJI_PM_A' }) });
  check('T8 guru mapel -> 403', r.status === 403, r.status);

  console.log(`\nRingkasan: ${pass} lulus, ${failed} gagal, ${skipped} dilewati`);
  process.exit(failed > 0 ? 1 : 0);
}

main().catch((e) => { console.error(e); process.exit(2); });
