// Uji API Jenjang Sekolah: deteksi default, validasi & peringatan 409 di save_system_config,
// guard aksi pkl_* di luar SMK, dan parser level di gtk_update_atribut.
// HANYA untuk dev/staging. Jalankan uji-jenjang-sekolah-seed.sql dulu (akun jj_uji_*), lalu
// uji-jenjang-sekolah-cleanup.sql setelahnya (memulihkan school_level/kop_sekolah/kop_telp).
// Jalankan dari root deploy: BASE=http://localhost/simantap/api/index.php node docs/patches/uji-jenjang-sekolah-api.mjs
const BASE = process.env.BASE || 'http://localhost/simantap/api/index.php';
const PASS = process.env.UJI_PASSWORD || 'uji12345';
const PESAN_PKL = 'Fitur Bimbingan PKL hanya tersedia untuk jenjang SMK.';
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
async function login(who, email) {
  const r = await call(who, 'login', { method: 'POST', body: { email, password: PASS } });
  if (r.status !== 200) throw new Error(`login ${who} gagal: ${r.status} ${r.text}`);
}
const config = async () => (await call('anon', 'get_config')).json || {};
const simpan = (who, configs, extra = {}) => call(who, 'save_system_config', { method: 'POST', body: { configs, ...extra } });
const levelPenugasan = async () => {
  const r = await call('admin', 'get_teacher_subjects&teacher_id=u_jj_uji_guru');
  return (Array.isArray(r.json) ? r.json : []).map((x) => x.class_level);
};

