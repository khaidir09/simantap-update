// Patch bundle frontend: Jadwal KBM mandiri oleh guru + isi otomatis jam jurnal.
// Spesifikasi: docs/specs/jadwal-kbm-mandiri-guru.md  |  Dokumentasi: docs/patches/jadwal-kbm-mandiri-guru.md
//  J0  Komponen __jkKelola (kelola jadwal, dipakai Profil & tab GTK), __jkKartuProfil, __jkPanelGtk,
//      hook form jurnal __jkPakaiJurnal + __jkChipJurnal, helper __jkTeksSesi, peta class __jkK.
//      Disisipkan tepat sebelum komponen __pnkPantauNilaiKelas (patch Pantau Nilai).
//  J1  Profil: kartu "Jadwal Mengajar (KBM)" untuk GURU, di bawah kartu Penugasan Mata Pelajaran.
//  J6/J7 Tab GTK: bagian jadwal (grid + form teks bebas + ruang) diganti __jkPanelGtk (endpoint jadwal_kbm_*).
//  J7b Tab GTK: master ruangan tidak lagi dimuat (hanya dipakai form jadwal lama).
//  J9  Widget Jadwal KBM di dashboard: tanpa ruang, jam dari data terstruktur.
//  J10-J13 Form jurnal: hook isi otomatis jam/mapel dari jadwal + flag manual pada input jam dan select mapel.
//  J12 Form jurnal: info & chip pilihan sesi di bawah field jam.
// Prasyarat: patch Tampilan Jurnal (apply-tampilan-jurnal-bundle.js) sudah diterapkan.
// Pemakaian (dari root deploy): node docs/patches/apply-jadwal-kbm-bundle.js
// File sumber TIDAK diubah; hasil ditulis ke salinan baru dan app.html diarahkan ke salinan itu.
// Tailwind di bundle sudah terkompilasi: skrip menolak class yang tidak ada di CSS bundle.
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const ROOT = path.resolve(__dirname, '..', '..');
const SRC_NAME = 'index-CGWL69P7-tampiljurnal2.js';
const OUT_NAME = 'index-CGWL69P7-jadwal1.js';
const SRC_SHA256 = '7d5fa4f15a349b9a1d52bf27eea56a11c1fb3af1c51ee973ef0a0658d99dea2c';
const CSS_NAME = 'index-CC1PBzaO.css';

const srcPath = path.join(ROOT, 'assets', SRC_NAME);
const outPath = path.join(ROOT, 'assets', OUT_NAME);
const cssPath = path.join(ROOT, 'assets', CSS_NAME);
const appHtmlPath = path.join(ROOT, 'app.html');

function fail(msg) {
  console.error('GAGAL: ' + msg);
  process.exit(1);
}

const src = fs.readFileSync(srcPath, 'utf8');
const hash = crypto.createHash('sha256').update(fs.readFileSync(srcPath)).digest('hex');
if (hash !== SRC_SHA256) fail(`hash ${SRC_NAME} berbeda (${hash}). Bundle sudah berubah, cari ulang anchor secara manual.`);
if (src.includes('__jk')) fail(`prefiks identifier __jk sudah dipakai di ${SRC_NAME}.`);

