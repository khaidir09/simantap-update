// Uji API matriks akses Ekstrakurikuler (docs/specs/pembina-ekstrakurikuler.md §9.3).
// HANYA untuk dev/staging: skrip membuat, mengubah, dan menghapus data (ekskul uji, anggota, aktivitas, akun uji_hapus).
// Prasyarat: akun uji_admin, uji_ks, uji_tu, uji_pembina, uji_wali, uji_rangkap, uji_guru, uji_bk, uji_hapus
// (email <uid>@uji.test, password UJI_PASSWORD) — lihat docs/patches/pembina-ekstrakurikuler.md §5.
// Jalankan: BASE=... EA=... STUDENT_A=... STUDENT_B=... node docs/patches/uji-pembina-ekskul-api.mjs
const BASE = process.env.BASE || 'http://localhost/simantap/api/index.php';
const EA = process.env.EA || 'extra_6aa80403aba7e'; // ekskul uji A: anggota AKTIF hanya dari kelas perwalian uji_wali
const STUDENT_B = process.env.STUDENT_B || 'std_0095767081'; // siswa kelas perwalian uji_rangkap, bukan anggota EA
const STUDENT_A = process.env.STUDENT_A || 'std_3092085157'; // siswa kelas perwalian uji_wali, anggota EA
let pass = 0, failed = 0;
const jars = {};

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

const users = ['uji_admin', 'uji_ks', 'uji_tu', 'uji_pembina', 'uji_wali', 'uji_rangkap', 'uji_guru', 'uji_bk', 'uji_hapus'];
for (const u of users) await login(u);
const EA_BASE = (await call("uji_admin", "get_extracurricular_members", { query: `&extracurricular_id=${EA}` })).json.length;

// --- ADMIN ---
let r = await call('uji_admin', 'get_extracurriculars');
check('E2 admin lihat semua + flag', r.status === 200 && r.json.some(e => e.id === EA && e.access.level === 'ADMIN' && typeof e.coachValid === 'boolean' && 'coachNameLegacy' in e), r.text.slice(0, 300));
r = await call('uji_admin', 'save_extracurricular', { method: 'POST', body: { name: 'Uji EB', schedule: 'Senin', room: 'R1', description: 'x', coachId: '' } });
check('E3 admin buat EB tanpa pembina', r.status === 200 && r.json.id, r.text);
const EB = r.json.id;
r = await call('uji_admin', 'save_extracurricular', { method: 'POST', body: { name: 'Uji EC', schedule: 'Selasa', room: 'R2', description: '', coachId: 'uji_hapus' } });
const EC = r.json.id;
check('E3b admin buat EC dengan pembina uji_hapus', r.status === 200);
r = await call('uji_admin', 'save_extracurricular', { method: 'POST', body: { id: EA, name: 'Web Design', schedule: "Setiap Jum'at, 14:00 - 16:00", room: 'Lab TJKT', description: 'Web Design', coachId: 'uji_ks' } });
check('E6 coachId akun KS ditolak 400', r.status === 400, r.text);
r = await call('uji_admin', 'save_extracurricular', { method: 'POST', body: { id: EA, name: 'Web Design', schedule: "Setiap Jum'at, 14:00 - 16:00", room: 'Lab TJKT', description: 'Web Design', coachId: 'uji_pembina' } });
check('E4 admin assign uji_pembina ke EA', r.status === 200, r.text);
r = await call('uji_admin', 'get_extracurriculars');
check('E4b coachName dari akun', r.json.find(e => e.id === EA).coachName === 'Uji Pembina' && r.json.find(e => e.id === EA).coachNameLegacy === 'Uji Pembina');
r = await call('uji_admin', 'save_extracurricular_member', { method: 'POST', body: { extracurricular_id: EB, student_id: STUDENT_B, student_name: 'PALSU', class_id: 'PALSU' } });
check('admin tambah anggota EB (nama dari DB)', r.status === 200, r.text);
r = await call('uji_admin', 'get_extracurricular_members', { query: `&extracurricular_id=${EB}` });
check('anggota EB nama bukan dari body', r.json.length === 1 && r.json[0].student_name !== 'PALSU' && r.json[0].class_id === 'X_AKT_1', r.text);

