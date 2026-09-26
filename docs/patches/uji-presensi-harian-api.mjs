// Uji API presensi harian dari jurnal (save_journal, delete_journal, save_attendance_records).
// HANYA untuk dev/staging. Jalankan uji-presensi-harian-seed.sql dulu, lalu cleanup setelahnya.
// Jalankan: BASE=http://localhost/simantap/api/index.php node docs/patches/uji-presensi-harian-api.mjs
const BASE = process.env.BASE || 'http://localhost/simantap/api/index.php';
const TGL = '2099-01-06';
const SID = 's_uji_ph_1';
let pass = 0, failed = 0, cookie = '';

async function call(action, { method = 'GET', body = null, query = '' } = {}) {
  const headers = { 'Content-Type': 'application/json' };
  if (cookie) headers.Cookie = cookie;
  const res = await fetch(`${BASE}?action=${action}${query}`, { method, headers, body: body ? JSON.stringify(body) : undefined });
  const setCookie = res.headers.get('set-cookie');
  if (setCookie) cookie = setCookie.split(';')[0];
  const text = await res.text();
  let json = null; try { json = JSON.parse(text); } catch {}
  return { status: res.status, json, text };
}
function check(id, cond, info = '') {
  if (cond) { pass++; console.log(`PASS ${id}`); }
  else { failed++; console.log(`FAIL ${id} ${info}`); }
}
async function jurnal(id, jam, status, isDraft = false) {
  const r = await call('save_journal', { method: 'POST', body: {
    id, date: TGL, classId: 'k_uji_ph', className: 'X UJI PH', subjectId: 'm_uji', subjectName: `Mapel${jam}`,
    time: '07:30', jam_ke: String(jam), topic: 'uji', activities: 'uji', attendance: { [SID]: status }, isDraft,
  } });
  if (r.status !== 200) throw new Error(`save_journal ${id}: ${r.status} ${r.text.slice(0, 300)}`);
}
async function harian() {
  const r = await call('get_attendance_records', { query: `&classId=k_uji_ph&date=${TGL}` });
  return (r.json || []).find((x) => x.student_id === SID) || null;
}
async function adaInsidenAlfa() {
  const r = await call('get_incidents');
  return (Array.isArray(r.json) ? r.json : []).some((x) => x.id === `inc_att_${SID}_${TGL}`);
}
async function insidenTjp() {
  const r = await call('get_incidents');
  return (Array.isArray(r.json) ? r.json : []).find((x) => x.id === `inc_tjp_${SID}_${TGL}`) || null;
}
async function simpanManual(status) {
  return call('save_attendance_records', { method: 'POST', body: { records: [
    { student_id: SID, student_name: 'Siswa Uji PH', class_id: 'k_uji_ph', class_name: 'X UJI PH', date: TGL, status },
  ] } });
}

const login = await call('login', { method: 'POST', body: { email: 'uji_ph_admin@uji.test', password: process.env.UJI_PASSWORD || 'uji12345' } });
if (login.status !== 200) { console.error(`login gagal: ${login.status} ${login.text}`); process.exit(2); }

try {
  await jurnal('j_uji_ph_h1', 1, 'HADIR');
  let h = await harian();
  check('A1 jurnal jam 1 HADIR -> harian HADIR', h?.status === 'HADIR' && h?.source === 'JURNAL', JSON.stringify(h));

  await jurnal('j_uji_ph_h3', 3, 'ALFA');
  h = await harian();
  check('A2 jurnal jam 3 ALFA tidak menimpa', h?.status === 'HADIR' && String(h?.notes).includes('jam 3'), JSON.stringify(h));
  check('A2 tanpa insiden ALFA', !(await adaInsidenAlfa()));
  let tjp = await insidenTjp();
  check('A2 insiden TINGGAL_JP dibuat', tjp && String(tjp.details).includes('jam 3 (Mapel3)'), JSON.stringify(tjp));

  let r = await call('delete_journal', { query: '&id=j_uji_ph_h1' });
  h = await harian();
  check('A3 hapus jurnal jam 1 -> ALFA + insiden', r.status === 200 && h?.status === 'ALPHA' && (await adaInsidenAlfa()), JSON.stringify(h));
  check('A3 TINGGAL_JP dicabut', !(await insidenTjp()));

  await jurnal('j_uji_ph_h3', 3, 'IZIN');
  h = await harian();
  check('A4 edit jurnal jam 3 jadi IZIN -> IZIN, insiden dicabut', h?.status === 'IZIN' && !(await adaInsidenAlfa()), JSON.stringify(h));

  r = await simpanManual('ALPHA');
  h = await harian();
  check('A5 wali kelas MANUAL ALFA + insiden', r.status === 200 && h?.status === 'ALPHA' && h?.source === 'MANUAL' && (await adaInsidenAlfa()), r.text);

  await jurnal('j_uji_ph_h5', 5, 'HADIR');
  h = await harian();
  check('A6 jurnal HADIR tidak menimpa MANUAL & insiden tetap', h?.status === 'ALPHA' && h?.source === 'MANUAL' && (await adaInsidenAlfa()), JSON.stringify(h));

  await jurnal('j_uji_ph_h5', 5, 'HADIR', true);
  h = await harian();
  check('A7 jurnal dijadikan draf lagi tidak mengubah MANUAL', h?.source === 'MANUAL');

  await jurnal('j_uji_ph_h5', 5, 'ALFA');
  r = await simpanManual('HADIR');
  tjp = await insidenTjp();
  check('A8 MANUAL HADIR + jam 5 ALFA -> TINGGAL_JP', r.status === 200 && tjp && String(tjp.details).includes('jam 5 (Mapel5)') && !(await adaInsidenAlfa()), JSON.stringify(tjp));

  r = await simpanManual('IZIN');
  check('A9 MANUAL IZIN mencabut TINGGAL_JP', r.status === 200 && !(await insidenTjp()));
} catch (e) {
  failed++;
  console.log(`ERROR ${e.message}`);
}
console.log(`\n${pass} lulus, ${failed} gagal`);
process.exit(failed ? 1 : 0);