// ---------------------------------------------------------------------------
// Semua class Tailwind (divalidasi terhadap CSS bundle di bawah).
// ---------------------------------------------------------------------------
const KELAS = {
  wrap: 'space-y-5',
  teksBantu: 'text-xs text-slate-500 font-medium mb-4',
  memuat: 'flex items-center justify-center gap-2 text-slate-400 text-sm font-bold py-6',
  galat: 'flex items-center gap-2 p-3 rounded-xl text-sm font-medium bg-red-50 text-red-700 border border-red-200',
  sukses: 'flex items-center gap-2 p-3 rounded-xl text-sm font-medium bg-emerald-50 text-emerald-700 border border-emerald-200',
  peringatan: 'p-3 rounded-xl text-xs font-bold bg-amber-50 text-amber-700 border border-amber-200 space-y-1',
  btnCoba: 'px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold text-slate-600 hover:bg-slate-50 cursor-pointer',
  // Form tambah sesi
  form: 'bg-slate-50 rounded-2xl p-5 space-y-4 border border-slate-100',
  formJudul: 'text-[10px] font-black text-slate-400 uppercase tracking-widest',
  formGrid: 'grid grid-cols-2 sm:grid-cols-4 gap-3',
  label: 'text-[9px] font-black text-slate-400 uppercase tracking-wider block mb-1',
  select: 'w-full px-3 py-2.5 rounded-xl border border-slate-200 text-xs font-bold outline-none bg-white cursor-pointer focus:ring-2 focus:ring-blue-200',
  input: 'w-full px-3 py-2.5 rounded-xl border border-slate-200 text-xs font-bold outline-none bg-white focus:ring-2 focus:ring-blue-200',
  formGalat: 'flex items-center gap-2 text-xs font-bold text-red-600',
  btnTambah: 'w-full py-2.5 bg-blue-600 text-white rounded-xl text-xs font-bold hover:bg-blue-700 transition-all disabled:opacity-40 flex items-center justify-center gap-2 cursor-pointer',
  // Daftar (Profil)
  daftar: 'space-y-4',
  kosong: 'text-center py-10 text-slate-400 text-xs font-bold uppercase tracking-widest border-2 border-dashed border-slate-100 rounded-2xl',
  hariJudul: 'text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2',
  hariIsi: 'space-y-2',
  sesi: 'flex items-start justify-between p-4 bg-slate-50 border border-slate-100 rounded-2xl gap-3',
  sesiIsi: 'flex-1 min-w-0',
  sesiBaris: 'flex items-center gap-2 flex-wrap',
  sesiKelas: 'font-bold text-slate-800 text-sm',
  sesiMapel: 'px-2 py-0.5 bg-blue-50 text-blue-600 rounded-lg text-[10px] font-bold',
  sesiJam: 'text-xs font-bold text-slate-500 mt-1',
  jamKeGrid: 'grid grid-cols-2 gap-2',
  sesiWarn: 'flex items-center gap-1.5 mt-2 text-[10px] font-black uppercase tracking-wider text-amber-600',
  aksi: 'flex items-center gap-2 shrink-0',
  btnYa: 'px-3 py-1.5 rounded-lg bg-red-600 text-white text-[10px] font-bold cursor-pointer',
  btnBatal: 'px-3 py-1.5 rounded-lg border border-slate-200 text-[10px] font-bold text-slate-500 cursor-pointer',
  btnHapus: 'text-slate-300 hover:text-red-500 transition-colors shrink-0 cursor-pointer disabled:opacity-40',
  // Grid (tab GTK), mengikuti gaya grid lama
  gtkWrap: 'space-y-4',
  gtkHead: 'flex items-center justify-between border-b border-slate-100 pb-2',
  gtkJudul: 'font-bold text-sm uppercase tracking-wider text-slate-800 flex items-center gap-1.5',
  gtkIkon: 'w-4 h-4 text-blue-600',
  gtkJumlah: 'text-[10px] bg-blue-50 border border-blue-200 text-blue-700 px-2.5 py-0.5 rounded-full font-bold',
  gtkGrid: 'grid grid-cols-1 md:grid-cols-5 gap-3.5',
  gtkHari: 'bg-slate-50/50 border border-slate-200/80 p-3 rounded-xl space-y-2 text-left min-h-[110px] flex flex-col justify-start',
  gtkHariJudul: 'text-[10px] font-extrabold text-blue-950 uppercase tracking-widest border-b border-slate-200/60 pb-1 block',
  gtkHariIsi: 'space-y-1.5 flex-1',
  gtkKartu: 'p-2 rounded-lg border-l-3 border-l-blue-500 bg-blue-50/40 shadow-xs space-y-0.5 relative group hover:bg-white transition duration-150 text-slate-800',
  gtkKartuWarn: 'p-2 rounded-lg border border-amber-200 bg-amber-50 shadow-xs space-y-0.5 relative group hover:bg-white transition duration-150 text-slate-800',
  gtkKelas: 'font-extrabold text-slate-900 text-[10px] pr-4',
  gtkTeks: 'text-[8.5px] text-slate-600 font-medium leading-none flex items-center gap-1 mt-0.5',
  gtkWarn: 'text-[8.5px] font-black text-amber-600 leading-tight',
  gtkHapus: 'absolute top-1 right-1 opacity-0 group-hover:opacity-100 p-0.5 text-rose-500 hover:bg-rose-50 rounded transition shrink-0',
  gtkNihil: 'text-[10px] text-slate-400 font-sans italic block text-center py-2',
  gtkKonfirm: 'flex gap-1 mt-1',
  // Form jurnal
  jurnalWrap: 'space-y-2 mt-3',
  jurnalInfo: 'text-[11px] font-bold text-blue-600',
  jurnalChips: 'flex flex-wrap items-center gap-2',
  jurnalChipLabel: 'text-[10px] font-black text-slate-400 uppercase tracking-widest',
  jurnalChip: 'px-3 py-1.5 rounded-xl border border-blue-200 bg-blue-50 text-blue-700 text-[11px] font-bold hover:bg-blue-100 transition-all cursor-pointer',
};

// ---------------------------------------------------------------------------
// J0 Komponen. Ditulis sebagai fungsi biasa lalu disisipkan lewat toString().
// Identifier bebas dari scope modul bundle:
//   a = jsx runtime, ue = React, K$ = URL api/index.php, H$ = kartu halaman Profil,
//   ikon lucide: y1 (loader), Qu (circle-alert), cr (circle-check), ar (trash-2), mo (calendar),
//   es (plus), Z1 (triangle-alert).  __jkK = peta class Tailwind (KELAS di atas).
// ---------------------------------------------------------------------------
/* eslint-disable no-undef */