// --- menu counts ---
let me = (await call('uji_pembina', 'session')).json.user;
check('E7 session pembina coach_count=1', me.ekskul_coach_count === 1, JSON.stringify(me));
me = (await call('uji_wali', 'session')).json.user;
check('E16 session wali homeroom_count=1 (XII-TKJ-1 vs XII_TKJ_1)', me.ekskul_homeroom_count === 1 && me.ekskul_coach_count === 0, JSON.stringify(me));
me = (await call('uji_guru', 'session')).json.user;
check('E23 session guru biasa 0/0', me.ekskul_homeroom_count === 0 && me.ekskul_coach_count === 0);
me = (await call('uji_admin', 'session')).json.user;
check('session admin 0/0', me.ekskul_coach_count === 0);

// --- PEMBINA ---
r = await call('uji_pembina', 'get_extracurriculars');
check('E8 pembina hanya EA level COACH', r.status === 200 && r.json.length === 1 && r.json[0].id === EA && r.json[0].access.canEditInfo && !r.json[0].access.canDelete, r.text.slice(0, 300));
r = await call('uji_pembina', 'save_extracurricular', { method: 'POST', body: { id: EA, name: 'DIUBAH', coachId: 'uji_guru', schedule: 'Sabtu Uji', room: 'Lab Uji', description: 'Desk Uji' } });
check('E9/E10 pembina ubah info', r.status === 200, r.text);
r = await call('uji_admin', 'get_extracurriculars');
let ea = r.json.find(e => e.id === EA);
check('E10 nama & pembina tidak berubah', ea.name === 'Web Design' && ea.coachId === 'uji_pembina' && ea.schedule === 'Sabtu Uji', JSON.stringify(ea));
r = await call('uji_pembina', 'save_extracurricular', { method: 'POST', body: { name: 'Baru', schedule: 'x', room: 'x' } });
check('E11a pembina buat baru 403', r.status === 403, r.text);
r = await call('uji_pembina', 'delete_extracurricular', { method: 'DELETE', query: `&id=${EA}` });
check('E11b pembina hapus 403', r.status === 403, r.text);
r = await call('uji_pembina', 'save_extracurricular_member', { method: 'POST', body: { extracurricular_id: EA, student_id: STUDENT_B } });
check('E12 pembina tambah anggota kelas lain', r.status === 200, r.text);
r = await call('uji_pembina', 'save_extracurricular_member', { method: 'POST', body: { extracurricular_id: EA, student_id: 'std_tidak_ada' } });
check('siswa tidak ada 400', r.status === 400, r.text);
r = await call('uji_pembina', 'save_extracurricular_activity', { method: 'POST', body: { extracurricular_id: EA, title: 'Uji Pertemuan', date: '2026-09-14', attendance: { [STUDENT_A]: true, [STUDENT_B]: false } } });
check('E13 pembina catat aktivitas', r.status === 200, r.text);
const ACT_A = r.json.id;
r = await call('uji_admin', 'save_extracurricular_activity', { method: 'POST', body: { extracurricular_id: EB, title: 'Uji EB', date: '2026-09-14', attendance: { [STUDENT_B]: true } } });
const ACT_B = r.json.id;
r = await call('uji_pembina', 'get_extracurricular_members', { query: `&extracurricular_id=${EB}` });
check('E14a pembina baca anggota EB 403', r.status === 403, r.text);
r = await call('uji_pembina', 'save_extracurricular_member', { method: 'POST', body: { extracurricular_id: EB, student_id: STUDENT_A } });
check('E14b pembina tambah anggota EB 403', r.status === 403, r.text);
r = await call('uji_pembina', 'save_extracurricular_activity', { method: 'POST', body: { id: ACT_B, extracurricular_id: EA, title: 'Timpa' } });
check('E14c timpa aktivitas EB lewat EA 403', r.status === 403, r.text);
r = await call('uji_pembina', 'get_extracurricular_members');
check('members tanpa id untuk guru 400', r.status === 400, r.text);
r = await call('uji_pembina', 'get_ekskul_journals');
check('jurnal pembina hanya EA + canManage', r.status === 200 && r.json.length > 0 && r.json.every(j => j.ekskulId === EA && j.canManage === true), r.text.slice(0, 300));
r = await call('uji_pembina', 'delete_extracurricular_activity', { method: 'GET', query: `&id=${ACT_A}` });
check('E31 delete via GET 405', r.status === 405, r.text);

