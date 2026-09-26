// Uji API dispensasi siswa (dispensasi_* dan pengaruhnya ke presensi harian).
// HANYA untuk dev/staging. Jalankan uji-presensi-harian-seed.sql dulu, lalu cleanup setelahnya.
// Jalankan dari root deploy: BASE=http://localhost/simantap/api/index.php node docs/patches/uji-dispensasi-api.mjs
import fs from 'fs';
import path from 'path';

const BASE = process.env.BASE || 'http://localhost/simantap/api/index.php';
const ROOT = path.resolve(path.dirname(new URL(import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1')), '..', '..');
const TGL = '2099-02-03';
const S1 = 's_uji_ph_1', S2 = 's_uji_ph_2', S9 = 's_uji_ph_9';
let pass = 0, failed = 0;
const jars = {};

async function call(who, action, { method = 'GET', body = null, query = '', form = null } = {}) {
  const headers = {};
  if (!form) headers['Content-Type'] = 'application/json';
  if (jars[who]) headers.Cookie = jars[who];
  const res = await fetch(`${BASE}?action=${action}${query}`, { method, headers, body: form || (body ? JSON.stringify(body) : undefined) });
  const setCookie = res.headers.get('set-cookie');
  if (setCookie) jars[who] = setCookie.split(';')[0];
  const text = await res.text();
  let json = null; try { json = JSON.parse(text); } catch {}
  return { status: res.status, json, text };
}
function check(id, cond, info = '') {
  if (cond) { pass++; console.log(`PASS ${id}`); }
  else { failed++; console.log(`FAIL ${id} ${info}`); }
}
async function login(who) {
  const r = await call(who, 'login', { method: 'POST', body: { email: `uji_ph_${who}@uji.test`, password: process.env.UJI_PASSWORD || 'uji12345' } });
  if (r.status !== 200) throw new Error(`login ${who} gagal: ${r.status} ${r.text}`);
}
const simpan = (who, extra = {}) => call(who, 'dispensasi_simpan', { method: 'POST', body: {
  student_ids: [S1], tanggal_mulai: TGL, tanggal_selesai: TGL, jam_ke_mulai: 3, jam_ke_selesai: 8,
  kegiatan: 'Lomba OSN Uji', nomor_surat: '421/UJI/2099', ...extra,
} });
const daftar = (who, q = '') => call(who, 'dispensasi_list', { query: `&dari=${TGL}&sampai=${TGL}${q}` });
async function jurnal(id, jam, att) {
  const r = await call('guru', 'save_journal', { method: 'POST', body: {
    id, date: TGL, classId: 'k_uji_ph', className: 'X UJI PH', subjectId: 'm_uji', subjectName: `Mapel${jam}`,
    time: '07:30', jam: String(jam), topic: 'uji', activities: 'uji', attendance: att, isDraft: false,
  } });
  if (r.status !== 200) throw new Error(`save_journal ${id}: ${r.status} ${r.text.slice(0, 300)}`);
}
async function harian(sid) {
  const r = await call('admin', 'get_attendance_records', { query: `&classId=k_uji_ph&date=${TGL}` });
  return (r.json || []).find((x) => x.student_id === sid) || null;
}
async function insidenTjp(sid) {
  const r = await call('admin', 'get_incidents');
  return (Array.isArray(r.json) ? r.json : []).find((x) => x.id === `inc_tjp_${sid}_${TGL}`) || null;
}

for (const u of ['admin', 'wali', 'guru', 'bk', 'tu']) await login(u);

try {
  // --- Akses ---
  let r = await daftar('guru');
  check('A1 guru biasa ditolak dari daftar', r.status === 403, r.text);
  r = await daftar('wali');
  check('A2 wali kelas melihat daftar, mode CLASS', r.status === 200 && r.json.scope.mode === 'CLASS', r.text);
  r = await daftar('tu');
  check('A3 TU mode ALL', r.status === 200 && r.json.scope.mode === 'ALL', r.text);
  r = await daftar('bk');
  check('A4 BK mode ALL', r.status === 200 && r.json.scope.mode === 'ALL', r.text);
  r = await call('wali', 'dispensasi_siswa', { query: '&class_id=k_uji_ph' });
  check('A5 wali memuat siswa kelasnya', r.status === 200 && r.json.students.some((s) => s.id === S1) && !r.json.students.some((s) => s.id === S9), r.text);
  r = await call('wali', 'dispensasi_siswa', { query: '&class_id=k_uji_lain' });
  check('A6 wali ditolak untuk kelas lain', r.status === 403);
  r = await simpan('wali', { student_ids: [S9] });
  check('A7 wali tidak bisa input siswa kelas lain', r.status === 403, r.text);
  r = await simpan('guru');
  check('A8 guru biasa tidak bisa input', r.status === 403);
  r = await call('wali', 'dispensasi_simpan', { body: null, query: '' });
  check('A9 GET ke dispensasi_simpan ditolak 405', r.status === 405, String(r.status));

  // --- Validasi ---
  r = await simpan('wali', { kegiatan: '' });
  check('V1 kegiatan kosong ditolak', r.status === 400 && /kegiatan/i.test(r.json?.error || ''), r.text);
  r = await simpan('wali', { jam_ke_mulai: 8, jam_ke_selesai: 3 });
  check('V2 jam terbalik ditolak', r.status === 400);
  r = await simpan('wali', { surat_path: 'uploads/tu/dispensasi/209902/' + 'a'.repeat(32) + '.pdf' });
  check('V3 surat yang tidak ada ditolak', r.status === 400, r.text);

  // --- Unggah surat ---
  const pdf = new Blob(['%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n'], { type: 'application/pdf' });
  let fd = new FormData(); fd.append('file', pdf, 'surat.php');
  r = await call('wali', 'dispensasi_unggah_surat', { method: 'POST', form: fd });
  const suratPath = r.json?.path || '';
  check('U1 unggah PDF, ekstensi dari isi (bukan .php)', r.status === 200 && /^uploads\/tu\/dispensasi\/\d{6}\/[a-f0-9]{32}\.pdf$/.test(suratPath), r.text);
  check('U2 berkas tersimpan', suratPath && fs.existsSync(path.join(ROOT, suratPath)));
  fd = new FormData(); fd.append('file', new Blob(['<?php echo 1;'], { type: 'image/png' }), 'x.png');
  r = await call('wali', 'dispensasi_unggah_surat', { method: 'POST', form: fd });
  check('U3 isi PHP berlabel PNG ditolak', r.status === 400, r.text);
  fd = new FormData(); fd.append('file', pdf, 'surat.pdf');
  r = await call('guru', 'dispensasi_unggah_surat', { method: 'POST', form: fd });
  check('U4 guru biasa tidak bisa unggah', r.status === 403);

  // --- Jurnal: HADIR jam 1, ALFA jam 3-4 -> TINGGAL_JP; dispensasi susulan mencabutnya ---
  await jurnal('j_uji_ph_d1', '1', { [S1]: 'HADIR', [S2]: 'HADIR' });
  await jurnal('j_uji_ph_d3', '3-4', { [S1]: 'ALFA', [S2]: 'HADIR' });
  check('J1 sebelum dispensasi: TINGGAL_JP', !!(await insidenTjp(S1)));

  r = await simpan('wali', { surat_path: suratPath });
  check('J2 wali menyimpan dispensasi jam 3-8', r.status === 200 && r.json.ids.length === 1, r.text);
  const idDisp = r.json?.ids?.[0];
  check('J3 dispensasi susulan mencabut TINGGAL_JP', !(await insidenTjp(S1)));
  const h = await harian(S1);
  check('J4 presensi harian HADIR + catatan dispensasi', h?.status === 'HADIR' && String(h?.notes).includes('DISPENSASI (Lomba OSN Uji)'), JSON.stringify(h));

  r = await daftar('wali');
  const baris = (r.json?.items || []).find((x) => x.id === idDisp);
  check('J5 daftar menampilkan dispensasi', baris && baris.cakupan === 'Jam ke-3 s.d. 8' && baris.surat_path === suratPath && baris.dicatat_oleh_nama === 'Wali Uji PH', JSON.stringify(baris));

  // --- Lookup form jurnal (guru mana pun) ---
  r = await call('guru', 'dispensasi_jurnal', { query: `&class_id=k_uji_ph&date=${TGL}&jam=5-6` });
  check('L1 jam 5-6 dicakup', r.status === 200 && r.json.items[S1]?.kegiatan === 'Lomba OSN Uji' && !r.json.items[S2], r.text);
  r = await call('guru', 'dispensasi_jurnal', { query: `&class_id=k_uji_ph&date=${TGL}&jam=1-2` });
  check('L2 jam 1-2 tidak dicakup', r.status === 200 && !r.json.items[S1], r.text);
  r = await call('guru', 'dispensasi_jurnal', { query: `&class_id=k_uji_lain&date=${TGL}&jam=5` });
  check('L3 kelas lain kosong', r.status === 200 && Object.keys(r.json.items).length === 0, r.text);

  // --- Jurnal dengan status D dari form ---
  await jurnal('j_uji_ph_d5', '5-6', { [S1]: 'DISPENSASI', [S2]: 'HADIR' });
  r = await call('admin', 'get_journals');
  const jd = (Array.isArray(r.json) ? r.json : []).find((x) => x.id === 'j_uji_ph_d5');
  check('L4 jurnal menyimpan status DISPENSASI', jd && (jd.studentAttendance || jd.attendance || {})[S1] === 'DISPENSASI', JSON.stringify(jd && jd.studentAttendance));
  check('L5 status D tidak memicu TINGGAL_JP', !(await insidenTjp(S1)));

  // --- Hapus ---
  r = await call('guru', 'dispensasi_hapus', { method: 'POST', body: { id: idDisp } });
  check('H1 guru biasa tidak bisa hapus', r.status === 403);
  r = await call('tu', 'dispensasi_hapus', { method: 'POST', body: { id: idDisp } });
  check('H2 TU menghapus dispensasi', r.status === 200, r.text);
  check('H3 TINGGAL_JP kembali setelah dispensasi dihapus', !!(await insidenTjp(S1)));
  check('H4 berkas surat yatim ikut dihapus', suratPath && !fs.existsSync(path.join(ROOT, suratPath)));

  // --- Banyak siswa, sehari penuh, rentang tanggal ---
  r = await simpan('bk', { student_ids: [S1, S2], sehari_penuh: true, tanggal_selesai: '2099-02-05' });
  check('M1 BK simpan 2 siswa sehari penuh 3 hari', r.status === 200 && r.json.ids.length === 2, r.text);
  check('M2 TINGGAL_JP dicabut oleh dispensasi sehari penuh', !(await insidenTjp(S1)));
  r = await call('bk', 'dispensasi_list', { query: '&dari=2099-02-05&sampai=2099-02-05' });
  check('M3 rentang tanggal terlihat di hari ketiga', (r.json?.items || []).filter((x) => x.cakupan === 'Sehari penuh').length === 2, r.text);
} catch (e) {
  failed++;
  console.log(`ERROR ${e.message}`);
}
console.log(`\n${pass} lulus, ${failed} gagal`);
process.exit(failed ? 1 : 0);