// Teks jam satu sesi untuk tampilan: "Jam ke-1–4 (07:30–10:45)" (+ mapel), atau teks jamKe lama.
function __jkTeksSesi(it, denganMapel) {
  if (!it) return '';
  const a1 = parseInt(it.jamKeMulai, 10);
  const b1 = parseInt(it.jamKeSelesai, 10);
  if (!Number.isFinite(a1) || !Number.isFinite(b1)) return String(it.jamKe || '');
  const jam = `Jam ke-${a1 === b1 ? a1 : `${a1}–${b1}`}`;
  const waktu = it.jamMulai && it.jamSelesai ? ` (${String(it.jamMulai).slice(0, 5)}–${String(it.jamSelesai).slice(0, 5)})` : '';
  return jam + waktu + (denganMapel !== false && it.mapel ? ` · ${it.mapel}` : '');
}

// Ambil JSON dari API; error membawa pesan backend dan status HTTP.
async function __jkAmbil(query, body) {
  const opsi = body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) } : undefined;
  const res = await fetch(`${K$}?action=${query}${body ? '' : `&_t=${Date.now()}`}`, opsi);
  let j = null;
  try { j = await res.json(); } catch (e) { j = null; }
  if (!res.ok) {
    const pesan = j && j.error ? j.error : `Gagal memuat data (HTTP ${res.status}).`;
    const err = new Error(pesan === 'Unauthorized' ? 'Sesi berakhir, silakan login ulang.' : pesan);
    err.status = res.status;
    throw err;
  }
  return j;
}

