// Uji API mode verifikasi jurnal (docs/specs/verifikasi-jurnal-otomatis.md §9.3).
// HANYA untuk dev/staging: skrip membuat, mengubah, memverifikasi, dan menghapus jurnal uji (tanggal
// TEST_DATE, kelas "UJI", tanpa presensi), serta mengubah mode verifikasi lalu mengembalikannya.
// PERINGATAN: mengubah mode ke OTOMATIS mengesahkan SEMUA jurnal Pending di database. Jalankan saat
// mode OTOMATIS (tidak ada Pending, dicek T1); jangan saat ada guru lain mengirim jurnal.
// Prasyarat: akun uji_admin (ADMIN), uji_ks (KEPALA_SEKOLAH), uji_guru dan uji_wali (GURU),
// email <uid>@uji.test, password UJI_PASSWORD — lihat docs/patches/verifikasi-jurnal-otomatis.md.
// Jalankan: BASE=... UJI_PASSWORD=... node docs/patches/uji-verifikasi-otomatis-api.mjs
const BASE = process.env.BASE || 'http://localhost/simantap/api/index.php';
const TEST_DATE = process.env.TEST_DATE || '2020-02-03';
let pass = 0, failed = 0;
const jars = {};
const dibuat = new Set();

async function call(who, action, { method = 'GET', body = null, query = '' } = {}) {
  const headers = { 'Content-Type': 'application/json' };
  if (who && jars[who]) headers.Cookie = jars[who];
  const res = await fetch(`${BASE}?action=${action}${query}`, { method, headers, body: body ? JSON.stringify(body) : undefined });
  const setCookie = res.headers.get('set-cookie');
  if (who && setCookie) jars[who] = setCookie.split(';')[0];
  const text = await res.text();
  let json = null; try { json = JSON.parse(text); } catch {}
  return { status: res.status, json, text };
}
function check(id, cond, info = '') {
  if (cond) { pass++; console.log(`PASS ${id}`); }
  else { failed++; console.log(`FAIL ${id} ${info}`); }
}
async function login(who) {
  const r = await call(who, 'login', { method: 'POST', body: { email: `${who}@uji.test`, password: process.env.UJI_PASSWORD || 'uji12345' } });
  if (r.status !== 200) throw new Error(`login ${who} gagal: ${r.status} ${r.text}`);
  return r.json.user;
}
let seq = 0;
const bodyJurnal = (id, extra = {}) => ({
  id, date: TEST_DATE, classId: 'UJI', className: 'Kelas Uji', subjectId: 'UJI', subjectName: 'Mapel Uji',
  time: '07:30', topic: `Topik uji ${id}`, activities: 'uji', attendance: {}, isDraft: false, isVerified: false, ...extra,
});
const simpan = (who, id, extra) => call(who, 'save_journal', { method: 'POST', body: bodyJurnal(id, extra) });
async function buat(who, extra = {}) {
  const id = `j_uji_verif_${Date.now()}_${++seq}`;
  const r = await simpan(who, id, extra);
  if (r.status !== 200) throw new Error(`save_journal ${who} gagal: ${r.status} ${r.text.slice(0, 300)}`);
  dibuat.add(id);
  return id;
}
async function jurnal(id) {
  const r = await call('uji_ks', 'get_journals');
  return (r.json || []).find((j) => j.id === id) || null;
}
const setMode = (who, mode, konfirmasi) => call(who, 'save_system_config', {
  method: 'POST', body: { configs: { journal_verification_mode: mode }, ...(konfirmasi ? { konfirmasi_verifikasi: true } : {}) },
});
const dashboard = async () => (await call('uji_ks', 'get_dashboard_analytics')).json || {};

for (const u of ['uji_admin', 'uji_ks', 'uji_guru', 'uji_wali']) await login(u);
const cfgAwal = (await call(null, 'get_config')).json || {};
const modeAwal = cfgAwal.journal_verification_mode;
const kepsek = String(cfgAwal.kop_kepsek_nama || '').trim();