(async () => {
  await login('admin', 'jj_uji_admin@uji.test');
  await login('tu', 'jj_uji_tu@uji.test');
  await login('guru', 'jj_uji_guru@uji.test');

  // A1–A2 deteksi default dari kop_sekolah (seed: tanpa school_level, kop_sekolah "SMKN 9 UJI JENJANG")
  let c = await config();
  check('A1 get_config tanpa login: school_level terdeteksi SMK', c.school_level === 'SMK' && c.school_level_sumber === 'deteksi', JSON.stringify([c.school_level, c.school_level_sumber]));
  const sys = (await call('anon', 'get_system_config')).json || {};
  check('A2 get_system_config: school_level {value, description Terdeteksi}', sys.school_level && sys.school_level.value === 'SMK' && /Terdeteksi/.test(sys.school_level.description || ''), JSON.stringify(sys.school_level));
  check('A2 get_system_config tidak memuat school_level_sumber', !('school_level_sumber' in sys));

  // A3 hanya ADMIN
  let r = await simpan('tu', { school_level: 'SD' });
  check('A3 TATA_USAHA menyimpan jenjang → 403', r.status === 403, String(r.status));

  // A4 nilai tidak valid
  r = await simpan('admin', { school_level: 'TK', kop_telp: 'UJI-400' });
  c = await config();
  check('A4 jenjang "TK" → 400', r.status === 400 && /tidak valid/.test(r.json && r.json.error), `${r.status} ${r.text}`);
  check('A4 tidak ada yang tersimpan', c.school_level_sumber === 'deteksi' && c.kop_telp !== 'UJI-400', JSON.stringify([c.school_level_sumber, c.kop_telp]));

  // A5 ganti ke SMP dengan data level X → 409, tidak ada yang tersimpan
  r = await simpan('admin', { school_level: 'SMP', kop_telp: 'UJI-409' });
  const w = (r.json && r.json.peringatan_jenjang) || {};
  check('A5 SMK→SMP dengan data X → 409 + peringatan', r.status === 409 && w.dari === 'SMK' && w.ke === 'SMP', `${r.status} ${r.text}`);
  check('A5 hitungan kelas/penugasan/siswa ≥ 1', w.kelas >= 1 && w.penugasan >= 1 && w.siswa >= 1, JSON.stringify(w));
  c = await config();
  check('A5 kop_telp & jenjang tidak berubah', c.kop_telp !== 'UJI-409' && c.school_level === 'SMK', JSON.stringify([c.kop_telp, c.school_level]));

  // A6 konfirmasi → tersimpan
  r = await simpan('admin', { school_level: 'SMP', kop_telp: 'UJI-409' }, { konfirmasi_jenjang: true });
  c = await config();
  check('A6 dengan konfirmasi_jenjang → 200', r.status === 200, `${r.status} ${r.text}`);
  check('A6 school_level SMP (pengaturan) + kop_telp tersimpan', c.school_level === 'SMP' && c.school_level_sumber === 'pengaturan' && c.kop_telp === 'UJI-409', JSON.stringify([c.school_level, c.school_level_sumber, c.kop_telp]));
  check('A6 konfirmasi_jenjang tidak disimpan sebagai key', !('konfirmasi_jenjang' in c));

  // A7 simpan ulang jenjang yang sama (data X masih ada) tidak memicu 409
  r = await simpan('admin', { school_level: 'SMP', kop_telp: 'UJI-SAMA' });
  check('A7 jenjang tidak berubah → 200 tanpa konfirmasi', r.status === 200, `${r.status} ${r.text}`);

  // A8 aksi PKL ditolak di luar SMK
  for (const [aksi, method, body] of [
    ['pkl_konteks', 'GET'],
    ['pkl_dudi_list', 'GET'],
    ['pkl_jurnal_list', 'GET'],
    ['pkl_jurnal_simpan', 'POST', { penempatan_id: 'x' }],
    ['pkl_penempatan_simpan', 'POST', {}],
  ]) {
    r = await call('guru', aksi, { method, body });
    check(`A8 ${aksi} di SMP → 403 pesan PKL`, r.status === 403 && r.json && r.json.error === PESAN_PKL, `${r.status} ${r.text.slice(0, 120)}`);
  }
  r = await call('guru', 'pkl_cetak_rekap');
  check('A8 pkl_cetak_rekap di SMP → 403 HTML', r.status === 403 && /<html/i.test(r.text) && r.text.includes(PESAN_PKL), `${r.status} ${r.text.slice(0, 120)}`);
  r = await call('anon', 'pkl_konteks');
  check('A8 pkl_konteks tanpa login → 401', r.status === 401, String(r.status));

  // A9 parser level di gtk_update_atribut (jenjang SMP)
  r = await call('admin', 'gtk_update_atribut', { method: 'POST', body: { id: 'u_jj_uji_guru', mapel: 'Matematika Uji JJ', kelas_ajar: 'VII A, VII B' } });
  let lv = await levelPenugasan();
  check('A9 kelas_ajar "VII A, VII B" → class_level VII', r.status === 200 && lv.length === 1 && lv[0] === 'VII', `${r.status} ${JSON.stringify(lv)}`);
  r = await call('admin', 'gtk_update_atribut', { method: 'POST', body: { id: 'u_jj_uji_guru', kelas_ajar: 'Kelas 8B' } });
  lv = await levelPenugasan();
  check('A9 kelas_ajar "Kelas 8B" → class_level VIII', lv[0] === 'VIII', JSON.stringify(lv));

  // A10 huruf kecil + format body flat
  r = await call('admin', 'save_system_config', { method: 'POST', body: { school_level: 'sd', konfirmasi_jenjang: true } });
  c = await config();
  check('A10 body flat "sd" → tersimpan SD', r.status === 200 && c.school_level === 'SD', `${r.status} ${c.school_level}`);
  check('A10 body flat: konfirmasi_jenjang tidak disimpan', !('konfirmasi_jenjang' in c));
  r = await call('admin', 'gtk_update_atribut', { method: 'POST', body: { id: 'u_jj_uji_guru', kelas_ajar: 'IVB, IV C' } });
  lv = await levelPenugasan();
  check('A10 SD: kelas_ajar "IVB" → class_level IV', lv[0] === 'IV', JSON.stringify(lv));

  // A11 kembali ke SMK → PKL aktif lagi
  r = await simpan('admin', { school_level: 'SMK' }, { konfirmasi_jenjang: true });
  c = await config();
  check('A11 kembali ke SMK → 200', r.status === 200 && c.school_level === 'SMK', `${r.status} ${c.school_level}`);
  r = await call('guru', 'pkl_konteks');
  check('A11 pkl_konteks di SMK tidak lagi diblokir', r.status !== 403 || (r.json && r.json.error !== PESAN_PKL), `${r.status} ${r.text.slice(0, 120)}`);

  console.log(`\n${pass} lulus, ${failed} gagal`);
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