// Kelola jadwal satu GTK. grid=true: tampilan grid Senin–Jumat (tab GTK); false: daftar per hari (Profil).
function __jkKelola({ gtkId, bolehUbah, grid }) {
  const K = __jkK;
  const HARI = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'];
  const h = (t, p, ...c) => {
    const { key, ...rest } = p || {};
    if (c.length) rest.children = c.length === 1 ? c[0] : c;
    return a.jsx(t, rest, key);
  };
  const kecil = (x) => String(x == null ? '' : x).trim().toLowerCase();
  const labelJamKe = (x, y) => `jam ke-${x === y ? x : `${x}–${y}`}`;
  const formKosong = (hari) => ({ hari: hari || 'Senin', classId: '', subjectCode: '', jkMulai: '', jkSelesai: '', mulai: '', selesai: '' });

  const [data, setData] = ue.useState(null);
  const [memuat, setMemuat] = ue.useState(true);
  const [galat, setGalat] = ue.useState('');
  const [penugasan, setPenugasan] = ue.useState([]);
  const [kelas, setKelas] = ue.useState([]);
  const [mapel, setMapel] = ue.useState([]);
  const [form, setForm] = ue.useState(formKosong());
  const [formGalat, setFormGalat] = ue.useState('');
  const [proses, setProses] = ue.useState(false);
  const [pesan, setPesan] = ue.useState(null);
  const [peringatan, setPeringatan] = ue.useState([]);
  const [hapusIdx, setHapusIdx] = ue.useState(-1);

  const muat = ue.useCallback(async () => {
    setMemuat(true);
    setGalat('');
    try {
      const id = encodeURIComponent(gtkId);
      const [d, p, k, m] = await Promise.all([
        __jkAmbil(`jadwal_kbm_get&gtk_id=${id}`),
        bolehUbah ? __jkAmbil(`get_teacher_subjects&teacher_id=${id}`).catch(() => []) : Promise.resolve([]),
        bolehUbah ? __jkAmbil('get_classes').catch(() => []) : Promise.resolve([]),
        bolehUbah ? __jkAmbil('get_subjects&active_only=1').catch(() => []) : Promise.resolve([]),
      ]);
      setData(d);
      setPenugasan((Array.isArray(p) ? p : []).map((x) => ({
        subject_code: String(x.subject_code || ''),
        subject_name: String(x.subject_name || x.subject_name_full || x.subject_code || ''),
        class_level: String(x.class_level || ''),
        class_ids: (Array.isArray(x.class_ids) ? x.class_ids : (() => { try { return JSON.parse(x.class_ids || '[]') || []; } catch (e) { return []; } })()).map(String),
      })));
      setKelas(Array.isArray(k) ? k : []);
      setMapel(Array.isArray(m) ? m : []);
    } catch (e) {
      setGalat(e.message);
    } finally {
      setMemuat(false);
    }
  }, [gtkId, bolehUbah]);
  ue.useEffect(() => { muat(); }, [muat]);

  const items = data && Array.isArray(data.items) ? data.items : [];
  const bersih = (it) => { const { efektif, legacy, perluDilengkapi, alasan, ...rest } = it; return rest; };

  // Kelas yang diizinkan dari penugasan (sama dengan aturan server; tanpa penugasan = semua kelas).
  const namaKelas = (c) => String(c.name || c.id || '').replace(/_/g, ' ');
  const kelasIzin = (() => {
    const semua = [...kelas].sort((x, y) => namaKelas(x).localeCompare(namaKelas(y), 'id', { numeric: true }));
    if (penugasan.length === 0) return semua;
    const izin = {};
    for (const p of penugasan) {
      if (p.class_ids.length) p.class_ids.forEach((c) => { izin[kecil(c)] = true; });
      else if (p.class_level) semua.forEach((c) => { if (kecil(c.level) === kecil(p.class_level)) izin[kecil(c.id)] = true; });
      else return semua;
    }
    return semua.filter((c) => izin[kecil(c.id)]);
  })();
  const kelasTerpilih = kelas.find((c) => kecil(c.id) === kecil(form.classId));
  const mencakup = (p, c) => (p.class_ids.length ? p.class_ids.some((x) => kecil(x) === kecil(c.id))
    : (p.class_level ? kecil(p.class_level) === kecil(c.level) : true));
  const opsiMapel = (() => {
    if (penugasan.length === 0) return mapel.map((x) => ({ kode: String(x.id), nama: String(x.name || x.id) }));
    if (!kelasTerpilih) return [];
    const hasil = [];
    penugasan.forEach((p) => {
      if (mencakup(p, kelasTerpilih) && !hasil.some((x) => kecil(x.kode) === kecil(p.subject_code))) {
        hasil.push({ kode: p.subject_code, nama: p.subject_name || p.subject_code });
      }
    });
    return hasil;
  })();

  const ubah = (k, v) => {
    setFormGalat('');
    setForm((f) => {
      const b = { ...f, [k]: v };
      if (k === 'classId') b.subjectCode = '';
      return b;
    });
  };
  const lengkap = form.hari && form.classId && form.jkMulai !== '' && form.jkSelesai !== '' && form.mulai && form.selesai;

  const kirim = async (daftar, pesanOk) => {
    setProses(true);
    setPesan(null);
    setPeringatan([]);
    try {
      const r = await __jkAmbil('jadwal_kbm_save', { gtk_id: gtkId, versi: data.versi, items: daftar });
      setData((d) => ({ ...d, versi: r.versi, items: Array.isArray(r.items) ? r.items : [] }));
      setPeringatan(Array.isArray(r.peringatan) ? r.peringatan : []);
      setPesan({ ok: true, teks: pesanOk });
      return true;
    } catch (e) {
      if (e.status === 409) await muat();
      setPesan({ ok: false, teks: e.message });
      return false;
    } finally {
      setProses(false);
      setHapusIdx(-1);
    }
  };

  const tambah = async () => {
    setFormGalat('');
    const x = parseInt(form.jkMulai, 10);
    const y = parseInt(form.jkSelesai, 10);
    if (!Number.isInteger(x) || !Number.isInteger(y) || x < 1 || y > 11 || x > y) { setFormGalat('Jam ke harus 1–11 dan jam ke mulai tidak boleh lebih besar dari jam ke selesai.'); return; }
    if (!(form.mulai < form.selesai)) { setFormGalat('Jam mulai harus lebih awal dari jam selesai.'); return; }
    const bentrok = items.find((it) => !it.perluDilengkapi && it.efektif.hari === form.hari
      && x <= it.efektif.jamKeSelesai && it.efektif.jamKeMulai <= y);
    if (bentrok) {
      setFormGalat(`Jadwal bentrok: ${bentrok.efektif.hari} ${labelJamKe(bentrok.efektif.jamKeMulai, bentrok.efektif.jamKeSelesai)} sudah terisi kelas ${bentrok.efektif.kelas}`);
      return;
    }
    const baru = {
      hari: form.hari, classId: form.classId, subjectCode: form.subjectCode,
      jamKeMulai: x, jamKeSelesai: y, jamMulai: form.mulai, jamSelesai: form.selesai,
    };
    if (await kirim([...items.map(bersih), baru], 'Jadwal tersimpan')) setForm(formKosong(form.hari));
  };
  const hapus = (idx) => kirim(items.filter((_, i) => i !== idx).map(bersih), 'Sesi jadwal dihapus');

  if (memuat && !data) return h('div', { className: K.memuat }, h(y1, { size: 16, className: 'animate-spin' }), 'Memuat jadwal…');
  if (galat && !data) {
    return h('div', { className: K.wrap },
      h('div', { className: K.galat }, h(Qu, { size: 16 }), galat),
      h('button', { onClick: muat, className: K.btnCoba }, 'Coba lagi'));
  }
  if (data && data.gtkAda === false) {
    return h('div', { className: K.galat }, h(Qu, { size: 16 }), 'Data kepegawaian (GTK) Anda belum tersedia. Hubungi Tata Usaha.');
  }

  // --- Satu sesi ---
  const tombolHapus = (idx, gaya) => bolehUbah && (hapusIdx === idx
    ? h('div', { className: grid ? K.gtkKonfirm : K.aksi },
      h('button', { onClick: () => hapus(idx), disabled: proses, className: K.btnYa }, 'Hapus'),
      h('button', { onClick: () => setHapusIdx(-1), className: K.btnBatal }, 'Batal'))
    : h('button', { onClick: () => setHapusIdx(idx), disabled: proses, title: 'Hapus sesi', className: gaya }, h(ar, { size: grid ? 13 : 16 })));
  const teksJam = (it) => (it.perluDilengkapi ? String(it.jamKe || '-') : __jkTeksSesi(it.efektif, false));
  const kartuDaftar = (it, idx) => h('div', { key: `${it.id || 'x'}#${idx}`, className: K.sesi },
    h('div', { className: K.sesiIsi },
      h('div', { className: K.sesiBaris },
        h('span', { className: K.sesiKelas }, `Kelas ${it.efektif.kelas || it.kelas || '-'}`),
        it.efektif.mapel && h('span', { className: K.sesiMapel }, it.efektif.mapel)),
      h('p', { className: K.sesiJam }, teksJam(it)),
      it.perluDilengkapi && h('div', { className: K.sesiWarn }, h(Z1, { size: 12 }), `Perlu dilengkapi — ${it.alasan.join('; ')}`)),
    tombolHapus(idx, K.btnHapus));
  const kartuGrid = (it, idx) => h('div', { key: `${it.id || 'x'}#${idx}`, className: it.perluDilengkapi ? K.gtkKartuWarn : K.gtkKartu },
    hapusIdx !== idx && tombolHapus(idx, K.gtkHapus),
    h('p', { className: K.gtkKelas }, `Kls ${it.efektif.kelas || it.kelas || '-'}`),
    it.efektif.mapel && h('p', { className: K.gtkTeks }, it.efektif.mapel),
    h('div', { className: K.gtkTeks }, h('span', null, '⏱️'), h('span', null, teksJam(it))),
    it.perluDilengkapi && h('p', { className: K.gtkWarn }, `Perlu dilengkapi: ${it.alasan.join('; ')}`),
    hapusIdx === idx && tombolHapus(idx, K.gtkHapus));

  // Kelompok per hari; sesi dengan hari tidak valid dikumpulkan di "Lainnya".
  const indeks = items.map((it, idx) => ({ it, idx }));
  const perHari = (hari) => indeks.filter(({ it }) => (it.efektif.hari || 'Lainnya') === hari);
  const lainnya = perHari('Lainnya');

  let daftar;
  if (grid) {
    daftar = h('div', { className: K.gtkGrid }, HARI.map((hari) => {
      const isi = perHari(hari);
      return h('div', { key: hari, className: K.gtkHari },
        h('span', { className: K.gtkHariJudul }, hari),
        h('div', { className: K.gtkHariIsi }, isi.length ? isi.map(({ it, idx }) => kartuGrid(it, idx)) : h('span', { className: K.gtkNihil }, 'Nihil Sesi')));
    }));
  } else {
    daftar = items.length === 0
      ? h('div', { className: K.kosong }, 'Belum ada jadwal mengajar')
      : h('div', { className: K.daftar }, [...HARI, 'Lainnya'].filter((hari) => perHari(hari).length).map((hari) => h('div', { key: hari },
        h('p', { className: K.hariJudul }, hari),
        h('div', { className: K.hariIsi }, perHari(hari).map(({ it, idx }) => kartuDaftar(it, idx))))));
  }
  const daftarLainnya = grid && lainnya.length > 0 && h('div', { className: K.hariIsi },
    h('p', { className: K.hariJudul }, 'Hari tidak valid'), lainnya.map(({ it, idx }) => kartuDaftar(it, idx)));

  const field = (label, isi) => h('div', null, h('label', { className: K.label }, label), isi);
  const formulir = bolehUbah && h('div', { className: K.form },
    h('p', { className: K.formJudul }, grid ? 'Alokasi Sesi Jadwal Kelas Baru' : 'Tambah Sesi Jadwal'),
    data && data.alasan && data.alasan.length > 0 && h('div', { className: K.peringatan },
      h('p', null, 'Data jadwal lama tidak dapat dibaca. Menambah sesi akan menggantikan data tersebut.')),
    h('div', { className: K.formGrid },
      field('Hari', h('select', { className: K.select, value: form.hari, onChange: (e) => ubah('hari', e.target.value) },
        HARI.map((x) => h('option', { key: x, value: x }, x)))),
      field('Kelas', h('select', { className: K.select, value: form.classId, onChange: (e) => ubah('classId', e.target.value) },
        h('option', { value: '' }, '— Pilih Kelas —'),
        kelasIzin.map((c) => h('option', { key: c.id, value: c.id }, namaKelas(c))))),
      field('Mata Pelajaran', h('select', { className: K.select, value: form.subjectCode, onChange: (e) => ubah('subjectCode', e.target.value) },
        h('option', { value: '' }, '— Tanpa mapel —'),
        opsiMapel.map((x) => h('option', { key: x.kode, value: x.kode }, x.nama)))),
      field('Jam ke (mulai–selesai)', h('div', { className: K.jamKeGrid },
        h('input', { type: 'number', min: 1, max: 11, className: K.input, value: form.jkMulai, placeholder: 'dari', onChange: (e) => ubah('jkMulai', e.target.value) }),
        h('input', { type: 'number', min: 1, max: 11, className: K.input, value: form.jkSelesai, placeholder: 's.d.', onChange: (e) => ubah('jkSelesai', e.target.value) }))),
      field('Jam mulai', h('input', { type: 'time', className: K.input, value: form.mulai, onChange: (e) => ubah('mulai', e.target.value) })),
      field('Jam selesai', h('input', { type: 'time', className: K.input, value: form.selesai, onChange: (e) => ubah('selesai', e.target.value) }))),
    formGalat && h('div', { className: K.formGalat }, h(Qu, { size: 14 }), formGalat),
    h('button', { onClick: tambah, disabled: !lengkap || proses, className: K.btnTambah },
      proses ? h(y1, { size: 16, className: 'animate-spin' }) : h(es, { size: 16 }), ' Tambah Sesi'));

  const umpan = h('div', { className: K.wrap },
    pesan && h('div', { className: pesan.ok ? K.sukses : K.galat }, pesan.ok ? h(cr, { size: 16 }) : h(Qu, { size: 16 }), pesan.teks),
    peringatan.length > 0 && h('div', { className: K.peringatan },
      h('p', null, 'Perhatian: bentrok dengan jadwal guru lain (tetap disimpan).'),
      peringatan.map((p, i) => h('p', { key: i }, `• ${p}`))));

  if (grid) {
    return h('div', { className: K.gtkWrap },
      h('div', { className: K.gtkHead },
        h('h4', { className: K.gtkJudul }, h(mo, { className: K.gtkIkon }), 'Jadwal KBM & Tatap Muka Terintegrasi'),
        h('span', { className: K.gtkJumlah }, `${items.length} Sesi Aktif`)),
      daftar, daftarLainnya, (pesan || peringatan.length > 0) && umpan, formulir);
  }
  return h('div', { className: K.wrap }, daftar, (pesan || peringatan.length > 0) && umpan, formulir);
}