// --- WALI ---
r = await call('uji_wali', 'get_extracurriculars');
check('E17 wali hanya EA HOMEROOM tanpa izin', r.status === 200 && r.json.length === 1 && r.json[0].id === EA && r.json[0].access.level === 'HOMEROOM' && !r.json[0].access.canManageMembers, r.text.slice(0, 300));
r = await call('uji_wali', 'get_extracurricular_members', { query: `&extracurricular_id=${EA}` });
check('E18 wali anggota tanpa siswa kelas lain', r.status === 200 && r.json.length === EA_BASE && !r.json.some(m => m.student_id === STUDENT_B), `${r.json && r.json.length} ${r.text.slice(0, 200)}`);
r = await call('uji_wali', 'get_extracurricular_activities', { query: `&extracurricular_id=${EA}` });
let act = r.json && r.json.find(a => a.id === ACT_A);
check('E19 presensi wali terfilter', r.status === 200 && act && Object.keys(act.attendance).length === 1 && act.attendance[STUDENT_A] === true, r.text.slice(0, 300));
r = await call('uji_wali', 'get_ekskul_journals');
check('E19b jurnal wali tanpa canManage', r.status === 200 && r.json.every(j => j.ekskulId === EA && j.canManage === false), r.text.slice(0, 200));
r = await call('uji_wali', 'save_extracurricular_member', { method: 'POST', body: { extracurricular_id: EA, student_id: STUDENT_A } });
check('E20a wali tambah anggota 403', r.status === 403);
r = await call('uji_wali', 'save_extracurricular_activity', { method: 'POST', body: { extracurricular_id: EA, title: 'x' } });
check('E20b wali catat aktivitas 403', r.status === 403);
r = await call('uji_wali', 'delete_extracurricular_activity', { method: 'DELETE', query: `&id=${ACT_A}` });
check('E20c wali hapus aktivitas 403', r.status === 403);
r = await call('uji_wali', 'get_extracurricular_members', { query: `&extracurricular_id=${EB}` });
check('E20d wali baca EB 403', r.status === 403);

// --- RANGKAP: pembina EA + wali X_AKT_1 (EB) ---
r = await call('uji_admin', 'save_extracurricular', { method: 'POST', body: { id: EA, name: 'Web Design', schedule: 'Sabtu Uji', room: 'Lab Uji', description: 'Desk Uji', coachId: 'uji_rangkap' } });
r = await call('uji_rangkap', 'get_extracurriculars');
const lv = Object.fromEntries((r.json || []).map(e => [e.id, e.access.level]));
check('E22 rangkap EA=COACH, EB=HOMEROOM', lv[EA] === 'COACH' && lv[EB] === 'HOMEROOM' && Object.keys(lv).length === 2, r.text.slice(0, 300));
r = await call('uji_rangkap', 'get_extracurricular_members', { query: `&extracurricular_id=${EA}` });
check('E22b rangkap COACH EA lihat semua anggota', r.status === 200 && r.json.length === EA_BASE + 1, `${r.json && r.json.length}`);
r = await call('uji_pembina', 'get_extracurriculars');
check('E-cabut: mantan pembina langsung tanpa akses', r.status === 200 && r.json.length === 0, r.text.slice(0, 200));
r = await call('uji_pembina', 'save_extracurricular_member', { method: 'POST', body: { extracurricular_id: EA, student_id: STUDENT_A } });
check('E-cabut: mantan pembina mutasi 403', r.status === 403);

