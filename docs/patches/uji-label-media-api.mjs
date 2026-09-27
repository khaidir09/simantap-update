// Uji API label Media / Alat Pembelajaran di form jurnal (media_label_list/simpan/hapus).
// HANYA untuk dev/staging. Jalankan uji-presensi-harian-seed.sql dulu (akun uji_ph_*), lalu
// uji-presensi-harian-cleanup.sql dan uji-label-media-cleanup.sql setelahnya.
// Jalankan dari root deploy: BASE=http://localhost/simantap/api/index.php node docs/patches/uji-label-media-api.mjs
const BASE = process.env.BASE || 'http://localhost/simantap/api/index.php';
let pass = 0, failed = 0;
const jars = {};

async function call(who, action, { method = 'GET', body = null } = {}) {
  const headers = { 'Content-Type': 'application/json' };
  if (jars[who]) headers.Cookie = jars[who];
  const res = await fetch(`${BASE}?action=${action}`, { method, headers, body: body ? JSON.stringify(body) : undefined });
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
const daftar = async (who) => (await call(who, 'media_label_list')).json;
const ada = (d, l) => [...d.bawaan, ...d.milik].some((x) => x.toLowerCase() === l.toLowerCase());

(async () => {
  check('L1 list tanpa login ditolak', (await call('anon', 'media_label_list')).status === 401);
  await login('guru');
  await login('wali');

  let d = await daftar('guru');
  check('L2 list berisi label bawaan', Array.isArray(d.bawaan) && d.bawaan.includes('Papan Tulis') && Array.isArray(d.milik));

  check('L3 simpan via GET ditolak', (await call('guru', 'media_label_simpan')).status === 405);
  check('L4 simpan label kosong ditolak', (await call('guru', 'media_label_simpan', { method: 'POST', body: { label: '  ' } })).status === 400);

  const r = await call('guru', 'media_label_simpan', { method: 'POST', body: { label: '  Kit   Robotik Uji ' } });
  check('L5 simpan label manual', r.status === 200 && r.json.label === 'Kit Robotik Uji', r.text);
  d = await daftar('guru');
  check('L6 label manual muncul di daftar guru', d.milik.includes('Kit Robotik Uji'));
  check('L7 label guru lain tidak ikut', !ada(await daftar('wali'), 'Kit Robotik Uji'));

  await call('guru', 'media_label_simpan', { method: 'POST', body: { label: 'kit robotik uji' } });
  d = await daftar('guru');
  check('L8 label beda huruf besar tidak dobel', d.milik.filter((x) => x.toLowerCase() === 'kit robotik uji').length === 1);

  await call('guru', 'media_label_hapus', { method: 'POST', body: { label: 'Kit Robotik Uji' } });
  check('L9 label disembunyikan', !ada(await daftar('guru'), 'Kit Robotik Uji'));
  await call('guru', 'media_label_hapus', { method: 'POST', body: { label: 'Speaker' } });
  check('L10 label bawaan bisa disembunyikan', !(await daftar('guru')).bawaan.includes('Speaker'));
  check('L11 label bawaan guru lain tetap', (await daftar('wali')).bawaan.includes('Speaker'));

  await call('guru', 'media_label_simpan', { method: 'POST', body: { label: 'Kit Robotik Uji' } });
  check('L12 diketik ulang -> muncul lagi', (await daftar('guru')).milik.includes('Kit Robotik Uji'));

  const j = await call('guru', 'save_journal', { method: 'POST', body: {
    id: 'j_uji_ph_media', date: '2099-02-11', classId: 'k_uji_ph', className: 'X UJI PH', subjectId: 'm_uji', subjectName: 'Mapel',
    time: '07:30', jam: '1', topic: 'uji', activities: 'uji', attendance: {}, isDraft: true,
    alatMedia: 'Papan Tulis, Globe Uji Dari Jurnal',
  } });
  check('L13 simpan jurnal dengan media', j.status === 200, j.text.slice(0, 200));
  d = await daftar('guru');
  check('L14 media dari jurnal jadi label', d.milik.includes('Globe Uji Dari Jurnal') && !d.milik.includes('Papan Tulis'));

  console.log(`\n${pass} lulus, ${failed} gagal`);
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