// J1 Kartu Profil (GURU).
function __jkKartuProfil({ user }) {
  return a.jsxs(H$, {
    title: 'Jadwal Mengajar (KBM)',
    icon: a.jsx(mo, { size: 16 }),
    children: [
      a.jsx('p', { className: __jkK.teksBantu, children: 'Jadwal ini dipakai untuk mengisi otomatis jam pelajaran di Jurnal Mengajar. Perubahan langsung berlaku.' }, 'bantu'),
      a.jsx(__jkKelola, { gtkId: user.uid, bolehUbah: true, grid: false }, 'kelola'),
    ],
  });
}

// J6/J7 Bagian jadwal di detail GTK (tab Kepegawaian).
function __jkPanelGtk({ gtk, bolehUbah }) {
  if (!gtk || !gtk.id) return null;
  return a.jsx(__jkKelola, { gtkId: gtk.id, bolehUbah: !!bolehUbah, grid: true }, gtk.id);
}

// J10-J13 Hook form jurnal: isi otomatis jam (dan mapel) dari jadwal pada jurnal baru.
function __jkPakaiJurnal({ user, edit, form, setForm, opsiMapel }) {
  const HARI_JS = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
  const kecil = (x) => String(x == null ? '' : x).trim().toLowerCase();
  const [jadwal, setJadwal] = ue.useState([]);
  const [manual, setManual] = ue.useState(false);
  const [info, setInfo] = ue.useState('');
  const mapelManual = ue.useRef(false);
  const uid = user && user.uid;

  ue.useEffect(() => {
    setJadwal([]);
    if (edit || !uid) return undefined;
    let batal = false;
    __jkAmbil(`jadwal_kbm_get&gtk_id=${encodeURIComponent(uid)}`)
      .then((d) => { if (!batal) setJadwal((d && Array.isArray(d.items) ? d.items : []).filter((it) => it && it.efektif && !it.perluDilengkapi)); })
      .catch(() => {});
    return () => { batal = true; };
  }, [edit, uid]);

  const hari = (() => {
    const p = String(form.tanggal || '').split('-').map(Number);
    if (p.length !== 3 || p.some((x) => !Number.isFinite(x))) return '';
    return HARI_JS[new Date(p[0], p[1] - 1, p[2]).getDay()];
  })();
  const cocok = edit || !form.kelas || !hari ? []
    : jadwal.filter((it) => it.efektif.hari === hari && kecil(it.efektif.classId) === kecil(form.kelas));
  const kunci = cocok.map((it) => it.id).join('|');

  const isi = (it) => {
    const e = it.efektif;
    const jam = e.jamKeMulai === e.jamKeSelesai ? String(e.jamKeMulai) : `${e.jamKeMulai}-${e.jamKeSelesai}`;
    const m = e.subjectCode ? (opsiMapel || []).find((o) => kecil(o.subject_code || o.id) === kecil(e.subjectCode)) : null;
    setForm((f) => {
      const b = { ...f, jam, jamMulai: e.jamMulai, jamSelesai: e.jamSelesai };
      // Mapel bawaan form (mapel pertama) boleh diganti; mapel yang dipilih guru sendiri tidak.
      if (m && !mapelManual.current) {
        const v = String(m.subject_code || m.id);
        b.mapelId = v;
        b.mapel = m.subject_name || m.subject_name_full || m.name || v;
      }
      return b;
    });
    setInfo(`Diisi dari jadwal ${e.hari}, jam ke-${e.jamKeMulai === e.jamKeSelesai ? e.jamKeMulai : `${e.jamKeMulai}–${e.jamKeSelesai}`}.`);
  };

  ue.useEffect(() => {
    if (edit) return;
    if (cocok.length === 1 && !manual) isi(cocok[0]);
    else setInfo('');
  }, [kunci, manual, edit, (opsiMapel || []).length]);

  return {
    info,
    pilihan: cocok.length > 1 || (cocok.length === 1 && manual) ? cocok : [],
    pilih: isi,
    ubah: (nama) => {
      if (edit) return;
      if (nama === 'mapelId') mapelManual.current = true;
      else if (nama === 'jam' || nama === 'jamMulai' || nama === 'jamSelesai') setManual(true);
    },
  };
}

