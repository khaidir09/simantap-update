// Uji API Jurnal Bimbingan PKL (pkl_*). HANYA untuk dev/staging.
// Jalankan uji-bimbingan-pkl-seed.sql dulu, lalu uji-bimbingan-pkl-cleanup.sql setelahnya.
// Jalankan dari root deploy: BASE=http://localhost/simantap/api/index.php node docs/patches/uji-bimbingan-pkl-api.mjs
import fs from 'fs';
import path from 'path';
import { execFileSync } from 'child_process';

const BASE = process.env.BASE || 'http://localhost/simantap/api/index.php';
const ROOT = path.resolve(path.dirname(new URL(import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1')), '..', '..');
const S = (n) => `s_uji_pkl_${n}`;
const UID = (who) => `u_uji_pkl_${who}`;
let pass = 0, failed = 0;
const jars = {};

// Tanggal relatif hari ini (zona waktu mesin = zona waktu server dev)
const iso = (d) => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
const hari = (n) => iso(new Date(Date.now() + n * 86400000));
const H = hari(0);
const MULAI = hari(-20), SELESAI = hari(20);

const JPEG = Buffer.from('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=', 'base64');

async function call(who, action, { method = 'GET', body = null, query = '', form = null } = {}) {
  const headers = {};
  if (!form) headers['Content-Type'] = 'application/json';
  if (jars[who]) headers.Cookie = jars[who];
  const res = await fetch(`${BASE}?action=${action}${query}`, { method, headers, body: form || (body ? JSON.stringify(body) : undefined) });
  const setCookie = res.headers.get('set-cookie');
  if (setCookie) jars[who] = setCookie.split(';')[0];
  const text = await res.text();
  let json = null; try { json = JSON.parse(text); } catch {}
  return { status: res.status, json, text, type: res.headers.get('content-type') || '' };
}
const post = (who, action, body) => call(who, action, { method: 'POST', body });
function check(id, cond, info = '') {
  if (cond) { pass++; console.log(`PASS ${id}`); }
  else { failed++; console.log(`FAIL ${id} ${String(info).slice(0, 400)}`); }
}
async function login(who) {
  const r = await post(who, 'login', { email: `uji_pkl_${who}@uji.test`, password: process.env.UJI_PASSWORD || 'uji12345' });
  if (r.status !== 200) throw new Error(`login ${who} gagal: ${r.status} ${r.text}`);
}
function unggah(who, buf, nama, type = 'image/jpeg') {
  const fd = new FormData();
  fd.append('file', new Blob([buf], { type }), nama);
  return call(who, 'pkl_unggah_foto', { method: 'POST', form: fd });
}
function hitung() {
  return JSON.parse(execFileSync('php', [path.join(ROOT, 'docs/patches/uji-bimbingan-pkl-hitung.php')], { encoding: 'utf8' }));
}
const jurnalBody = (extra) => ({
  tanggal: hari(-3), jam_mulai: '09:00', jam_selesai: '11:00', jenis: 'KUNJUNGAN',
  uraian: 'Monitoring pekerjaan jaringan di DU/DI', kondisi_siswa: 'Baik', permasalahan: '', tindak_lanjut: '', foto: [], ...extra,
});
const daftarMilik = async (who) => ((await call(who, 'pkl_penempatan_list', { query: `&guru=${UID(who)}` })).json?.items || []);
const cariP = (items, sid) => items.find((p) => p.siswa_id === sid);

for (const u of ['admin', 'ks', 'tu', 'guru_a', 'guru_b', 'guru_c', 'wali']) await login(u);

try {
  // --- 1. Akses & konteks ---
  let r = await call('tu', 'pkl_dudi_list');
  check('A1 TU ditolak dari pkl_dudi_list (403)', r.status === 403, r.text);
  r = await call('guru_a', 'pkl_jurnal_simpan');
  check('A2 GET ke pkl_jurnal_simpan ditolak 405', r.status === 405, r.status);
  const ktx = {};
  for (const u of ['guru_a', 'guru_b', 'guru_c', 'wali', 'ks', 'admin']) ktx[u] = (await call(u, 'pkl_konteks')).json || {};
  check('A3 guru A memenuhi syarat (class_ids XII)', ktx.guru_a.is_pembimbing === true, JSON.stringify(ktx.guru_a));
  check('A4 guru B memenuhi syarat (class_level XII)', ktx.guru_b.is_pembimbing === true, JSON.stringify(ktx.guru_b));
  check('A5 guru C tidak memenuhi syarat (class_ids hanya XI)', ktx.guru_c.is_pembimbing === false, JSON.stringify(ktx.guru_c));
  check('A6 wali XII: bukan pembimbing, is_wali_xii', ktx.wali.is_pembimbing === false && ktx.wali.is_wali_xii === true, JSON.stringify(ktx.wali));
  check('A7 admin menerima daftar guru + syarat', Array.isArray(ktx.admin.guru) && ktx.admin.guru.some((g) => g.uid === UID('guru_c') && !g.memenuhi_syarat), '');
  r = await post('guru_c', 'pkl_penempatan_simpan', { siswa_ids: [S(4)], dudi_id: 'x', tanggal_mulai: MULAI, tanggal_selesai: SELESAI });
  check('A8 guru C tidak bisa membuat penempatan (403)', r.status === 403, r.text);
  r = await post('guru_c', 'pkl_dudi_simpan', { nama: 'CV Guru C Uji PKL' });
  check('A9 guru C tidak bisa menambah DU/DI (403)', r.status === 403, r.text);
  r = await post('wali', 'pkl_dudi_simpan', { nama: 'CV Wali Uji PKL' });
  check('A10 wali tanpa penugasan XII tidak bisa menambah DU/DI (403)', r.status === 403, r.text);

  // --- 2. DU/DI ---
  r = await post('guru_a', 'pkl_dudi_simpan', { nama: 'PT. Maju Jaya Uji PKL', alamat: 'Jl. Uji 1', pembimbing_nama: 'Pak Industri', pembimbing_telp: '0811-000-111' });
  check('D1 guru A menambah DU/DI', r.status === 200 && r.json?.item?.id, r.text);
  const DUDI = r.json?.item?.id;
  r = await post('guru_b', 'pkl_dudi_simpan', { nama: 'Maju Jaya Uji PKL' });
  check('D2 nama sama setelah normalisasi -> 409 + existing', r.status === 409 && r.json?.existing?.id === DUDI, r.text);
  r = await post('guru_b', 'pkl_dudi_simpan', { nama: 'CV Sejahtera Uji PKL' });
  const DUDI_B = r.json?.item?.id;
  check('D3 guru B menambah DU/DI lain', r.status === 200 && DUDI_B, r.text);
  r = await post('guru_b', 'pkl_dudi_simpan', { id: DUDI, nama: 'PT Maju Jaya Uji PKL Ubah' });
  check('D4 guru B tidak bisa mengubah DU/DI buatan guru A (403)', r.status === 403, r.text);
  r = await post('guru_a', 'pkl_dudi_simpan', { nama: 'X', pembimbing_telp: 'abc<script>' });
  check('D5 telepon tidak valid ditolak', r.status === 400, r.text);

  // --- 3. Kandidat ---
  r = await call('guru_a', 'pkl_kandidat_siswa');
  const kandidatUji = (r.json?.siswa || []).filter((s) => s.id.startsWith('s_uji_pkl_')).map((s) => s.id).sort();
  check('K1 kandidat hanya 4 siswa aktif XII', JSON.stringify(kandidatUji) === JSON.stringify([S(1), S(2), S(3), S(4)]), JSON.stringify(kandidatUji));
  r = await call('guru_c', 'pkl_kandidat_siswa');
  check('K2 guru tanpa syarat & bukan wali tidak bisa melihat kandidat (403)', r.status === 403, r.text);

  // --- 4. Penempatan & tumpang tindih ---
  r = await post('guru_a', 'pkl_penempatan_simpan', { siswa_ids: [S(1), S(2)], dudi_id: DUDI, tanggal_mulai: MULAI, tanggal_selesai: SELESAI });
  check('P1 guru A menempatkan siswa 1-2', r.status === 200 && r.json.tersimpan.length === 2, r.text);
  r = await post('guru_b', 'pkl_penempatan_simpan', { siswa_ids: [S(2), S(3)], dudi_id: DUDI_B, tanggal_mulai: hari(-5), tanggal_selesai: hari(40) });
  check('P2 sebagian berhasil: siswa 3 tersimpan, siswa 2 bentrok dengan nama guru A',
    r.status === 200 && r.json.tersimpan.length === 1 && r.json.tersimpan[0].siswa_id === S(3)
    && r.json.gagal.length === 1 && r.json.gagal[0].siswa_id === S(2) && /Guru A Uji PKL/.test(r.json.gagal[0].alasan), r.text);
  r = await post('guru_b', 'pkl_penempatan_simpan', { siswa_ids: [S(1)], dudi_id: DUDI_B, tanggal_mulai: MULAI, tanggal_selesai: SELESAI });
  check('P3 semua bentrok -> 409', r.status === 409 && /Guru A Uji PKL/.test(r.json?.error || ''), r.text);
  const paralel = await Promise.all(['guru_a', 'guru_b'].map((u) => post(u, 'pkl_penempatan_simpan', { siswa_ids: [S(4)], dudi_id: u === 'guru_a' ? DUDI : DUDI_B, tanggal_mulai: MULAI, tanggal_selesai: SELESAI })));
  check('P4 dua permintaan paralel siswa 4: tepat satu berhasil', paralel.filter((x) => x.status === 200).length === 1 && paralel.filter((x) => x.status === 409).length === 1,
    paralel.map((x) => x.status).join(','));
  r = await post('guru_a', 'pkl_penempatan_simpan', { siswa_ids: [S(5)], dudi_id: DUDI, tanggal_mulai: MULAI, tanggal_selesai: SELESAI });
  check('P5 siswa non-aktif ditolak 400', r.status === 400, r.text);
  r = await post('guru_a', 'pkl_penempatan_simpan', { siswa_ids: [S(9)], dudi_id: DUDI, tanggal_mulai: MULAI, tanggal_selesai: SELESAI });
  check('P6 siswa kelas XI ditolak 400', r.status === 400 && /XII/.test(r.json?.error || ''), r.text);
  r = await post('guru_a', 'pkl_penempatan_simpan', { siswa_ids: [S(4)], dudi_id: DUDI, tanggal_mulai: SELESAI, tanggal_selesai: MULAI });
  check('P7 tanggal selesai < mulai ditolak', r.status === 400, r.text);
  r = await post('admin', 'pkl_dudi_hapus', { id: DUDI });
  check('P8 DU/DI yang dipakai tidak bisa dihapus (409)', r.status === 409, r.text);

  const milikA = await daftarMilik('guru_a');
  const P1 = cariP(milikA, S(1)), P2 = cariP(milikA, S(2));
  check('P9 status penempatan AKTIF', P1?.status === 'AKTIF' && P1?.dudi_nama?.includes('Maju Jaya'), JSON.stringify(P1));

  // --- 5. Jurnal ---
  const sebelum = hitung();
  r = await unggah('guru_a', JPEG, 'bukti.jpg');
  check('F1 unggah foto JPG', r.status === 200 && /^uploads\/journals\/pkl\/\d{6}\/[a-f0-9]{32}\.jpg$/.test(r.json?.path || ''), r.text);
  const FOTO = r.json?.path;
  r = await post('guru_a', 'pkl_jurnal_simpan', { ...jurnalBody({ foto: [FOTO] }), penempatan_id: P1.id });
  check('J1 jurnal valid (kunjungan + foto)', r.status === 200 && r.json.item.foto[0] === FOTO, r.text);
  const J1 = r.json?.item?.id;
  r = await post('guru_a', 'pkl_jurnal_simpan', { ...jurnalBody({ tanggal: hari(-1), jenis: 'DARING' }), penempatan_id: P1.id });
  check('J2 jurnal kedua (daring)', r.status === 200, r.text);
  const J2 = r.json?.item?.id;
  r = await post('guru_a', 'pkl_jurnal_simpan', { ...jurnalBody({ tanggal: hari(-1), jenis: 'SEKOLAH', jam_mulai: '13:00', jam_selesai: '14:00' }), penempatan_id: P1.id });
  check('J3 jurnal ketiga di hari yang sama diizinkan', r.status === 200, r.text);
  const J3 = r.json?.item?.id;
  for (const [id, extra] of [['J4 tanggal besok', { tanggal: hari(1) }], ['J5 tanggal sebelum periode', { tanggal: hari(-21) }],
    ['J6 jam selesai < mulai', { jam_mulai: '10:00', jam_selesai: '09:00' }], ['J7 jenis tidak dikenal', { jenis: 'X' }],
    ['J8 uraian kosong', { uraian: '  ' }], ['J9 foto path asing', { foto: ['../api/db.php'] }]]) {
    r = await post('guru_a', 'pkl_jurnal_simpan', { ...jurnalBody(extra), penempatan_id: P1.id });
    check(`${id} ditolak 400`, r.status === 400, r.text);
  }
  r = await post('guru_b', 'pkl_jurnal_simpan', { ...jurnalBody(), penempatan_id: P1.id });
  check('J10 guru B tidak bisa menulis jurnal penempatan guru A (403)', r.status === 403, r.text);
  r = await post('admin', 'pkl_jurnal_simpan', { ...jurnalBody(), penempatan_id: P1.id });
  check('J11 admin tidak menulis jurnal (403)', r.status === 403, r.text);
  const empat = [];
  for (let i = 0; i < 4; i++) empat.push((await unggah('guru_a', JPEG, `f${i}.jpg`)).json?.path);
  r = await post('guru_a', 'pkl_jurnal_simpan', { ...jurnalBody({ foto: empat }), penempatan_id: P1.id });
  check('J12 lebih dari 3 foto ditolak', r.status === 400, r.text);
  r = await call('guru_a', 'pkl_jurnal_list', { query: `&penempatan_id=${encodeURIComponent(P1.id)}` });
  check('J13 daftar jurnal berisi 3 jurnal, tanpa field kehadiran', r.status === 200 && r.json.items.length === 3 && !('kehadiran_siswa' in r.json.items[0]), r.text);
  const sesudah = hitung();
  check('J14 presensi/insiden/poin/panggilan/jurnal mengajar tidak berubah', JSON.stringify(sebelum) === JSON.stringify(sesudah), `${JSON.stringify(sebelum)} vs ${JSON.stringify(sesudah)}`);

  // --- 6. Unggahan berbahaya ---
  r = await unggah('guru_a', Buffer.from('<?php echo 1; ?>'), 'shell.jpg');
  check('F2 file PHP berganti nama .jpg ditolak', r.status === 400, r.text);
  r = await unggah('guru_a', Buffer.concat([JPEG, Buffer.alloc(3 * 1024 * 1024)]), 'besar.jpg');
  check('F3 foto 3 MB ditolak', r.status === 400 || r.status === 413, r.text);
  r = await unggah('guru_c', JPEG, 'c.jpg');
  check('F4 guru tanpa syarat & tanpa penempatan tidak bisa unggah (403)', r.status === 403, r.text);

  // --- 7. Ubah/hapus jurnal ---
  r = await post('guru_b', 'pkl_jurnal_simpan', { ...jurnalBody(), id: J2 });
  check('U1 guru B tidak bisa mengubah jurnal guru A (403)', r.status === 403, r.text);
  r = await post('guru_b', 'pkl_jurnal_hapus', { id: J2 });
  check('U2 guru B tidak bisa menghapus jurnal guru A (403)', r.status === 403, r.text);
  r = await post('guru_a', 'pkl_jurnal_simpan', { ...jurnalBody({ tanggal: hari(-1), uraian: 'Uraian diubah', jenis: 'DARING' }), id: J2 });
  check('U3 guru A mengubah jurnalnya', r.status === 200 && r.json.item.uraian === 'Uraian diubah', r.text);
  r = await post('guru_a', 'pkl_jurnal_hapus', { id: J3 });
  check('U4 guru A menghapus jurnalnya', r.status === 200, r.text);
  r = await post('admin', 'pkl_jurnal_hapus', { id: J1 });
  check('U5 admin menghapus jurnal siapa pun, foto ikut terhapus', r.status === 200 && !fs.existsSync(path.join(ROOT, FOTO)), r.text);
  for (const f of empat) if (f && fs.existsSync(path.join(ROOT, f))) fs.unlinkSync(path.join(ROOT, f)); // foto yatim uji

  // --- 8. Tutup & hapus penempatan ---
  r = await post('guru_a', 'pkl_penempatan_tutup', { id: P1.id, tanggal_selesai: hari(-2) });
  check('T1 tutup sebelum jurnal terakhir ditolak', r.status === 400 && /jurnal terakhir/.test(r.json?.error || ''), r.text);
  r = await post('guru_a', 'pkl_penempatan_tutup', { id: P1.id, tanggal_selesai: hari(-1) });
  check('T2 tutup per tanggal jurnal terakhir', r.status === 200 && r.json.item.tanggal_selesai === hari(-1) && r.json.item.status === 'SELESAI', r.text);
  r = await post('guru_a', 'pkl_penempatan_hapus', { id: P1.id });
  check('T3 penempatan berjurnal tidak bisa dihapus (409)', r.status === 409, r.text);
  r = await post('guru_b', 'pkl_penempatan_simpan', { siswa_ids: [S(1)], dudi_id: DUDI_B, tanggal_mulai: H, tanggal_selesai: hari(30) });
  check('T4 setelah ditutup, siswa 1 bisa ditempatkan guru B mulai hari berikutnya', r.status === 200 && r.json.tersimpan.length === 1, r.text);
  const P1B = r.json?.tersimpan?.[0];
  r = await post('guru_b', 'pkl_penempatan_hapus', { id: P1B?.id });
  check('T5 penempatan tanpa jurnal bisa dihapus', r.status === 200, r.text);

  // --- 9. Alih pembimbing ---
  r = await post('guru_b', 'pkl_penempatan_alih', { id: P2.id, guru_id_baru: UID('guru_b'), tanggal_alih: H });
  check('L1 guru tidak bisa mengalihkan (403)', r.status === 403, r.text);
  r = await post('admin', 'pkl_penempatan_alih', { id: P2.id, guru_id_baru: UID('guru_c'), tanggal_alih: H });
  check('L2 alih ke guru tanpa syarat ditolak 400', r.status === 400, r.text);
  r = await post('admin', 'pkl_penempatan_alih', { id: P2.id, guru_id_baru: UID('guru_b'), tanggal_alih: H });
  check('L3 alih ke guru B: lama berakhir H, baru mulai H+1',
    r.status === 200 && r.json.lama.tanggal_selesai === H && r.json.baru.tanggal_mulai === hari(1)
    && r.json.baru.tanggal_selesai === SELESAI && r.json.baru.guru_id === UID('guru_b'), r.text);

  // Penugasan XII guru A dicabut: jurnal lama tetap jalan, penempatan baru ditolak
  const ts = (await call('admin', 'get_teacher_subjects', { query: `&teacher_id=${UID('guru_a')}` })).json || [];
  for (const t of ts) await call('admin', 'delete_teacher_subject', { method: 'POST', query: `&id=${t.id}` });
  r = await call('guru_a', 'pkl_konteks');
  check('L4 guru A tidak lagi memenuhi syarat', r.json?.is_pembimbing === false, r.text);
  r = await post('guru_a', 'pkl_jurnal_simpan', { ...jurnalBody({ tanggal: H }), penempatan_id: P2.id });
  check('L5 guru A tetap bisa menulis jurnal di penempatan lama', r.status === 200, r.text);
  r = await post('guru_a', 'pkl_penempatan_simpan', { siswa_ids: [S(1)], dudi_id: DUDI, tanggal_mulai: hari(31), tanggal_selesai: hari(60) });
  check('L6 guru A tidak bisa membuat penempatan baru (403)', r.status === 403, r.text);

  // --- 10. Wali kelas & Kepala Sekolah ---
  r = await call('wali', 'pkl_penempatan_list');
  const waliItems = (r.json?.items || []).filter((p) => p.siswa_id.startsWith('s_uji_pkl_'));
  check('W1 wali melihat penempatan kelasnya (kelas_wali, tidak bisa ubah)', r.status === 200 && waliItems.length >= 4 && waliItems.every((p) => p.kelas_wali && !p.bisa_ubah), r.text);
  check('W2 belum_ditempatkan berisi siswa 1, tanpa siswa XI', (r.json?.belum_ditempatkan || []).some((s) => s.id === S(1)) && !(r.json?.belum_ditempatkan || []).some((s) => s.id === S(9)), JSON.stringify(r.json?.belum_ditempatkan));
  r = await call('wali', 'pkl_jurnal_list', { query: `&penempatan_id=${encodeURIComponent(P1.id)}` });
  check('W3 wali membaca jurnal siswa kelasnya', r.status === 200 && r.json.items.length === 1, r.text);
  r = await post('wali', 'pkl_penempatan_tutup', { id: P2.id, tanggal_selesai: H });
  check('W4 wali tidak bisa menutup penempatan guru A (403)', r.status === 403, r.text);
  r = await call('guru_b', 'pkl_jurnal_list', { query: `&penempatan_id=${encodeURIComponent(P1.id)}` });
  check('W5 guru B tidak melihat jurnal guru A', r.status === 200 && r.json.items.length === 0, r.text);
  r = await call('ks', 'pkl_penempatan_list');
  check('K3 kepsek melihat semua penempatan + belum_ditempatkan', r.status === 200 && (r.json.items || []).filter((p) => p.siswa_id.startsWith('s_uji_pkl_')).length >= 5 && Array.isArray(r.json.belum_ditempatkan), r.text);
  for (const [act, body] of [['pkl_penempatan_simpan', { siswa_ids: [S(1)], dudi_id: DUDI, tanggal_mulai: hari(31), tanggal_selesai: hari(60) }],
    ['pkl_penempatan_tutup', { id: P2.id, tanggal_selesai: H }], ['pkl_jurnal_hapus', { id: J2 }], ['pkl_dudi_simpan', { nama: 'PT Kepsek Uji PKL' }],
    ['pkl_dudi_hapus', { id: DUDI_B }], ['pkl_jurnal_simpan', { ...jurnalBody(), penempatan_id: P2.id }]]) {
    r = await post('ks', act, body);
    check(`K4 kepsek ditolak: ${act}`, r.status === 403, r.text);
  }

  // --- 11. Cetak ---
  const bulanJ2 = hari(-1).slice(0, 7);
  r = await call('guru_a', 'pkl_cetak_rekap', { query: `&bulan=${bulanJ2}` });
  check('C1 rekap guru A: HTML berisi siswa, Mengetahui, tanpa kolom kehadiran',
    r.status === 200 && r.type.includes('text/html') && r.text.includes('Siswa PKL Satu') && r.text.includes('Mengetahui') && r.text.includes('198001012005011001') && !/Kehadiran/i.test(r.text), r.text.slice(0, 300));
  r = await call('guru_b', 'pkl_cetak_rekap', { query: `&bulan=${bulanJ2}&guru=${UID('guru_a')}` });
  check('C2 guru B tidak bisa mencetak rekap guru A (403)', r.status === 403, r.status);
  r = await call('ks', 'pkl_cetak_rekap', { query: `&bulan=${bulanJ2}&guru=${UID('guru_a')}` });
  check('C3 kepsek mencetak rekap guru A', r.status === 200 && r.text.includes('Guru A Uji PKL'), r.status);
  r = await call('guru_a', 'pkl_cetak_rekap', { query: '&bulan=2000-01' });
  check('C4 bulan tanpa data -> Nihil', r.status === 200 && r.text.includes('Nihil'), r.status);
  r = await call('guru_a', 'pkl_cetak_rekap', { query: '&bulan=2999-01' });
  check('C5 bulan belum berjalan ditolak', r.status === 400, r.status);
  r = await call('admin', 'pkl_cetak_kartu', { query: `&siswa=${S(2)}` });
  check('C6 kartu siswa 2 memuat dua pembimbing (A lalu B)', r.status === 200 && r.text.includes('Guru A Uji PKL') && r.text.includes('Guru B Uji PKL'), r.status);
  r = await call('wali', 'pkl_cetak_kartu', { query: `&siswa=${S(2)}` });
  check('C7 wali mencetak kartu siswa kelasnya', r.status === 200, r.status);
  r = await call('guru_c', 'pkl_cetak_kartu', { query: `&siswa=${S(2)}` });
  check('C8 guru lain tidak bisa mencetak kartu (403)', r.status === 403, r.status);
  r = await call('tu', 'pkl_cetak_kartu', { query: `&siswa=${S(2)}` });
  check('C9 TU tidak bisa mencetak kartu (403)', r.status === 403, r.status);
} catch (e) {
  failed++;
  console.log('ERROR ' + (e.stack || e.message));
}

console.log(`\n${pass} lulus, ${failed} gagal`);
process.exit(failed ? 1 : 0);