// --- GURU / BK / KS / TU / anonim ---
r = await call('uji_guru', 'get_extracurriculars');
check('E23a guru biasa []', r.status === 200 && Array.isArray(r.json) && r.json.length === 0);
r = await call('uji_guru', 'get_extracurricular_members', { query: `&extracurricular_id=${EA}` });
check('E23b guru biasa members 403', r.status === 403);
r = await call('uji_bk', 'get_extracurriculars');
check('E24 bk non-pembina []', r.status === 200 && r.json.length === 0);
r = await call('uji_bk', 'save_extracurricular', { method: 'POST', body: { id: EB, name: 'x', schedule: 'x', room: 'x' } });
check('E24b bk edit 403', r.status === 403);
r = await call('uji_ks', 'get_extracurriculars');
check('E26a ks lihat semua MONITOR', r.status === 200 && r.json.length >= 3 && r.json.every(e => e.access.level === 'MONITOR'));
r = await call('uji_ks', 'get_extracurricular_members', { query: `&extracurricular_id=${EA}` });
check('E26b ks baca anggota', r.status === 200 && r.json.length === EA_BASE + 1);
for (const [act2, opt] of [
  ['save_extracurricular', { method: 'POST', body: { id: EA, name: 'x', schedule: 'x', room: 'x' } }],
  ['save_extracurricular', { method: 'POST', body: { name: 'KS baru', schedule: 'x', room: 'x' } }],
  ['save_extracurricular_member', { method: 'POST', body: { extracurricular_id: EA, student_id: STUDENT_A } }],
  ['delete_extracurricular_activity', { method: 'DELETE', query: `&id=${ACT_A}` }],
  ['delete_extracurricular', { method: 'DELETE', query: `&id=${EB}` }],
]) {
  r = await call('uji_ks', act2, opt);
  check(`E26 ks ${act2} 403`, r.status === 403, r.text);
}
r = await call('uji_tu', 'get_extracurriculars');
check('E27a tu 403', r.status === 403);
r = await call('uji_tu', 'get_ekskul_journals');
check('E27b tu jurnal 403', r.status === 403);
for (const [act2, q] of [['get_extracurriculars', ''], ['get_extracurricular_members', `&extracurricular_id=${EA}`], ['get_extracurricular_activities', `&extracurricular_id=${EA}`], ['get_ekskul_journals', '']]) {
  r = await call(null, act2, { query: q });
  check(`E28 anonim ${act2} 401`, r.status === 401, `${r.status}`);
}

// --- Sinkronisasi akun ---
r = await call('uji_admin', 'delete_user', { method: 'DELETE', query: '&uid=uji_hapus' });
check('E29a hapus akun pembina EC', r.status === 200, r.text);
r = await call('uji_admin', 'get_extracurriculars');
let ec = r.json.find(e => e.id === EC);
check('E29a EC pembina kosong', ec && ec.coachId === '' && ec.coachValid === false && ec.coachNameLegacy === '', JSON.stringify(ec));
r = await call('uji_admin', 'save_user_v2', { method: 'POST', body: { uid: 'uji_rangkap', email: 'uji_rangkap@uji.test', displayName: 'Uji Rangkap', role: 'TATA_USAHA', class_homeroom: 'X_AKT_1', is_bk: 0 } });
check('E29b ubah role rangkap -> TU', r.status === 200, r.text);
r = await call('uji_admin', 'get_extracurriculars');
ea = r.json.find(e => e.id === EA);
check('E29b EA pembina dikosongkan', ea.coachId === '', JSON.stringify(ea));
r = await call('uji_admin', 'save_user_v2', { method: 'POST', body: { uid: 'uji_rangkap', email: 'uji_rangkap@uji.test', displayName: 'Uji Rangkap', role: 'GURU', class_homeroom: 'X_AKT_1', is_bk: 0 } });
r = await call('uji_admin', 'get_extracurriculars');
check('E29b kembali GURU tidak otomatis pembina', r.json.find(e => e.id === EA).coachId === '');

// --- Hapus ekskul oleh admin ---
r = await call('uji_admin', 'delete_extracurricular', { method: 'DELETE', query: `&id=${EC}` });
check('E32 admin hapus EC', r.status === 200, r.text);
r = await call('uji_admin', 'delete_extracurricular', { method: 'DELETE', query: `&id=${EB}` });
check('E32b admin hapus EB (cascade)', r.status === 200, r.text);
r = await call('uji_admin', 'delete_extracurricular_activity', { method: 'DELETE', query: `&id=${ACT_A}` });
check('admin hapus aktivitas uji EA', r.status === 200, r.text);
r = await call('uji_admin', 'delete_extracurricular_member', { method: 'DELETE', query: `&extracurricular_id=${EA}&student_id=${STUDENT_B}` });
check('admin keluarkan anggota uji EA', r.status === 200, r.text);

console.log(`\nHasil: ${pass} lulus, ${failed} gagal`);
process.exit(failed ? 1 : 0);