// J12 Info & chip pilihan sesi di bawah field jam form jurnal.
function __jkChipJurnal({ j }) {
  if (!j || (!j.info && j.pilihan.length === 0)) return null;
  const K = __jkK;
  return a.jsxs('div', {
    className: K.jurnalWrap,
    children: [
      j.info ? a.jsx('p', { className: K.jurnalInfo, children: j.info }, 'info') : null,
      j.pilihan.length > 0 ? a.jsxs('div', {
        className: K.jurnalChips,
        children: [
          a.jsx('span', { className: K.jurnalChipLabel, children: 'Pilih dari jadwal:' }, 'lbl'),
          ...j.pilihan.map((it) => a.jsx('button', { type: 'button', onClick: () => j.pilih(it), className: K.jurnalChip, children: __jkTeksSesi(it.efektif) }, it.id)),
        ],
      }, 'chips') : null,
    ],
  });
}
/* eslint-enable no-undef */

const FUNGSI = [__jkTeksSesi, __jkAmbil, __jkKelola, __jkKartuProfil, __jkPanelGtk, __jkPakaiJurnal, __jkChipJurnal];
const KOMPONEN_SRC = `const __jkK=${JSON.stringify(KELAS)};${FUNGSI.map((f) => f.toString()).join('')}`;