try {
  // T1 migrasi awal & mode default
  check('T1 get_config mode OTOMATIS', modeAwal === 'OTOMATIS', `mode=${modeAwal}`);
  check('T1 penanda migrasi ada', !!cfgAwal.journal_verification_migrated);
  let d = await dashboard();
  check('T1 dashboard OTOMATIS, belum_verifikasi 0', d.mode_verifikasi_jurnal === 'OTOMATIS' && d.jurnal_belum_verifikasi === 0, JSON.stringify(d));
  check('T1 kop_kepsek_nama terisi (prasyarat T7)', kepsek !== '');

  // T2 draf tidak SAH, kirim → SAH SISTEM
  const j1 = await buat('uji_guru', { isDraft: true });
  let j = await jurnal(j1);
  check('T2 draf isVerified=false', j && j.isDraft === true && j.isVerified === false, JSON.stringify(j && { d: j.isDraft, v: j.isVerified }));
  let r = await simpan('uji_guru', j1, { isDraft: false });
  j = await jurnal(j1);
  check('T2 kirim → SAH SISTEM', r.status === 200 && j.isVerified === true && j.verifiedBy === 'SISTEM' && !!j.verifiedAt, JSON.stringify(j && { v: j.isVerified, by: j.verifiedBy, at: j.verifiedAt }));
  const at1 = j.verifiedAt;

  // T3 edit jurnal SAH oleh pemilik
  await new Promise((res) => setTimeout(res, 1100));
  r = await simpan('uji_guru', j1, { topic: 'Topik diedit' });
  j = await jurnal(j1);
  check('T3 edit SAH → 200, tetap SAH, verifiedAt sama', r.status === 200 && j.topic === 'Topik diedit' && j.isVerified && j.verifiedAt === at1 && j.isDraft === false, `${r.status} ${JSON.stringify(j && { t: j.topic, at: j.verifiedAt })}`);

  // T4 body mencoba draf/membatalkan verifikasi
  r = await simpan('uji_guru', j1, { isDraft: true, isVerified: false, verifiedBy: 'x', verifiedAt: null });
  j = await jurnal(j1);
  check('T4 body diabaikan, isDraft dipaksa 0', r.status === 200 && j.isVerified && j.isDraft === false && j.verifiedBy === 'SISTEM' && j.verifiedAt === at1);

  // T5 guru lain
  r = await simpan('uji_wali', j1, { topic: 'bajak' });
  check('T5 edit jurnal guru lain → 403', r.status === 403, String(r.status));
  r = await call('uji_wali', 'delete_journal', { method: 'POST', query: `&id=${j1}` });
  check('T5 hapus jurnal guru lain → 403', r.status === 403, String(r.status));

  // T6 hapus jurnal SAH milik sendiri
  const j2 = await buat('uji_guru');
  r = await call('uji_guru', 'delete_journal', { method: 'POST', query: `&id=${j2}` });
  check('T6 hapus jurnal SAH sendiri → 200', r.status === 200 && !(await jurnal(j2)), String(r.status));
  dibuat.delete(j2);

  // T7 nama pengesah SISTEM = kepala sekolah
  j = await jurnal(j1);
  check('T7 verifiedByName SISTEM = kop_kepsek_nama', j.verifiedByName === kepsek, `"${j.verifiedByName}"`);

  // T8 Kepsek tidak boleh mengubah mode
  r = await setMode('uji_ks', 'MANUAL');
  check('T8 KEPALA_SEKOLAH ubah mode → 403', r.status === 403, String(r.status));

  // T9 mode MANUAL = perilaku lama
  r = await setMode('uji_admin', 'MANUAL');
  check('T9 ADMIN → MANUAL 200', r.status === 200, r.text);
  check('T9 get_config MANUAL', (await call(null, 'get_config')).json.journal_verification_mode === 'MANUAL');
  const j3 = await buat('uji_guru');
  j = await jurnal(j3);
  check('T9 jurnal baru Pending', j.isVerified === false && !j.verifiedBy);
  r = await simpan('uji_guru', j1, { topic: 'edit di manual' });
  check('T9 edit jurnal SAH lama → 403', r.status === 403 && /sudah diverifikasi/.test(r.text), `${r.status} ${r.text}`);
  r = await call('uji_guru', 'delete_journal', { method: 'POST', query: `&id=${j1}` });
  check('T9 hapus jurnal SAH lama → 403', r.status === 403, String(r.status));
  d = await dashboard();
  check('T9 dashboard MANUAL, belum_verifikasi ≥ 1', d.mode_verifikasi_jurnal === 'MANUAL' && d.jurnal_belum_verifikasi >= 1, JSON.stringify(d));
  r = await call('uji_ks', 'verify_journal', { method: 'POST', body: { id: j3 } });
  j = await jurnal(j3);
  check('T9 verifikasi manual → verifiedByName "Uji KS"', r.status === 200 && j.isVerified && j.verifiedByName === 'Uji KS', JSON.stringify(j && { by: j.verifiedBy, n: j.verifiedByName }));
  const j4 = await buat('uji_guru');

  // T10 MANUAL → OTOMATIS tanpa konfirmasi
  r = await setMode('uji_admin', 'OTOMATIS');
  check('T10 → OTOMATIS tanpa konfirmasi 409', r.status === 409 && r.json && r.json.peringatan_verifikasi && r.json.peringatan_verifikasi.pending === 1, `${r.status} ${r.text}`);
  j = await jurnal(j4);
  check('T10 data & mode tidak berubah', j.isVerified === false && (await call(null, 'get_config')).json.journal_verification_mode === 'MANUAL');

  // T11 dengan konfirmasi
  r = await setMode('uji_admin', 'OTOMATIS', true);
  j = await jurnal(j4);
  check('T11 konfirmasi → 200, Pending jadi SAH SISTEM', r.status === 200 && j.isVerified && j.verifiedBy === 'SISTEM', `${r.status} ${r.text}`);
  r = await simpan('uji_guru', j3, { topic: 'edit lagi di otomatis' });
  j = await jurnal(j3);
  check('T11 jurnal yang diverifikasi Kepsek bisa diedit, verifiedBy tetap', r.status === 200 && j.verifiedByName === 'Uji KS');

  // T12 validasi nilai & penanda
  r = await setMode('uji_admin', 'X');
  check('T12 mode tidak valid → 400', r.status === 400, String(r.status));
  const penanda = (await call(null, 'get_config')).json.journal_verification_migrated;
  r = await call('uji_admin', 'save_system_config', { method: 'POST', body: { configs: { journal_verification_migrated: '1999-01-01 00:00:00' } } });
  check('T12 penanda migrasi tidak bisa diubah', r.status === 200 && (await call(null, 'get_config')).json.journal_verification_migrated === penanda);

  // T13 save_config legacy
  r = await call('uji_admin', 'save_config', { method: 'POST', body: { config_key: 'journal_verification_mode', config_value: 'MANUAL' } });
  check('T13 save_config key mode → 400', r.status === 400, String(r.status));
  check('T13 mode tetap OTOMATIS', (await call(null, 'get_config')).json.journal_verification_mode === 'OTOMATIS');
} finally {
  for (const id of dibuat) await call('uji_admin', 'delete_journal', { method: 'POST', query: `&id=${id}` });
  if (modeAwal && (await call(null, 'get_config')).json.journal_verification_mode !== modeAwal) {
    await setMode('uji_admin', modeAwal, true);
  }
  console.log(`\n${pass} lulus, ${failed} gagal`);
  process.exitCode = failed ? 1 : 0;
}