// ---------------------------------------------------------------------------
// J6/J7: bagian jadwal di detail GTK = satu elemen <div space-y-4> (header + grid + form lama).
// Batasnya dicari dengan mencocokkan kurung, lalu diverifikasi awal dan akhirnya.
// ---------------------------------------------------------------------------
const GTK_AWAL = 'a.jsxs("div",{className:"space-y-4",children:[a.jsxs("div",{className:"flex items-center justify-between border-b border-slate-100 pb-2",children:[a.jsxs("h4",{className:"font-bold text-sm uppercase tracking-wider  text-slate-800 flex items-center gap-1.5",children:[a.jsx(mo,{className:"w-4 h-4 text-blue-600 animate-pulse"}),"Jadwal KBM & Tatap Muka Terintegrasi"]})';
const GTK_AKHIR = 'id:"schedule-form-submit",children:"Tambah"})]})]})]})]})]})';
function cocokKurung(s, start) {
  let i = s.indexOf('(', start), d = 0, q = null;
  for (; i < s.length; i++) {
    const c = s[i];
    if (q) { if (c === '\\') { i++; continue; } if (c === q) q = null; continue; }
    if (c === '"' || c === "'" || c === '`') { q = c; continue; }
    if (c === '(') d++;
    else if (c === ')') { d--; if (d === 0) return i; }
  }
  return -1;
}
const gtkMulai = src.indexOf(GTK_AWAL);
if (gtkMulai < 0 || src.split(GTK_AWAL).length - 1 !== 1) fail('J6/J7: awal bagian jadwal GTK tidak ditemukan tepat 1 kali.');
const gtkSelesai = cocokKurung(src, gtkMulai) + 1;
const GTK_SEG = src.slice(gtkMulai, gtkSelesai);
if (!GTK_SEG.endsWith(GTK_AKHIR)) fail('J6/J7: akhir bagian jadwal GTK tidak sesuai (' + GTK_SEG.slice(-80) + ').');
if (GTK_SEG.length !== 5889) fail(`J6/J7: panjang bagian jadwal GTK ${GTK_SEG.length}, harus 5889.`);

const patches = [
  {
    id: 'J0 komponen jadwal KBM',
    from: 'function __pnkPantauNilaiKelas(',
    to: `${KOMPONEN_SRC}function __pnkPantauNilaiKelas(`,
  },
  {
    id: 'J1 kartu Profil Jadwal Mengajar (GURU)',
    from: 'a.jsxs(H$,{title:"Ubah Password"',
    to: '__tmGuru&&a.jsx(__jkKartuProfil,{user:e}),a.jsxs(H$,{title:"Ubah Password"',
  },
  {
    id: 'J6/J7 tab GTK: panel jadwal baru',
    from: GTK_SEG,
    to: 'a.jsx(__jkPanelGtk,{gtk:I,bolehUbah:un})',
  },
  {
    id: 'J7b tab GTK: master ruangan tidak dimuat',
    from: 'he=await et.tu.sarpras.ruangan();',
    to: 'he=[];',
  },
  {
    id: 'J9 widget dashboard tanpa ruang',
    from: 'a.jsx("p",{className:"text-[10px] text-slate-500 font-semibold",children:I.jamKe}),I.ruang&&a.jsxs("p",{className:"text-[9px] text-slate-400 font-bold flex items-center gap-1 mt-0.5",children:[a.jsx(I0,{size:9})," ",I.ruang]})',
    to: 'a.jsx("p",{className:"text-[10px] text-slate-500 font-semibold",children:__jkTeksSesi(I)})',
  },
  {
    id: 'J10/J11 hook jadwal di form jurnal',
    from: 'const __dspJ=__dspPakaiJurnal(',
    to: 'const __jkJ=__jkPakaiJurnal({user:t,edit:!!n,form:y,setForm:N,opsiMapel:Ae.length>0?Ae:Te}),__dspJ=__dspPakaiJurnal(',
  },
  {
    id: 'J13 flag manual pada input jam',
    from: 'Yt=Ge=>{N(at=>({...at,[Ge.target.name]:Ge.target.value}))}',
    to: 'Yt=Ge=>{__jkJ.ubah(Ge.target.name),N(at=>({...at,[Ge.target.name]:Ge.target.value}))}',
  },
  {
    id: 'J13b pilihan mapel manual di form jurnal',
    from: 'a.jsxs("select",{value:y.mapelId,onChange:Ge=>{const Ke=(Ae.length>0?Ae:Te).find(',
    to: 'a.jsxs("select",{value:y.mapelId,onChange:Ge=>{__jkJ.ubah("mapelId");const Ke=(Ae.length>0?Ae:Te).find(',
  },
  {
    id: 'J12 info & chip jadwal di bawah field jam',
    from: 'a.jsx("div",{className:"flex justify-end pt-2",children:a.jsxs("button",{onClick:()=>{!y.jam.trim()',
    to: 'a.jsx(__jkChipJurnal,{j:__jkJ}),a.jsx("div",{className:"flex justify-end pt-2",children:a.jsxs("button",{onClick:()=>{!y.jam.trim()',
  },
];

// ---------------------------------------------------------------------------
const css = fs.readFileSync(cssPath, 'utf8');
const cssEscape = (c) => c.replace(/[^a-zA-Z0-9_-]/g, (ch) => '\\' + ch);
const kelas = new Set();
Object.values(KELAS).forEach((v) => v.split(/\s+/).filter(Boolean).forEach((c) => kelas.add(c)));
for (const m of FUNGSI.map((f) => f.toString()).join('').matchAll(/className:\s*(['"`])([^'"`]*?)\1/g)) {
  m[2].split(/\s+/).filter((c) => c && !c.includes('${')).forEach((c) => kelas.add(c));
}
const hilang = [...kelas].filter((c) => {
  const re = new RegExp('\\.' + cssEscape(c).replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '(?![a-zA-Z0-9_\\\\-])');
  return !re.test(css);
});
if (hilang.length) fail('class Tailwind tidak ada di ' + CSS_NAME + ': ' + hilang.join(' '));
console.log(`OK  ${kelas.size} class Tailwind tersedia di CSS bundle`);

let out = src;
for (const p of patches) {
  const count = out.split(p.from).length - 1;
  if (count !== 1) fail(`${p.id}: anchor ditemukan ${count} kali (harus 1).`);
  out = out.replace(p.from, () => p.to);
  console.log('OK  ' + p.id);
}

fs.writeFileSync(outPath, out, 'utf8');
console.log(`Ditulis assets/${OUT_NAME}`);

const html = fs.readFileSync(appHtmlPath, 'utf8');
if (html.includes(`./assets/${OUT_NAME}`)) {
  console.log('app.html sudah mereferensikan bundle patch.');
} else {
  const ref = `./assets/${SRC_NAME}`;
  if (html.split(ref).length - 1 !== 1) fail(`app.html tidak mereferensikan ${ref} tepat 1 kali.`);
  fs.writeFileSync(appHtmlPath, html.replace(ref, `./assets/${OUT_NAME}`), 'utf8');
  console.log(`app.html diarahkan ke assets/${OUT_NAME}`);
}
